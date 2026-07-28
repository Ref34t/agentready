<?php
/**
 * LLM-powered entry-description orchestrator per AgDR-0027 / AgDR-0028.
 *
 * Async per-post pipeline: `save_post` (or WP-CLI backfill) → cron event
 * → `Client_Wrapper::generate()` with a cheap-tier-friendly prompt →
 * post-meta cache. The read-side filter `Description_Filter` reads the
 * cached value at /llms.txt compose time; nothing here blocks regen.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\LlmsTxt;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Ai\Client_Wrapper;
use Mokhai\Support\Shortcode_Stripper;

\defined( 'ABSPATH' ) || exit;

/**
 * Static async orchestrator for the LLM descriptions pipeline.
 * Owns the cron-based per-post schedule/run/invalidate lifecycle and
 * the two-slot storage model:
 *
 *   1. No approval state machine. Descriptions are short factual
 *      sentences with a 160-char cap; approval ceremony isn't worth
 *      the UI complexity. See AgDR-0027 § "Status state machine".
 *   2. Two disjoint meta keys for storage: `_auto` (LLM) and
 *      `_manual` (admin override, written by Phase B). Stickiness
 *      is structural — neither writer touches the other's slot.
 */
final class Description_Orchestrator {

	/**
	 * Status state machine. See AgDR-0027 for the full lifecycle.
	 *
	 * @var string
	 */
	public const STATUS_PENDING     = 'pending';
	public const STATUS_DONE        = 'done';
	public const STATUS_NEEDS_RETRY = 'needs-retry';
	public const STATUS_FAILED      = 'failed';

	/**
	 * Generation skipped because the post body is below the quality floor
	 * (#214). The LLM is never called, no `_auto` is written, and /llms.txt
	 * falls back to the deterministic title-only floor. Distinct from FAILED
	 * (a provider/parse error) — a skip is a deliberate "not worth a
	 * description" verdict, not an error.
	 *
	 * @var string
	 */
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * Default minimum stripped-content length (characters) a post must have
	 * before the LLM description job runs. Below this, generation is skipped
	 * so the index is not padded with filler ("X is available at URL.") and
	 * LLM spend is not wasted on near-empty posts. Filterable — see
	 * {@see min_content_chars()}.
	 *
	 * @var int
	 */
	public const MIN_CONTENT_CHARS = 50;

	/**
	 * Cron action fired by `schedule()` and handled by `run()`.
	 *
	 * @var string
	 */
	public const SCHEDULE_ACTION = 'mokhai_llms_description_run';

	/**
	 * Action fired whenever a post's *served* /llms.txt description changes
	 * (`_auto` regenerated, `_manual` set/cleared, or the whole slot
	 * invalidated). `LlmsTxt\Service` subscribes this to its debounced
	 * recompose so the cached /llms.txt document reflects current descriptions
	 * instead of lagging until the next save / profile / editorial change or
	 * the daily backstop (#151).
	 *
	 * @var string
	 */
	public const DESCRIPTION_CHANGED_ACTION = 'mokhai_llms_txt_description_changed';

	/**
	 * LLM-generated description. Regen-overwritable. Empty key when no
	 * successful LLM run has produced an output for this post.
	 *
	 * @var string
	 */
	public const META_KEY_AUTO = '_mokhai_llms_description_auto';

	/**
	 * Admin-set description. Sticky — never overwritten by the
	 * orchestrator. Phase B's editorial UI writes this slot; Phase A
	 * only reads it (to skip scheduling when it's set).
	 *
	 * @var string
	 */
	public const META_KEY_MANUAL = '_mokhai_llms_description_manual';

	/**
	 * The `post_modified_gmt` value at the time the `_auto` slot was
	 * generated. Phase B compares this against the current
	 * `post_modified_gmt` to mark stale descriptions; Phase A reads it
	 * inside `should_schedule()` to skip re-generation when the cache
	 * is still fresh.
	 *
	 * @var string
	 */
	public const META_KEY_GENERATED_FOR_MODIFIED = '_mokhai_llms_description_generated_for_modified_gmt';

