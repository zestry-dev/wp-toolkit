<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\Manifest;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `wp zt update`: taking a later toolkit without losing local work.
 *
 * Runs against a throwaway plugin under WP_PLUGIN_DIR that has really had `add`
 * copy a module into it, so the files, the manifest and the registry all agree
 * the way they would in a real plugin. An "upstream change" is then simulated by
 * rewriting the recorded hash -- the manifest is the only record of what was
 * copied, so a wrong hash there is indistinguishable from the toolkit having
 * moved on, which is exactly the condition under test.
 *
 * @covers \Zestry\WPToolkit\DevTools\Manifest
 */
final class UpdateCommandTest extends TestCase {

	private string $target_plugin_dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->target_plugin_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/zestry-update-test-' . uniqid();
		mkdir( $this->target_plugin_dir, 0777, true );

		file_put_contents(
			$this->target_plugin_dir . '/zestry.json',
			(string) json_encode(
				array(
					'namespace'   => 'Acme\\Plugin',
					'root'        => 'lib',
					'text_domain' => 'acme-plugin',
				),
				JSON_PRETTY_PRINT
			)
		);

		$this->run_command( 'add.php', array( 'path' ), array() );
	}

	public function tear_down(): void {
		$this->remove_dir( $this->target_plugin_dir );
		parent::tear_down();
	}

	/**
	 * `add` records what it copied, or nothing downstream can compare anything.
	 */
	public function test_adding_a_service_records_it_in_the_manifest(): void {
		$this->assertFileExists( $this->target_plugin_dir . '/zestry.lock.json' );

		$files = $this->read_manifest()['files'];
		$this->assertArrayHasKey( 'lib/Core/Modules/Path.php', $files );
	}

	public function test_a_freshly_added_plugin_is_already_up_to_date(): void {
		$this->run_command( 'update.php', array(), array( 'yes' => true ) );

		$this->assertStringContainsString( 'Everything matches the installed toolkit.', $this->stdout() );
		$this->assertStringContainsString( 'Already up to date', (string) \WP_CLI::last( 'success' )[0] );
	}

	public function test_an_upstream_change_is_taken(): void {
		$this->pretend_upstream_changed( 'lib/Core/Modules/Path.php' );

		$this->run_command( 'update.php', array(), array( 'yes' => true ) );

		$this->assertStringContainsString( '1 file to update', $this->stdout() );
		$this->assertStringContainsString( 'Updated 1 file', (string) \WP_CLI::last( 'success' )[0] );

		// Back to what the toolkit writes, and recorded as such -- so a second
		// run has nothing left to do.
		$this->assertSame(
			Manifest::UNCHANGED,
			$this->compare()['lib/Core/Modules/Path.php'],
			'The manifest describes the tree as it now stands.'
		);
	}

	/**
	 * The file the whole manifest exists for: work an overwrite would destroy,
	 * with no upstream change to gain by destroying it.
	 */
	public function test_a_locally_edited_file_is_kept_and_named(): void {
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php', '<?php // hand-edited' );

		$this->run_command( 'update.php', array(), array( 'yes' => true ) );

		$this->assertStringContainsString( '1 you have edited', $this->stdout() );
		$this->assertStringContainsString( 'edited    lib/Core/Modules/Path.php', $this->stdout() );
		$this->assertStringContainsString(
			'hand-edited',
			(string) file_get_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' )
		);
	}

	public function test_a_conflict_is_reported_and_still_kept(): void {
		$this->pretend_upstream_changed( 'lib/Core/Modules/Path.php' );
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php', '<?php // hand-edited' );

		$this->run_command( 'update.php', array(), array( 'yes' => true ) );

		$this->assertStringContainsString( '1 conflicted', $this->stdout() );
		$this->assertStringContainsString( 'conflict  lib/Core/Modules/Path.php', $this->stdout() );
		$this->assertStringContainsString(
			'hand-edited',
			(string) file_get_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' ),
			'Kept without --force, since a conflict is the consumer\'s decision.'
		);
	}

	public function test_force_replaces_an_edited_file(): void {
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php', '<?php // hand-edited' );

		$this->run_command(
			'update.php',
			array(),
			array(
				'yes'   => true,
				'force' => true,
			)
		);

		$contents = (string) file_get_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' );
		$this->assertStringNotContainsString( 'hand-edited', $contents );
		$this->assertStringContainsString( 'namespace Acme\\Plugin\\Core\\Modules;', $contents );
	}

	public function test_a_dry_run_reports_without_writing(): void {
		$this->pretend_upstream_changed( 'lib/Core/Modules/Path.php' );
		$before = (string) file_get_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' );

		$this->run_command( 'update.php', array(), array( 'dry-run' => true ) );

		$this->assertStringContainsString( '1 file to update', $this->stdout() );
		$this->assertStringContainsString( 'Dry run; nothing written', (string) \WP_CLI::last( 'success' )[0] );
		$this->assertSame(
			$before,
			(string) file_get_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' )
		);
	}

	/**
	 * A plugin initialized before manifests existed still has to be updatable;
	 * it just cannot be told which side of a difference moved.
	 */
	public function test_without_a_manifest_it_says_so_rather_than_refusing(): void {
		unlink( $this->target_plugin_dir . '/zestry.lock.json' );

		$this->run_command( 'update.php', array(), array( 'dry-run' => true ) );

		$this->assertNotNull( \WP_CLI::last( 'warning' ) );
		$this->assertStringContainsString( 'No zestry.lock.json', (string) \WP_CLI::last( 'warning' )[0] );
		$this->assertNotNull( \WP_CLI::last( 'success' ) );
	}

	/**
	 * Nothing outside `Core/` is the toolkit's to replace.
	 */
	public function test_the_consumers_own_modules_are_never_touched(): void {
		wp_mkdir_p( $this->target_plugin_dir . '/lib/Modules' );
		file_put_contents( $this->target_plugin_dir . '/lib/Modules/Shortcode.php', '<?php // mine' );

		$this->run_command( 'update.php', array(), array( 'yes' => true ) );

		$this->assertSame(
			'<?php // mine',
			(string) file_get_contents( $this->target_plugin_dir . '/lib/Modules/Shortcode.php' )
		);
		$this->assertArrayNotHasKey( 'lib/Modules/Shortcode.php', $this->read_manifest()['files'] );
	}

	/**
	 * What the run says it will change and what it says it changed are one piece
	 * of work, so they say the same number. This has been wrong in both
	 * directions: counting files a deleted module made unwritable, and counting
	 * every file the tree copy touched.
	 */
	public function test_the_prompt_and_the_result_agree(): void {
		$this->pretend_upstream_changed( 'lib/Core/Modules/Path.php' );

		$this->run_command( 'update.php', array(), array( 'yes' => true ) );

		$this->assertStringContainsString( '1 file to update', $this->stdout() );
		$this->assertStringContainsString( 'Updated 1 file.', (string) \WP_CLI::last( 'success' )[0] );
	}

	/**
	 * And the number is what changed, not the size of the tree that was walked
	 * to change it -- the copy rewrites whole directories on purpose.
	 */
	public function test_the_count_is_not_the_size_of_the_tree(): void {
		$this->run_command( 'add.php', array( 'ajax' ), array() );
		$this->pretend_upstream_changed( 'lib/Core/Modules/Path.php' );

		\WP_CLI::reset();
		$this->run_command( 'update.php', array(), array( 'yes' => true ) );

		$copied = count( $this->read_manifest()['files'] );

		$this->assertGreaterThan( 5, $copied, 'The tree is big enough for the two numbers to differ.' );
		$this->assertStringContainsString( 'Updated 1 file.', (string) \WP_CLI::last( 'success' )[0] );
	}

	/**
	 * There is no `remove module`, so taking one out is a directory delete —
	 * after which an update copies what the plugin has, and this module is not
	 * it. Saying "missing" would promise a write that cannot happen.
	 */
	public function test_a_deleted_module_is_reported_as_removed_rather_than_missing(): void {
		$this->run_command( 'add.php', array( 'ajax' ), array() );
		$this->remove_dir( $this->target_plugin_dir . '/lib/Core/Modules/Ajax' );

		$this->run_command( 'update.php', array(), array( 'dry-run' => true ) );

		$this->assertStringContainsString( 'removed with the "ajax" module', $this->stdout() );
		$this->assertStringContainsString( 'wp zt add ajax', $this->stdout() );
		$this->assertStringNotContainsString( 'missing', $this->stdout() );
	}

	/**
	 * And the lock catches up, so the same phantom is not reported for as long
	 * as the plugin exists.
	 */
	public function test_a_deleted_module_is_dropped_from_the_lock(): void {
		$this->run_command( 'add.php', array( 'ajax' ), array() );
		$this->remove_dir( $this->target_plugin_dir . '/lib/Core/Modules/Ajax' );

		$this->run_command( 'update.php', array(), array( 'dry-run' => true ) );

		foreach ( array_keys( $this->read_manifest()['files'] ) as $recorded ) {
			$this->assertStringNotContainsString( 'Modules/Ajax', $recorded );
		}

		\WP_CLI::reset();
		$this->run_command( 'update.php', array(), array( 'dry-run' => true ) );

		$this->assertStringNotContainsString( 'removed', $this->stdout(), 'Reported once, then gone.' );
	}

	/**
	 * A file deleted from a module that is still installed is genuinely missing,
	 * and an update does write it back.
	 */
	public function test_a_deleted_file_inside_an_installed_module_is_restored(): void {
		$this->run_command( 'add.php', array( 'ajax' ), array() );
		unlink( $this->target_plugin_dir . '/lib/Core/Modules/Ajax/AjaxAction.php' );

		\WP_CLI::reset();
		$this->run_command( 'update.php', array(), array( 'yes' => true ) );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/Ajax/AjaxAction.php' );
	}

	/**
	 * The count is what was written, not what was planned — the two differ
	 * exactly when a copy cannot happen, which is when a wrong number is worst.
	 */
	public function test_the_count_reports_what_was_written(): void {
		$this->run_command( 'add.php', array( 'ajax' ), array() );
		$this->remove_dir( $this->target_plugin_dir . '/lib/Core/Modules/Ajax' );

		\WP_CLI::reset();
		$this->run_command( 'update.php', array(), array( 'yes' => true ) );

		$success = \WP_CLI::last( 'success' );

		$this->assertNotNull( $success );
		$this->assertStringNotContainsString( 'Updated 2 files', (string) $success[0] );
	}

	/**
	 * `overwrite` warns by module name, which says how much is at stake only if
	 * you already remember what you changed. The manifest knows, so it names the
	 * files -- and says nothing extra when a module was never touched.
	 */
	public function test_overwrite_names_the_edited_files_it_would_discard(): void {
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php', '<?php // hand-edited' );

		$this->run_command( 'overwrite.php', array( 'path' ), array( 'yes' => true ) );

		$this->assertStringContainsString( 'edited  lib/Core/Modules/Path.php', $this->stdout() );
	}

	public function test_overwrite_says_nothing_extra_when_nothing_was_edited(): void {
		$this->run_command( 'overwrite.php', array( 'path' ), array( 'yes' => true ) );

		$this->assertStringNotContainsString( 'edited', $this->stdout() );
		$this->assertNotNull( \WP_CLI::last( 'success' ) );
	}

	/**
	 * Rewrite a recorded hash so the file reads as changed upstream.
	 *
	 * The manifest is the only record of what was copied, so a hash that does
	 * not match what the toolkit now renders is precisely the upstream-moved
	 * condition -- no second checkout of this package needed to produce it.
	 *
	 * @param string $relative Plugin-relative path.
	 * @return void
	 */
	private function pretend_upstream_changed( string $relative ): void {
		$manifest = $this->read_manifest();

		$manifest['files'][ $relative ] = hash( 'sha256', 'what an earlier release wrote' );

		file_put_contents(
			$this->target_plugin_dir . '/zestry.lock.json',
			(string) json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		// The disk has to match the record, or the file reads as edited too.
		file_put_contents( $this->target_plugin_dir . '/' . $relative, 'what an earlier release wrote' );
	}

	/**
	 * The manifest as JSON.
	 *
	 * @return array{version: string|null, files: array<string, string>}
	 */
	private function read_manifest(): array {
		return (array) json_decode( (string) file_get_contents( $this->target_plugin_dir . '/zestry.lock.json' ), true );
	}

	/**
	 * Classify the copied tree as `update` would, for asserting on afterwards.
	 *
	 * @return array<string, string>
	 */
	private function compare(): array {
		$plugin  = ( new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-update-test' ) )->declare_multiple( $this->get_toolkit_modules() );
		$copier  = $plugin->get( \Zestry\WPToolkit\DevTools\Copier::class );
		$rendered = $copier->render_directory(
			dirname( __DIR__, 3 ) . '/src/Modules',
			$this->target_plugin_dir . '/lib/Core/Modules',
			'Acme\\Plugin\\Core',
			'acme-plugin'
		);

		$relative = array();
		$root     = $this->target_plugin_dir . '/';

		foreach ( $rendered as $absolute => $hash ) {
			$relative[ substr( $absolute, strlen( $root ) ) ] = $hash;
		}

		return $plugin->get( Manifest::class )->compare( $this->target_plugin_dir, $relative );
	}

	/**
	 * Run one devtool command against the throwaway plugin.
	 *
	 * @param string               $file       Path under `commands/`.
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	private function run_command( string $file, array $args, array $assoc_args ): void {
		\WP_CLI::reset();

		$package_plugin = ( new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-update-test' ) )->declare_multiple( $this->get_toolkit_modules() );

		/** @var Command $command */
		$command = require dirname( __DIR__, 3 ) . '/commands/' . $file;
		$package_plugin->wire( $command );
		$command->set_arguments( $args, $assoc_args );

		$previous_cwd = (string) getcwd();
		chdir( $this->target_plugin_dir );

		try {
			$command->handle( $args, $assoc_args );
		} finally {
			chdir( $previous_cwd );
		}
	}

	/**
	 * Everything the run wrote to STDOUT, as one string.
	 *
	 * @return string
	 */
	private function stdout(): string {
		$lines = array();

		foreach ( \WP_CLI::$calls as $call ) {
			if ( 'log' === $call[0] ) {
				$lines[] = (string) $call[1];
			}
		}

		return implode( "\n", $lines );
	}
}
