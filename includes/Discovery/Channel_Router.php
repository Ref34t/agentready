<?php
/**
 * URL routing for the served AI-discovery channels (#172 / AgDR-0056).
 *
 * Serves `ai.txt`, `/.well-known/llms-policy.json`, and
 * `/.well-known/ai-layer` virtually — rewrite rule on `init`, dispatch on
 * `template_redirect` — mirroring `LlmsTxt\Router` (AgDR-0021). No files are
 * ever written: an operator's real static file at any of these paths is
 * served by the web server before the request reaches WordPress, and a
 * `file_exists` guard covers misrouted setups, so "defer to the operator's
 * file" holds by construction.
 *
 * Behaviour-vs-side-effects split mirrors `LlmsTxt\Router`:
 *   - `build_response()` is a pure decision function returning a response
 *     array — testable without mocking `exit`.
 *   - `maybe_serve()` is the WordPress entry point that calls `dispatch()`.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Discovery;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Support\Output_Buffer;

\defined( 'ABSPATH' ) || exit;

/**
 * Rewrite-rule lifecycle + dispatch for the discovery channels.
 */
final class Channel_Router {

	/**
	 * Module key consumed by `Context_Profile_Settings::is_module_enabled()`
	 * (profile field `discovery_channels_enabled`).
	 */
	public const MODULE = 'discovery_channels';

	/**
	 * Routes version persisted to {@see ROUTES_VERSION_OPTION}. Bump when a
	 * rewrite rule is added/changed so `maybe_flush()` re-flushes on plugin
	 * UPDATE (activation-hook flushes only cover install/reactivate — the
	 * same gap `Markdown_Views\Schema::maybe_upgrade()` closes for tables).
	 */
	public const ROUTES_VERSION = 1;

	/**
	 * Option storing the last-flushed routes version. Listed in
	 * `Support\Uninstaller::option_keys()` per the #189 cleanup contract.
	 */
	public const ROUTES_VERSION_OPTION = 'mokhai_discovery_routes_version';

	/**
	 * Channel registry: rewrite regex, query var, the static-file path the
	 * plugin defers to, and the served Content-Type.
	 *
	 * The extension-less `ai-layer` path is exactly why the Content-Type is
	 * declared per channel — it must serve `application/json`, never fall
	 * back to `text/html` (#172 AC).
	 */
	private const CHANNELS = array(
		'ai_txt'      => array(
			'regex'        => '^ai\.txt/?$',
			'query_var'    => 'mokhai_ai_txt',
			'static_file'  => 'ai.txt',
			'content_type' => 'text/plain',
		),
		'llms_policy' => array(
			'regex'        => '^\.well-known/llms-policy\.json/?$',
			'query_var'    => 'mokhai_llms_policy',
			'static_file'  => '.well-known/llms-policy.json',
			'content_type' => 'application/json',
		),
		'ai_layer'    => array(
			'regex'        => '^\.well-known/ai-layer/?$',
			'query_var'    => 'mokhai_ai_layer',
			'static_file'  => '.well-known/ai-layer',
			'content_type' => 'application/json',
		),
	);

	/**
	 * Wire the WordPress hooks owned by this class.
	 */
	public static function register_hooks(): void {
		\add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		\add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		\add_action( 'template_redirect', array( self::class, 'maybe_serve' ), 0 );
		\add_action( 'admin_init', array( self::class, 'maybe_flush' ) );
	}

	/**
	 * Register the channel rewrite rules on `init`. `'top'` precedence, same
	 * rationale as `LlmsTxt\Router::add_rewrite_rule()`.
	 */
	public static function add_rewrite_rules(): void {
		foreach ( self::CHANNELS as $channel ) {
			\add_rewrite_tag( '%' . $channel['query_var'] . '%', '1' );
			\add_rewrite_rule(
				$channel['regex'],
				'index.php?' . $channel['query_var'] . '=1',
				'top'
			);
		}
	}

	/**
	 * Register the query vars so WordPress doesn't strip them during request
	 * parsing.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 *
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		foreach ( self::CHANNELS as $channel ) {
			$vars[] = $channel['query_var'];
		}
		return $vars;
	}

	/**
	 * Activation lifecycle: register the rules, flush, and stamp the routes
	 * version so `maybe_flush()` is a no-op until the next bump.
	 */
	public static function flush_on_activation(): void {
		self::add_rewrite_rules();
		\flush_rewrite_rules();
		\update_option( self::ROUTES_VERSION_OPTION, self::ROUTES_VERSION, false );
	}

	/**
	 * Deactivation lifecycle: flush after our init hook is unregistered so
	 * the rules disappear from the persisted set — disabling the plugin
	 * removes the served channels (#172 AC).
	 */
	public static function flush_on_deactivation(): void {
		\flush_rewrite_rules();
	}

