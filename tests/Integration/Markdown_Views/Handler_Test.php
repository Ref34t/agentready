<?php
/**
 * Integration tests for the Markdown Views public-route handler.
 *
 * Exercises the pure(-ish) `build_response()` method so we can assert on the
 * response shape without dealing with `exit`. Verifies:
 *
 *   - 200 + text/plain + body on an exposable post (#293: ChatGPT's fetcher
 *     rejects text/markdown, so twins ship as text/plain + inline)
 *   - 404 + empty body on a module-disabled toggle (AgDR-0015)
 *   - 404 + empty body on a non-exposable post (draft, password, wrong CPT)
 *   - Response shape matches AgDR-0013's content-negotiation contract
 *
 * Direct dispatch (status_header, header, echo, exit) is unit-untestable in
 * PHPUnit without process isolation; covered indirectly by `build_response()`
 * tests + manual smoke during QA.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Markdown_Views;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Handler;
use Mokhai\Markdown_Views\Schema;
use Mokhai\Markdown_Views\Router;

final class Handler_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Schema::create();

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post', 'page' ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);
	}

	protected function tearDown(): void {
		Schema::drop();
		remove_all_filters( 'mokhai_post_is_noindexed' );
		parent::tearDown();
	}

	public function test_exposable_post_returns_200_with_markdown_body(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => '<h2>Heading</h2><p>Body with <strong>strong</strong>.</p>',
			)
		);

		$response = Handler::build_response( $post );

		self::assertSame( 200, $response['status'] );
		self::assertSame( 'text/plain; charset=utf-8', $response['headers']['Content-Type'] );
		self::assertSame( 'inline', $response['headers']['Content-Disposition'] );
		self::assertStringContainsString( '## Heading', $response['body'] );
		self::assertStringContainsString( '**strong**', $response['body'] );
	}

	public function test_exposable_post_response_includes_noindex_robots_header(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Visible.</p>' )
		);

		$response = Handler::build_response( $post );

		self::assertSame( 'noindex', $response['headers']['X-Robots-Tag'] );
	}

	public function test_module_disabled_returns_404_with_empty_body(): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'           => array( 'post' ),
					'markdown_views_enabled' => false,
				)
			)
		);

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Would have content.</p>' )
		);

		$response = Handler::build_response( $post );

		self::assertSame( 404, $response['status'] );
		self::assertSame( '', $response['body'], 'AgDR-0015: 404 must not leak the reason' );
	}

	public function test_password_protected_post_returns_404(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_content'  => '<p>Secret.</p>',
				'post_password' => 'sekret',
			)
		);

		$response = Handler::build_response( $post );

		self::assertSame( 404, $response['status'] );
		self::assertSame( '', $response['body'] );
	}

	public function test_draft_post_returns_404(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => '<p>Not yet.</p>',
				'post_status'  => 'draft',
			)
		);

		$response = Handler::build_response( $post );

		self::assertSame( 404, $response['status'] );
	}

	public function test_unexposed_cpt_returns_404(): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array( 'exposed_cpts' => array() )
			)
		);

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Hello.</p>' )
		);

		$response = Handler::build_response( $post );

		self::assertSame( 404, $response['status'] );
	}

	public function test_404_response_carries_text_plain_content_type(): void {
		$response = Handler::build_404_response();

		self::assertSame( 404, $response['status'] );
		self::assertSame( 'text/plain; charset=utf-8', $response['headers']['Content-Type'] );
		self::assertSame( '', $response['body'] );
	}

	public function test_rewrite_rule_registered_after_router_init(): void {
		// Simulate the init hook by calling add_rewrite_rule directly.
		Router::add_rewrite_rule();

		// `add_rewrite_rule()` writes to `$wp_rewrite->extra_rules_top`. The
		// `->rules` property is lazily built by `wp_rewrite_rules()` and is
		// not populated synchronously when the rule is registered. Assert
		// on the actual write target.
		global $wp_rewrite;
		$rules = (array) $wp_rewrite->extra_rules_top;

		self::assertArrayHasKey( '^(.+)\.md/?$', $rules );
		self::assertStringContainsString( 'mokhai_md_request', $rules['^(.+)\.md/?$'] );
	}

	public function test_query_var_filter_registers_rewrite_var(): void {
		$vars = Router::register_query_var( array( 'existing' ) );

		self::assertContains( Router::REWRITE_VAR, $vars );
		self::assertContains( 'existing', $vars, 'Filter must preserve existing vars' );
	}

	/* ---------------------------------------------------------------------
	 * Vary: Accept (#332)
	 * ------------------------------------------------------------------- */

	public function test_accept_negotiated_response_carries_vary_accept(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Negotiated.</p>' )
		);

		$response = Handler::build_response( $post, true );

		self::assertSame(
			'Accept',
			$response['headers']['Vary'] ?? null,
			'#332: a response reached by Accept negotiation on the canonical URL must advertise Vary: Accept so shared caches key the variant separately'
		);
	}

	public function test_distinct_url_response_omits_vary_accept(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Distinct URL.</p>' )
		);

		$response = Handler::build_response( $post, false );

		self::assertArrayNotHasKey(
			'Vary',
			$response['headers'],
			'#332: /path.md and ?format=md return Markdown regardless of Accept — advertising Vary there only fragments the cache'
		);
	}

	public function test_build_response_defaults_to_no_vary(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Default.</p>' )
		);

		self::assertArrayNotHasKey( 'Vary', Handler::build_response( $post )['headers'] );
	}

	public function test_cache_control_is_no_store_without_redundant_must_revalidate(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Cache policy.</p>' )
		);

		$response = Handler::build_response( $post );

		self::assertSame(
			'no-store',
			$response['headers']['Cache-Control'],
			'must-revalidate is redundant alongside no-store (#332)'
		);
	}

	public function test_content_type_stays_text_plain_alongside_vary(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Compat.</p>' )
		);

		$response = Handler::build_response( $post, true );

		self::assertSame(
			'text/plain; charset=utf-8',
			$response['headers']['Content-Type'],
			'#293: ChatGPT rejects text/markdown — the Vary fix must not change the served type'
		);
	}

	public function test_send_vary_header_hook_is_registered_on_send_headers(): void {
		Router::register_hooks();

		self::assertNotFalse(
			has_action( 'send_headers', array( Handler::class, 'send_vary_header' ) ),
			'#332: Vary must reach the HTML representation, which only send_headers sees'
		);
	}
}
