<?php
/**
 * Markdown Views URL routing.
 *
 * Owns the rewrite rule that turns `/path.md` into a query var, plus the
 * activation/deactivation rewrite-flush lifecycle. Per AgDR-0013, the three
 * supported URL forms are `?format=md`, `Accept: text/markdown`, and
 * `/path.md` — this class registers the rewrite; `Handler` dispatches all
 * three forms.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Markdown_Views;

\defined( 'ABSPATH' ) || exit;

/**
 * Rewrite-rule lifecycle for `/path.md`.
 *
 * Three execution surfaces:
 *   - `register_hooks()` wires the init-time rule registration + query-var
 *     filter + template_redirect handler dispatch.
 *   - `flush_on_activation()` is called from the plugin activation hook to
 *     persist the rule into the `rewrite_rules` option.
 *   - `flush_on_deactivation()` clears the rule on deactivate.
 */
final class Router {

	/**
	 * Query var that carries the matched path segment from a `/path.md`
	 * request through to the handler.
	 *
	 * @var string
	 */
	public const REWRITE_VAR = 'mokhai_md_request';

	/**
	 * Wire the WordPress hooks owned by this class. Called from
	 * Main::register_hooks().
	 */
	public static function register_hooks(): void {
		\add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		\add_filter( 'query_vars', array( self::class, 'register_query_var' ) );
		\add_action( 'template_redirect', array( Handler::class, 'maybe_serve_markdown' ), 0 );
		// `Vary: Accept` on negotiable URLs — must reach the HTML representation
		// too, so it hangs off `send_headers` rather than the Markdown dispatch
		// (#332). Mirrors the `send_headers` priority used by the alternate
		// advertiser's `Link` header.
		\add_action( 'send_headers', array( Handler::class, 'send_vary_header' ), 10, 0 );
	}

	/**
	 * Register the `/path.md` rewrite rule.
	 *
	 * Runs on every `init` so the in-memory rules array is populated even if
	 * the persisted `rewrite_rules` option hasn't been flushed yet (e.g.
	 * fresh clone of a multisite). The activation hook handles the flush
	 * once so the rule survives to wp_loaded.
	 */
	public static function add_rewrite_rule(): void {
		\add_rewrite_tag( '%' . self::REWRITE_VAR . '%', '([^&]+)' );
		\add_rewrite_rule(
			'^(.+)\.md/?$',
			'index.php?' . self::REWRITE_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Register the query var so WordPress's main query parser doesn't strip
	 * `mokhai_md_request` when it builds the query.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 *
	 * @return array<int, string>
	 */
	public static function register_query_var( array $vars ): array {
		$vars[] = self::REWRITE_VAR;
		return $vars;
	}

	/**
	 * Activation lifecycle: register the rule, then flush.
	 *
	 * Called from `Main::on_activate()`. `flush_rewrite_rules()` is expensive
	 * (writes the rewrite_rules option) — never called on every page load.
	 */
	public static function flush_on_activation(): void {
		self::add_rewrite_rule();
		\flush_rewrite_rules();
	}

	/**
	 * Deactivation lifecycle: remove the rule by flushing without our hook
	 * being registered. WordPress rebuilds the rewrite_rules option from
	 * whatever hooks remain — ours is gone after deactivation, so the rule
	 * is dropped.
	 */
	public static function flush_on_deactivation(): void {
		\flush_rewrite_rules();
	}
}
