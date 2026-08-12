<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `wp zt add <module>...`: dependency resolution, copying, and the
 * already-present skip guard.
 *
 * Exercises the real commands/add/module.php file. Path resolves toolkit source
 * against this package's own real src/Core/Modules/ (this repository doubles as
 * the zestry-dev/wp-toolkit package root), while ConsumerPlugin's target plugin
 * root is a throwaway directory under WP_PLUGIN_DIR, matching the pattern
 * established by MakeCommandTest/ConsumerPluginTest for the same real-CWD
 * requirement.
 *
 * @covers \Zestry\WPToolkit\DevTools\Copier
 */
final class AddCommandTest extends TestCase {

	private string $target_plugin_dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->target_plugin_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/zestry-add-test-' . uniqid();
		mkdir( $this->target_plugin_dir, 0777, true );

		file_put_contents(
			$this->target_plugin_dir . '/zestry.json',
			json_encode( array( 'namespace' => 'Acme\\Plugin', 'root' => 'lib' ), JSON_PRETTY_PRINT )
		);
	}

	public function tear_down(): void {
		$this->remove_dir( $this->target_plugin_dir );
		parent::tear_down();
	}

	public function test_copies_a_module_with_no_dependencies(): void {
		$this->run_add( array( 'path' ), 'service' );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Services/Path.php' );
		$this->assertNotNull( \WP_CLI::last( 'success' ) );
	}

	public function test_copies_a_transitive_dependency_first(): void {
		$this->run_add( array( 'rest-api' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Services/Path.php' );
		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/RestApi/RestApi.php' );

		$this->assertContains( 'Also adding required dependencies: path, request', $this->logged_messages() );
	}

	public function test_skips_a_module_already_present_instead_of_overwriting_it(): void {
		mkdir( $this->target_plugin_dir . '/lib/Core/Services', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Services/Path.php', '<?php // hand-edited' );

		$this->run_add( array( 'path' ), 'service' );

		// The existing, hand-edited file is left untouched, not overwritten
		// with the toolkit's own copy of Path.php.
		$this->assertSame(
			'<?php // hand-edited',
			file_get_contents( $this->target_plugin_dir . '/lib/Core/Services/Path.php' )
		);

		$this->assertContains( 'Skipped path (already present)', $this->logged_messages() );
	}

	public function test_skips_only_the_already_present_module_among_several(): void {
		mkdir( $this->target_plugin_dir . '/lib/Core/Services', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Services/Path.php', '<?php // hand-edited' );

		$this->run_add( array( 'path', 'globals' ), 'service' );

		$this->assertSame(
			'<?php // hand-edited',
			file_get_contents( $this->target_plugin_dir . '/lib/Core/Services/Path.php' )
		);
		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Services/Globals.php' );
	}

	/**
	 * Adding `blocks` sets up the JavaScript build a block needs, which copying
	 * PHP cannot provide on its own.
	 */
	public function test_adding_blocks_creates_the_build_wiring(): void {
		$this->run_add( array( 'blocks' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/package.json' );
		$this->assertFileExists( $this->target_plugin_dir . '/tsconfig.json' );

		$package = json_decode( (string) file_get_contents( $this->target_plugin_dir . '/package.json' ), true );

		$this->assertStringContainsString( '--blocks-manifest', $package['scripts']['build'] );
		$this->assertStringContainsString( '--experimental-modules', $package['scripts']['build'] );
		$this->assertStringContainsString( '--webpack-copy-php', $package['scripts']['build'] );
		$this->assertArrayHasKey( '@wordpress/scripts', $package['devDependencies'] );
		$this->assertArrayHasKey( '@wordpress/interactivity', $package['devDependencies'] );

		$tsconfig = json_decode( (string) file_get_contents( $this->target_plugin_dir . '/tsconfig.json' ), true );
		$this->assertTrue(
			$tsconfig['compilerOptions']['resolveJsonModule'],
			"A block's index.tsx imports its own block.json, which needs this."
		);

		$this->assertStringContainsString(
			'build/',
			(string) file_get_contents( $this->target_plugin_dir . '/.gitignore' )
		);
	}

	/**
	 * Prettier reads the first config name it resolves and ignores the rest, so
	 * a second one under a different name is not a second opinion -- it is a
	 * file that never applies, and one that reads as configuration to everyone
	 * but Prettier. `wp zt init` writes `.prettierrc.js`; this must find it.
	 */
	public function test_adding_blocks_does_not_write_a_second_prettier_config(): void {
		file_put_contents( $this->target_plugin_dir . '/.prettierrc.js', '// mine' );

		$this->run_add( array( 'blocks' ) );

		$this->assertSame( '// mine', file_get_contents( $this->target_plugin_dir . '/.prettierrc.js' ) );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/prettier.config.mjs' );
		$this->assertContains( 'Kept your existing Prettier configuration.', $this->logged_messages() );
	}

	/**
	 * Any of the names Prettier resolves counts, not just the one `init` writes
	 * -- a consumer who chose their own is still configured.
	 */
	public function test_adding_blocks_respects_a_prettier_config_under_any_name(): void {
		file_put_contents( $this->target_plugin_dir . '/prettier.config.cjs', '// mine' );

		$this->run_add( array( 'blocks' ) );

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/.prettierrc.js' );
	}

	/**
	 * A plugin with no Prettier config gets the same file `init` writes, from
	 * the same stub, so the two commands cannot disagree about the name.
	 */
	public function test_adding_blocks_writes_the_same_prettier_config_init_does(): void {
		$this->run_add( array( 'blocks' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/.prettierrc.js' );
		$this->assertStringContainsString(
			'@wordpress/prettier-config',
			(string) file_get_contents( $this->target_plugin_dir . '/.prettierrc.js' )
		);
	}

	public function test_adding_blocks_keeps_an_existing_build_script(): void {
		file_put_contents(
			$this->target_plugin_dir . '/package.json',
			(string) json_encode( array( 'scripts' => array( 'build' => 'my-own-build' ) ) )
		);

		$this->run_add( array( 'blocks' ) );

		$package = json_decode( (string) file_get_contents( $this->target_plugin_dir . '/package.json' ), true );

		$this->assertSame( 'my-own-build', $package['scripts']['build'], 'An existing script is never replaced.' );
		$this->assertArrayHasKey( 'start', $package['scripts'], 'The scripts it had no opinion on are still added.' );
		$this->assertContains( 'Kept your existing scripts: build', $this->logged_messages() );
	}

	public function test_adding_blocks_twice_does_not_double_the_gitignore_entry(): void {
		$this->run_add( array( 'blocks' ) );
		$this->run_add( array( 'blocks' ) );

		$contents = (string) file_get_contents( $this->target_plugin_dir . '/.gitignore' );

		$this->assertSame( 1, substr_count( $contents, 'build/' ) );
	}

	public function test_adding_a_module_other_than_blocks_writes_no_build_wiring(): void {
		$this->run_add( array( 'views' ) );

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/package.json' );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/tsconfig.json' );
	}

	/**
	 * Every message passed to \WP_CLI::log() during the last run_add() call.
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
	 * Copying a module's files is half of adding it: a module does nothing
	 * until the plugin builds it, and being listed in `bootstrap.php` is what
	 * builds it -- so `add` declares what it copied.
	 */
	public function test_declares_each_copied_module_in_bootstrap(): void {
		file_put_contents( $this->target_plugin_dir . '/bootstrap.php', "<?php\n\nreturn array(\n);\n" );

		$this->run_add( array( 'rest-api' ) );

		$bootstrap = (string) file_get_contents( $this->target_plugin_dir . '/bootstrap.php' );

		// RestApi is a Module, so being listed is what builds it -- the entry
		// itself is bare, since its value would be an initializer and `add` has
		// none to supply.
		$this->assertStringContainsString( 'RestApi::class,', $bootstrap );

		// What it *can* be given is shown commented above, so the configuration
		// is discoverable without standing between the module and being built.
		// The variable name comes from the module's own @setup block, so it is
		// the one its documentation already uses -- not a lowercased class name.
		$this->assertStringContainsString(
			'// RestApi::class => static function ( RestApi $api ): void {',
			$bootstrap
		);
		$this->assertStringContainsString( "//     \$api->set_routes_root( '' );", $bootstrap );

		// Path came along as a dependency, but it is a Service: built the moment
		// something asks for it, so declaring it would do nothing.
		$this->assertStringNotContainsString( 'Path::class', $bootstrap );

		// Referenced by its short name, so the file carries the import that
		// binds it.
		$this->assertStringContainsString( 'use Acme\\Plugin\\Core\\Modules\\RestApi\\RestApi;', $bootstrap );
		$this->assertStringEndsWith( ");\n", $bootstrap, 'The array is still closed.' );

		// One tab, matching the flat array it is appended to: the commented
		// configuration sits directly above the entry it belongs to.
		$this->assertStringContainsString( "\n\t// RestApi::class =>", $bootstrap );
		$this->assertStringContainsString( "\n\tRestApi::class,", $bootstrap );
	}

	/**
	 * A module already declared keeps whatever configuration is there: adding
	 * it again must not duplicate the entry or overwrite an initializer.
	 */
	public function test_does_not_redeclare_a_module_already_in_bootstrap(): void {
		file_put_contents(
			$this->target_plugin_dir . '/bootstrap.php',
			"<?php\n\nreturn array(\n"
				. "\t\\Acme\\Plugin\\Core\\Modules\\Cron\\Cron::class => static function ( \$cron ): void {\n"
				. "\t\t\$cron->configured_by_hand();\n"
				. "\t},\n"
				. ");\n"
		);

		$this->run_add( array( 'cron' ) );

		$bootstrap = (string) file_get_contents( $this->target_plugin_dir . '/bootstrap.php' );

		$this->assertSame(
			1,
			substr_count( $bootstrap, 'Cron\\Cron::class' ),
			'Declared once, not twice.'
		);
		$this->assertStringContainsString( 'configured_by_hand', $bootstrap, 'An existing initializer survives.' );
	}

	/**
	 * With no bootstrap.php there is nothing to append to, so the entries are
	 * printed to paste rather than a file being invented.
	 */
	public function test_prints_the_entries_when_there_is_no_bootstrap_file(): void {
		// A module, because only a module is ever declared: a service is built
		// on demand, so `add path` has no entry to print.
		$this->run_add( array( 'cron' ) );

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/bootstrap.php' );

		// Printed with the configuration it would have been written with, since
		// a line pasted without its autoload key declares something different
		// from what `add` intended.
		$this->assertStringContainsString(
			"\t\\Acme\\Plugin\\Core\\Modules\\Cron\\Cron::class,",
			implode( "\n", $this->logged_messages() )
		);
		$this->assertNull(
			\WP_CLI::last( 'warning' ),
			'Having no bootstrap.php is a choice, not something to warn about.'
		);
	}

	/**
	 * The generator and the appender, against each other.
	 *
	 * Every other test here writes its own bootstrap.php, so both halves could
	 * disagree about the file's shape and still pass: the stub closed its array
	 * on the opening line, which the appender did not recognise, so the first
	 * `add` in a freshly initialised plugin copied every module and declared
	 * none of them -- reporting success either way. Asserting against the real
	 * stub is what makes that a failure.
	 */
	public function test_declares_into_the_file_init_actually_writes(): void {
		copy(
			dirname( __DIR__, 3 ) . '/src/DevTools/stubs/bootstrap.php.stub',
			$this->target_plugin_dir . '/bootstrap.php'
		);

		$this->run_add( array( 'cron' ) );

		$bootstrap = (string) file_get_contents( $this->target_plugin_dir . '/bootstrap.php' );

		$this->assertStringContainsString( 'Cron::class,', $bootstrap );
		$this->assertStringContainsString( 'use Acme\\Plugin\\Core\\Modules\\Cron\\Cron;', $bootstrap );
		// path came along as a dependency but is a service, so it is copied
		// without being declared -- only cron is named here.
		$this->assertContains( 'Declared in bootstrap.php: cron', $this->logged_messages() );

		// Still valid PHP returning an array, rather than text that merely
		// contains the right substrings.
		$declared = require $this->target_plugin_dir . '/bootstrap.php';

		$this->assertIsArray( $declared );
		// A bare entry is a value rather than a key: a key's value would be an
		// initializer, and `add` has none to supply.
		$this->assertContains( 'Acme\\Plugin\\Core\\Modules\\Cron\\Cron', $declared );
	}

	/**
	 * A file whose returned array cannot be found is not edited blindly -- but
	 * the modules are already on disk and inert, so it says so.
	 */
	public function test_warns_and_prints_entries_when_the_file_cannot_be_parsed(): void {
		file_put_contents( $this->target_plugin_dir . '/bootstrap.php', "<?php

// Nothing returned at all.
" );

		$this->run_add( array( 'cron' ) );

		$this->assertNotNull( \WP_CLI::last( 'warning' ) );
		$this->assertStringContainsString(
			"\t\\Acme\\Plugin\\Core\\Modules\\Cron\\Cron::class,",
			implode( "\n", $this->logged_messages() )
		);
	}

	/**
	 * Each command brought its own separator, so a plugin that had run `add` a
	 * dozen times ended up with more blank lines than imports.
	 */
	public function test_repeated_adds_do_not_pad_the_import_block(): void {
		copy(
			dirname( __DIR__, 3 ) . '/src/DevTools/stubs/bootstrap.php.stub',
			$this->target_plugin_dir . '/bootstrap.php'
		);

		$this->run_add( array( 'cron' ) );
		$this->run_add( array( 'options' ) );
		$this->run_add( array( 'views' ) );

		$bootstrap = (string) file_get_contents( $this->target_plugin_dir . '/bootstrap.php' );

		// Only the modules appear: path and views are services, copied but never
		// declared, so they bind no import here.
		$this->assertStringContainsString(
			"use Acme\\Plugin\\Core\\Modules\\Cron\\Cron;\n"
				. "use Acme\\Plugin\\Core\\Modules\\Options;\n\n"
				. 'return array(',
			$bootstrap
		);

		// Specifically an import followed by a blank line and another import --
		// the gap between the ABSPATH guard and the first one is meant to be there.
		$this->assertDoesNotMatchRegularExpression( '/^use .+;\n\nuse /m', $bootstrap );
	}

	/**
	 * `wp zt add service` copies a service, and declares nothing.
	 *
	 * A service is built the first time something asks for it, so an entry
	 * naming one in bootstrap.php would do nothing.
	 */
	public function test_add_service_copies_without_declaring(): void {
		file_put_contents( $this->target_plugin_dir . '/bootstrap.php', "<?php\n\nreturn array(\n);\n" );

		$this->run_add( array( 'views' ), 'service' );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Services/Views.php' );
		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Services/Path.php', 'Its dependency comes too.' );

		$bootstrap = (string) file_get_contents( $this->target_plugin_dir . '/bootstrap.php' );
		$this->assertStringNotContainsString( 'Views::class', $bootstrap );
		$this->assertStringNotContainsString( 'Path::class', $bootstrap );
	}

	/**
	 * The subcommand names the kind you are asking for, not the kinds it may
	 * copy. Nine of the ten modules depend on `path`, a service, so refusing to
	 * cross the boundary would make the command useless.
	 */
	public function test_add_module_still_copies_the_services_it_depends_on(): void {
		$this->run_add( array( 'rest-api' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/RestApi/RestApi.php' );
		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Services/Path.php' );
	}

	public function test_add_module_rejects_a_service_and_names_the_right_subcommand(): void {
		$this->run_add( array( 'path' ) );

		$error = \WP_CLI::last( 'error' );
		$this->assertNotNull( $error );
		$this->assertStringContainsString( '"path" is a service, not a module', (string) $error[0] );
		$this->assertStringContainsString( 'wp zt add service path', (string) $error[0] );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/lib/Core/Services/Path.php', 'Nothing is copied.' );
	}

	public function test_add_service_rejects_a_module_and_names_the_right_subcommand(): void {
		$this->run_add( array( 'cli' ), 'service' );

		$error = \WP_CLI::last( 'error' );
		$this->assertNotNull( $error );
		$this->assertStringContainsString( '"cli" is a module, not a service', (string) $error[0] );
		$this->assertStringContainsString( 'wp zt add module cli', (string) $error[0] );
	}

	/**
	 * A rejection stops the whole batch before anything is written, rather than
	 * copying the valid names and failing on the last one.
	 */
	public function test_one_wrong_kind_cancels_the_whole_batch(): void {
		$this->run_add( array( 'cli', 'path' ) );

		$this->assertNotNull( \WP_CLI::last( 'error' ) );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/lib/Core/Modules/CLI/CLI.php' );
	}

	/**
	 * Require the real commands/add/module.php, wire it, and invoke handle() with the
	 * CWD inside the throwaway target plugin directory.
	 *
	 * @param string[] $modules Module names to pass as positional args.
	 * @return void
	 */
	private function run_add( array $modules, string $kind = 'module' ): void {
		\WP_CLI::reset();

		$package_plugin = new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-add-test' );

		/** @var Command $command */
		$command = require dirname( __DIR__, 3 ) . '/commands/add/' . $kind . '.php';
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
