<?php
/**
 * `/llms.txt` URL routing.
 *
 * Owns the rewrite rule (AgDR-0021) plus the activation / deactivation
 * rewrite-flush lifecycle, and dispatches the `template_redirect` request to
 * the response builder.
 *
 * Behaviour-vs-side-effects split mirrors `Markdown_Views\Handler`:
 *   - `build_response()` is a pure decision function returning a response
 *     array — testable without mocking `exit`.
 *   - `maybe_serve()` is the WordPress entry point that calls `dispatch()`.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\LlmsTxt;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Support\Output_Buffer;

\defined( 'ABSPATH' ) || exit;

/**
 * Rewrite-rule lifecycle + dispatch for `/llms.txt`.
 */
final class Router {

	/**
	 * Query var carrying the "llms.txt request" flag from the rewrite rule
	 * through to the template_redirect handler.
	 *
	 * @var string
	 */
	public const REWRITE_VAR = 'mokhai_llms_txt';

	/**
	 * Query var for the consolidated `/llms-full.txt` route (#179).
	 *
	 * @var string
	 */
	public const FULL_REWRITE_VAR = 'mokhai_llms_full_txt';

	/**
	 * Module key consumed by `Context_Profile_Settings::is_module_enabled()`
	 * for the `/llms-full.txt` route (profile field `llms_full_txt_enabled`).
	 *
	 * @var string
	 */
	public const FULL_MODULE = Service::FULL_MODULE;

	/**
	 * Routes version persisted to {@see ROUTES_VERSION_OPTION}. Bump when a
	 * rewrite rule is added/changed so `maybe_flush()` re-flushes on plugin
	 * UPDATE — activation-hook flushes only cover install/reactivate, the
	 * same gap `Discovery\Channel_Router` closes for the #172 channels.
	 * Version 1 = the `/llms-full.txt` rule (#179).
	 *
	 * @var int
	 */
	public const ROUTES_VERSION = 1;

	/**
	 * Option storing the last-flushed routes version. Listed in
	 * `Support\Uninstaller::option_keys()` per the #189 cleanup contract.
	 *
	 * @var string
	 */
	public const ROUTES_VERSION_OPTION = 'mokhai_llms_txt_routes_version';

	/**
	 * Wire the WordPress hooks owned by this class.
	 */
	public static function register_hooks(): void {
		\add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		\add_filter( 'query_vars', array( self::class, 'register_query_var' ) );
		\add_action( 'template_redirect', array( self::class, 'maybe_serve' ), 0 );
		\add_action( 'admin_init', array( self::class, 'maybe_flush' ) );
	}

	/**
	 * Register the `/llms.txt` rewrite rule on `init`.
	 *
	 * `'top'` precedence ensures we match before WP's permalink rules try to
	 * resolve a page slugged `llms.txt`. The trailing-slash variant
	 * (`/llms.txt/`) is also accepted via the `/?$` suffix.
	 */
	public static function add_rewrite_rule(): void {
		\add_rewrite_tag( '%' . self::REWRITE_VAR . '%', '1' );
		\add_rewrite_rule(
			'^llms\.txt/?$',
			'index.php?' . self::REWRITE_VAR . '=1',
			'top'
		);

		\add_rewrite_tag( '%' . self::FULL_REWRITE_VAR . '%', '1' );
		\add_rewrite_rule(
			'^llms-full\.txt/?$',
			'index.php?' . self::FULL_REWRITE_VAR . '=1',
			'top'
		);
	}

	/**
	 * Register the query var so WordPress doesn't strip it during request parsing.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 *
	 * @return array<int, string>
	 */
	public static function register_query_var( array $vars ): array {
		$vars[] = self::REWRITE_VAR;
		$vars[] = self::FULL_REWRITE_VAR;
		return $vars;
	}

	/**
	 * Activation lifecycle: register the rules, flush so the rewrites are
	 * persisted into the `rewrite_rules` option, and stamp the routes version
	 * so `maybe_flush()` is a no-op until the next bump.
	 */
	public static function flush_on_activation(): void {
		self::add_rewrite_rule();
		\flush_rewrite_rules();
		\update_option( self::ROUTES_VERSION_OPTION, self::ROUTES_VERSION, false );
	}

	/**
	 * `admin_init` upgrade path: a plugin UPDATE that ships new/changed rules
	 * (the `/llms-full.txt` route, #179) never runs the activation hook, so
	 * the persisted rewrite_rules option would lack them until a manual
	 * flush. One cheap option read per admin page-load; the flush only fires
	 * when the stored version lags. Mirrors `Discovery\Channel_Router`.
	 */
	public static function maybe_flush(): void {
		if ( (int) \get_option( self::ROUTES_VERSION_OPTION, 0 ) >= self::ROUTES_VERSION ) {
			return;
		}

		\flush_rewrite_rules();
		\update_option( self::ROUTES_VERSION_OPTION, self::ROUTES_VERSION, false );
	}