	/**
	 * Status meta key — see state machine constants above.
	 *
	 * @var string
	 */
	public const META_KEY_STATUS = '_mokhai_llms_description_status';

	/**
	 * JSON diagnostic blob from the most recent attempt: attempted_at,
	 * error code (if any), output length.
	 *
	 * @var string
	 */
	public const META_KEY_DIAGNOSTICS = '_mokhai_llms_description_diagnostics';

	/**
	 * The generator version a cached `_auto` description was produced under.
	 * Stored alongside `_auto` on every write (#149).
	 *
	 * @var string
	 */
	public const META_KEY_GENERATED_BY_VERSION = '_mokhai_llms_description_generated_by_version';

	/**
	 * Generator version — bump on any output-affecting change to the
	 * description generation (prompt, excerpt construction, post-processing).
	 *
	 * Mirrors `Markdown_Views\Walker::WALKER_VERSION`: a stored version that
	 * differs from this constant marks the cached description stale, so a
	 * code-level generator change invalidates existing descriptions the way a
	 * `post_modified_gmt` bump does — without it, `is_stale()` /
	 * `should_schedule()` (and therefore `bulk-regenerate-stale`) would miss
	 * descriptions generated by an older generator and they would persist
	 * until each post is individually re-saved or regenerated (#149).
	 *
	 * Version history:
	 *   1 — baseline. Pre-#149 descriptions have NO stored version, which the
	 *       staleness check treats as "older" → flagged stale → regenerated
	 *       with the post-#147 shortcode-stripping generator.
	 *
	 * @var string
	 */
	public const DESCRIPTION_GENERATOR_VERSION = '1';

	/**
	 * Output character ceiling. Matches `Entry_Source::normalise_description`
	 * so the cached value can be served verbatim without re-truncation.
	 *
	 * @var int
	 */
	public const MAX_OUTPUT_CHARS = 160;

	/**
	 * Maximum input-excerpt length the prompt builder strips down to.
	 * 500 chars covers the topical-signal a one-sentence summary needs
	 * without bloating the prompt for backfill at scale.
	 *
	 * @var int
	 */
	private const MAX_EXCERPT_CHARS = 500;

	/**
	 * Sentinel the model emits when the input has no extractable topic.
	 * See AgDR-0028 § "EMPTY sentinel".
	 *
	 * @var string
	 */
	private const EMPTY_SENTINEL = 'EMPTY';

	/**
	 * System prompt — see AgDR-0028 for the full rationale.
	 *
	 * @var string
	 */
	private const DESCRIPTION_SYSTEM_PROMPT = <<<'PROMPT'
You write one-sentence descriptions for an /llms.txt index that AI agents
scan to decide which pages to read.

Rules:
- Output ONE factual sentence.
- Maximum 160 characters.
- Use only information present in the input.
- No marketing language. No first-person. No hedging ("might be", "seems
  to"). No emoji. No preamble (do not start with "This page", "Here is",
  "Description:", etc.).
- If the input is empty or has no extractable topic, output the word
  EMPTY.

Output the sentence itself with no quotation marks, no Markdown, no
labels.
PROMPT;

	/**
	 * User-prompt sprintf template — three placeholders in this order:
	 * title, URL, excerpt.
	 *
	 * @var string
	 */
	private const DESCRIPTION_USER_TEMPLATE = <<<'PROMPT'
Title: %1$s
URL: %2$s
Excerpt (may be truncated):
%3$s
PROMPT;

	/**
	 * Preamble prefixes the model occasionally emits despite the
	 * system-prompt instruction. Stripped (case-insensitive) at the start
	 * of the output in `normalise_output`. Order matters — longer
	 * matches first so "This page is" wins over "This".
	 *
	 * @var array<int, string>
	 */
	private const PREAMBLE_PREFIXES = array(
		'description:',
		'summary:',
		'this page is about',
		'this page describes',
		'this page',
		'here is a description of',
		'here is',
	);

