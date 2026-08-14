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

	private function autoload_flag( string $option_name ): ?string {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name )
		);
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

	/**
	 * The whole point of dropping the shutdown hook: a request that changes
	 * settings and then dies leaves the stored row exactly as it was.
	 */
	public function test_nothing_is_written_until_save_is_called(): void {
		$options = $this->options();
		$options->set( 'never_saved', 'value' );

		$this->assertFalse( get_option( self::UNGROUPED_KEY ), 'set() alone must not reach the database.' );

		// And shutdown is not a second chance: nothing is listening for it.
		$level = ob_get_level();
		do_action( 'shutdown' );
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		while ( ob_get_level() < $level ) {
			ob_start();
		}

		$this->assertFalse( get_option( self::UNGROUPED_KEY ), 'shutdown must not write either.' );
	}

	/**
	 * The row is read on the first accessor call, not when the module is built,
	 * so a request that never asks for a setting never queries for one.
	 */
	public function test_the_row_is_read_on_first_access_and_not_before(): void {
		update_option( self::UNGROUPED_KEY, array( 'seeded' => 'from-db' ) );

		$reads = 0;
		$count = static function ( $pre ) use ( &$reads ) {
			++$reads;
			return $pre;
		};
		add_filter( 'pre_option_' . self::UNGROUPED_KEY, $count );

		$options = $this->options();
		$this->assertSame( 0, $reads, 'Building the module must not read the row.' );

		$this->assertSame( 'from-db', $options->get( 'seeded' ) );
		$this->assertSame( 1, $reads, 'The first read loads it.' );

		$options->get( 'seeded' );
		$options->has( 'seeded' );
		$this->assertSame( 1, $reads, 'And it is kept for the rest of the request.' );

		remove_filter( 'pre_option_' . self::UNGROUPED_KEY, $count );
	}

	/**
	 * A key is a dotted path, so a group holds structure rather than a flat list.
	 */
	public function test_a_dotted_key_reads_and_writes_a_nested_value(): void {
		$options = $this->options();
		$options->set( 'mail.from.name', 'Acme' );

		$this->assertSame( 'Acme', $options->get( 'mail.from.name' ) );
		$this->assertTrue( $options->has( 'mail.from' ) );
		$this->assertSame( array( 'name' => 'Acme' ), $options->get( 'mail.from' ) );

		$options->save();

		$this->assertSame(
			array( 'mail' => array( 'from' => array( 'name' => 'Acme' ) ) ),
			get_option( self::UNGROUPED_KEY ),
			'The nesting reaches the database as written.'
		);

		$options->delete( 'mail.from.name' );
		$this->assertFalse( $options->has( 'mail.from.name' ) );
		$this->assertTrue( $options->has( 'mail.from' ), 'Only the leaf is removed.' );
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

	public function test_ungrouped_option_is_stored_under_the_namespaced_key(): void {
		$options = $this->options();
		$options->set( 'k', 'v' );
		$options->save();

		$this->assertNotFalse( get_option( self::UNGROUPED_KEY ), 'Stored under {slug}__options_.' );
		$this->assertFalse( get_option( 'zestry-test' ), 'Not stored under the bare slug.' );
	}

	/**
	 * The plugin's own settings are what a request reads if it reads anything,
	 * so the ungrouped row autoloads without being asked.
	 *
	 * `auto-on` rather than `on`: the module is choosing from where the settings
	 * live, not relaying a decision the consumer made about this row, and only
	 * the `auto-` values let core reconsider under its own size limits.
	 */
	public function test_the_default_group_autoloads_as_auto_on(): void {
		$options = $this->options();
		$options->set( 'k', 'v' );
		$options->save();

		$this->assertSame( 'auto-on', $this->autoload_flag( self::UNGROUPED_KEY ) );
	}

	/**
	 * A group exists to be read by fewer requests than the default row, so it is
	 * marked not-autoloaded rather than left for core to guess at.
	 */
	public function test_a_group_does_not_autoload_by_default(): void {
		$options = $this->options();

		$api = $options->group( 'api' );
		$api->set( 'k', 'v' );
		$api->save();

		$this->assertSame( 'auto-off', $this->autoload_flag( self::GROUP_API_KEY ) );
	}

	public function test_a_group_can_opt_into_autoloading(): void {
		$options = $this->options();
		$options->add_autoloaded_groups( array( 'flag' ) );

		$flag = $options->group( 'flag' );
		$flag->set( 'k', 'v' );
		$flag->save();

		$this->assertSame( 'auto-on', $this->autoload_flag( 'zestry-test_flag' ) );

		delete_option( 'zestry-test_flag' );
	}

	/**
	 * The filter `write()` installs answers for its own option only -- answering
	 * for someone else's would move their row.
	 */
	public function test_saving_does_not_change_another_plugins_autoload_value(): void {
		add_option( 'zestry_test_foreign', 'x', '', false );

		$options = $this->options();
		$options->set( 'k', 'v' );
		$options->save();

		$this->assertSame( 'off', $this->autoload_flag( 'zestry_test_foreign' ) );

		delete_option( 'zestry_test_foreign' );
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