	/**
	 * Deactivation lifecycle: flush after our init hook is unregistered so the
	 * rule disappears from the persisted set.
	 */
	public static function flush_on_deactivation(): void {
		\flush_rewrite_rules();
	}

	/**
	 * `template_redirect` callback. Returns silently if the request isn't
	 * the llms.txt route; otherwise dispatches the response.
	 */
	public static function maybe_serve(): void {
		if ( self::is_llms_full_txt_request() ) {
			self::dispatch( self::build_full_response() );
			return;
		}

		if ( ! self::is_llms_txt_request() ) {
			return;
		}

		self::dispatch( self::build_response() );
	}

	/**
	 * Decide whether the current request is the llms.txt route. The rewrite
	 * rule sets the query var to `1`; absence (or any other value) means
	 * not-our-request.
	 */
	public static function is_llms_txt_request(): bool {
		return '1' === (string) \get_query_var( self::REWRITE_VAR );
	}

	/**
	 * Decide whether the current request is the llms-full.txt route (#179).
	 */
	public static function is_llms_full_txt_request(): bool {
		return '1' === (string) \get_query_var( self::FULL_REWRITE_VAR );
	}

	/**
	 * Build the response shape — pure, no globals, no headers emitted. Tests
	 * inspect the returned array directly.
	 *
	 * Per AgDR-0021 § "Public route is uniformly 200 or 404, never 500", the
	 * route always returns 200 for an enabled module. Since #244 a fresh
	 * install with no exposed CPTs composes to the site identity header alone
	 * (not a blank body), so agents fetching /llms.txt see an identifiable
	 * document. A genuinely empty body only occurs when the site has no name
	 * at all — still a valid 200.
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	public static function build_response(): array {
		$body = Service::get_composed_body();

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'  => 'text/plain; charset=' . self::charset(),
				// `noindex` but deliberately NOT `nofollow` (#338). The document
				// has no business in search results, but it is nothing except a
				// curated inventory of links — telling a crawler not to follow
				// them turns the index into a dead end and defeats the whole
				// point of publishing it. AI fetchers ignore this header, but
				// crawlers on search infrastructure that also feed AI surfaces
				// honour it. Do not re-add `nofollow` here.
				'X-Robots-Tag'  => 'noindex',
				'Cache-Control' => 'no-store, must-revalidate',
			),
			'body'    => $body,
		);
	}

	/**
	 * Build the `/llms-full.txt` response shape (#179) — pure, no globals
	 * mutated, no headers emitted.
	 *
	 * Soft-disable (AgDR-0015 convention): `llms_full_txt_enabled` off →
	 * explicit 404, never a fall-through that would render the homepage
	 * under the route. With the module on, the same uniform 200-or-404
	 * contract as `/llms.txt` applies — an empty composition is a valid
	 * `200` with an empty body.
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	public static function build_full_response(): array {
		if ( ! Context_Profile_Settings::is_module_enabled( self::FULL_MODULE ) ) {
			return array(
				'status'  => 404,
				'headers' => array(
					'Content-Type' => 'text/plain; charset=' . self::charset(),
					// Same `noindex`-without-`nofollow` rule as the 200 path
					// above (#338) — kept identical so the two responses can't
					// drift into contradicting each other.
					'X-Robots-Tag' => 'noindex',
				),
				'body'    => '',
			);
		}

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'  => 'text/plain; charset=' . self::charset(),
				'X-Robots-Tag'  => 'noindex',
				'Cache-Control' => 'no-store, must-revalidate',
			),
			'body'    => Service::get_composed_full_body(),
		);
	}

	/**
	 * Resolve the response charset. Honours the `blog_charset` option so
	 * non-UTF-8 sites (rare but legal) get the matching declaration.
	 */
	private static function charset(): string {
		$charset = \get_option( 'blog_charset', 'UTF-8' );
		return is_string( $charset ) && '' !== $charset ? $charset : 'UTF-8';
	}

	/**
	 * Emit the response and terminate. Wrapped so tests can call
	 * `build_response()` directly without the `exit`.
	 *
	 * @param array{status:int, headers:array<string,string>, body:string} $response Response shape.
	 */
	private static function dispatch( array $response ): void {
		// Discard any BOM / whitespace leaked into the output buffer by the
		// theme or another plugin BEFORE we send headers, so our text/plain
		// body starts at the intended first byte and the headers below still
		// send (a flushed buffer would have already sent them). See #175.
		Output_Buffer::discard_pending();

		\status_header( $response['status'] );
		\nocache_headers();

		foreach ( $response['headers'] as $name => $value ) {
			\header( $name . ': ' . $value );
		}

		// Raw text/plain body — no HTML rendering context applies, so the
		// HTML-escape sniff doesn't apply either. Strip a leading BOM in case
		// the composed body itself begins with one (#175).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Output_Buffer::strip_leading_bom( $response['body'] );

		exit;
	}
}
