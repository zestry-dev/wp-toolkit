<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\Options;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Options persistence against the real wp_options table (review #23, #24, #37, #39).
 *
 * @covers \Zestry\WPToolkit\Modules\Options
 */
final class OptionsTest extends TestCase {

	private const GROUP_API_KEY = 'zestry-test_api';

	private const UNGROUPED_KEY = 'zestry-test_' . Options::DEFAULT_GROUP_NAME;

	public function tear_down(): void {
		delete_option( self::UNGROUPED_KEY );
		delete_option( self::GROUP_API_KEY );
		delete_option( 'zestry-test_options' );
		parent::tear_down();
	}

	private function options(): Options {
		return $this->plugin->get( Options::class );
	}

	public function test_set_and_get_a_simple_value(): void {
		$options = $this->options();
		$options->set( 'colour', 'blue' );

		$this->assertSame( 'blue', $options->get( 'colour' ) );
		$this->assertSame( 'fallback', $options->get( 'missing', 'fallback' ) );
	}

	public function test_set_and_get_a_key(): void {
		$options = $this->options();
		$options->set( 'timeout', 30 );

		$this->assertSame( 30, $options->get( 'timeout' ) );
	}

	public function test_has_reports_presence(): void {
		$options = $this->options();
		$options->set( 'colour', 'blue' );

		$this->assertTrue( $options->has( 'colour' ) );
		$this->assertFalse( $options->has( 'missing' ) );
	}

	/**
	 * `array_key_exists()` rather than `isset()`: a key deliberately stored as
	 * null is set, and has to read as different from one never written.
	 */
	public function test_has_distinguishes_a_stored_null_from_a_missing_key(): void {
		$options = $this->options();
		$options->set( 'colour', null );

		$this->assertTrue( $options->has( 'colour' ) );
		$this->assertFalse( $options->has( 'never-set' ) );
	}

	public function test_delete_removes_a_key(): void {
		$options = $this->options();
		$options->set( 'colour', 'blue' );

		$options->delete( 'colour' );

		$this->assertFalse( $options->has( 'colour' ) );
		$this->assertSame( 'fallback', $options->get( 'colour', 'fallback' ) );
	}

	/**
	 * has() must distinguish a stored null from an absent key.
	 *
	 * This is why it walks with array_key_exists() rather than isset(); an
	 * isset()-based walk reports false for both, which is the ambiguity has()
	 * exists to remove.
	 */
	public function test_has_reports_true_for_a_key_stored_as_null(): void {
		$options = $this->options();
		$options->set( 'explicit_null', null );

		$this->assertTrue( $options->has( 'explicit_null' ) );
		$this->assertFalse( $options->has( 'never_set' ) );
	}

	/**
	 * Options and Globals must answer stored-null identically.
	 *
	 * Pinned because the two stores are documented as siblings: a consumer
	 * switching between them should not find get() disagreeing about whether a
	 * stored null comes back as null or as the fallback.
	 */
	public function test_get_agrees_with_globals_on_a_stored_null(): void {
		$options = $this->options();
		$globals = $this->plugin->get( \Zestry\WPToolkit\Modules\Globals::class );

		$options->set( 'stored_null', null );
		$globals->set( 'stored_null', null );

		$this->assertNull( $options->get( 'stored_null', 'fallback' ) );
		$this->assertSame(
			$globals->get( 'stored_null', 'fallback' ),
			$options->get( 'stored_null', 'fallback' ),
			'The two key/value stores must agree on stored-null semantics.'
		);
	}

	public function test_values_persist_to_the_database_on_save(): void {
		$options = $this->options();
		$options->set( 'persisted', 'yes' );
		$options->save();

		// Read straight from the DB, bypassing the in-memory instance.
		$stored = get_option( self::UNGROUPED_KEY );
		$this->assertIsArray( $stored );
		$this->assertSame( 'yes', $stored['persisted'] );
	}

	public function test_the_shutdown_hook_persists_pending_changes(): void {
		// on_boot() registers a shutdown callback that flushes dirty values.
		$options = $this->options();
		$options->set( 'via_shutdown', 'saved' );

		// Firing the global shutdown action also runs WordPress core shutdown
		// handlers that open/close output buffers, so restore the level afterwards.
		$level = ob_get_level();
		do_action( 'shutdown' );
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		while ( ob_get_level() < $level ) {
			ob_start();
		}

		$stored = get_option( self::UNGROUPED_KEY );
		$this->assertIsArray( $stored );
		$this->assertSame( 'saved', $stored['via_shutdown'] );
	}

	public function test_save_is_a_no_op_when_not_dirty(): void {
		$options = $this->options();
		$options->set( 'x', 1 );
		$options->save();

		// A second save with no further changes must not re-write.
		$options->save();
		$this->assertSame( 1, $options->get( 'x' ) );
	}

	/**
	 * Regression guard: re-saving a value that already matches must not throw.
	 *
	 * update_option() reports `false` both for a genuine failure and for a
	 * value identical to what is stored, so save() has to recognize the no-op
	 * itself. Distinct from test_save_is_a_no_op_when_not_dirty(), which only
	 * exercises the is_dirty short-circuit and so never reaches that check --
	 * here the instance is genuinely dirty and the stored value already
	 * matches, which is the exact combination that regressed once before.
	 */
	public function test_re_saving_an_already_stored_value_is_a_no_op_rather_than_a_failure(): void {
		$options = $this->options();
		$options->set( 'version', '1.0.0' );
		$options->save();

		// A second, independent instance loads the persisted value, then sets
		// the same value again -- dirty in memory, identical in the database.
		$fresh = $this->plugin->make( Options::class );
		$fresh->set( 'version', '1.0.0' );

		$fresh->save();

		$stored = get_option( self::UNGROUPED_KEY );
		$this->assertIsArray( $stored );
		$this->assertSame( '1.0.0', $stored['version'] );
	}

	public function test_a_failed_write_throws_and_a_later_save_retries_the_change(): void {
		$options = $this->options();
		$options->set( 'never_written', 'value' );

		$block = static function () {
			return false;
		};
		add_filter( 'pre_update_option_' . self::UNGROUPED_KEY, $block );
		try {
			$options->save();
			$this->fail( 'Expected save() to throw when update_option() fails.' );
		} catch ( \RuntimeException $exception ) {
			// Expected: the write failed, is_dirty must still be set below.
		} finally {
			remove_filter( 'pre_update_option_' . self::UNGROUPED_KEY, $block );
		}

		$this->assertFalse( get_option( self::UNGROUPED_KEY ), 'the failed write never reached the database.' );

		// The dirty flag must still be set, so this second, unblocked save
		// actually persists the change rather than treating it as already saved.
		$options->save();
		$stored = get_option( self::UNGROUPED_KEY );
		$this->assertIsArray( $stored );
		$this->assertSame( 'value', $stored['never_written'] );
	}

	public function test_the_shutdown_hook_catches_a_failed_save_instead_of_propagating_it(): void {
		// A thrown exception has nowhere to go once shutdown is underway --
		// the registered callback must catch it, not let it surface as an
		// uncaught error during request teardown.
		$options = $this->options();
		$options->set( 'via_shutdown_failure', 'value' );

		$block = static function () {
			return false;
		};
		add_filter( 'pre_update_option_' . self::UNGROUPED_KEY, $block );

		// The failure is announced on the plugin's log action, which is how a Log
		// module -- or a consumer's own handler -- picks it up without this
		// module depending on either. A listener also means error_log() is
		// skipped, keeping the message out of the suite's output.
		$seen = array();

		add_action(
			'zestry-test-log',
			static function ( string $level, string $message, array $context = array() ) use ( &$seen ): void {
				$seen = compact( 'level', 'message', 'context' );
			},
			10,
			3
		);

		$level = ob_get_level();
		do_action( 'shutdown' );
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		while ( ob_get_level() < $level ) {
			ob_start();
		}

		remove_filter( 'pre_update_option_' . self::UNGROUPED_KEY, $block );

		// Reaching this line at all is half the assertion: do_action( 'shutdown' )
		// above did not let the RuntimeException escape.
		$this->assertFalse( get_option( self::UNGROUPED_KEY ), 'the failed write never reached the database.' );
		$this->assertSame( 'error', $seen['level'] );
		$this->assertSame( array( 'option' => self::UNGROUPED_KEY ), $seen['context'] );
	}

	public function test_ungrouped_option_is_stored_under_the_namespaced_key(): void {
		$options = $this->options();
		$options->set( 'k', 'v' );
		$options->save();

		$this->assertNotFalse( get_option( self::UNGROUPED_KEY ), 'Stored under {slug}__options_.' );
		$this->assertFalse( get_option( 'zestry-test' ), 'Not stored under the bare slug.' );
	}

	public function test_option_is_not_autoloaded(): void {
		$options = $this->options();
		$options->set( 'k', 'v' );
		$options->save();

		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", self::UNGROUPED_KEY )
		);

		// WordPress stores non-autoloaded options with a 'no'-family flag.
		$this->assertNotSame( 'yes', $autoload, 'Plugin config must not autoload.' );
	}

	public function test_a_group_can_opt_into_autoloading(): void {
		$options = $this->options();
		$options->add_autoloaded_groups( array( 'flag' ) );

		$flag = $options->group( 'flag' );
		$flag->set( 'k', 'v' );
		$flag->save();

		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'zestry-test_flag' )
		);

		// update_option( ..., true ) stores the boolean as the 'on' autoload
		// value (see wp_determine_option_autoload_value()); WordPress treats
		// 'yes'/'on'/'auto-on'/'auto' as autoloaded (wp_autoload_values_to_autoload()).
		$this->assertContains( $autoload, array( 'yes', 'on' ), 'A group created with autoload=true is autoloaded.' );

		delete_option( 'zestry-test_flag' );
	}

	/**
	 * Placed after test_option_is_not_autoloaded(): $autoloaded_groups is a
	 * static, per-process registry, so calling autoload_default_group() here
	 * would otherwise leak into that earlier test if run out of order.
	 */
	public function test_autoload_default_group_opts_the_ungrouped_instance_in(): void {
		$options = $this->options();
		$options->autoload_default_group();
		$options->set( 'k', 'v' );
		$options->save();

		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", self::UNGROUPED_KEY )
		);

		$this->assertContains( $autoload, array( 'yes', 'on' ), 'autoload_default_group() makes the ungrouped instance autoload.' );
	}

	public function test_groups_use_a_separate_option_and_do_not_collide(): void {
		$options = $this->options();
		$options->set( 'shared', 'default-value' );

		$api = $options->group( 'api' );
		$api->set( 'shared', 'api-value' );

		$this->assertSame( 'default-value', $options->get( 'shared' ) );
		$this->assertSame( 'api-value', $api->get( 'shared' ) );

		// A user group literally named "options" must not collide with the default.
		$literal = $options->group( 'options' );
		$this->assertNotSame( $api, $literal );
		$this->assertSame( $api, $options->group( 'api' ), 'group() caches per name.' );
	}

	public function test_a_corrupted_non_array_option_is_coerced_to_empty(): void {
		// Simulate an externally-overwritten option holding a scalar.
		update_option( self::UNGROUPED_KEY, 'a plain string, not an array' );

		// Boot a fresh instance so db_retrieve() runs against the corrupted value.
		$options = $this->plugin->make( Options::class );

		$this->assertSame( 'default', $options->get( 'anything', 'default' ) );
		$options->set( 'now', 'ok' );
		$this->assertSame( 'ok', $options->get( 'now' ) );
	}
}
