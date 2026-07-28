<?php
/**
 * Integration tests for `Mokhai\LlmsTxt\Description_Orchestrator`
 * and `Description_Filter` per AgDR-0027.
 *
 * Runs inside wp-phpunit so we exercise real post-meta, real cron, real
 * filter dispatch through `Entry_Source::DESCRIPTION_FILTER`. The
 * `run()` cron handler itself is not exercised here — it would require
 * a real `wp_ai_client_prompt()` round-trip; the orchestrator's
 * deterministic surface (eligibility, scheduling, regen, invalidate,
 * read-side filter) is what this suite pins.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\LlmsTxt\Description_Orchestrator;
use Mokhai\LlmsTxt\Entry_Source;
use Mokhai\LlmsTxt\Service;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

final class Description_Orchestrator_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Same Markdown_Views schema reseed pattern as Service_Test.
		// The wp-env bootstrap drops the cache table and Markdown_Views\Service
		// hooks save_post → invalidate() which writes to it; without
		// recreating the table every factory()->post->create() prints a
		// wpdberror that trips strict-output tests.
		Markdown_Views_Schema::create();

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'             => array( 'post' ),
					'exposed_statuses'         => array( 'publish' ),
					'llm_descriptions_enabled' => true,
				)
			)
		);

		wp_clear_scheduled_hook( Description_Orchestrator::SCHEDULE_ACTION );
	}

	public function test_should_schedule_returns_true_for_eligible_post_without_cache(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
			)
		);

		self::assertTrue( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_false_when_manual_description_present(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_MANUAL,
			'Admin-set sticky description.'
		);

		self::assertFalse( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_false_when_toggle_disabled(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'             => array( 'post' ),
					'llm_descriptions_enabled' => false,
				)
			)
		);

		self::assertFalse( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_false_when_cpt_not_exposed(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'page', 'post_status' => 'publish' )
		);

		// `exposed_cpts` was seeded as ['post'] in setUp — `page` is not in the set.
		self::assertFalse( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_true_when_auto_is_stale(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_AUTO,
			'Older cached description.'
		);
		// generated_for_modified_gmt strictly earlier than post_modified_gmt.
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
			'2000-01-01 00:00:00'
		);

		self::assertTrue( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_false_when_auto_is_fresh(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_AUTO,
			'Fresh cached description.'
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
			$post->post_modified_gmt
		);
		// A genuinely fresh description also carries the current generator
		// version (#149) — without it the post is treated as generator-stale.
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_GENERATED_BY_VERSION,
			Description_Orchestrator::DESCRIPTION_GENERATOR_VERSION
		);

		self::assertFalse( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_true_when_generator_version_stale(): void {
		// #149: cached `_auto`, NOT modified-stale (generated_for == current),
		// but no stored generator version (a pre-#149 description) → stale.
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_AUTO,
			'Pre-#149 cached description.'
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
			$post->post_modified_gmt
		);
		// No META_KEY_GENERATED_BY_VERSION — represents a description generated
		// before the version signal existed.

		self::assertTrue( Description_Orchestrator::should_schedule( $post ) );
		self::assertTrue( Description_Orchestrator::is_stale( $post ) );
	}

	public function test_is_stale_false_when_version_current_and_not_modified(): void {
		// #149 regression guard: a description carrying the current generator
		// version on an unedited post is NOT stale.
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_AUTO,
			'Current-generator description.'
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
			$post->post_modified_gmt
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_GENERATED_BY_VERSION,
			Description_Orchestrator::DESCRIPTION_GENERATOR_VERSION
		);

		self::assertFalse( Description_Orchestrator::is_stale( $post ) );
	}

	public function test_schedule_queues_cron_event_and_records_pending_status(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		Description_Orchestrator::schedule( $post );

		self::assertNotFalse(
			wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, array( (int) $post->ID ) )
		);
		self::assertSame(
			Description_Orchestrator::STATUS_PENDING,
			Description_Orchestrator::get_status( (int) $post->ID )
		);
	}

	public function test_schedule_is_idempotent_when_already_queued(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		Description_Orchestrator::schedule( $post );
		Description_Orchestrator::schedule( $post );

		$cron  = _get_cron_array();
		$count = 0;
		foreach ( (array) $cron as $hooks ) {
			if ( isset( $hooks[ Description_Orchestrator::SCHEDULE_ACTION ] ) ) {
				$count += count( $hooks[ Description_Orchestrator::SCHEDULE_ACTION ] );
			}
		}
		self::assertSame( 1, $count, 'second schedule() must not double-queue' );
	}

	/**
	 * Ref34t/agentready#121 — per-post stale-event recovery.
	 *
	 * A per-post description event left in the cron queue with a past
	 * timestamp must be cleared before a fresh future event can be
	 * scheduled. Without the clear, WP de-dups the new event against
	 * the stale entry and the description never fires, but the meta
	 * marker keeps reporting `pending`.
	 */
	public function test_schedule_clears_stale_past_event_and_reschedules(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		$post_id = (int) $post->ID;
		$args    = array( $post_id );

		// `factory()->post->create_and_get` fires save_post, which the
		// production register_hooks chain wires up to schedule(). Clear
		// whatever auto-scheduled event landed so we can stage a clean
		// stale-only state.
		wp_clear_scheduled_hook( Description_Orchestrator::SCHEDULE_ACTION, $args );

		// Stage a stale past-timestamp event directly, bypassing the
		// public API so we don't accidentally test the very logic
		// we're regressing.
		$past = time() - 60;
		wp_schedule_single_event( $past, Description_Orchestrator::SCHEDULE_ACTION, $args );
		self::assertSame( $past, wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, $args ) );

		Description_Orchestrator::schedule( $post );

		$next = wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, $args );
		self::assertIsInt( $next );
		self::assertGreaterThan(
			time(),
			$next,
			'schedule() must produce a future event for this post even when a stale past event was in the queue.'
		);
		self::assertLessThanOrEqual(
			time() + 5,
			$next,
			'New event must be scheduled within a few seconds.'
		);
		self::assertSame(
			Description_Orchestrator::STATUS_PENDING,
			Description_Orchestrator::get_status( $post_id )
		);
	}

	public function test_regenerate_clears_auto_cache_and_queues_fresh_job(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'old' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED, '2000-01-01 00:00:00' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_STATUS, Description_Orchestrator::STATUS_DONE );

		$result = Description_Orchestrator::regenerate( $post );

		self::assertTrue( $result );
		self::assertSame( '', (string) get_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, true ) );
		self::assertSame(
			Description_Orchestrator::STATUS_PENDING,
			Description_Orchestrator::get_status( (int) $post->ID )
		);
	}

	/**
	 * A sticky post is refused outright — no meta is touched and no job is
	 * queued (#330).
	 *
	 * Before the guard, `regenerate()` deleted the whole `_auto` family and
	 * queued a run that `should_schedule()` then refused, so the cached
	 * description was destroyed with nothing to replace it. This test asserts
	 * `_auto` survives, which is what the old
	 * `test_regenerate_preserves_manual_override` did not check — it set
	 * `_auto` and only asserted `_manual`, so the data loss passed review.
	 */
	public function test_regenerate_refuses_and_touches_nothing_when_manual_is_sticky(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_MANUAL,
			'Sticky admin description.'
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'old auto' );
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
			'2000-01-01 00:00:00'
		);

		// Creating the post queued a job before `_manual` existed, so the
		// already-pending short-circuit would refuse first and this test would
		// pass without ever reaching the stickiness guard. Clear that state so
		// the guard is what's under test.
		wp_clear_scheduled_hook(
			Description_Orchestrator::SCHEDULE_ACTION,
			array( (int) $post->ID )
		);
		delete_post_meta( $post->ID, Description_Orchestrator::META_KEY_STATUS );
		self::assertSame(
			'',
			Description_Orchestrator::get_status( (int) $post->ID ),
			'Precondition: the pending short-circuit must not be the reason for the refusal.'
		);

		$result = Description_Orchestrator::regenerate( $post );

		self::assertFalse( $result, 'A sticky post must report the refusal.' );
		self::assertSame(
			'Sticky admin description.',
			(string) get_post_meta( $post->ID, Description_Orchestrator::META_KEY_MANUAL, true )
		);
		self::assertSame(
			'old auto',
			(string) get_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, true ),
			'The cached _auto must survive — regeneration would not have replaced it.'
		);
		self::assertSame(
			'2000-01-01 00:00:00',
			(string) get_post_meta(
				$post->ID,
				Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
				true
			),
			'The staleness bookmark must survive alongside the _auto it describes.'
		);
		self::assertSame(
			'',
			Description_Orchestrator::get_status( (int) $post->ID ),
			'No job may be queued for a post the cron would refuse.'
		);
		self::assertFalse(
			wp_next_scheduled(
				Description_Orchestrator::SCHEDULE_ACTION,
				array( (int) $post->ID )
			)
		);
	}

	/**
	 * The sticky refusal is distinguishable from the already-pending one, so
	 * callers can report the right reason rather than defaulting to "pending".
	 */
	public function test_has_manual_reports_stickiness_and_ignores_whitespace(): void {
		$post_id = (int) self::factory()->post->create();

		self::assertFalse( Description_Orchestrator::has_manual( $post_id ) );

		update_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, "   \n\t " );
		self::assertFalse(
			Description_Orchestrator::has_manual( $post_id ),
			'Whitespace-only is a clear, matching set_manual() treating blank input as such.'
		);

		update_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, 'Real override.' );
		self::assertTrue( Description_Orchestrator::has_manual( $post_id ) );
	}

	public function test_regenerate_is_idempotent_when_pending(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		Description_Orchestrator::schedule( $post );

		$result = Description_Orchestrator::regenerate( $post );

		self::assertFalse( $result );
	}

	public function test_get_cached_description_prefers_manual(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_MANUAL, 'manual wins' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'auto loses' );

		self::assertSame(
			'manual wins',
			Description_Orchestrator::get_cached_description( (int) $post->ID )
		);
	}

	public function test_get_cached_description_falls_back_to_auto(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'auto value' );

		self::assertSame(
			'auto value',
			Description_Orchestrator::get_cached_description( (int) $post->ID )
		);
	}

	public function test_get_cached_description_returns_empty_when_nothing_set(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		self::assertSame( '', Description_Orchestrator::get_cached_description( (int) $post->ID ) );
	}

	public function test_filter_returns_manual_description(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_MANUAL, 'manual desc' );

		// Description_Filter::register_hooks is called by Main::register_hooks
		// at plugin boot, so the filter is already wired in the test process.
		$out = apply_filters( Entry_Source::DESCRIPTION_FILTER, '', $post );

		self::assertSame( 'manual desc', $out );
	}

	public function test_filter_returns_auto_when_no_manual(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'auto desc' );

		$out = apply_filters( Entry_Source::DESCRIPTION_FILTER, '', $post );

		self::assertSame( 'auto desc', $out );
	}

	public function test_filter_falls_through_when_cache_empty(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		$out = apply_filters( Entry_Source::DESCRIPTION_FILTER, '', $post );

		self::assertSame( '', $out );
	}

	public function test_filter_does_not_override_a_prior_non_empty_value(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'auto desc' );

		// Another subscriber may have already set a description; we
		// honour it rather than clobber.
		$out = apply_filters( Entry_Source::DESCRIPTION_FILTER, 'set by upstream', $post );

		self::assertSame( 'set by upstream', $out );
	}

	public function test_save_post_listener_schedules_for_eligible_post(): void {
		// The save_post hook is wired by Description_Orchestrator::register_hooks
		// at plugin boot, so factory()->post->create() should trigger it.
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Some content for description generation.',
			)
		);

		// Eligibility check evaluated `Client_Wrapper::has_ai_client()` —
		// in wp-env the WP AI Client backport is bundled so it returns true.
		// If the test environment ever changes this, the assertion below
		// becomes "no cron event" and that's also a valid pass.
		if ( ! \Mokhai\Ai\Client_Wrapper::has_ai_client() ) {
			self::markTestSkipped( 'WP AI Client unavailable in this test instance; save_post listener short-circuits before scheduling.' );
		}

		self::assertNotFalse(
			wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, array( $post_id ) )
		);
	}

	public function test_invalidate_clears_all_orchestrator_meta(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'auto' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_MANUAL, 'manual' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_STATUS, Description_Orchestrator::STATUS_DONE );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED, '2026-01-01 00:00:00' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_DIAGNOSTICS, '{}' );

		Description_Orchestrator::invalidate( (int) $post->ID );

		foreach (
			array(
				Description_Orchestrator::META_KEY_AUTO,
				Description_Orchestrator::META_KEY_MANUAL,
				Description_Orchestrator::META_KEY_STATUS,
				Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
				Description_Orchestrator::META_KEY_DIAGNOSTICS,
			) as $key
		) {
			self::assertSame( '', (string) get_post_meta( $post->ID, $key, true ), $key . ' should be cleared' );
		}
	}

	public function test_build_user_prompt_strips_orphaned_shortcodes(): void {
		// #147: the description excerpt is built from raw post_content with
		// wp_strip_all_tags(), which does not remove shortcodes. An orphaned
		// builder shortcode must not reach the LLM prompt (or it gets parroted
		// into the published /llms.txt description).
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Body paragraph with [vc_btn title="X"] residue.</p>',
			)
		);

		$prompt = Description_Orchestrator::build_user_prompt( $post );

		self::assertStringNotContainsString( '[vc_btn', $prompt );
		self::assertStringContainsString( 'residue.', $prompt );
	}

	/**
	 * Create a published post and clear any /llms.txt recompose that its
	 * save_post fired, so a subsequent assertion proves the description path
	 * scheduled it — not the post creation (#151).
	 */
	private function post_with_cleared_regen(): \WP_Post {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		self::assertFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'precondition: no recompose scheduled before the description change'
		);
		return $post;
	}

	public function test_set_manual_schedules_llms_txt_recompose(): void {
		$post = $this->post_with_cleared_regen();

		Description_Orchestrator::set_manual( (int) $post->ID, 'A manual description.' );

		self::assertNotFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'set_manual must schedule a /llms.txt recompose (#151)'
		);
	}

	public function test_clear_manual_schedules_recompose_when_manual_existed(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_MANUAL, 'Sticky.' );
		wp_clear_scheduled_hook( Service::REGEN_ACTION );

		Description_Orchestrator::clear_manual( (int) $post->ID );

		self::assertNotFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'clearing an existing manual override must schedule a recompose (#151)'
		);
	}

	public function test_invalidate_schedules_recompose_when_description_existed(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'Cached desc.' );
		wp_clear_scheduled_hook( Service::REGEN_ACTION );

		Description_Orchestrator::invalidate( (int) $post->ID );

		self::assertNotFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'invalidating a post that had a description must schedule a recompose (#151)'
		);
	}

	public function test_invalidate_does_not_schedule_recompose_when_empty(): void {
		$post = $this->post_with_cleared_regen();

		Description_Orchestrator::invalidate( (int) $post->ID );

		self::assertFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'invalidating a post with no description should be a recompose no-op (#151)'
		);
	}

	public function test_run_skips_thin_content_without_calling_the_llm(): void {
		// #214: a near-empty post must not get a filler description. run()
		// returns at the quality floor before the LLM call, so no provider is
		// needed for this assertion.
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Hi.',
			)
		);

		Description_Orchestrator::run( (int) $post->ID );

		self::assertSame(
			Description_Orchestrator::STATUS_SKIPPED,
			Description_Orchestrator::get_status( (int) $post->ID )
		);
		self::assertSame(
			'',
			(string) get_post_meta( (int) $post->ID, Description_Orchestrator::META_KEY_AUTO, true ),
			'thin content must not write an _auto description'
		);
	}

	public function test_run_clears_prior_filler_auto_when_content_falls_below_floor(): void {
		// #214: a post that previously received a filler _auto and is now thin
		// must have the filler cleared so /llms.txt drops to the title-only floor.
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Hi.',
			)
		);
		// Stale filler: _auto set, no generated_for marker → should_schedule true.
		update_post_meta( (int) $post->ID, Description_Orchestrator::META_KEY_AUTO, 'Hi is available at http://example.test/hi/.' );

		Description_Orchestrator::run( (int) $post->ID );

		self::assertSame(
			Description_Orchestrator::STATUS_SKIPPED,
			Description_Orchestrator::get_status( (int) $post->ID )
		);
		self::assertSame(
			'',
			(string) get_post_meta( (int) $post->ID, Description_Orchestrator::META_KEY_AUTO, true ),
			'prior filler _auto must be cleared on skip'
		);
	}

	public function test_min_content_chars_respects_the_filter(): void {
		$filter = static function () {
			return 5;
		};
		add_filter( 'mokhai_description_min_content_chars', $filter );

		self::assertSame( 5, Description_Orchestrator::min_content_chars() );

		remove_filter( 'mokhai_description_min_content_chars', $filter );
		self::assertSame(
			Description_Orchestrator::MIN_CONTENT_CHARS,
			Description_Orchestrator::min_content_chars()
		);
	}
}
