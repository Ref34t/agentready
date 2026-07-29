<?php
/**
 * Integration tests for the served AI-discovery channels (#172 / AgDR-0056).
 *
 * Pins the routing + payload contract: the three channels serve 200 with the
 * declared Content-Type (the extension-less ai-layer path must be JSON, never
 * text/html), the soft-disable toggle 404s instead of falling through, the
 * llms-policy access stance is profile-driven with deny-training defaults,
 * defer-to-static skips dispatch, and the Context Score credits the served
 * channels rather than only filesystem hits.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Discovery;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Context_Score\Signal_Collector;
use Mokhai\Discovery\Channel_Content;
use Mokhai\Discovery\Channel_Router;

final class Channel_Router_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		update_option( Context_Profile_Settings::OPTION_KEY, Context_Profile_Settings::get_defaults() );
	}

	/**
	 * Merge an override into the stored profile.
	 *
	 * @param array<string, mixed> $overrides Profile keys to override.
	 */
	private function set_profile( array $overrides ): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge( Context_Profile_Settings::get_defaults(), $overrides )
		);
	}

	public function test_ai_txt_serves_text_plain_with_pointers_and_policy(): void {
		$response = Channel_Router::build_response( 'ai_txt' );

		$this->assertSame( 200, $response['status'] );
		$this->assertStringStartsWith( 'text/plain;', $response['headers']['Content-Type'] );
		$this->assertSame( 'noindex', $response['headers']['X-Robots-Tag'] );
		$this->assertStringContainsString( 'LLMs-Index: ' . home_url( '/llms.txt' ), $response['body'] );
		// Default stance: inference allowed, training denied (AgDR-0056).
		$this->assertStringContainsString( 'Inference: allowed', $response['body'] );
		$this->assertStringContainsString( 'Training: disallowed', $response['body'] );
	}

	public function test_llms_policy_serves_json_with_profile_driven_stance(): void {
		$response = Channel_Router::build_response( 'llms_policy' );

		$this->assertSame( 200, $response['status'] );
		$this->assertStringStartsWith( 'application/json;', $response['headers']['Content-Type'] );

		$payload = json_decode( $response['body'], true );
		$this->assertIsArray( $payload );
		$this->assertSame( Channel_Content::PAYLOAD_VERSION, $payload['version'] );
		$this->assertSame( get_option( 'blogname' ), $payload['organization'] );
		$this->assertSame( home_url( '/llms.txt' ), $payload['llms_txt'] );
		$this->assertTrue( $payload['policy']['allow_inference'] );
		$this->assertFalse( $payload['policy']['allow_training'] );
	}

	public function test_llms_policy_honours_operator_stance_overrides(): void {
		$this->set_profile(
			array(
				'policy_allow_inference' => false,
				'policy_allow_training'  => true,
			)
		);

		$payload = json_decode( Channel_Router::build_response( 'llms_policy' )['body'], true );

		$this->assertFalse( $payload['policy']['allow_inference'] );
		$this->assertTrue( $payload['policy']['allow_training'] );

		$ai_txt = Channel_Router::build_response( 'ai_txt' )['body'];
		$this->assertStringContainsString( 'Inference: disallowed', $ai_txt );
		$this->assertStringContainsString( 'Training: allowed', $ai_txt );
	}

	public function test_ai_layer_serves_json_channel_descriptor(): void {
		$response = Channel_Router::build_response( 'ai_layer' );

		$this->assertSame( 200, $response['status'] );
		// The extension-less path must declare JSON, never fall back to HTML.
		$this->assertStringStartsWith( 'application/json;', $response['headers']['Content-Type'] );

		$payload = json_decode( $response['body'], true );
		$this->assertIsArray( $payload );
		$this->assertSame( 'mokhai', $payload['generator'] );
		$this->assertSame( home_url( '/llms.txt' ), $payload['channels']['llms_txt'] );
		$this->assertSame( home_url( '/ai.txt' ), $payload['channels']['ai_txt'] );
		$this->assertSame( home_url( '/.well-known/llms-policy.json' ), $payload['channels']['llms_policy'] );
		$this->assertIsBool( $payload['channels']['markdown_views'] );
	}

	public function test_disabled_toggle_serves_404_not_fallthrough(): void {
		$this->set_profile( array( 'discovery_channels_enabled' => false ) );

		foreach ( array( 'ai_txt', 'llms_policy', 'ai_layer' ) as $channel ) {
			$response = Channel_Router::build_response( $channel );
			$this->assertSame( 404, $response['status'], "Channel '{$channel}' must soft-404 when disabled." );
			$this->assertSame( 'noindex', $response['headers']['X-Robots-Tag'] );
		}
	}

	public function test_unknown_channel_is_404(): void {
		$this->assertSame( 404, Channel_Router::build_response( 'nonsense' )['status'] );
	}

	public function test_rewrite_rules_register_in_top_extras(): void {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();

		Channel_Router::add_rewrite_rules();

		$this->assertArrayHasKey( '^ai\.txt/?$', $wp_rewrite->extra_rules_top );
		$this->assertArrayHasKey( '^\.well-known/llms-policy\.json/?$', $wp_rewrite->extra_rules_top );
		$this->assertArrayHasKey( '^\.well-known/ai-layer/?$', $wp_rewrite->extra_rules_top );
	}

	public function test_register_query_vars_appends_all_three(): void {
		$vars = Channel_Router::register_query_vars( array( 'foo' ) );

		$this->assertContains( 'mokhai_ai_txt', $vars );
		$this->assertContains( 'mokhai_llms_policy', $vars );
		$this->assertContains( 'mokhai_ai_layer', $vars );
		$this->assertContains( 'foo', $vars );
	}

	public function test_matched_channel_resolves_query_var(): void {
		$this->assertNull( Channel_Router::matched_channel() );

		set_query_var( 'mokhai_llms_policy', '1' );
		$this->assertSame( 'llms_policy', Channel_Router::matched_channel() );
		set_query_var( 'mokhai_llms_policy', '' );
	}

	public function test_maybe_serve_defers_to_operator_static_file(): void {
		$static = ABSPATH . 'ai.txt';
		$this->assertFalse( file_exists( $static ), 'Precondition: no static ai.txt in the test root.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		file_put_contents( $static, "operator-owned\n" );
		set_query_var( 'mokhai_ai_txt', '1' );

		try {
			// dispatch() would exit; deferring returns without output. Reaching
			// the assertion below proves the static-file guard short-circuited.
			Channel_Router::maybe_serve();
			$this->assertTrue( true );
		} finally {
			set_query_var( 'mokhai_ai_txt', '' );
			unlink( $static ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
		}
	}

	public function test_maybe_flush_stamps_routes_version(): void {
		delete_option( Channel_Router::ROUTES_VERSION_OPTION );

		Channel_Router::maybe_flush();

		$this->assertSame(
			Channel_Router::ROUTES_VERSION,
			(int) get_option( Channel_Router::ROUTES_VERSION_OPTION )
		);
	}

	public function test_signal_collector_credits_served_channels(): void {
		$signals = Signal_Collector::collect()['multi_channel_discovery'];

		$this->assertTrue( $signals['ai_txt_present'] );
		$this->assertTrue( $signals['well_known_ai_layer'] );
		$this->assertTrue( $signals['well_known_llms_policy'] );
	}

	public function test_signal_collector_falls_back_to_file_probes_when_disabled(): void {
		$this->set_profile( array( 'discovery_channels_enabled' => false ) );

		$signals = Signal_Collector::collect()['multi_channel_discovery'];

		// No static files exist in the test root and the module is off, so
		// the probes report absent — existing detection behaviour preserved.
		$this->assertFalse( $signals['ai_txt_present'] );
		$this->assertFalse( $signals['well_known_llms_policy'] );
	}

	public function test_profile_sanitize_defaults_and_overrides_for_new_keys(): void {
		// Legacy (pre-#172, schema v2) stored profile: missing keys migrate
		// to the safe defaults — channels on, inference allowed, training denied.
		$legacy = Context_Profile_Settings::get_defaults();
		unset( $legacy['discovery_channels_enabled'], $legacy['policy_allow_inference'], $legacy['policy_allow_training'] );
		$legacy['schema_version'] = 2;
		update_option( Context_Profile_Settings::OPTION_KEY, $legacy );

		$profile = Context_Profile_Settings::get_profile();
		$this->assertSame( Context_Profile_Settings::CURRENT_SCHEMA_VERSION, $profile['schema_version'] );
		$this->assertTrue( $profile['discovery_channels_enabled'] );
		$this->assertTrue( $profile['policy_allow_inference'] );
		$this->assertFalse( $profile['policy_allow_training'] );

		// Explicit operator choices survive sanitisation.
		$this->set_profile(
			array(
				'discovery_channels_enabled' => false,
				'policy_allow_training'      => true,
			)
		);
		$profile = Context_Profile_Settings::get_profile();
		$this->assertFalse( $profile['discovery_channels_enabled'] );
		$this->assertTrue( $profile['policy_allow_training'] );
	}
}