	/**
	 * `admin_init` upgrade path: a plugin UPDATE that ships new/changed
	 * channel rules never runs the activation hook, so the persisted
	 * rewrite_rules option would lack them until a manual flush. One cheap
	 * option read per admin page-load; the flush only fires when the stored
	 * version lags. Mirrors `Markdown_Views\Schema::maybe_upgrade()` (#52).
	 */
	public static function maybe_flush(): void {
		if ( (int) \get_option( self::ROUTES_VERSION_OPTION, 0 ) >= self::ROUTES_VERSION ) {
			return;
		}

		\flush_rewrite_rules();
		\update_option( self::ROUTES_VERSION_OPTION, self::ROUTES_VERSION, false );
	}

	/**
	 * `template_redirect` callback. Returns silently unless exactly one
	 * channel query var is set; defers to an operator-owned static file;
	 * otherwise dispatches the (200 or soft-404) response.
	 */
	public static function maybe_serve(): void {
		$channel = self::matched_channel();
		if ( null === $channel ) {
			return;
		}

		// Defer-to-static (#172 AC): the plugin never overwrites an
		// operator's real file. Normally the web server serves it and this
		// path never runs; the guard covers servers that route the request
		// to WordPress anyway.
		if ( \file_exists( \ABSPATH . self::CHANNELS[ $channel ]['static_file'] ) ) {
			return;
		}

		self::dispatch( self::build_response( $channel ) );
	}

	/**
	 * Resolve which channel the current request matched, if any.
	 *
	 * @return string|null Channel key, or null when not a channel request.
	 */
	public static function matched_channel(): ?string {
		foreach ( self::CHANNELS as $key => $channel ) {
			if ( '1' === (string) \get_query_var( $channel['query_var'] ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Build the response shape — pure, no globals mutated, no headers
	 * emitted. Tests inspect the returned array directly.
	 *
	 * Soft-disable (AgDR-0015 convention): toggle off → explicit 404, never
	 * a fall-through that would render the homepage under a channel URL.
	 *
	 * @param string $channel Channel key from {@see CHANNELS}.
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	public static function build_response( string $channel ): array {
		if ( ! isset( self::CHANNELS[ $channel ] ) ) {
			return self::not_found_response();
		}

		if ( ! Context_Profile_Settings::is_module_enabled( self::MODULE ) ) {
			return self::not_found_response();
		}

		$body = self::body_for( $channel );

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'  => self::CHANNELS[ $channel ]['content_type'] . '; charset=' . self::charset(),
				// `noindex` but deliberately NOT `nofollow` (#338) — matches
				// `LlmsTxt\Router`. Every channel here is a discovery document
				// whose value is the URLs inside it (`ai.txt`, the llms policy,
				// the AI layer manifest), so instructing a crawler to ignore
				// those links defeats the channel's purpose. Do not re-add it.
				'X-Robots-Tag'  => 'noindex',
				'Cache-Control' => 'no-store, must-revalidate',
			),
			'body'    => $body,
		);
	}

	/**
	 * Compose the body for one channel.
	 *
	 * @param string $channel Channel key.
	 */
	private static function body_for( string $channel ): string {
		if ( 'ai_txt' === $channel ) {
			return Channel_Content::ai_txt();
		}

		$payload = 'llms_policy' === $channel
			? Channel_Content::llms_policy()
			: Channel_Content::ai_layer();

		$json = \wp_json_encode( $payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES );
		return \is_string( $json ) ? $json . "\n" : '';
	}

	/**
	 * The soft-disable / unknown-channel response.
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	private static function not_found_response(): array {
		return array(
			'status'  => 404,
			'headers' => array(
				'Content-Type' => 'text/plain; charset=' . self::charset(),
				// Same `noindex`-without-`nofollow` rule as the 200 path (#338),
				// kept identical so the two can't drift apart.
				'X-Robots-Tag' => 'noindex',
			),
			'body'    => '',
		);
	}

	/**
	 * Resolve the response charset (mirrors `LlmsTxt\Router::charset()`).
	 */
	private static function charset(): string {
		$charset = \get_option( 'blog_charset', 'UTF-8' );
		return is_string( $charset ) && '' !== $charset ? $charset : 'UTF-8';
	}

	/**
	 * Emit the response and terminate. Wrapped so tests call
	 * `build_response()` directly without the `exit`.
	 *
	 * @param array{status:int, headers:array<string,string>, body:string} $response Response shape.
	 */
	private static function dispatch( array $response ): void {
		// Discard upstream-leaked buffer output before headers (#175).
		Output_Buffer::discard_pending();

		\status_header( $response['status'] );
		\nocache_headers();

		foreach ( $response['headers'] as $name => $value ) {
			\header( $name . ': ' . $value );
		}

		// Raw text/plain or application/json body — no HTML rendering
		// context applies. Strip a leading BOM defensively (#175).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Output_Buffer::strip_leading_bom( $response['body'] );

		exit;
	}
}
