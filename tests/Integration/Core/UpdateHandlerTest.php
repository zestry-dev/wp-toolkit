<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Abstracts\UpdateHandler;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\Options;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * UpdateHandler: noticing that the running code is a different version from the
 * one recorded, and what happens when the work it triggers fails.
 *
 * @covers \Zestry\WPToolkit\Kernel\Abstracts\UpdateHandler
 */
final class UpdateHandlerTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		SpyUpdate::reset();

		// A listener on the plugin's log hook, so the deliberate failures below
		// report there instead of falling back to error_log() and filling the
		// test output with expected errors.
		add_action( 'zestry-update-log', '__return_null' );
	}

	public function tear_down(): void {
		remove_action( 'zestry-update-log', '__return_null' );

		SeamUpdate::$calls = array();
		delete_option( 'zestry-update_update_' );

		delete_option( 'zestry-update_version' );
		delete_option( 'zestry-update_update_error' );
		delete_transient( 'zestry-update_version_updating' );

		parent::tear_down();
	}

	public function test_a_site_with_no_recorded_version_updates_from_null(): void {
		$this->boot_at( '1.0.0' );

		$this->assertSame( array( array( null, '1.0.0' ) ), SpyUpdate::$calls );
		$this->assertSame( '1.0.0', get_option( 'zestry-update_version' ) );
	}

	public function test_nothing_runs_again_while_the_version_is_unchanged(): void {
		$this->boot_at( '1.0.0' );
		SpyUpdate::$calls = array();

		$this->boot_at( '1.0.0' );

		$this->assertSame( array(), SpyUpdate::$calls, 'The same code running again is not an update.' );
	}

	public function test_a_changed_version_runs_the_handler_with_the_previous_one(): void {
		$this->boot_at( '1.0.0' );
		SpyUpdate::$calls = array();

		// The file on disk is what changed -- by an updater, by WP-CLI, or by
		// somebody dropping it in over FTP. Nothing here can tell which.
		$this->boot_at( '2.1.0' );

		$this->assertSame( array( array( '1.0.0', '2.1.0' ) ), SpyUpdate::$calls );
		$this->assertSame( '2.1.0', get_option( 'zestry-update_version' ) );
	}

	public function test_a_rollback_does_not_run(): void {
		$this->boot_at( '2.0.0' );
		SpyUpdate::$calls = array();

		$this->boot_at( '1.0.0' );

		$this->assertSame(
			array(),
			SpyUpdate::$calls,
			'The recorded version stays ahead until a release passes it again.'
		);
	}

	public function test_the_gate_can_be_widened_past_the_version(): void {
		$this->boot_at( '1.0.0' );
		SpyUpdate::$calls = array();

		// A version says whether the *code* changed. A migration added without a
		// version bump -- routine while developing -- leaves the schema behind
		// while the version says there is nothing to do, so should_update() is
		// overridable to ask the other question too.
		SpyUpdate::$force = true;
		$this->boot_at( '1.0.0' );

		$this->assertSame(
			array( array( '1.0.0', '1.0.0' ) ),
			SpyUpdate::$calls,
			'The same version, and it still runs, because the handler said there was work.'
		);
	}

	public function test_versions_compare_on_version_compares_terms(): void {
		// version_compare() reads these two as the same version, so nothing runs.
		// Override should_update() to decide otherwise.
		$this->boot_at( '1.0.0-beta1' );
		SpyUpdate::$calls = array();

		$this->boot_at( '1.0.0-beta.1' );

		$this->assertSame( array(), SpyUpdate::$calls );
	}

	public function test_a_real_change_still_counts_when_both_carry_metadata(): void {
		$this->boot_at( '1.0.0+build.71' );
		SpyUpdate::$calls = array();

		$this->boot_at( '1.0.1+build.71' );

		$this->assertSame( array( array( '1.0.0+build.71', '1.0.1+build.71' ) ), SpyUpdate::$calls );
	}

	public function test_a_plugin_with_no_version_header_is_left_alone(): void {
		file_put_contents( $this->entry_file, "<?php\n/*\nPlugin Name: Zestry Test\n*/\n" );

		$plugin = ( new Plugin( $this->entry_file, 'zestry-update' ) )->declare_multiple( array( SpyUpdate::class ) );
		$plugin->get( SpyUpdate::class );

		$this->assertSame( array(), SpyUpdate::$calls );
		$this->assertFalse( get_option( 'zestry-update_version' ), 'Recording nothing beats recording an absence.' );
	}

	public function test_a_returned_false_leaves_the_version_unrecorded(): void {
		SpyUpdate::$result = false;

		$this->boot_at( '1.0.0' );

		$this->assertFalse(
			get_option( 'zestry-update_version' ),
			'An update that did not finish is one this site still needs.'
		);
		$this->assertSame( 'The update handler returned false.', $this->handler()->get_last_error() );
	}

	public function test_a_returned_wp_error_is_what_the_administrator_is_shown(): void {
		SpyUpdate::$result = new \WP_Error( 'db_unavailable', 'The database refused the connection.' );

		$this->boot_at( '1.0.0' );

		$this->assertSame( 'The database refused the connection.', $this->handler()->get_last_error() );
	}

	public function test_a_thrown_exception_is_caught_rather_than_taking_the_admin_down(): void {
		SpyUpdate::$throw = new \RuntimeException( 'the migration blew up' );

		// No expectException: an update that fatals on every admin request locks
		// an administrator out of the screen they would fix it from.
		$this->boot_at( '1.0.0' );

		$this->assertSame( 'the migration blew up', $this->handler()->get_last_error() );
		$this->assertFalse( get_option( 'zestry-update_version' ) );
	}

	public function test_a_failure_leaves_the_lock_so_the_retry_is_throttled(): void {
		SpyUpdate::$result = false;

		$this->boot_at( '1.0.0' );

		$this->assertNotFalse(
			get_transient( 'zestry-update_version_updating' ),
			'The concurrency guard becomes the retry interval while an update is failing.'
		);

		SpyUpdate::$calls = array();
		$this->boot_at( '1.0.0' );

		$this->assertSame( array(), SpyUpdate::$calls, 'A broken update must not re-run on every admin request.' );
	}

	public function test_a_succeeding_update_clears_an_earlier_failure(): void {
		SpyUpdate::$result = false;
		$this->boot_at( '1.0.0' );

		$this->assertNotNull( $this->handler()->get_last_error() );

		// Whatever was wrong is fixed, and the throttle window has passed.
		delete_transient( 'zestry-update_version_updating' );
		SpyUpdate::$result = true;
		$this->boot_at( '1.0.0' );

		$this->assertNull( $this->handler()->get_last_error() );
		$this->assertSame( '1.0.0', get_option( 'zestry-update_version' ) );
	}

	public function test_the_failure_reaches_an_admin_screen(): void {
		SpyUpdate::$result = new \WP_Error( 'nope', 'Could not write the table.' );
		$this->boot_at( '1.0.0' );

		ob_start();
		$this->handler()->render_notice_now();
		$notice = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'Could not write the table.', $notice );
		$this->assertStringContainsString( 'Zestry Test could not finish updating.', $notice );
	}

	public function test_a_failure_message_is_escaped_before_it_is_printed(): void {
		SpyUpdate::$result = new \WP_Error( 'nope', '<script>alert(1)</script>' );
		$this->boot_at( '1.0.0' );

		ob_start();
		$this->handler()->render_notice_now();
		$notice = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $notice );
		$this->assertStringContainsString( '&lt;script&gt;', $notice );
	}

	public function test_building_the_module_is_the_check(): void {
		file_put_contents( $this->entry_file, "<?php\n/*\nPlugin Name: Zestry Test\nVersion: 1.0.0\n*/\n" );

		$plugin = ( new Plugin( $this->entry_file, 'zestry-update' ) )->declare_multiple( array( SpyUpdate::class ) );

		$this->assertSame( array(), SpyUpdate::$calls, 'Declared is not built.' );

		$plugin->get( SpyUpdate::class );

		// No hook of its own: whatever heading bootstrap.php lists it under is
		// when this happens, which is the same rule every other module follows.
		$this->assertSame( array( array( null, '1.0.0' ) ), SpyUpdate::$calls );
	}

	public function test_storage_can_be_moved_onto_the_options_module(): void {
		// The seam the class docblock documents: the kernel cannot reach a module
		// it may not have been installed with, so a plugin that does have one
		// points the four methods at it.
		file_put_contents( $this->entry_file, "<?php\n/*\nPlugin Name: Zestry Test\nVersion: 3.0.0\n*/\n" );

		$plugin = ( new Plugin( $this->entry_file, 'zestry-update' ) )
			->declare_multiple( array( SeamUpdate::class, Options::class ) );

		$plugin->get( SeamUpdate::class );

		$this->assertSame( array( array( null, '3.0.0' ) ), SeamUpdate::$calls );
		$this->assertSame(
			'3.0.0',
			$plugin->get( Options::class )->group( '_update_' )->get( 'version' ),
			'The override decides where the version lands.'
		);
		$this->assertFalse(
			get_option( 'zestry-update_version' ),
			'And the default row is never written once it has been overridden.'
		);
	}

	public function test_update_site_brings_one_site_of_a_network_up_to_date(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only; run the suite with WP_MULTISITE=1.' );
		}

		$site_id = (int) self::factory()->blog->create();

		file_put_contents( $this->entry_file, "<?php\n/*\nPlugin Name: Zestry Test\nVersion: 4.0.0\n*/\n" );

		// Building the module checks the site it is built on, so this brings the
		// current one up to date first -- leaving update_site() as the only thing
		// that can account for the call below.
		$plugin  = ( new Plugin( $this->entry_file, 'zestry-update' ) )->declare_multiple( array( SpyUpdate::class ) );
		$handler = $plugin->get( SpyUpdate::class );

		$this->assertSame( '4.0.0', get_option( 'zestry-update_version' ) );

		SpyUpdate::$calls = array();
		$handler->update_site( $site_id );

		// The record is per site, which is the whole reason a network needs a
		// lever like this rather than one loop at activation: the new site was
		// not up to date just because the one beside it was.
		switch_to_blog( $site_id );
		$recorded = get_option( 'zestry-update_version' );
		restore_current_blog();

		$this->assertSame(
			array( array( null, '4.0.0' ) ),
			SpyUpdate::$calls,
			'One call, for the site it was given.'
		);
		$this->assertSame( '4.0.0', $recorded );

		wp_delete_site( $site_id );
	}

	/**
	 * The booted handler, for reading what the last run recorded.
	 *
	 * @return SpyUpdate
	 */
	private function handler(): SpyUpdate {
		return $this->plugin_instance->get( SpyUpdate::class );
	}

	/**
	 * Write the entry file at a version, build the plugin, and fire the hook.
	 *
	 * Its own slug, so this class's version option can never be read by another
	 * test class.
	 *
	 * @param string $version The `Version:` header to write.
	 * @return void
	 */
	private function boot_at( string $version ): void {
		file_put_contents( $this->entry_file, "<?php\n/*\nPlugin Name: Zestry Test\nVersion: {$version}\n*/\n" );

		$this->plugin_instance = ( new Plugin( $this->entry_file, 'zestry-update' ) )
			->declare_multiple( array( SpyUpdate::class ) );

		// Building it is the check: the module's bootstrap heading is its timing,
		// so on_boot() runs maybe_update() rather than binding it anywhere.
		$this->plugin_instance->get( SpyUpdate::class );
	}

	/**
	 * The plugin built by the last boot_at() call.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin_instance;
}

/**
 * Records what it was called with, and fails however the test asks it to.
 */
