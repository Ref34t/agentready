<?php
/**
 * Integration tests for Mokhai\Cli\Llms_Txt_Descriptions_Command.
 *
 * Scope is deliberately narrow: the `regen` subcommand's refusal paths.
 * WP-CLI is one of the two callers that reached `Description_Orchestrator::
 * regenerate()` with no stickiness guard (#330) — the other being a direct
 * REST call — so the guard is proven on both rather than only on the route.
 *
 * WP-CLI is not loaded inside wp-phpunit, so this file ships the same minimal
 * `WP_CLI` shim as Llms_Txt_Command_Test. Both definitions are guarded on
 * `class_exists`, so whichever file PHPUnit loads first wins and the other
 * is a no-op — the duplication buys load-order independence. Extracting it
 * into a shared test helper is worth doing, but not on a P1 bugfix diff:
 * it would mean editing a passing suite to land a guard.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Cli;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Cli\Llms_Txt_Descriptions_Command;
use Mokhai\LlmsTxt\Description_Orchestrator;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

if ( ! class_exists( '\\WP_CLI' ) ) {
	// Declared in a `namespace {}` block via eval() so the class lands in the
	// root namespace, not Mokhai\Tests\Integration\Cli.
	eval(
		'namespace {
			class WP_CLI {
				public static $lines = array();
				public static $successes = array();
				public static $errors = array();

				public static function reset() {
					self::$lines = array();
					self::$successes = array();
					self::$errors = array();
				}

				public static function line( $message = "" ) {
					self::$lines[] = (string) $message;
				}

				public static function success( $message ) {
					self::$successes[] = (string) $message;
				}

				public static function error( $message, $exit = true ) {
					self::$errors[] = (string) $message;
				}

				public static function warning( $message ) {
					self::$lines[] = "Warning: " . (string) $message;
				}

				public static function log( $message ) {
					self::$lines[] = (string) $message;
				}

				public static function add_command( $name, $class ) {
					// no-op: tests bypass the command registry.
				}
			}
		}'
	);
}

/**
 * @covers \Mokhai\Cli\Llms_Txt_Descriptions_Command
 */
final class Llms_Txt_Descriptions_Command_Test extends WP_UnitTestCase {

	private Llms_Txt_Descriptions_Command $command;

	protected function setUp(): void {
		parent::setUp();

		// Same schema reseed as the sibling LlmsTxt suites: the wp-env
		// bootstrap drops the Markdown_Views cache table, and its Service
		// hooks save_post → invalidate(), so every factory post would print a
		// wpdberror and mark these tests risky.
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

		\WP_CLI::reset();
		$this->command = new Llms_Txt_Descriptions_Command();
	}

	/**
	 * Drop the job the post's own creation queued.
	 *
	 * Seeding an eligible post schedules a run, which makes `regenerate()`
	 * refuse via the already-pending short-circuit — the wrong reason for
	 * these tests. Clearing it puts the stickiness guard under test.
	 */
	private function clear_seeded_schedule( int $post_id ): void {
		\wp_clear_scheduled_hook( Description_Orchestrator::SCHEDULE_ACTION, array( $post_id ) );
		\delete_post_meta( $post_id, Description_Orchestrator::META_KEY_STATUS );
	}

	/**
	 * A sticky post is refused before any meta is touched, and the warning
	 * names the real reason rather than falling through to the
	 * already-pending message (#330).
	 */
	public function test_regen_refuses_sticky_post_and_preserves_auto(): void {
		$post_id = (int) self::factory()->post->create(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_AUTO, 'cached auto' );
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_MANUAL, 'Sticky override.' );
		$this->clear_seeded_schedule( $post_id );

		$this->command->regen( array( (string) $post_id ), array() );

		$warnings = implode( "\n", \WP_CLI::$lines );
		self::assertStringContainsString( 'sticky manual description', $warnings );
		self::assertStringNotContainsString(
			'already has a description job pending',
			$warnings,
			'The sticky refusal must not be reported as an in-flight job.'
		);
		self::assertSame( array(), \WP_CLI::$successes );
		self::assertSame(
			'cached auto',
			(string) \get_post_meta( $post_id, Description_Orchestrator::META_KEY_AUTO, true )
		);
		self::assertFalse(
			\wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, array( $post_id ) )
		);
	}

	/**
	 * The unguarded path is unaffected: a post with no override still queues.
	 */
	public function test_regen_queues_when_no_manual_override(): void {
		$post_id = (int) self::factory()->post->create(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		\update_post_meta( $post_id, Description_Orchestrator::META_KEY_AUTO, 'cached auto' );
		$this->clear_seeded_schedule( $post_id );

		$this->command->regen( array( (string) $post_id ), array() );

		self::assertNotEmpty( \WP_CLI::$successes );
		self::assertSame(
			'',
			(string) \get_post_meta( $post_id, Description_Orchestrator::META_KEY_AUTO, true ),
			'A non-sticky regen still clears _auto — the run that replaces it will proceed.'
		);
	}

	/**
	 * A missing post errors out without reaching the orchestrator.
	 */
	public function test_regen_errors_on_missing_post(): void {
		$this->command->regen( array( '99999999' ), array() );

		self::assertNotEmpty( \WP_CLI::$errors );
		self::assertStringContainsString( 'not found', implode( "\n", \WP_CLI::$errors ) );
	}
}
