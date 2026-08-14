<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\DevTools\Abstracts\AddCommand;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `wp zt overwrite <module>...`: the batch warning, single confirmation, and
 * cancel-leaves-nothing-copied behavior.
 *
 * Exercises a local subclass of AddCommand replicating commands/overwrite.php
 * exactly (rather than requiring that file directly), so read_line() can be
 * scripted the same way CommandTest.php does for Command::confirm() -- an
 * anonymous class instance returned by `require` cannot have a test-only
 * override applied to it after the fact. Path resolves toolkit source against
 * this package's own real src/Core/Modules/ (this repository doubles as the
 * zestry-dev/wp-toolkit package root), while ConsumerPlugin's target plugin root
 * is a throwaway directory under WP_PLUGIN_DIR, matching AddCommandTest.
 *
 * @covers \Zestry\WPToolkit\DevTools\Abstracts\AddCommand
 */
final class OverwriteCommandTest extends TestCase {

	private string $target_plugin_dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->target_plugin_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/zestry-overwrite-test-' . uniqid();
		mkdir( $this->target_plugin_dir, 0777, true );

		file_put_contents(
			$this->target_plugin_dir . '/zestry.json',
			json_encode( array( 'namespace' => 'Acme\\Plugin', 'root' => 'lib' ), JSON_PRETTY_PRINT )
		);

		$this->write_entry_file();
	}

	/**
	 * Write the fixture plugin's entry file.
	 *
	 * A plugin is a directory with a file declaring one, and `Requires at least:`
	 * is what the version checks read. Declared high enough that nothing in the
	 * registry is out of reach here.
	 *
	 * @return void
	 */
	private function write_entry_file(): void {
		file_put_contents(
			$this->target_plugin_dir . '/acme-plugin.php',
			"<?php\n/**\n * Plugin Name: Acme Plugin\n * Requires at least: 7.1\n */\n"
		);
	}

	public function tear_down(): void {
		$this->remove_dir( $this->target_plugin_dir );
		parent::tear_down();
	}

	public function test_overwrites_an_already_present_module_after_confirmation(): void {
		mkdir( $this->target_plugin_dir . '/lib/Core/Modules', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php', '<?php // hand-edited' );

		$this->run_overwrite( array( 'path' ), "y\n", 'services' );

		$contents = (string) file_get_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' );
		$this->assertStringNotContainsString( 'hand-edited', $contents );
		$this->assertStringContainsString( 'namespace Acme\\Plugin', $contents );

		$this->assertContains( 'Overwrote path', $this->logged_messages() );
		$this->assertNotNull( \WP_CLI::last( 'success' ) );
	}

	public function test_warns_listing_every_already_present_module_before_confirming(): void {
		mkdir( $this->target_plugin_dir . '/lib/Core/Modules', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php', '<?php // hand-edited' );

		$this->run_overwrite( array( 'rest-api' ), "y\n" );

		$this->assertNotNull( \WP_CLI::last( 'warning' ) );
		$this->assertStringContainsString( 'overwrite existing files for: path', (string) \WP_CLI::last( 'warning' )[0] );
	}

	public function test_declining_cancels_and_copies_nothing_including_new_dependencies(): void {
		mkdir( $this->target_plugin_dir . '/lib/Core/Modules', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php', '<?php // hand-edited' );

		$this->run_overwrite( array( 'rest-api' ), "n\n" );

		// Path is untouched, and RestApi -- not previously present at all -- is
		// still not copied, since declining cancels the whole batch.
		$this->assertSame(
			'<?php // hand-edited',
			file_get_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' )
		);
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/lib/Core/Modules/RestApi/RestApi.php' );

		$this->assertContains( 'Cancelled.', $this->logged_messages() );
		$this->assertNull( \WP_CLI::last( 'success' ) );
	}

	public function test_copies_a_module_not_previously_present_without_prompting(): void {
		// No already-present modules in this batch, so filter_existing_modules()
		// is never called and no confirmation is needed.
		$this->run_overwrite( array( 'path' ), false, 'services' );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' );
		$this->assertNull( \WP_CLI::last( 'warning' ) );
		$this->assertNotNull( \WP_CLI::last( 'success' ) );
	}

	/**
	 * Every message passed to \WP_CLI::log() during the last run_overwrite() call.
	 *
	 * @return string[]
	 */
	private function logged_messages(): array {
		$messages = array();
		foreach ( \WP_CLI::$calls as $call ) {
			if ( 'log' === $call[0] ) {
				$messages[] = $call[1];
			}
		}
		return $messages;
	}

	/**
	 * Build a subclass of AddCommand replicating commands/overwrite.php, with
	 * read_line() scripted to return $confirm_input, and invoke handle() with
	 * the CWD inside the throwaway target plugin directory.
	 *
	 * @param string[]     $modules      Module names to pass as positional args.
	 * @param string|false $confirm_input What read_line() should return when confirm() is reached.
	 * @return void
	 */
	private function run_overwrite( array $modules, $confirm_input, string $kind = 'modules' ): void {
		\WP_CLI::reset();

		$package_plugin = ( new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-overwrite-test' ) )->declare_multiple( $this->get_toolkit_modules() );

		$command = new class extends AddCommand {
			/** @var string|false */
			public $scripted_input = false;

			/**
			 * Which subcommand this stands in for, set per test.
			 *
			 * `overwrite` is two subcommands now, and these tests drive both --
			 * `path` through `overwrite service`, `rest-api` through
			 * `overwrite module`.
			 *
			 * @var string
			 */
			public static string $kind = 'modules';

			public function handle( array $args, array $assoc_args ): void {
				parent::handle( $args, $assoc_args );
			}

			protected function filter_existing_modules( array $existing_names, array $destinations, array &$to_copy ): bool {
				$this->warning( 'This will overwrite existing files for: ' . implode( ', ', $existing_names ) );

				if ( ! $this->confirm( 'Any local changes to these files will be lost. Continue?', false ) ) {
					$this->log( 'Cancelled.' );
					return true;
				}

				return false;
			}

			protected static function get_word(): string {
				return 'overwrite';
			}

			protected static function get_kind(): string {
				return static::$kind;
			}

			protected static function get_past_tense(): string {
				return 'Overwrote';
			}

			protected function read_line() {
				return $this->scripted_input;
			}
		};

		$command::$kind          = $kind;
		$command->scripted_input = $confirm_input;
		$package_plugin->wire( $command );

		$previous_cwd = (string) getcwd();
		chdir( $this->target_plugin_dir );

		try {
			$command->handle( $modules, array() );
		} finally {
			chdir( $previous_cwd );
		}
	}
}
