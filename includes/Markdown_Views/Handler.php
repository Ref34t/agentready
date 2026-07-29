<?php
/**
 * Public-route handler for Markdown Views.
 *
 * Hooked to `template_redirect` by `Router::register_hooks()`. Resolves the
 * incoming request to a post, decides whether the caller asked for the
 * Markdown form (one of three signals per AgDR-0013), and either serves the
 * MD body (status 200, Content-Type text/plain) or 404s with no body.
 *
 * Per AgDR-0015, a 404 never reveals *why* — module-disabled, post-not-found,
 * and not-exposable all produce the same 404 shape so the response carries
 * no exposure-rule information.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Markdown_Views;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Support\Output_Buffer;

\defined( 'ABSPATH' ) || exit;

/**
 * Dispatches `template_redirect` for the three supported URL forms.
 *
 * Behaviour-vs-side-effects split:
 *   - `build_response()` is the pure(-ish) decision function. It produces a
 *     response shape (`status`, `headers`, `body`) without touching globals.
 *     Tested directly with no need to mock `exit`.
 *   - `maybe_serve_markdown()` is the WordPress entry point. It resolves
 *     globals, calls `build_response()`, then `dispatch()`s.
 */
final class Handler {

	/**
	 * `template_redirect` callback. Returns silently if the request isn't
	 * one of our three forms.
	 */
	public static function maybe_serve_markdown(): void {
		$post = self::resolve_post();

		if ( null === $post ) {
			// No post → not our request (or `/nonexistent.md` → 404 if the
			// rewrite var is set).
			if ( '' !== (string) \get_query_var( Router::REWRITE_VAR ) ) {
				self::dispatch( self::build_404_response() );
			}
			return;
		}

		if ( ! self::requested_as_markdown() ) {
			return;
		}

		self::dispatch( self::build_response( $post, self::negotiates_on_accept() ) );
	}

	/**
	 * `send_headers` callback: advertise `Vary: Accept` on a URL whose
	 * representation depends on the request's `Accept` header.
	 *
	 * This covers the **HTML** representation. Of the three signals in
	 * `requested_as_markdown()`, only `Accept: text/markdown` reuses the
	 * canonical URL — `/path.md` and `?format=md` are distinct URLs a cache
	 * already keys separately. So `/lessons/foo/` can return either HTML or
	 * Markdown depending on a request header, which is exactly the condition
	 * RFC 9110 § 12.5.5 requires `Vary` for. Without it, a shared cache
	 * (CDN, reverse proxy, page-cache plugin) stores whichever variant it saw
	 * first and serves it to everyone — humans receiving raw Markdown, or
	 * agents receiving HTML (#332).
	 *
	 * `Vary` must appear on *every* variant of the resource, not just the
	 * negotiated one — hence both here and in `build_response()`. This hook
	 * runs on `send_headers`, which fires before `template_redirect`, so a
	 * Markdown-negotiated request passes through here too; `dispatch()` then
	 * re-sends the identical value, which is a no-op.
	 *
	 * Appended (`false` third arg) so a `Vary` set by the theme, another
	 * plugin, or the host stack is preserved rather than clobbered — repeated
	 * `Vary` field lines combine per RFC 9110 § 5.3. Mirrors the append used
	 * for the `Link` header in `Discovery\Alternate_Advertiser`.
	 */
	public static function send_vary_header(): void {
		if ( \headers_sent() ) {
			return;
		}

		if ( ! \function_exists( 'is_singular' ) || ! \is_singular() ) {
			return;
		}

		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			return;
		}

		// A distinct-URL request (`/path.md`, `?format=md`) does not vary on
		// Accept — it returns Markdown regardless. Advertising `Vary` there
		// would only fragment the cache once these responses become cacheable
		// (#333), so scope the header to the canonical URL.
		if ( ! self::negotiates_on_accept() ) {
			return;
		}

