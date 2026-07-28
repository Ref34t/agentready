<?php
/**
 * WP-CLI surface for #8 LLM-powered entry descriptions.
 *
 * Three subcommands mounted at `wp mokhai llms-txt descriptions`:
 *   - `status` — counts (manual / auto / pending / missing) for the
 *     current exposure set.
 *   - `backfill` — iterate the exposure set and schedule a description
 *     job for every post missing an `_auto` cache.
 *   - `regen <post>` — force-regenerate one post regardless of cache
 *     state.
 *
 * The orchestrator's `run()` is the actual LLM call; this surface only
 * decides scheduling.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Cli;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Ai\Client_Wrapper;
use Mokhai\LlmsTxt\Description_Orchestrator;
use Mokhai\LlmsTxt\Entry_Source;

\defined( 'ABSPATH' ) || exit;

/**
 * Manage LLM-powered /llms.txt entry descriptions from the command line.
 *
 * ## EXAMPLES
 *
 *     # Counts for the current exposure set.
 *     $ wp mokhai llms-txt descriptions status
 *
 *     # Queue a description job for every missing entry across all
 *     # exposed CPTs and statuses.
 *     $ wp mokhai llms-txt descriptions backfill
 *
 *     # Force-regenerate the cache for one post.
 *     $ wp mokhai llms-txt descriptions regen 42
 */
final class Llms_Txt_Descriptions_Command {

	/**
	 * Register the command tree.
	 *
	 * Called from `Main::register_hooks()` after the WP_CLI runtime
	 * guard.
	 */
	public static function register(): void {
		if ( ! \defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'mokhai llms-txt descriptions', self::class );
	}

	/**
	 * Report the description-cache state across the exposure set.
	 *
	 * ## OPTIONS
	 *
	 * [--cpt=<cpt>]
	 * : Limit to one post type. Default: every entry in
	 *   `exposed_cpts`.
	 *
	 * [--porcelain]
	 * : One `key=value` line per field instead of the human-readable
	 *   table.
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );

		$cpts = self::resolve_cpts( $assoc_args );
		if ( array() === $cpts ) {
			\WP_CLI::warning( 'No exposed CPTs configured. Nothing to report.' );
			return;
		}

		$totals = array(
			'exposed_total'  => 0,
			'manual_set'     => 0,
			'auto_cached'    => 0,
			'pending'        => 0,
			'needs_retry'    => 0,
			'failed'         => 0,
			'missing'        => 0,
			'llm_available'  => Client_Wrapper::has_ai_client() ? 'yes' : 'no',
			'toggle_enabled' => empty( Context_Profile_Settings::get_profile()['llm_descriptions_enabled'] ) ? 'no' : 'yes',
		);

		foreach ( $cpts as $cpt ) {
			$post_ids = self::collect_post_ids( $cpt );
			foreach ( $post_ids as $post_id ) {
				++$totals['exposed_total'];

				$manual = \get_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, true );
				if ( \is_string( $manual ) && '' !== \trim( $manual ) ) {
					++$totals['manual_set'];
					continue;
				}

				$auto = \get_post_meta( $post_id, Description_Orchestrator::META_KEY_AUTO, true );
				if ( \is_string( $auto ) && '' !== \trim( $auto ) ) {
					++$totals['auto_cached'];
					continue;
				}

				$status = Description_Orchestrator::get_status( $post_id );
				if ( Description_Orchestrator::STATUS_PENDING === $status ) {
					++$totals['pending'];
				} elseif ( Description_Orchestrator::STATUS_NEEDS_RETRY === $status ) {
					++$totals['needs_retry'];
				} elseif ( Description_Orchestrator::STATUS_FAILED === $status ) {
					++$totals['failed'];
				} else {
					++$totals['missing'];
				}
			}
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			foreach ( $totals as $key => $value ) {
				\WP_CLI::line( $key . '=' . $value );
			}
			return;
		}

		$rows = array();
		foreach ( $totals as $key => $value ) {
			$rows[] = array(
				'Field' => $key,
				'Value' => (string) $value,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );
	}

	/**
	 * Schedule description jobs for every exposed post without a cached
	 * description.
	 *
	 * ## OPTIONS
	 *
	 * [--cpt=<cpt>]
	 * : Limit to one post type. Default: every entry in
	 *   `exposed_cpts`.
	 *
	 * [--limit=<n>]
	 * : Cap how many jobs this run will schedule. Useful when the
	 *   descriptions cap is small and you want to drive the queue in
	 *   batches across multiple invocations.
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 */
	public function backfill( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! Client_Wrapper::has_ai_client() ) {
			\WP_CLI::error( 'WP AI Client is not available. Configure it before running backfill.' );
			return;
		}

