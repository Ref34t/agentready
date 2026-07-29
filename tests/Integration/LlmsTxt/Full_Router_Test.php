<?php
/**
 * Integration tests for the `/llms-full.txt` route (#179).
 *
 * Covers the Router response shape (`build_full_response()`), the module
 * toggle's soft-disable 404, content parity with `/llms.txt`, the exclusion
 * gates, the shared regen pipeline (one `regen_sync()` writes both caches,
 * toggle-off clears the full cache), and the Markdown-Views-disabled
 * fallback. The `dispatch()` path (headers + exit) is not exercised — same
 * rationale as `Router_Test`.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\LlmsTxt\Router;
use Mokhai\LlmsTxt\Service;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

final class Full_Router_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The Markdown_Views save_post invalidation hook (and the full-body
		// markdown resolution) need the cache table.
		Markdown_Views_Schema::create();

		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );

		$this->set_profile( array() );
		delete_option( 'mokhai_llms_txt_editorial' );

		wp_clear_scheduled_hook( Service::REGEN_ACTION );
	}

	protected function tearDown(): void {
		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		set_query_var( Router::REWRITE_VAR, '' );
		set_query_var( Router::FULL_REWRITE_VAR, '' );
		Markdown_Views_Schema::drop();
		parent::tearDown();
	}

	/**
	 * Write the Context Profile with post CPT exposed plus overrides.
	 *
	 * @param array<string, mixed> $overrides Profile key overrides.
	 */
	private function set_profile( array $overrides ): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post' ),
					'exposed_statuses' => array( 'publish' ),
				),
				$overrides
			)
		);
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
	}

	public function test_is_llms_full_txt_request_reads_query_var(): void {
		set_query_var( Router::FULL_REWRITE_VAR, '1' );
		$this->assertTrue( Router::is_llms_full_txt_request() );

		set_query_var( Router::FULL_REWRITE_VAR, '' );
		$this->assertFalse( Router::is_llms_full_txt_request() );
	}

	public function test_add_rewrite_rule_registers_full_route_in_top_extras(): void {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();

		Router::add_rewrite_rule();

		$this->assertArrayHasKey( '^llms-full\.txt/?$', $wp_rewrite->extra_rules_top );
		$this->assertStringContainsString(
			Router::FULL_REWRITE_VAR,
			$wp_rewrite->extra_rules_top['^llms-full\.txt/?$']
		);
	}

	public function test_register_query_var_appends_full_rewrite_var(): void {
		$vars = Router::register_query_var( array() );

		$this->assertContains( Router::FULL_REWRITE_VAR, $vars );
	}

	public function test_build_full_response_returns_text_plain_with_full_content(): void {
		self::factory()->post->create(
			array(
				'post_title'   => 'Full Body Post',
				'post_content' => '<h2>Section Heading</h2><p>The complete paragraph body.</p>',
				'post_status'  => 'publish',
			)
		);

		$response = Router::build_full_response();

		$this->assertSame( 200, $response['status'] );
		$this->assertStringStartsWith( 'text/plain', $response['headers']['Content-Type'] );
		$this->assertSame( 'noindex', $response['headers']['X-Robots-Tag'] );
		$this->assertStringContainsString( 'Full Body Post', $response['body'] );
		// Index lines carry titles; only the full document carries body text.
		$this->assertStringContainsString( 'The complete paragraph body.', $response['body'] );
	}

	public function test_body_has_no_bom_or_leading_whitespace(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Any',
				'post_status' => 'publish',
			)
		);

		$body = Router::build_full_response()['body'];

		$this->assertNotSame( '', $body );
		$this->assertSame( '#', substr( $body, 0, 1 ) );
	}

	public function test_module_toggle_off_returns_404(): void {
		$this->set_profile( array( 'llms_full_txt_enabled' => false ) );

		$response = Router::build_full_response();

		$this->assertSame( 404, $response['status'] );
		$this->assertSame( '', $response['body'] );
	}

	public function test_empty_composition_returns_200_header_only(): void {
		$this->set_profile( array( 'exposed_cpts' => array() ) );

		$response = Router::build_full_response();

		// #244: nothing exposed → 200 with the site identity header, not blank.
		$this->assertSame( 200, $response['status'] );
		$this->assertNotSame( '', $response['body'] );
		$this->assertStringStartsWith( '# ', $response['body'] );
		$this->assertStringNotContainsString( "\n- [", $response['body'], 'No content exposed → no entries.' );
	}

	public function test_every_llms_txt_url_appears_in_llms_full_txt(): void {
		self::factory()->post->create_many(
			3,
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Body text.</p>',
			)
		);

		Service::regen_sync();

		$index = Service::get_composed_body();
		$full  = Service::get_composed_full_body();

		preg_match_all( '/\]\(([^)]+)\)/', $index, $matches );
		$this->assertNotEmpty( $matches[1] );

		foreach ( $matches[1] as $url ) {
			$this->assertStringContainsString( 'URL: ' . $url, $full );
		}
	}

	public function test_excluded_post_absent_from_llms_full_txt(): void {
		$kept     = self::factory()->post->create(
			array(
				'post_title'   => 'Kept Document',
				'post_content' => '<p>Kept body.</p>',
				'post_status'  => 'publish',
			)
		);
		$excluded = self::factory()->post->create(
			array(
				'post_title'   => 'Hidden Document',
				'post_content' => '<p>Hidden body.</p>',
				'post_status'  => 'publish',
			)
		);
		unset( $kept );

		update_post_meta( $excluded, Context_Profile_Settings::EXCLUDE_META_KEY, '1' );

		Service::regen_sync();
		$full = Service::get_composed_full_body();

		$this->assertStringContainsString( 'Kept Document', $full );
		$this->assertStringNotContainsString( 'Hidden Document', $full );
		$this->assertStringNotContainsString( 'Hidden body.', $full );
	}

	public function test_regen_sync_writes_full_cache_and_toggle_off_clears_it(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Cache Me',
				'post_status' => 'publish',
			)
		);

		Service::regen_sync();

		$payload = get_option( Service::FULL_CACHE_OPTION, null );
		$this->assertIsArray( $payload );
		$this->assertSame( Service::FULL_CACHE_SCHEMA_VERSION, (int) $payload['schema_version'] );
		$this->assertStringContainsString( 'Cache Me', (string) $payload['body'] );

		$this->set_profile( array( 'llms_full_txt_enabled' => false ) );
		Service::regen_sync();

		$this->assertFalse( get_option( Service::FULL_CACHE_OPTION, false ) );
	}

	public function test_markdown_views_disabled_still_inlines_walker_markdown(): void {
		$this->set_profile( array( 'markdown_views_enabled' => false ) );

		self::factory()->post->create(
			array(
				'post_title'   => 'No MV Post',
				'post_content' => '<h2>Heading Here</h2><p>Paragraph survives without Markdown Views.</p>',
				'post_status'  => 'publish',
			)
		);

		Service::regen_sync();
		$full = Service::get_composed_full_body();

		$this->assertStringContainsString( 'No MV Post', $full );
		$this->assertStringContainsString( 'Paragraph survives without Markdown Views.', $full );
	}
}
