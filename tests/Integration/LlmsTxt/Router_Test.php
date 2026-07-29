<?php
/**
 * Integration tests for Mokhai\LlmsTxt\Router.
 *
 * Verifies that `build_response()` produces a coherent response shape
 * (status 200, text/plain Content-Type, noindex header, composed body)
 * and that `is_llms_txt_request()` correctly identifies the rewrite-var
 * signal.
 *
 * The `dispatch()` path (status_header + header + echo + exit) is NOT
 * exercised here — exit isn't testable without process isolation, and the
 * `build_response()` decoupling exists exactly so we can test the
 * decision without the side effects.
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

final class Router_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// See Service_Test::setUp() comment — the Markdown_Views save_post
		// invalidation hook errors loudly without this table.
		Markdown_Views_Schema::create();

		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post' ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);
		delete_option( 'mokhai_llms_txt_editorial' );

		// Profile-update hook may have queued a regen — clear so the
		// rewrite-rule test below doesn't run a coalesced regen mid-assert.
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
	}

	protected function tearDown(): void {
		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		set_query_var( Router::REWRITE_VAR, '' );
		Markdown_Views_Schema::drop();
		parent::tearDown();
	}

	public function test_is_llms_txt_request_true_when_query_var_set(): void {
		set_query_var( Router::REWRITE_VAR, '1' );

		$this->assertTrue( Router::is_llms_txt_request() );
	}

	public function test_is_llms_txt_request_false_when_query_var_absent(): void {
		set_query_var( Router::REWRITE_VAR, '' );

		$this->assertFalse( Router::is_llms_txt_request() );
	}

	public function test_build_response_returns_text_plain_and_noindex_headers(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Hello',
				'post_status' => 'publish',
			)
		);

		$response = Router::build_response();

		$this->assertSame( 200, $response['status'] );
		$this->assertArrayHasKey( 'Content-Type', $response['headers'] );
		$this->assertStringStartsWith( 'text/plain', $response['headers']['Content-Type'] );
		$this->assertSame( 'noindex', $response['headers']['X-Robots-Tag'] );
		$this->assertSame( 'no-store, must-revalidate', $response['headers']['Cache-Control'] );
	}

	public function test_build_response_body_contains_post_title(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Findable',
				'post_status' => 'publish',
			)
		);

		$response = Router::build_response();

		$this->assertStringContainsString( 'Findable', $response['body'] );
	}

	public function test_build_response_returns_200_header_only_when_nothing_exposed(): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array(),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);

		$response = Router::build_response();

		// #244: nothing exposed → 200 with the site identity header (not blank).
		$this->assertSame( 200, $response['status'] );
		$this->assertNotSame( '', $response['body'] );
		$this->assertStringStartsWith( '# ', $response['body'] );
		$this->assertStringNotContainsString( "\n## ", $response['body'], 'No content exposed → no sections.' );
		$this->assertStringNotContainsString( "\n- [", $response['body'], 'No content exposed → no entries.' );
	}

	public function test_add_rewrite_rule_registers_in_top_extras(): void {
		// Force a clean rewrite registration — clear extras, re-add our rule,
		// then assert it appears in $wp_rewrite->extra_rules_top. We don't
		// call `flush_rewrite_rules()` here (no need to round-trip through
		// the options table in a test) — `add_rewrite_rule` with `'top'`
		// precedence populates `extra_rules_top` synchronously, which is the
		// surface our rule lives on.
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();

		Router::add_rewrite_rule();

		$this->assertArrayHasKey( '^llms\.txt/?$', $wp_rewrite->extra_rules_top );
		$this->assertStringContainsString(
			Router::REWRITE_VAR,
			$wp_rewrite->extra_rules_top['^llms\.txt/?$']
		);
	}

	public function test_register_query_var_appends_rewrite_var(): void {
		$vars = Router::register_query_var( array( 'foo' ) );

		$this->assertContains( Router::REWRITE_VAR, $vars );
		$this->assertContains( 'foo', $vars );
	}

	/**
	 * Regression guard for #338. The exact-match assertions elsewhere would
	 * also catch a re-added `nofollow`, but they read as "the header equals
	 * this string" rather than "this directive must never come back" — and the
	 * reason it must not is easy to lose. `/llms.txt` is a curated inventory of
	 * links; `nofollow` tells a crawler to ignore precisely the links the file
	 * exists to publish, so it turns the index into a dead end. `noindex` is
	 * the intended suppression and must stay.
	 */
	public function test_robots_header_suppresses_indexing_without_suppressing_links(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Linked',
				'post_status' => 'publish',
			)
		);

		$robots = Router::build_response()['headers']['X-Robots-Tag'];

		$this->assertStringContainsString(
			'noindex',
			$robots,
			'#338: the index should stay out of search results'
		);
		$this->assertStringNotContainsString(
			'nofollow',
			$robots,
			'#338: nofollow would tell crawlers to ignore the very links /llms.txt exists to advertise'
		);
	}
}