	/**
	 * Wire the cron handler + save_post listener. Called from
	 * `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_action( self::SCHEDULE_ACTION, array( self::class, 'run' ), 10, 1 );
		\add_action( 'save_post', array( self::class, 'on_save_post' ), 30, 2 );
		\add_action( 'wp_trash_post', array( self::class, 'on_trash_post' ), 10, 1 );
		\add_action( 'before_delete_post', array( self::class, 'on_trash_post' ), 10, 1 );
	}

	/**
	 * `save_post` listener: schedule a description job when the post is
	 * eligible and the cache is missing or stale.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( \wp_is_post_autosave( $post_id ) || \wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! self::should_schedule( $post ) ) {
			return;
		}

		self::schedule( $post );
	}

	/**
	 * Trash / delete listener: clear stored meta so a re-published post
	 * later doesn't surface a description generated for the old content
	 * via stale meta.
	 *
	 * @param int $post_id Post ID being trashed / deleted.
	 */
	public static function on_trash_post( int $post_id ): void {
		self::invalidate( $post_id );
	}

	/**
	 * Eligibility check at schedule time. The same predicate runs again
	 * inside `run()` — a post that was eligible at schedule time may have
	 * been edited / had the toggle flipped before the cron tick fires.
	 *
	 * Returns true when:
	 *   - LLM descriptions toggle is on
	 *   - WP AI Client is available
	 *   - Post type + status are in the Context Profile exposure set
	 *   - Post is exposable per is_url_exposable (password / noindex gates)
	 *   - No `_manual` description (sticky admin override wins)
	 *   - Either `_auto` is empty OR `_generated_for_modified_gmt` is older
	 *     than the post's current `post_modified_gmt` (stale → regen)
	 */
	public static function should_schedule( \WP_Post $post ): bool {
		$profile = Context_Profile_Settings::get_profile();

		if ( empty( $profile['llm_descriptions_enabled'] ) ) {
			return false;
		}

		if ( ! Client_Wrapper::has_ai_client() ) {
			return false;
		}

		$cpts = isset( $profile['exposed_cpts'] ) && \is_array( $profile['exposed_cpts'] )
			? $profile['exposed_cpts']
			: array();
		if ( ! \in_array( $post->post_type, $cpts, true ) ) {
			return false;
		}

		$statuses = isset( $profile['exposed_statuses'] ) && \is_array( $profile['exposed_statuses'] )
			? $profile['exposed_statuses']
			: array( 'publish' );
		if ( ! \in_array( $post->post_status, $statuses, true ) ) {
			return false;
		}

		if ( ! Context_Profile_Settings::is_url_exposable( $post ) ) {
			return false;
		}

		$post_id = (int) $post->ID;

		if ( self::has_manual( $post_id ) ) {
			return false;
		}

		$auto = \get_post_meta( $post_id, self::META_KEY_AUTO, true );
		if ( ! \is_string( $auto ) || '' === \trim( $auto ) ) {
			return true;
		}

		$generated_for = \get_post_meta( $post_id, self::META_KEY_GENERATED_FOR_MODIFIED, true );
		$current_mod   = (string) $post->post_modified_gmt;

		if ( ! \is_string( $generated_for ) || '' === $generated_for ) {
			return true;
		}

		if ( $generated_for < $current_mod ) {
			return true;
		}

		// Generator-version staleness (#149): an `_auto` produced by an older
		// generator (or pre-#149, with no stored version) is stale even when
		// the post hasn't been edited, so bulk-regenerate-stale picks it up.
		return self::generator_version_stale( $post_id );
	}

