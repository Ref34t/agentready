<?php
/**
 * Integration tests for the Phase B descriptions REST controller per
 * AgDR-0029. Runs inside wp-phpunit so we exercise the real REST
 * infrastructure, real capability checks, real post-meta layer.
 *
 * Covers the five routes:
 *   GET    /ai-readiness-kit/v1/llms-txt/descriptions
 *   PATCH  /ai-readiness-kit/v1/llms-txt/descriptions/<post>
 *   DELETE /ai-readiness-kit/v1/llms-txt/descriptions/<post>/manual
 *   POST   /ai-readiness-kit/v1/llms-txt/descriptions/<post>/regenerate
 *   POST   /ai-readiness-kit/v1/llms-txt/descriptions/bulk-regenerate-stale
 *
 * The orchestrator's `run()` cron handler is NOT exercised here — that's
 * an LLM round-trip; the controller's read + mutation surfaces are what
 * this suite pins.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\LlmsTxt;

use WP_REST_Request;
use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\LlmsTxt\Description_Orchestrator;
use Mokhai\LlmsTxt\Descriptions_Rest_Controller;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

final class Descriptions_Rest_Controller_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Markdown_Views_Schema::create();

		\update_option(
			Context_Profile_Settings::OPTION_KEY,
			\array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'             => array( 'post' ),
					'exposed_statuses'         => array( 'publish' ),
					'llm_descriptions_enabled' => true,
				)
			)
		);

		\wp_clear_scheduled_hook( Description_Orchestrator::SCHEDULE_ACTION );
	}

	protected function tearDown(): void {
		\wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function make_admin(): int {
		return self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function make_subscriber(): int {
		return self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function seed_post( array $args = array() ): int {
		return (int) self::factory()->post->create(
			\array_merge(
				array(
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_content' => 'Body for description generation.',
				),
				$args
			)
		);
	}

	private function path( string $suffix = '' ): string {
		return '/' . Descriptions_Rest_Controller::NAMESPACE . Descriptions_Rest_Controller::ROUTE_BASE . $suffix;
	}

	public function test_get_list_returns_paginated_envelope_for_admin(): void {
		\wp_set_current_user( $this->make_admin() );
		$this->seed_post();
		$this->seed_post();

		$req      = new WP_REST_Request( 'GET', $this->path() );
		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertArrayHasKey( 'items', $data );
		self::assertArrayHasKey( 'total', $data );
		self::assertArrayHasKey( 'page', $data );
		self::assertArrayHasKey( 'per_page', $data );
		self::assertArrayHasKey( 'pages', $data );
		self::assertGreaterThanOrEqual( 2, $data['total'] );
	}

	public function test_get_list_rejects_subscriber(): void {
		\wp_set_current_user( $this->make_subscriber() );
		$this->seed_post();

		$response = \rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		self::assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_get_list_returns_empty_envelope_with_no_exposed_cpts(): void {
		\wp_set_current_user( $this->make_admin() );
		\update_option(
			Context_Profile_Settings::OPTION_KEY,
			\array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'             => array(),
					'llm_descriptions_enabled' => true,
				)
			)
		);

		$response = \rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 0, $response->get_data()['total'] );
		self::assertSame( array(), $response->get_data()['items'] );
	}

	public function test_get_list_status_filter_missing(): void {
		\wp_set_current_user( $this->make_admin() );
		$missing = $this->seed_post();
		$cached  = $this->seed_post();
		\update_post_meta( $cached, Description_Orchestrator::META_KEY_AUTO, 'cached auto.' );

		$req = new WP_REST_Request( 'GET', $this->path() );
		$req->set_query_params( array( 'status' => 'missing' ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		$ids = \array_column( $response->get_data()['items'], 'post_id' );
		self::assertContains( $missing, $ids );
		self::assertNotContains( $cached, $ids );
	}

	public function test_get_list_row_includes_resolved_and_source_fields(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_post();
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_AUTO, 'cached auto description.' );

		$response = \rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );
		$rows     = $response->get_data()['items'];

		$row = null;
		foreach ( $rows as $candidate ) {
			if ( $candidate['post_id'] === $post_id ) {
				$row = $candidate;
				break;
			}
		}
		self::assertNotNull( $row );
		self::assertSame( 'cached auto description.', $row['auto'] );
		self::assertSame( 'cached auto description.', $row['resolved'] );
		self::assertSame( 'auto', $row['source'] );
	}

	public function test_get_list_row_flags_non_exposable_posts_as_excluded(): void {
		// Regression (#215): the table lists posts in an exposed CPT/status even
		// when a gate (password / noindex / manual exclusion) keeps them out of
		// /llms.txt. The row must carry an `excluded` flag so the UI can mark them.
		\wp_set_current_user( $this->make_admin() );
		$exposable = $this->seed_post();
		$protected = $this->seed_post( array( 'post_password' => 'secret' ) );

		$response = \rest_do_request( new WP_REST_Request( 'GET', $this->path() ) );
		$rows     = $response->get_data()['items'];

		$by_id = array();
		foreach ( $rows as $candidate ) {
			$by_id[ $candidate['post_id'] ] = $candidate;
		}

		self::assertArrayHasKey( $exposable, $by_id );
		self::assertArrayHasKey( $protected, $by_id );
		self::assertFalse( $by_id[ $exposable ]['excluded'] );
		self::assertTrue( $by_id[ $protected ]['excluded'] );
	}

	public function test_patch_writes_manual_and_returns_updated_row(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_post();

		$req = new WP_REST_Request( 'PATCH', $this->path( '/' . $post_id ) );
		$req->set_body_params( array( 'manual' => 'Curated description set by editor.' ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 'Curated description set by editor.', $data['manual'] );
		self::assertSame( 'manual', $data['source'] );
		self::assertSame(
			'Curated description set by editor.',
			\get_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, true )
		);
	}

	public function test_patch_truncates_overlong_manual_via_normalise_pipeline(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_post();
		$long    = \str_repeat( 'x', 200 );

		$req = new WP_REST_Request( 'PATCH', $this->path( '/' . $post_id ) );
		$req->set_body_params( array( 'manual' => $long ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		$stored = \get_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, true );
		self::assertSame( Description_Orchestrator::MAX_OUTPUT_CHARS, \strlen( $stored ) );
	}

	public function test_patch_with_empty_manual_clears_the_slot(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_post();
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, 'existing manual.' );

		$req = new WP_REST_Request( 'PATCH', $this->path( '/' . $post_id ) );
		$req->set_body_params( array( 'manual' => '' ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( '', (string) \get_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, true ) );
	}

	public function test_patch_rejects_subscriber(): void {
		\wp_set_current_user( $this->make_subscriber() );
		$post_id = $this->seed_post();

		$req = new WP_REST_Request( 'PATCH', $this->path( '/' . $post_id ) );
		$req->set_body_params( array( 'manual' => 'try' ) );

		$response = \rest_do_request( $req );

		self::assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_delete_manual_clears_slot(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_post();
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, 'manual to clear.' );

		$response = \rest_do_request(
			new WP_REST_Request( 'DELETE', $this->path( '/' . $post_id . '/manual' ) )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( '', (string) \get_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, true ) );
	}

	public function test_regenerate_route_schedules_when_ai_client_available(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_post();
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_AUTO, 'old' );

		if ( ! \Mokhai\Ai\Client_Wrapper::has_ai_client() ) {
			self::markTestSkipped( 'WP AI Client unavailable; regenerate returns 409 in this environment.' );
		}

		$response = \rest_do_request(
			new WP_REST_Request( 'POST', $this->path( '/' . $post_id . '/regenerate' ) )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertNotFalse(
			\wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, array( $post_id ) )
		);
	}

	public function test_regenerate_returns_409_when_ai_unavailable(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_post();

		if ( \Mokhai\Ai\Client_Wrapper::has_ai_client() ) {
			self::markTestSkipped( 'WP AI Client is available here; the 409 path is not reachable.' );
		}

		$response = \rest_do_request(
			new WP_REST_Request( 'POST', $this->path( '/' . $post_id . '/regenerate' ) )
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'rest_ai_client_unavailable', $response->get_data()['code'] ?? '' );
	}

	/**
	 * The route refuses a sticky post rather than clearing its cached `_auto`
	 * and queueing a run the cron would decline (#330).
	 *
	 * Environment-independent: the sticky check runs before the AI-client
	 * check, so this holds whether or not a client is configured — which is
	 * also why it asserts the error code, not just the shared 409 status.
	 */
	public function test_regenerate_returns_409_and_preserves_auto_when_manual_is_sticky(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_post();
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_AUTO, 'cached auto' );
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, 'Sticky override.' );

		// Seeding queued a job before `_manual` existed. Clear it so the
		// stickiness refusal is what's observed, not leftover seed state.
		\wp_clear_scheduled_hook( Description_Orchestrator::SCHEDULE_ACTION, array( $post_id ) );
		\delete_post_meta( $post_id, Description_Orchestrator::META_KEY_STATUS );

		$response = \rest_do_request(
			new WP_REST_Request( 'POST', $this->path( '/' . $post_id . '/regenerate' ) )
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'rest_manual_description_sticky', $response->get_data()['code'] ?? '' );
		self::assertSame(
			'cached auto',
			(string) \get_post_meta( $post_id, Description_Orchestrator::META_KEY_AUTO, true )
		);
		self::assertFalse(
			\wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, array( $post_id ) )
		);
	}

	public function test_bulk_regenerate_stale_schedules_missing_posts(): void {
		\wp_set_current_user( $this->make_admin() );

		if ( ! \Mokhai\Ai\Client_Wrapper::has_ai_client() ) {
			self::markTestSkipped( 'WP AI Client unavailable; bulk regen returns 409 here.' );
		}

		$missing_1 = $this->seed_post();
		$missing_2 = $this->seed_post();

		$response = \rest_do_request(
			new WP_REST_Request( 'POST', $this->path( '/bulk-regenerate-stale' ) )
		);

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertGreaterThanOrEqual( 2, $data['scheduled'] );

		self::assertNotFalse(
			\wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, array( $missing_1 ) )
		);
		self::assertNotFalse(
			\wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, array( $missing_2 ) )
		);
	}

	public function test_bulk_regenerate_stale_returns_409_when_descriptions_disabled(): void {
		\wp_set_current_user( $this->make_admin() );
		\update_option(
			Context_Profile_Settings::OPTION_KEY,
			\array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'             => array( 'post' ),
					'llm_descriptions_enabled' => false,
				)
			)
		);

		$this->seed_post();

		$response = \rest_do_request(
			new WP_REST_Request( 'POST', $this->path( '/bulk-regenerate-stale' ) )
		);

		self::assertSame( 409, $response->get_status() );
	}

	public function test_bulk_regenerate_stale_respects_limit(): void {
		\wp_set_current_user( $this->make_admin() );
		if ( ! \Mokhai\Ai\Client_Wrapper::has_ai_client() ) {
			self::markTestSkipped( 'WP AI Client unavailable.' );
		}

		// Three missing posts; limit=1 should schedule exactly one.
		$this->seed_post();
		$this->seed_post();
		$this->seed_post();

		$req = new WP_REST_Request( 'POST', $this->path( '/bulk-regenerate-stale' ) );
		$req->set_body_params( array( 'limit' => 1 ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 1, $response->get_data()['scheduled'] );
	}

	public function test_orchestrator_is_stale_true_when_post_modified_after_generation(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		\update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'old desc' );
		\update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
			'2000-01-01 00:00:00'
		);

		self::assertTrue( Description_Orchestrator::is_stale( $post ) );
	}

	public function test_orchestrator_is_stale_false_when_no_auto(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		self::assertFalse( Description_Orchestrator::is_stale( $post ) );
	}

	public function test_orchestrator_set_manual_normalises_input(): void {
		$post_id = $this->seed_post();
		Description_Orchestrator::set_manual( $post_id, '  Manual description with leading whitespace.  ' );

		self::assertSame(
			'Manual description with leading whitespace.',
			\get_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, true )
		);
	}

	public function test_orchestrator_set_manual_empty_input_clears_slot(): void {
		$post_id = $this->seed_post();
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, 'existing' );

		Description_Orchestrator::set_manual( $post_id, '   ' );

		self::assertSame(
			'',
			(string) \get_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, true )
		);
	}

	public function test_orchestrator_clear_manual_deletes_slot(): void {
		$post_id = $this->seed_post();
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, 'before clear' );

		Description_Orchestrator::clear_manual( $post_id );

		self::assertSame(
			'',
			(string) \get_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, true )
		);
	}
}
