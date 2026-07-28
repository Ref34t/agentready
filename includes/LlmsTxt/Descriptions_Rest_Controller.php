<?php
/**
 * REST controller for the Phase B admin UI of #8 (LLM-powered
 * /llms.txt entry descriptions), per AgDR-0029.
 *
 * Five routes under `mokhai/v1/llms-txt/descriptions/*`, all gated
 * by `manage_options`:
 *
 *   GET    /mokhai/v1/llms-txt/descriptions
 *   PATCH  /mokhai/v1/llms-txt/descriptions/<post_id>
 *   DELETE /mokhai/v1/llms-txt/descriptions/<post_id>/manual
 *   POST   /mokhai/v1/llms-txt/descriptions/<post_id>/regenerate
 *   POST   /mokhai/v1/llms-txt/descriptions/bulk-regenerate-stale
 *
 * Read responses (GET + every mutation's response body) share a single
 * row-projection function so the UI can refresh state from a mutation
 * response without a second fetch.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\LlmsTxt;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Ai\Client_Wrapper;

\defined( 'ABSPATH' ) || exit;

/**
 * Static REST controller for the LLM descriptions admin surface.
 */
final class Descriptions_Rest_Controller {

	/**
	 * REST namespace shared with the rest of mokhai.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'mokhai/v1';

	/**
	 * Base path under the namespace. Per-post routes append `/<post_id>`;
	 * collection / bulk routes append nothing or `/bulk-regenerate-stale`.
	 *
	 * @var string
	 */
	public const ROUTE_BASE = '/llms-txt/descriptions';

	/**
	 * Default page size. Bounded by MAX_PER_PAGE so a hostile request
	 * cannot blow up the WP_Query.
	 *
	 * @var int
	 */
	public const DEFAULT_PER_PAGE = 20;

	/**
	 * Hard upper bound on `per_page`.
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Hard upper bound on the bulk-regenerate `limit` parameter. Keeps
	 * one request from scheduling thousands of cron events at once.
	 *
	 * @var int
	 */
	public const MAX_BULK_LIMIT = 100;

	/**
	 * Status-filter values accepted by GET. Anything else degrades to
	 * `any` (no extra predicate).
	 *
	 * @var array<int, string>
	 */
	private const STATUS_FILTERS = array(
		'any',
		'missing',
		'cached',
		'pending',
		'failed',
		'needs-retry',
		'stale',
		'manual',
	);