	/**
	 * Queue an async description job. Idempotent — doesn't double-queue
	 * an identical event already pending.
	 *
	 * Stale-event recovery (Ref34t/mokhai-agent-readiness-kit#121, sibling of #103 / #115):
	 * if a previously-scheduled event for this post is still sitting in the
	 * cron queue with a past timestamp (the cron didn't fire it — common on
	 * wp-env without traffic, or any site where the LLM call rate-limited /
	 * timed out and cron failed during the description window), the old event
	 * is cleared before a fresh one is scheduled. Without this, WP de-dups
	 * `wp_schedule_single_event` against the stale entry and the new schedule
	 * is silently dropped — the post's description never fires, but
	 * `wp mokhai llms-txt descriptions status` keeps reporting
	 * `pending` because the meta marker is rewritten on every schedule call.
	 */
	public static function schedule( \WP_Post $post ): void {
		$post_id  = (int) $post->ID;
		$args     = array( $post_id );
		$existing = \wp_next_scheduled( self::SCHEDULE_ACTION, $args );

		// Future event already pending for this post — refresh the
		// status marker and exit; the existing cron event will fire
		// normally.
		if ( false !== $existing && $existing > \time() ) {
			\update_post_meta( $post_id, self::META_KEY_STATUS, self::STATUS_PENDING );
			return;
		}

		// Stale past-timestamp event still in the queue — clear it so
		// the fresh schedule below isn't silently de-duped by WP.
		// See #121.
		if ( false !== $existing ) {
			\wp_clear_scheduled_hook( self::SCHEDULE_ACTION, $args );
		}

		if ( self::pending_count() >= Context_Profile_Settings::get_descriptions_max_per_run() ) {
			return;
		}

		\wp_schedule_single_event( \time() + 1, self::SCHEDULE_ACTION, $args );
		\update_post_meta( $post_id, self::META_KEY_STATUS, self::STATUS_PENDING );
	}