		\header( 'Vary: Accept', false );
	}

	/**
	 * Whether the current request is one whose representation is chosen by the
	 * `Accept` header — i.e. the canonical URL, with neither distinct-URL
	 * signal present.
	 *
	 * Deliberately independent of whether Markdown was actually requested:
	 * a plain HTML request to a negotiable URL still needs to advertise the
	 * variance (that is the representation most crawlers land on).
	 *
	 * No nonce verification: read-only public route, same rationale as
	 * `requested_as_markdown()`.
	 */
	private static function negotiates_on_accept(): bool {
		if ( '' !== (string) \get_query_var( Router::REWRITE_VAR ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$format = isset( $_GET['format'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? \sanitize_key( \wp_unslash( $_GET['format'] ) )
			: '';

		return 'md' !== $format;
	}

	/**
	 * Decide whether the current request asked for the MD form.
	 *
	 * Three signals, any one is enough:
	 *   1. Rewrite var set → `/path.md` URL.
	 *   2. `?format=md` query string.
	 *   3. `Accept: text/markdown` header.
	 *
	 * No nonce verification: this is a public read-only route. The
	 * NonceVerification sniff is broadly applied to `$_GET` reads, but
	 * doesn't apply to read-only public endpoints.
	 */
	public static function requested_as_markdown(): bool {
		if ( '' !== (string) \get_query_var( Router::REWRITE_VAR ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$format = isset( $_GET['format'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? \sanitize_key( \wp_unslash( $_GET['format'] ) )
			: '';

		if ( 'md' === $format ) {
			return true;
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT'] )
			? \sanitize_text_field( \wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) )
			: '';

		if ( '' !== $accept && false !== \stripos( $accept, 'text/markdown' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Build the response shape for a resolved post. Pure(-ish) — calls
	 * `Service::get_markdown_for_post()` but does not touch globals or
	 * emit headers. Returned as a plain array so tests can inspect.
	 *
	 * @param \WP_Post $post            The resolved post.
	 * @param bool     $vary_on_accept  Whether this response was reached on the
	 *                                  canonical URL via `Accept` negotiation
	 *                                  (as opposed to the distinct `/path.md`
	 *                                  or `?format=md` URLs). Adds
	 *                                  `Vary: Accept` so a shared cache keys
	 *                                  this variant separately from the HTML
	 *                                  one (#332). Globals stay out of this
	 *                                  method — the caller resolves the flag.
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	public static function build_response( \WP_Post $post, bool $vary_on_accept = false ): array {
		$result = Service::get_markdown_for_post( $post );

		if ( \is_wp_error( $result ) ) {
			return self::build_404_response();
		}

		$headers = array(
			// text/plain, NOT text/markdown: ChatGPT's URL fetcher rejects
			// text/markdown responses with a 400-class error and accepts
			// text/plain; Claude's fetcher accepts both (#293, spike #291).
			'Content-Type'        => 'text/plain; charset=utf-8',
			'Content-Disposition' => 'inline',
			'X-Robots-Tag'        => 'noindex',
			'Cache-Control'       => 'no-store',
		);

		if ( $vary_on_accept ) {
			$headers['Vary'] = 'Accept';
		}

		return array(
			'status'  => 200,
			'headers' => $headers,
			'body'    => $result,
		);
	}

	/**
	 * 404 with empty body and a plain-text content type — uniform across
	 * every denial reason (per AgDR-0015's "never leak why" rule).
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	public static function build_404_response(): array {
		return array(
			'status'  => 404,
			'headers' => array(
				'Content-Type' => 'text/plain; charset=utf-8',
			),
			'body'    => '',
		);
	}

	/**
	 * Resolve the post for the current request.
	 *
	 * Two paths:
	 *   - Rewrite var set: turn the captured path into a URL, resolve via
	 *     `url_to_postid()`.
	 *   - No rewrite var: WP's main query has already resolved a singular
	 *     post (or not); read `get_queried_object()`.
	 */
	private static function resolve_post(): ?\WP_Post {
		$rewrite_path = (string) \get_query_var( Router::REWRITE_VAR );

		if ( '' !== $rewrite_path ) {
			$url     = \home_url( '/' . \ltrim( $rewrite_path, '/' ) . '/' );
			$post_id = \url_to_postid( $url );

			if ( $post_id <= 0 ) {
				return null;
			}

			$post = \get_post( $post_id );

			return $post instanceof \WP_Post ? $post : null;
		}

		$obj = \get_queried_object();

		return $obj instanceof \WP_Post ? $obj : null;
	}

	/**
	 * Emit the response and terminate. Wrapped here so tests can avoid the
	 * `exit` by calling `build_response()` directly.
	 *
	 * @param array{status:int, headers:array<string,string>, body:string} $response
	 */
	private static function dispatch( array $response ): void {
		// Discard any BOM / whitespace leaked into the output buffer by the
		// theme or another plugin BEFORE we send headers, so the Markdown body
		// starts at the intended first byte and the headers below still send
		// (a flushed buffer would have already sent them). See #175.
		Output_Buffer::discard_pending();

		\status_header( $response['status'] );

		foreach ( $response['headers'] as $name => $value ) {
			\header( $name . ': ' . $value );
		}

		// Output is raw Markdown served with `Content-Type: text/plain` —
		// no HTML rendering context, so HTML-escape semantics do not apply.
		// Strip a leading BOM in case the body itself begins with one (#175).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Output_Buffer::strip_leading_bom( $response['body'] );

		exit;
	}
}