		if ( empty( Context_Profile_Settings::get_profile()['llm_descriptions_enabled'] ) ) {
			\WP_CLI::error( 'LLM descriptions are disabled in the Context Profile (`llm_descriptions_enabled`).' );
			return;
		}

		$cpts = self::resolve_cpts( $assoc_args );
		if ( array() === $cpts ) {
			\WP_CLI::warning( 'No exposed CPTs configured. Nothing to do.' );
			return;
		}

		$limit = isset( $assoc_args['limit'] ) ? \max( 1, (int) $assoc_args['limit'] ) : PHP_INT_MAX;

		$scheduled = 0;
		$skipped   = 0;

		foreach ( $cpts as $cpt ) {
			if ( $scheduled >= $limit ) {
				break;
			}

			$post_ids = self::collect_post_ids( $cpt );
			foreach ( $post_ids as $post_id ) {
				if ( $scheduled >= $limit ) {
					break;
				}

				$post = \get_post( $post_id );
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}

				if ( ! Description_Orchestrator::should_schedule( $post ) ) {
					++$skipped;
					continue;
				}

				Description_Orchestrator::schedule( $post );
				++$scheduled;
			}
		}

		\WP_CLI::success(
			\sprintf( 'Scheduled %d description job(s); %d post(s) already had a cached or sticky description.', $scheduled, $skipped )
		);
	}

	/**
	 * Force-regenerate the cache for one post.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mokhai llms-txt descriptions regen 42
	 *
	 * @param array<int, string>    $args       Positional args — [post_id].
	 * @param array<string, string> $assoc_args Associative args (unused).
	 */
	public function regen( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		if ( array() === $args ) {
			\WP_CLI::error( 'Usage: wp mokhai llms-txt descriptions regen <post_id>' );
			return;
		}

		$post_id = (int) $args[0];
		$post    = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			\WP_CLI::error( \sprintf( 'Post #%d not found.', $post_id ) );
			return;
		}

		// Ask before calling, so the two refusals (#330) report distinctly
		// instead of both surfacing as "already pending".
		if ( Description_Orchestrator::has_manual( $post_id ) ) {
			\WP_CLI::warning(
				\sprintf(
					'Post #%d has a sticky manual description; nothing to regenerate. Clear the manual override first (Tools → Context → descriptions).',
					$post_id
				)
			);
			return;
		}

		$regenerated = Description_Orchestrator::regenerate( $post );

		if ( ! $regenerated ) {
			\WP_CLI::warning( \sprintf( 'Post #%d already has a description job pending.', $post_id ) );
			return;
		}

		\WP_CLI::success( \sprintf( 'Queued regen for post #%d.', $post_id ) );
	}

	/**
	 * Resolve the CPT list for a subcommand invocation. If `--cpt=X` is
	 * passed, return [X] when X is also in `exposed_cpts`; otherwise
	 * return the configured `exposed_cpts`.
	 *
	 * @param array<string, string> $assoc_args
	 *
	 * @return array<int, string>
	 */
	private static function resolve_cpts( array $assoc_args ): array {
		$profile = Context_Profile_Settings::get_profile();
		$exposed = isset( $profile['exposed_cpts'] ) && \is_array( $profile['exposed_cpts'] )
			? $profile['exposed_cpts']
			: array();

		if ( isset( $assoc_args['cpt'] ) && '' !== $assoc_args['cpt'] ) {
			$requested = (string) $assoc_args['cpt'];
			if ( ! \in_array( $requested, $exposed, true ) ) {
				\WP_CLI::warning(
					\sprintf( 'Post type "%s" is not in exposed_cpts; ignoring.', $requested )
				);
				return array();
			}
			return array( $requested );
		}

		return \array_values( \array_filter( $exposed, 'is_string' ) );
	}

	/**
	 * Collect post IDs for one CPT under the current exposure set.
	 * Pulls `WP_Query` with the same statuses and per-CPT cap that
	 * `Entry_Source` uses, so the surfaces stay in lockstep.
	 *
	 * @return array<int, int>
	 */
	private static function collect_post_ids( string $cpt ): array {
		$profile  = Context_Profile_Settings::get_profile();
		$statuses = isset( $profile['exposed_statuses'] ) && \is_array( $profile['exposed_statuses'] )
			? $profile['exposed_statuses']
			: array( 'publish' );

		$query = new \WP_Query(
			array(
				'post_type'              => $cpt,
				'post_status'            => $statuses,
				'posts_per_page'         => Entry_Source::PER_CPT_CAP,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$ids = array();
		foreach ( $query->posts as $post_id ) {
			$ids[] = (int) $post_id;
		}

		return $ids;
	}
}