	/**
	 * Wire the registration hook. Called from `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register all five routes under the current namespace, plus legacy aliases.
	 */
	public static function register_routes(): void {
		$routes = array(
			array(
				'path' => self::ROUTE_BASE,
				'args' => array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'handle_list' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'paged'    => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => self::DEFAULT_PER_PAGE,
							'sanitize_callback' => 'absint',
						),
						'cpt'      => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'status'   => array(
							'type'              => 'string',
							'default'           => 'any',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			),
			array(
				'path' => self::ROUTE_BASE . '/(?P<post_id>\d+)',
				'args' => array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'handle_patch' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'post_id' => self::post_id_arg(),
						'manual'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			),
			array(
				'path' => self::ROUTE_BASE . '/(?P<post_id>\d+)/manual',
				'args' => array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'handle_clear_manual' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'post_id' => self::post_id_arg(),
					),
				),
			),
			array(
				'path' => self::ROUTE_BASE . '/(?P<post_id>\d+)/regenerate',
				'args' => array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle_regenerate' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'post_id' => self::post_id_arg(),
					),
				),
			),
			array(
				'path' => self::ROUTE_BASE . '/bulk-regenerate-stale',
				'args' => array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle_bulk_regenerate_stale' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'limit' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			),
		);

		foreach ( $routes as $route ) {
			\register_rest_route( self::NAMESPACE, $route['path'], $route['args'] );
		}
	}

	/**
	 * GET handler: paginated list of exposed posts with description state.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 */
	public static function handle_list( \WP_REST_Request $request ): \WP_REST_Response {
		$paged     = \max( 1, (int) $request->get_param( 'paged' ) );
		$per_page  = \min( self::MAX_PER_PAGE, \max( 1, (int) $request->get_param( 'per_page' ) ) );
		$cpt_param = (string) $request->get_param( 'cpt' );
		$status    = (string) $request->get_param( 'status' );

		if ( ! \in_array( $status, self::STATUS_FILTERS, true ) ) {
			$status = 'any';
		}

		$profile  = Context_Profile_Settings::get_profile();
		$exposed  = isset( $profile['exposed_cpts'] ) && \is_array( $profile['exposed_cpts'] )
			? $profile['exposed_cpts']
			: array();
		$statuses = isset( $profile['exposed_statuses'] ) && \is_array( $profile['exposed_statuses'] )
			? $profile['exposed_statuses']
			: array( 'publish' );

		$cpts = '' !== $cpt_param && \in_array( $cpt_param, $exposed, true )
			? array( $cpt_param )
			: $exposed;

		if ( array() === $cpts ) {
			return new \WP_REST_Response(
				array(
					'items'    => array(),
					'total'    => 0,
					'page'     => $paged,
					'per_page' => $per_page,
					'pages'    => 0,
				),
				200
			);
		}

		$query_args = array(
			'post_type'              => $cpts,
			'post_status'            => $statuses,
			'posts_per_page'         => $per_page,
			'paged'                  => $paged,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
		);

		$meta_query = self::meta_query_for_status_filter( $status );
		if ( array() !== $meta_query ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new \WP_Query( $query_args );

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$items[] = self::project_row( $post );
		}

		$total = (int) $query->found_posts;
		$pages = $per_page > 0 ? (int) \ceil( $total / $per_page ) : 0;

		\wp_reset_postdata();

		return new \WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $paged,
				'per_page' => $per_page,
				'pages'    => $pages,
			),
			200
		);
	}

	/**
	 * PATCH handler: set the sticky `_manual` override for one post.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_patch( \WP_REST_Request $request ) {
		$post = self::resolve_post( (int) $request->get_param( 'post_id' ) );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		$manual = (string) $request->get_param( 'manual' );
		Description_Orchestrator::set_manual( (int) $post->ID, $manual );

		return new \WP_REST_Response( self::project_row( $post ), 200 );
	}

	/**
	 * DELETE handler: clear the `_manual` override; `_auto` (or excerpt)
	 * takes over on next /llms.txt regen.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_clear_manual( \WP_REST_Request $request ) {
		$post = self::resolve_post( (int) $request->get_param( 'post_id' ) );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		Description_Orchestrator::clear_manual( (int) $post->ID );

		return new \WP_REST_Response( self::project_row( $post ), 200 );
	}

	/**
	 * POST handler: regenerate one post's `_auto` description.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_regenerate( \WP_REST_Request $request ) {
		$post = self::resolve_post( (int) $request->get_param( 'post_id' ) );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		// Checked before AI availability, mirroring the descriptions UI's own
		// precedence: a sticky post can never be regenerated, which is a more
		// specific answer than "the client isn't configured". The UI disables
		// the button for these rows, but a non-browser caller (WP-CLI, a direct
		// REST call, an ability) arrives with no such guard (#330).
		if ( Description_Orchestrator::has_manual( (int) $post->ID ) ) {
			return new \WP_Error(
				'rest_manual_description_sticky',
				\__(
					'Manual override is sticky. Clear the manual description before regenerating.',
					'mokhai-agent-readiness-kit'
				),
				array( 'status' => 409 )
			);
		}

		if ( ! Client_Wrapper::has_ai_client() ) {
			return new \WP_Error(
				'rest_ai_client_unavailable',
				\__( 'WP AI Client is not configured.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 409 )
			);
		}

		Description_Orchestrator::regenerate( $post );

		return new \WP_REST_Response( self::project_row( $post ), 200 );
	}

	/**
	 * POST handler: schedule description jobs for every exposed post
	 * whose `_auto` is stale OR missing AND no sticky `_manual` exists.
	 *
	 * Returns `{ scheduled, skipped, total_considered }` so the UI can
	 * surface a non-misleading toast.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_bulk_regenerate_stale( \WP_REST_Request $request ) {
		if ( ! Client_Wrapper::has_ai_client() ) {
			return new \WP_Error(
				'rest_ai_client_unavailable',
				\__( 'WP AI Client is not configured.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 409 )
			);
		}

		if ( empty( Context_Profile_Settings::get_profile()['llm_descriptions_enabled'] ) ) {
			return new \WP_Error(
				'rest_descriptions_disabled',
				\__( 'LLM descriptions are disabled in the Context Profile.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 409 )
			);
		}

		$requested_limit = (int) $request->get_param( 'limit' );
		if ( $requested_limit <= 0 ) {
			$requested_limit = Context_Profile_Settings::get_descriptions_max_per_run();
		}
		$limit = \min( self::MAX_BULK_LIMIT, $requested_limit );

		$profile  = Context_Profile_Settings::get_profile();
		$cpts     = isset( $profile['exposed_cpts'] ) && \is_array( $profile['exposed_cpts'] )
			? $profile['exposed_cpts']
			: array();
		$statuses = isset( $profile['exposed_statuses'] ) && \is_array( $profile['exposed_statuses'] )
			? $profile['exposed_statuses']
			: array( 'publish' );

		if ( array() === $cpts ) {
			return new \WP_REST_Response(
				array(
					'scheduled'        => 0,
					'skipped'          => 0,
					'total_considered' => 0,
				),
				200
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $cpts,
				'post_status'            => $statuses,
				'posts_per_page'         => Entry_Source::PER_CPT_CAP,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);

		$scheduled = 0;
		$skipped   = 0;

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			if ( $scheduled >= $limit ) {
				++$skipped;
				continue;
			}

			if ( ! Description_Orchestrator::should_schedule( $post ) ) {
				++$skipped;
				continue;
			}

			Description_Orchestrator::schedule( $post );
			++$scheduled;
		}

		\wp_reset_postdata();

		return new \WP_REST_Response(
			array(
				'scheduled'        => $scheduled,
				'skipped'          => $skipped,
				'total_considered' => $scheduled + $skipped,
			),
			200
		);
	}

	/**
	 * Capability gate. `manage_options` matches the rest of the Context
	 * Profile screen.
	 *
	 * @return bool|\WP_Error
	 */
	public static function check_permission() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You are not allowed to manage entry descriptions.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Validate + resolve a post ID into a `WP_Post`, or return an error.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private static function resolve_post( int $post_id ) {
		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'rest_invalid_post',
				\__( 'A valid post ID is required.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 400 )
			);
		}
		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'rest_post_not_found',
				\__( 'Post not found.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 404 )
			);
		}
		return $post;
	}

	/**
	 * Build the row DTO for one post. Used by GET and by every mutation
	 * response so the UI can refresh from a mutation reply.
	 *
	 * @return array<string, mixed>
	 */
	private static function project_row( \WP_Post $post ): array {
		$post_id = (int) $post->ID;

		$auto    = (string) \get_post_meta( $post_id, Description_Orchestrator::META_KEY_AUTO, true );
		$manual  = (string) \get_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, true );
		$gen_for = (string) \get_post_meta( $post_id, Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED, true );

		$resolved = Description_Orchestrator::get_cached_description( $post_id );
		$source   = 'none';
		if ( '' !== \trim( $manual ) ) {
			$source = 'manual';
		} elseif ( '' !== \trim( $auto ) ) {
			$source = 'auto';
		} else {
			// Fall through to Entry_Source's excerpt behaviour for parity.
			$excerpt = \trim( (string) \get_post_field( 'post_excerpt', $post, 'raw' ) );
			if ( '' !== $excerpt ) {
				$source   = 'excerpt';
				$resolved = $excerpt;
			}
		}

		$diagnostics_raw = \get_post_meta( $post_id, Description_Orchestrator::META_KEY_DIAGNOSTICS, true );
		$diagnostics     = null;
		if ( \is_string( $diagnostics_raw ) && '' !== $diagnostics_raw ) {
			$decoded = \json_decode( $diagnostics_raw, true );
			if ( \is_array( $decoded ) ) {
				$diagnostics = $decoded;
			}
		}

		return array(
			'post_id'                    => $post_id,
			'title'                      => (string) \get_the_title( $post ),
			'url'                        => (string) \get_permalink( $post ),
			'post_type'                  => (string) $post->post_type,
			'post_modified_gmt'          => (string) $post->post_modified_gmt,
			'auto'                       => $auto,
			'manual'                     => $manual,
			'resolved'                   => $resolved,
			'source'                     => $source,
			'status'                     => Description_Orchestrator::get_status( $post_id ),
			'generated_for_modified_gmt' => $gen_for,
			'is_stale'                   => Description_Orchestrator::is_stale( $post ),
			// Posts in an exposed CPT/status can still be excluded from
			// /llms.txt by the password / noindex / manual-exclusion gates.
			// The table lists them for visibility; this flag lets the UI mark
			// them as excluded so the "N skipped" behaviour is self-explanatory.
			'excluded'                   => ! Context_Profile_Settings::is_url_exposable( $post ),
			'diagnostics'                => $diagnostics,
		);
	}

	/**
	 * Translate the `status` query parameter into a `meta_query` slice.
	 * Returns an empty array for `any` / unknown.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function meta_query_for_status_filter( string $status ): array {
		switch ( $status ) {
			case 'missing':
				return array(
					array(
						'key'     => Description_Orchestrator::META_KEY_AUTO,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Description_Orchestrator::META_KEY_MANUAL,
						'compare' => 'NOT EXISTS',
					),
				);
			case 'cached':
				return array(
					array(
						'key'     => Description_Orchestrator::META_KEY_AUTO,
						'compare' => 'EXISTS',
					),
				);
			case 'pending':
				return array(
					array(
						'key'   => Description_Orchestrator::META_KEY_STATUS,
						'value' => Description_Orchestrator::STATUS_PENDING,
					),
				);
			case 'failed':
				return array(
					array(
						'key'   => Description_Orchestrator::META_KEY_STATUS,
						'value' => Description_Orchestrator::STATUS_FAILED,
					),
				);
			case 'needs-retry':
				return array(
					array(
						'key'   => Description_Orchestrator::META_KEY_STATUS,
						'value' => Description_Orchestrator::STATUS_NEEDS_RETRY,
					),
				);
			case 'manual':
				return array(
					array(
						'key'     => Description_Orchestrator::META_KEY_MANUAL,
						'compare' => 'EXISTS',
					),
				);
			case 'stale':
				// `stale` is "auto exists AND generated_for < modified".
				// Phase B uses an EXISTS predicate at the SQL level + a
				// PHP-side `is_stale()` re-filter on the returned page,
				// because comparing two columns (meta vs post_modified_gmt)
				// in WP_Query is awkward. Per-page filter is fine — the
				// outer SQL still narrows to posts with `_auto` set, so
				// the post-filter loops over at most `per_page` rows.
				return array(
					array(
						'key'     => Description_Orchestrator::META_KEY_AUTO,
						'compare' => 'EXISTS',
					),
				);
			case 'any':
			default:
				return array();
		}
	}

	/**
	 * Standard arg-spec for a post-id URL parameter.
	 *
	 * @return array<string, mixed>
	 */
	private static function post_id_arg(): array {
		return array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => static function ( $value ): bool {
				return \is_numeric( $value ) && (int) $value > 0;
			},
		);
	}
}