	/**
	 * Count description-orchestrator events currently pending in cron.
	 */
	private static function pending_count(): int {
		if ( ! \function_exists( '_get_cron_array' ) ) {
			return 0;
		}

		$cron = \_get_cron_array();

		if ( ! \is_array( $cron ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $cron as $hooks ) {
			if ( ! \is_array( $hooks ) ) {
				continue;
			}
			if ( isset( $hooks[ self::SCHEDULE_ACTION ] ) && \is_array( $hooks[ self::SCHEDULE_ACTION ] ) ) {
				$count += \count( $hooks[ self::SCHEDULE_ACTION ] );
			}
		}

		return $count;
	}

	/**
	 * Cron handler. Runs the LLM call, post-processes, persists. Catches
	 * everything — a cron handler must not throw.
	 *
	 * @param int $post_id Post ID provided as the cron-event argument.
	 */
	public static function run( int $post_id ): void {
		$post = \get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Re-check eligibility at run time — toggle may have flipped,
		// post may have been edited, manual description may have been set.
		if ( ! self::should_schedule( $post ) ) {
			\delete_post_meta( $post_id, self::META_KEY_STATUS );
			return;
		}

		// Quality floor (#214): skip near-empty posts before spending any LLM
		// budget. A description like "Real Article is available at <url>." adds
		// nothing for agents and pads /llms.txt. Clear any prior filler `_auto`
		// so the index drops back to the deterministic title-only floor, then
		// record a distinct SKIPPED status (not FAILED — this is a deliberate
		// verdict, not an error).
		if ( self::content_chars( $post ) < self::min_content_chars() ) {
			$previous_auto = (string) \get_post_meta( $post_id, self::META_KEY_AUTO, true );
			if ( '' !== $previous_auto ) {
				\delete_post_meta( $post_id, self::META_KEY_AUTO );
				\delete_post_meta( $post_id, self::META_KEY_GENERATED_FOR_MODIFIED );
				\delete_post_meta( $post_id, self::META_KEY_GENERATED_BY_VERSION );
				self::notify_description_changed( $post_id );
			}
			self::record_diagnostic(
				$post_id,
				array(
					'stage'        => 'pre_generation',
					'error_code'   => 'content_below_floor',
					'attempted_at' => \current_time( 'mysql', true ),
				)
			);
			\update_post_meta( $post_id, self::META_KEY_STATUS, self::STATUS_SKIPPED );
			return;
		}

		$prompt = self::build_user_prompt( $post );
		// `temperature` deliberately omitted — OpenAI reasoning models
		// (o1 / o3 family) reject the parameter with a 400. `max_tokens`
		// set to 200 to give reasoning-class models headroom for internal
		// reasoning tokens; a chat-class model still emits a single tight
		// sentence within this budget. See AgDR-0028 § "Options passed to
		// Client_Wrapper::generate".
		$result = Client_Wrapper::generate(
			$prompt,
			array(
				'system'     => self::DESCRIPTION_SYSTEM_PROMPT,
				'max_tokens' => 200,
			)
		);

		if ( null !== $result->error_code() ) {
			self::record_diagnostic(
				$post_id,
				array(
					'stage'        => 'provider',
					'error_code'   => $result->error_code(),
					'attempted_at' => \current_time( 'mysql', true ),
				)
			);
			\update_post_meta(
				$post_id,
				self::META_KEY_STATUS,
				$result->needs_retry() ? self::STATUS_NEEDS_RETRY : self::STATUS_FAILED
			);
			return;
		}

		$normalised = self::normalise_output( (string) $result->content() );

		if ( null === $normalised ) {
			self::record_diagnostic(
				$post_id,
				array(
					'stage'        => 'post_process',
					'error_code'   => 'empty_or_sentinel',
					'attempted_at' => \current_time( 'mysql', true ),
				)
			);
			\update_post_meta( $post_id, self::META_KEY_STATUS, self::STATUS_FAILED );
			return;
		}

		$previous_auto = (string) \get_post_meta( $post_id, self::META_KEY_AUTO, true );

		\update_post_meta( $post_id, self::META_KEY_AUTO, $normalised );
		\update_post_meta( $post_id, self::META_KEY_GENERATED_FOR_MODIFIED, (string) $post->post_modified_gmt );
		\update_post_meta( $post_id, self::META_KEY_GENERATED_BY_VERSION, self::DESCRIPTION_GENERATOR_VERSION );

		// Recompose /llms.txt only when the served text actually changed — a
		// generator-version refresh that produces identical copy needs no
		// document rewrite (#151).
		if ( $previous_auto !== $normalised ) {
			self::notify_description_changed( $post_id );
		}
		self::record_diagnostic(
			$post_id,
			array(
				'attempted_at' => \current_time( 'mysql', true ),
				'output_chars' => \strlen( $normalised ),
			)
		);
		\update_post_meta( $post_id, self::META_KEY_STATUS, self::STATUS_DONE );
	}

	/**
	 * True when the post has an `_auto` cache AND its
	 * `_generated_for_modified_gmt` is older than the post's current
	 * `post_modified_gmt`. Pure read — Phase B / AgDR-0029 surfaces this
	 * to the admin UI as the "stale" badge + filter.
	 *
	 * Returns false for posts that have never been generated — those are
	 * "missing", not "stale". Callers that need both should also inspect
	 * the orchestrator's status / `get_cached_description()` result.
	 */
	public static function is_stale( \WP_Post $post ): bool {
		$auto = \get_post_meta( (int) $post->ID, self::META_KEY_AUTO, true );
		if ( ! \is_string( $auto ) || '' === \trim( $auto ) ) {
			return false;
		}

		$generated_for = \get_post_meta( (int) $post->ID, self::META_KEY_GENERATED_FOR_MODIFIED, true );
		if ( ! \is_string( $generated_for ) || '' === $generated_for ) {
			// Treat unbookmarked `_auto` as stale — invariant violation
			// caused by an external write; the safe move is to regen.
			return true;
		}

		if ( $generated_for < (string) $post->post_modified_gmt ) {
			return true;
		}

		// Generator-version staleness (#149): a cached `_auto` from an older
		// generator (or pre-#149, version-less) is stale even on an unedited
		// post, so the admin UI surfaces it and bulk-regenerate refreshes it.
		return self::generator_version_stale( (int) $post->ID );
	}

	/**
	 * Whether the cached `_auto` for a post was produced by a generator
	 * version other than the current one (#149). A missing version meta
	 * (every pre-#149 description) counts as stale — `!==` invalidates on any
	 * difference, mirroring the WALKER_VERSION cache-key semantics rather than
	 * lexical `<` ordering (which would misorder e.g. '10' vs '2').
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when the stored generator version differs from the
	 *              current `DESCRIPTION_GENERATOR_VERSION`.
	 */
	private static function generator_version_stale( int $post_id ): bool {
		$stored = \get_post_meta( $post_id, self::META_KEY_GENERATED_BY_VERSION, true );

		return ! \is_string( $stored ) || self::DESCRIPTION_GENERATOR_VERSION !== $stored;
	}

	/**
	 * Whether a sticky admin override is present.
	 *
	 * Single read path for the "is this post sticky?" question, which
	 * `should_schedule()`, `regenerate()`, the REST controller, and WP-CLI
	 * all need to ask. Whitespace-only counts as absent, matching
	 * `set_manual()`'s treatment of blank input as a clear.
	 */
	public static function has_manual( int $post_id ): bool {
		$manual = \get_post_meta( $post_id, self::META_KEY_MANUAL, true );

		return \is_string( $manual ) && '' !== \trim( $manual );
	}

	/**
	 * Set the sticky admin-override `_manual` slot. Single write path so
	 * the REST controller, WP-CLI, and any future programmatic surface all
	 * share sanitisation + truncation.
	 *
	 * Empty / whitespace-only input is treated as "clear" — same as
	 * `clear_manual()`. Trimmed, tag-stripped, truncated to MAX_OUTPUT_CHARS.
	 */
	public static function set_manual( int $post_id, string $description ): void {
		$cleaned = self::normalise_output( $description );
		if ( null === $cleaned ) {
			self::clear_manual( $post_id );
			return;
		}

		$previous = (string) \get_post_meta( $post_id, self::META_KEY_MANUAL, true );
		\update_post_meta( $post_id, self::META_KEY_MANUAL, $cleaned );

		if ( $previous !== $cleaned ) {
			self::notify_description_changed( $post_id );
		}
	}

	/**
	 * Delete the `_manual` slot. The next /llms.txt regen falls back to
	 * `_auto` (or excerpt, if `_auto` is empty too).
	 */
	public static function clear_manual( int $post_id ): void {
		$had = (string) \get_post_meta( $post_id, self::META_KEY_MANUAL, true );
		\delete_post_meta( $post_id, self::META_KEY_MANUAL );

		// Clearing a manual override changes the served description (it falls
		// back to `_auto` / excerpt), so recompose /llms.txt (#151).
		if ( '' !== $had ) {
			self::notify_description_changed( $post_id );
		}
	}

	/**
	 * Hard reset: clear the `_auto` slot + schedule a fresh cron run.
	 *
	 * Returns false without touching any meta in two cases:
	 *
	 *   1. A cron event for this post is already pending (idempotence).
	 *   2. A sticky `_manual` override is set. `should_schedule()` refuses
	 *      such posts, so `run()` would exit early and generate nothing —
	 *      clearing `_auto` first would destroy the cached description with
	 *      no replacement, unrecoverably (#330). Callers must clear the
	 *      override first; the descriptions UI already says so.
	 *
	 * Use `has_manual()` to tell the two refusals apart before calling, so
	 * the reason can be surfaced to whoever asked.
	 */
	public static function regenerate( \WP_Post $post ): bool {
		$post_id = (int) $post->ID;

		if ( self::STATUS_PENDING === self::get_status( $post_id ) ) {
			return false;
		}

		if ( self::has_manual( $post_id ) ) {
			return false;
		}

		\delete_post_meta( $post_id, self::META_KEY_AUTO );
		\delete_post_meta( $post_id, self::META_KEY_GENERATED_FOR_MODIFIED );
		\delete_post_meta( $post_id, self::META_KEY_GENERATED_BY_VERSION );
		\delete_post_meta( $post_id, self::META_KEY_DIAGNOSTICS );
		\delete_post_meta( $post_id, self::META_KEY_STATUS );

		self::schedule( $post );
		return true;
	}

	/**
	 * Resolve the cached description for /llms.txt rendering. Single
	 * read path for `Description_Filter`.
	 *
	 * Resolution order:
	 *   1. `_manual` (sticky admin override)
	 *   2. `_auto` (LLM cache)
	 *   3. empty string — caller falls through to excerpt fallback
	 */
	public static function get_cached_description( int $post_id ): string {
		$manual = \get_post_meta( $post_id, self::META_KEY_MANUAL, true );
		if ( \is_string( $manual ) && '' !== \trim( $manual ) ) {
			return \trim( $manual );
		}

		$auto = \get_post_meta( $post_id, self::META_KEY_AUTO, true );
		if ( \is_string( $auto ) && '' !== \trim( $auto ) ) {
			return \trim( $auto );
		}

		return '';
	}

	/**
	 * Read the stored status, or '' if no attempt has been made.
	 */
	public static function get_status( int $post_id ): string {
		$value = \get_post_meta( $post_id, self::META_KEY_STATUS, true );
		return \is_string( $value ) ? $value : '';
	}

	/**
	 * Clear all orchestrator-owned meta for a post. Manual descriptions
	 * are ALSO cleared — invalidate is called from trash / delete, where
	 * the post is going away entirely.
	 */
	public static function invalidate( int $post_id ): void {
		$had_description =
			'' !== (string) \get_post_meta( $post_id, self::META_KEY_AUTO, true )
			|| '' !== (string) \get_post_meta( $post_id, self::META_KEY_MANUAL, true );

		\delete_post_meta( $post_id, self::META_KEY_AUTO );
		\delete_post_meta( $post_id, self::META_KEY_MANUAL );
		\delete_post_meta( $post_id, self::META_KEY_GENERATED_FOR_MODIFIED );
		\delete_post_meta( $post_id, self::META_KEY_GENERATED_BY_VERSION );
		\delete_post_meta( $post_id, self::META_KEY_STATUS );
		\delete_post_meta( $post_id, self::META_KEY_DIAGNOSTICS );

		// Dropping a served description changes /llms.txt — recompose (#151).
		// Guarded so invalidating an already-empty post is a no-op.
		if ( $had_description ) {
			self::notify_description_changed( $post_id );
		}
	}

	/**
	 * Fire the description-changed signal so `LlmsTxt\Service` schedules a
	 * debounced /llms.txt recompose (#151).
	 *
	 * @param int $post_id Post whose served description changed.
	 */
	private static function notify_description_changed( int $post_id ): void {
		// Hook name resolves to `mokhai_llms_txt_description_changed` —
		// the constant is prefixed; phpcs can't see through the const ref.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		\do_action( self::DESCRIPTION_CHANGED_ACTION, $post_id );
	}

	/**
	 * Build the user-prompt body. Public so the WP-CLI command can show
	 * what the LLM will see when an operator is debugging.
	 */
	/**
	 * Minimum stripped-content length a post must have before the LLM
	 * description job runs. Adopters can raise/lower the floor via the
	 * `mokhai_description_min_content_chars` filter (#214).
	 */
	public static function min_content_chars(): int {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$min = (int) \apply_filters( 'mokhai_description_min_content_chars', self::MIN_CONTENT_CHARS );
		return \max( 0, $min );
	}

	/**
	 * Stripped, shortcode-cleaned body length in characters. Uses the same
	 * extraction as {@see build_user_prompt()} so the floor check and the
	 * prompt see the identical text (#214).
	 */
	public static function content_chars( \WP_Post $post ): int {
		$content = \wp_strip_all_tags( Shortcode_Stripper::strip_orphaned( (string) $post->post_content ) );
		return \strlen( \trim( $content ) );
	}

	public static function build_user_prompt( \WP_Post $post ): string {
		$title = (string) \get_the_title( $post );
		$url   = (string) \get_permalink( $post );
		// Strip orphaned/unregistered shortcodes BEFORE flattening to text, so
		// residue like `[vc_btn title="X"]` never reaches the LLM excerpt and
		// gets parroted into the published /llms.txt description (#147). This
		// path uses raw post_content (no the_content / do_shortcode), so the
		// shared stripper is the only thing removing the tokens here. Same
		// helper the Markdown walker uses (#145) — one implementation.
		$content = \wp_strip_all_tags( Shortcode_Stripper::strip_orphaned( (string) $post->post_content ) );

		$excerpt = \trim( $content );
		if ( \strlen( $excerpt ) > self::MAX_EXCERPT_CHARS ) {
			$excerpt = \rtrim( \substr( $excerpt, 0, self::MAX_EXCERPT_CHARS - 1 ) ) . '…';
		}

		return \sprintf( self::DESCRIPTION_USER_TEMPLATE, $title, $url, $excerpt );
	}

	/**
	 * Apply the AgDR-0028 post-processing pipeline to raw LLM output.
	 * Public so unit tests can pin the pipeline without an LLM call.
	 *
	 * Returns null when the output is empty or the EMPTY sentinel —
	 * orchestrator treats null as `failed`, filter falls through to
	 * excerpt.
	 */
	public static function normalise_output( string $raw ): ?string {
		$text = \trim( $raw );

		// Strip preamble prefixes case-insensitively. Loop until none
		// match — model occasionally stacks ("Summary: This page…").
		// Bounded to 5 iterations as defence against a pathological
		// cycle-shaped output (each iteration strips ≥ 1 byte off a
		// finite string, but the bound makes the termination explicit
		// for static analysers + future maintainers).
		$max_iterations = 5;
		$changed        = true;
		while ( $changed && $max_iterations-- > 0 ) {
			$changed = false;
			foreach ( self::PREAMBLE_PREFIXES as $prefix ) {
				$prefix_len = \strlen( $prefix );
				if ( $prefix_len > \strlen( $text ) ) {
					continue;
				}
				if ( 0 === \strcasecmp( \substr( $text, 0, $prefix_len ), $prefix ) ) {
					$text    = \ltrim( \substr( $text, $prefix_len ) );
					$changed = true;
					break;
				}
			}
		}

		// Defence-in-depth: strip tags in case the model wrapped output.
		$text = \wp_strip_all_tags( $text );

		// Collapse internal whitespace (incl. newlines) to single spaces.
		$collapsed = \preg_replace( '/\s+/', ' ', $text );
		$text      = \is_string( $collapsed ) ? \trim( $collapsed ) : '';

		if ( '' === $text || 0 === \strcasecmp( $text, self::EMPTY_SENTINEL ) ) {
			return null;
		}

		// Truncation at 157 + ellipsis matches Entry_Source::normalise_description.
		if ( \strlen( $text ) > self::MAX_OUTPUT_CHARS ) {
			$text = \rtrim( \substr( $text, 0, self::MAX_OUTPUT_CHARS - 3 ) ) . '...';
		}

		return $text;
	}

	/**
	 * Persist the diagnostic blob (JSON-encoded).
	 *
	 * @param array<string, mixed> $diagnostics
	 */
	private static function record_diagnostic( int $post_id, array $diagnostics ): void {
		\update_post_meta( $post_id, self::META_KEY_DIAGNOSTICS, (string) \wp_json_encode( $diagnostics ) );
	}
}
