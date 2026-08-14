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
 * Exercises the real commands/add.php file. Path resolves toolkit source
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

		// A plugin is a directory with an entry file declaring one, and the version
		// gate reads its header. New enough for everything in the registry, so the
		// tests that are not about versions never meet it.
		$this->write_entry_file( '7.1' );
	}

	public function tear_down(): void {
		$this->remove_dir( $this->target_plugin_dir );
		parent::tear_down();
	}

	public function test_copies_a_module_with_no_dependencies(): void {
		$this->run_add( array( 'path' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' );
		$this->assertNotNull( \WP_CLI::last( 'success' ) );
	}

	public function test_copies_a_transitive_dependency_first(): void {
		$this->run_add( array( 'rest-api' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' );
		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/RestApi/RestApi.php' );

		$this->assertContains( 'Also adding required dependencies: path, request', $this->logged_messages() );
	}

	public function test_skips_a_module_already_present_instead_of_overwriting_it(): void {
		mkdir( $this->target_plugin_dir . '/lib/Core/Modules', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php', '<?php // hand-edited' );

		$this->run_add( array( 'path' ) );

		// The existing, hand-edited file is left untouched, not overwritten
		// with the toolkit's own copy of Path.php.
		$this->assertSame(
			'<?php // hand-edited',
			file_get_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' )
		);

		$this->assertContains( 'Skipped path (already present)', $this->logged_messages() );
	}

	public function test_skips_only_the_already_present_module_among_several(): void {
		mkdir( $this->target_plugin_dir . '/lib/Core/Modules', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php', '<?php // hand-edited' );

		$this->run_add( array( 'path', 'globals' ) );

		$this->assertSame(
			'<?php // hand-edited',
			file_get_contents( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' )
		);
		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/Globals.php' );
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

		// RestApi acts on its own, so the kernel refuses an entry that does not
		// say when it boots -- `add` writes the plugin's own loaded action,
		// which is what makes the entry complete without a hand-edit.
		$this->assertStringContainsString(
			sprintf( "'%s_loaded' => array(", str_replace( '-', '_', basename( $this->target_plugin_dir ) ) ),
			$bootstrap,
			'The heading says when it boots.'
		);
		$this->assertStringContainsString( "\t\tRestApi::class,", $bootstrap, 'And the module sits under it.' );

		// Path only works when called, so it has nothing to time and stays bare.
		$this->assertStringContainsString( 'Path::class,', $bootstrap );

		// Nothing is written above either: with the directories fixed, a module
		// has no configuration to suggest, and an empty commented block would be
		// a heading over nothing.
		$this->assertStringNotContainsString( '//', $bootstrap );
	}

	/**
	 * A module already declared keeps whatever configuration is there: adding
	 * it again must not duplicate the entry or overwrite an initializer.
	 */
	public function test_does_not_redeclare_a_module_already_in_bootstrap(): void {
		file_put_contents(
			$this->target_plugin_dir . '/bootstrap.php',
			"<?php\n\nreturn array(\n"
				. "\t\\Acme\\Plugin\\Core\\Modules\\Cron\\Cron::class => array(\n"
				. "\t\t'configure' => static function ( \$cron ): void {\n"
				. "\t\t\t\$cron->configured_by_hand();\n"
				. "\t\t},\n"
				. "\t),\n"
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

		// Printed under the heading it would have been written under, since a
		// line pasted without it declares something different from what `add`
		// intended -- a module that acts, listed as one that does not.
		$this->assertStringContainsString(
			"\t'init' => array(\n\t\t\\Acme\\Plugin\\Core\\Modules\\Cron\\Cron::class,\n\t),",
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

		$this->assertStringContainsString( "'init' => array(", $bootstrap );
		$this->assertStringContainsString( "\t\tCron::class,", $bootstrap );
		$this->assertStringContainsString( 'use Acme\\Plugin\\Core\\Modules\\Cron\\Cron;', $bootstrap );
		// path came along as a dependency, and a dependency is a module like any
		// other -- so it is declared too, and named alongside cron.
		$this->assertContains( 'Declared in bootstrap.php: path, cron', $this->logged_messages() );

		// Still valid PHP returning an array, rather than text that merely
		// contains the right substrings.
		$declared = require $this->target_plugin_dir . '/bootstrap.php';

		$this->assertIsArray( $declared );
		// cron names a boot hook, so it lands under that heading rather than at
		// the top level.
		$this->assertArrayHasKey( 'init', $declared );
		$this->assertContains( 'Acme\\Plugin\\Core\\Modules\\Cron\\Cron', $declared['init'] );
		// path does not, so it is written bare -- a value rather than a key.
		$this->assertContains( 'Acme\\Plugin\\Core\\Modules\\Path', $declared );
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
			"\t'init' => array(\n\t\t\\Acme\\Plugin\\Core\\Modules\\Cron\\Cron::class,\n\t),",
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

		// Every copied module binds an import, dependencies included, in the
		// order they were added.
		$this->assertStringContainsString(
			"use Acme\\Plugin\\Core\\Modules\\Path;\n"
				. "use Acme\\Plugin\\Core\\Modules\\Cron\\Cron;\n"
				. "use Acme\\Plugin\\Core\\Modules\\Options;\n"
				. "use Acme\\Plugin\\Core\\Modules\\Views;\n\n"
				. 'return array(',
			$bootstrap
		);

		// Specifically an import followed by a blank line and another import --
		// the gap between the ABSPATH guard and the first one is meant to be there.
		$this->assertDoesNotMatchRegularExpression( '/^use .+;\n\nuse /m', $bootstrap );
	}

	/**
	 * A module with no `on_boot()` is declared like any other.
	 *
	 * Nothing is built that `bootstrap.php` does not declare, so a module that
	 * only works when called still has to be listed -- there is no second kind
	 * of entry, and no kind of module that gets there another way.
	 */
	public function test_a_module_with_no_on_boot_is_declared_like_any_other(): void {
		file_put_contents( $this->target_plugin_dir . '/bootstrap.php', "<?php\n\nreturn array(\n);\n" );

		$this->run_add( array( 'views' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/Views.php' );
		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/Path.php', 'Its dependency comes too.' );

		$bootstrap = (string) file_get_contents( $this->target_plugin_dir . '/bootstrap.php' );
		$this->assertStringContainsString( 'Views::class', $bootstrap );
		$this->assertStringContainsString( 'Path::class', $bootstrap, 'A dependency is declared too.' );
	}

	/**
	 * The subcommand names the kind you are asking for, not the kinds it may
	 * copy. Nine of the ten modules depend on `path`, a service, so refusing to
	 * cross the boundary would make the command useless.
	 */
	public function test_a_module_copies_what_it_depends_on(): void {
		$this->run_add( array( 'rest-api' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/RestApi/RestApi.php' );
		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' );
	}




	/**
	 * Measured against the plugin's own `Requires at least:` header, not against
	 * the WordPress this developer runs: the second says nothing about the oldest
	 * site the plugin will be installed on, which is where a module registering
	 * against a missing API does its damage.
	 */
	public function test_refuses_a_module_the_plugin_promises_too_old_a_wordpress_for(): void {
		$this->write_entry_file( '6.5' );

		$this->run_add( array( 'icons-library' ) );

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/lib/Core/Modules/IconsLibrary/IconsLibrary.php' );
		$this->assertStringContainsString(
			'icons-library needs WordPress 7.1',
			(string) \WP_CLI::last( 'error' )[0]
		);
	}

	/**
	 * Undeclared is not "old enough by default". A plugin promising nothing is one
	 * WordPress will activate on anything at all, so it is the widest possible
	 * promise rather than the absence of one.
	 */
	public function test_refuses_a_module_when_the_plugin_declares_no_minimum(): void {
		$this->write_entry_file( null );

		$this->run_add( array( 'icons-library' ) );

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/lib/Core/Modules/IconsLibrary/IconsLibrary.php' );
		$this->assertStringContainsString(
			'Set `Requires at least: 7.1`',
			(string) \WP_CLI::last( 'error' )[0]
		);
	}

	/**
	 * Nothing at all is copied, including the dependencies that would have been
	 * fine on their own: a half-satisfied `depends` is a state neither `add` nor
	 * `update` has any way to describe.
	 */
	public function test_refusing_one_module_copies_none_of_its_dependencies(): void {
		$this->write_entry_file( '6.5' );

		$this->run_add( array( 'icons-library' ) );

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' );
	}

	public function test_copies_a_module_the_plugin_promises_a_new_enough_wordpress_for(): void {
		$this->write_entry_file( '7.1' );

		$this->run_add( array( 'icons-library' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/IconsLibrary/IconsLibrary.php' );
	}

	/**
	 * The same missing fact the doctor reports, said at the moment code is being
	 * added -- which is when it is cheapest to write down. A warning rather than a
	 * refusal, since nothing in this batch needed a version.
	 */
	public function test_warns_about_a_missing_header_even_when_nothing_needs_a_version(): void {
		$this->write_entry_file( null );

		$this->run_add( array( 'path' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Core/Modules/Path.php' );
		$this->assertStringContainsString(
			'does not declare a `Requires at least:` header',
			(string) \WP_CLI::last( 'warning' )[0]
		);
	}

	/**
	 * Write the fixture plugin's entry file, with or without a declared minimum.
	 *
	 * @param string|null $requires_wp The `Requires at least:` value, or null to leave the header out.
	 * @return void
	 */
	private function write_entry_file( ?string $requires_wp ): void {
		$header = null === $requires_wp ? '' : "\n * Requires at least: " . $requires_wp;

		file_put_contents(
			$this->target_plugin_dir . '/acme-plugin.php',
			"<?php\n/**\n * Plugin Name: Acme Plugin" . $header . "\n */\n"
		);
	}

	/**
	 * Require the real commands/add.php, wire it, and invoke handle() with the
	 * CWD inside the throwaway target plugin directory.
	 *
	 * @param string[] $modules Module names to pass as positional args.
	 * @return void
	 */
	private function run_add( array $modules ): void {
		\WP_CLI::reset();

		$package_plugin = ( new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-add-test' ) )->declare_multiple( $this->get_toolkit_modules() );

		/** @var Command $command */
		$command = require dirname( __DIR__, 3 ) . '/commands/add.php';
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