class SpyUpdate extends UpdateHandler {

	/**
	 * Every call, as [ previous, current ] pairs.
	 *
	 * @var array<int, array{0: string|null, 1: string}>
	 */
	public static array $calls = array();

	/**
	 * What update() returns, when it is not throwing.
	 *
	 * @var bool|\WP_Error
	 */
	public static bool|\WP_Error $result = true;

	/**
	 * What update() throws, when the test wants it to.
	 *
	 * @var \Throwable|null
	 */
	public static ?\Throwable $throw = null;

	/**
	 * Stands in for "a migration is pending", the reason to widen the gate.
	 *
	 * @var bool
	 */
	public static bool $force = false;

	public static function reset(): void {
		self::$calls  = array();
		self::$result = true;
		self::$throw  = null;
		self::$force  = false;
	}

	public function update( ?string $previous, string $current ): bool|\WP_Error {
		self::$calls[] = array( $previous, $current );

		if ( null !== self::$throw ) {
			throw self::$throw;
		}

		return self::$result;
	}

	/**
	 * Render the notice without an admin request to hang it on.
	 *
	 * @return void
	 */
	public function render_notice_now(): void {
		$this->render_failure_notice();
	}

	protected function should_update( ?string $previous, string $current ): bool {
		return parent::should_update( $previous, $current ) || self::$force;
	}
}

/**
 * Keeps its state in the Options module instead of two option rows.
 */
class SeamUpdate extends UpdateHandler {

	/**
	 * Every call, as [ previous, current ] pairs.
	 *
	 * @var array<int, array{0: string|null, 1: string}>
	 */
	public static array $calls = array();

	public function update( ?string $previous, string $current ): bool|\WP_Error {
		self::$calls[] = array( $previous, $current );

		return true;
	}

	public function get_recorded_version(): ?string {
		return $this->with( Options::class )->group( '_update_' )->get( 'version' );
	}

	public function get_last_error(): ?string {
		return $this->with( Options::class )->group( '_update_' )->get( 'error' );
	}

	protected function record_version( string $version ): void {
		$options = $this->with( Options::class )->group( '_update_' );

		$options->set( 'version', $version );
		$options->save();
	}

	protected function record_error( ?string $message ): void {
		$options = $this->with( Options::class )->group( '_update_' );

		$options->set( 'error', $message );
		$options->save();
	}
}
