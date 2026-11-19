<?php

/**
 * Devtool command: `wp zt tests`.
 *
 * Writes the test suites a plugin built on this toolkit needs, one directory
 * per runner under `tests/`: PHPUnit in `tests/php/`, Jest in `tests/js/`,
 * Playwright in `tests/e2e/`, plus a `.wp-env.test.json` providing the
 * WordPress to run them against. Additive throughout: anything already on disk
 * is left exactly as it is.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\DevTools\Formatter;
use Zestry\WPToolkit\DevTools\GitIgnore;
use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\DevTools\StubRenderer;
use Zestry\WPToolkit\DevTools\TestMirror;
use Zestry\WPToolkit\DevTools\Tooling;
use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Modules\Path;

return new class() extends Command {

	/**
	 * Where a plugin's own JavaScript packages live, searched in this order
	 * for one to write the example unit test against.
	 *
	 * @var array<int, string>
	 */
	private const SCRIPT_PACKAGE_DIRS = array( 'src/shared', 'src/entries' );

	/**
	 * Set this plugin up to run PHP, JavaScript and end-to-end tests.
	 *
	 * Every test lives under `tests/`, in one directory per runner, so each
	 * runner collects its own directory and nothing else:
	 *
	 * - `tests/php/` -- PHPUnit, against WordPress's own test suite. Your
	 * features are files in a directory, and the module that discovers them
	 * needs real files, real hooks and a real database, so each test builds a
	 * throwaway `Plugin` pointed at a temporary directory it can write
	 * fixtures into.
	 *
	 * - `tests/js/` -- Jest, through `wp-scripts test-unit-js`, for the
	 * JavaScript under `src/`. Its tree mirrors `src/`.
	 *
	 * - `tests/e2e/` -- Playwright, on the config `@wordpress/scripts` ships,
	 * driving a real browser against a real WordPress.
	 *
	 * Requires `wp zt init` to have already run, since the generated files are
	 * written under your namespace and import your copy of `Plugin`.
	 *
	 * ## WHAT IT WRITES
	 *
	 * For PHPUnit:
	 *
	 * - `phpunit.xml.dist`, collecting `tests/php/`. Support code lives in
	 * `tests/php/Support/` and is excluded from the suite, so the base test
	 * case is never collected as a test.
	 *
	 * - `tests/php/bootstrap.php`, which locates WordPress's test suite through
	 * `WP_TESTS_DIR` and then the `/wordpress-phpunit` mount, and loads your
	 * autoloader before it. It never loads your plugin's entry file: that
	 * builds and runs the real `Plugin` against the directories you ship, and
	 * your autoloader already provides every class without it.
	 *
	 * - `tests/php/Support/TestCase.php`, the base every test extends. It
	 * declares the modules you have installed, since nothing is built that is
	 * not declared, and gives each test `$this->plugin`, `$this->plugin_dir`,
	 * `write_plugin_file()` and `get_shipped_path()`. The declarations are a
	 * list in your own file -- add to it as you add modules.
	 *
	 * - `tests/php/Support/PluginTestCase.php`, for the tests that are about
	 * what you ship rather than about a fixture. It extends the base and adds a
	 * second `Plugin` pointed at your real entry file, so every module walks
	 * `resources/` itself -- still under the test slug, so what it writes is
	 * never the row the real plugin reads.
	 *
	 * - `tests/php/Support/wp-cli-stubs.php`, recording doubles for `WP_CLI` and
	 * `WP_CLI_Command`. PHPUnit runs without the WP-CLI phar, so any test
	 * touching a command file fatals without them.
	 *
	 * - `tests/php/ExampleTest.php`, one passing test to prove the suite runs.
	 * Delete it once you have written your own.
	 *
	 * For Jest, one example test, and only if there is something to test: the
	 * first package under `src/shared/` or `src/entries/` whose entry is one
	 * file -- its `package.json` `main`, or its single `index` script. A plugin
	 * with no JavaScript of its own gets the scripts and no test.
	 *
	 * For Playwright:
	 *
	 * - `tests/e2e/playwright.config.js`, which extends the one in
	 * `@wordpress/scripts` and points it at `.wp-env.test.json`: that file's
	 * port, and `npm run env:start` to start it. The default would run
	 * `npm run wp-env start`, your development site.
	 *
	 * - `tests/e2e/specs/plugin.spec.js`, one spec that activates the plugin and
	 * finds it active on the Plugins screen.
	 *
	 * And `.wp-env.test.json`, the WordPress the PHP and end-to-end tests run
	 * against. Its own file rather than `.wp-env.json`, on a port of its own,
	 * so a plugin already keeping one for development keeps it and both can
	 * run at once.
	 *
	 * Then the manifests. For PHPUnit, PHPUnit and three packages your editor
	 * needs go into `composer.json`, with an `autoload-dev` PSR-4 entry mapping
	 * `Tests\` to `tests/php/`. `package.json` gains `@wordpress/env` and the
	 * `env:start` / `env:stop` / `test:php` scripts, `@wordpress/scripts` and
	 * `test:unit` for Jest, and the two Playwright packages with `test:e2e`.
	 * `.gitignore` gains `.phpunit.result.cache` and Playwright's `artifacts/`.
	 *
	 * The three editor packages are worth naming, because between them they
	 * are what makes a test file resolve. `WP_UnitTestCase` ships with the
	 * WordPress test suite rather than with a Composer package, so nothing in
	 * `vendor/` declares the class a test extends -- and an editor that cannot
	 * resolve the parent offers no assertion above it, which is why
	 * `assertNotWPError()` reads as undefined in a hand-rolled suite.
	 * `wp-test-utils` gives the base case a real class to name,
	 * `phpunit-polyfills` sits above it, and `wordpress-tests-stubs` fills the
	 * gap between them. It declares no autoloader, so nothing loads it at run
	 * time: your tests still run against the real suite.
	 *
	 * Nothing here is overwritten. A file already on disk is left exactly as it
	 * is, a dependency already required keeps whatever constraint it has, and an
	 * existing script is never rewritten -- so running this a second time
	 * changes nothing, and running it after adding a module is how the example
	 * of what to declare gets refreshed.
	 *
	 * ## RUNNING IT
	 *
	 * Install what was just added, start the WordPress, run the suites:
	 *
	 *     composer update && npm install
	 *     npx playwright install chromium
	 *     npm run env:start
	 *     npm run test:php
	 *     npm run test:unit
	 *     npm run test:e2e
	 *
	 * `npx playwright install` is a one-time browser download, which is why it
	 * is left to you. `test:e2e` starts the WordPress itself when nothing is
	 * answering yet.
	 *
	 * Docker is what `wp-env` needs, and nothing else is. Without it, install
	 * WordPress's test suite locally with `install-wp-tests.sh` and export
	 * `WP_TESTS_DIR` for PHPUnit, and point `WP_BASE_URL` at any WordPress for
	 * Playwright.
	 *
	 * ## OPTIONS
	 *
	 * [--no-php]
	 * : Skip the PHPUnit suite and its Composer entries.
	 *
	 * [--no-unit]
	 * : Skip Jest: no example test, no `test:unit` scripts.
	 *
	 * [--no-e2e]
	 * : Skip Playwright: no config, no spec, no `test:e2e` scripts.
	 *
	 * [--no-wp-env]
	 * : Skip `.wp-env.test.json`, `@wordpress/env` and the scripts that start
	 * it. For a plugin that already has a WordPress to test against.
	 *
	 * [--yes]
	 * : Assume yes to any prompt, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # All three suites.
	 *     $ wp zt tests
	 *     Wrote phpunit.xml.dist
	 *     Wrote tests/php/bootstrap.php
	 *     Wrote tests/php/Support/TestCase.php
	 *     Wrote tests/php/Support/PluginTestCase.php
	 *     Wrote tests/php/Support/wp-cli-stubs.php
	 *     Wrote tests/php/ExampleTest.php
	 *     Wrote tests/js/shared/collections/index.test.ts
	 *     Wrote tests/e2e/playwright.config.js
	 *     Wrote tests/e2e/specs/plugin.spec.js
	 *     Wrote .wp-env.test.json
	 *     Added to composer.json: phpunit/phpunit, yoast/phpunit-polyfills, ...
	 *     Added autoload-dev: Acme\Plugin\Tests\ => tests/php/
	 *     Added scripts: composer test
	 *     Added to package.json: @wordpress/env, @wordpress/scripts, ...
	 *     Added scripts: npm env:start, env:stop, test:php, test:unit, ...
	 *     Added .phpunit.result.cache to .gitignore
	 *     Added artifacts/ to .gitignore
	 *     Success: Run `composer update`, `npm install` and `npx playwright install chromium`, then `npm run env:start`, `npm run test:php`, `npm run test:unit` and `npm run test:e2e`.
	 *
	 *     # PHPUnit only, against a WordPress you already have.
	 *     $ wp zt tests --no-unit --no-e2e --no-wp-env
	 *     ...
	 *     Success: Run `composer update`, then `vendor/bin/phpunit` with WP_TESTS_DIR set.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$php  = false !== ( $assoc_args['php'] ?? null );
		$unit = false !== ( $assoc_args['unit'] ?? null );
		$e2e  = false !== ( $assoc_args['e2e'] ?? null );

		if ( ! $php && ! $unit && ! $e2e ) {
			$this->error( 'Nothing to write: --no-php, --no-unit and --no-e2e leave no suite.' );
			return;
		}

		try {
			$plugin_root = $this->with( ConsumerPlugin::class )->get_plugin_root();
			$config      = $this->with( ZestryConfig::class )->read( $plugin_root );
		} catch ( \RuntimeException $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}

		$namespace  = rtrim( $config['namespace'], '\\' );
		$directory  = basename( rtrim( $plugin_root, '/\\' ) );
		$slug       = $this->with( RuntimePlugin::class )->get_slug_or_default( $plugin_root );
		$entry_file = $this->get_entry_file_name( $plugin_root, $directory );

		// Only something that needs a WordPress gets one.
		$wp_env = false !== ( $assoc_args['wp-env'] ?? null ) && ( $php || $e2e );

		$values = array(
			'namespace'           => $namespace,
			'copied_namespace'    => Copier::get_target_namespace( $namespace ),
			'root'                => trim( $config['root'], '/\\' ),
			'title'               => $this->with( StubRenderer::class )->to_title( $slug ),
			'plugin_dir'          => $directory,
			/*
			 * A slug of its own, because every name a module registers is
			 * composed from it -- option rows, hook names, AJAX actions, `wp
			 * {slug} ...` commands. Sharing the real plugin's would put a
			 * test's writes exactly where the real plugin reads.
			 */
			'test_slug'           => $slug . '-test',
			/*
			 * The entry file by name, so PluginTestCase can point a Plugin at
			 * it. A plugin that has not written one yet cannot be asked, and
			 * the directory name is the same answer get_slug_or_default()
			 * gives -- the generated file is the consumer's to correct.
			 */
			'entry_file'          => $entry_file,
			// How WordPress identifies the plugin, which the example spec activates.
			'plugin_file'         => $directory . '/' . $entry_file,
			'module_imports'      => '',
			'module_declarations' => '',
		);

		if ( $php ) {
			$values = array_merge( $values, $this->get_module_values( $plugin_root, $config, $slug ) );

			$this->write_php_suite( $plugin_root, $values );
		}

		if ( $unit ) {
			$this->write_unit_example( $plugin_root, $values );
		}

		if ( $e2e ) {
			$this->write_file( $plugin_root, TestMirror::E2E_DIR . '/playwright.config.js', 'tests/playwright.config.js.stub', $values );
			$this->write_file( $plugin_root, TestMirror::E2E_DIR . '/specs/plugin.spec.js', 'tests/plugin.spec.js.stub', $values );
		}

		if ( $wp_env ) {
			$this->write_file( $plugin_root, Tooling::WP_ENV_CONFIG, 'tests/wp-env.test.json.stub', $values );
		}

		if ( $php ) {
			$this->update_composer( $plugin_root, $namespace );
		}

		$this->update_package_json( $plugin_root, $directory, $php, $unit, $e2e, $wp_env );

		$ignored = array_merge(
			$php ? array( '.phpunit.result.cache' ) : array(),
			// Playwright's traces, screenshots and the saved login.
			$e2e ? array( 'artifacts/' ) : array()
		);

		foreach ( $this->with( GitIgnore::class )->add_entries( $plugin_root, $ignored ) as $entry ) {
			$this->log( 'Added ' . $entry . ' to .gitignore' );
		}

		$this->success( $this->get_next_steps( $php, $unit, $e2e, $wp_env ) );
	}

	/**
	 * Write the PHPUnit suite, skipping any file already there.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param array<string, string> $values      Placeholder values for the stubs.
	 * @return void
	 */
	private function write_php_suite( string $plugin_root, array $values ): void {
		$files = array(
			'phpunit.xml.dist'                            => 'phpunit.xml.dist.stub',
			TestMirror::PHP_DIR . '/bootstrap.php'        => 'bootstrap.php.stub',
			TestMirror::SUPPORT_DIR . '/TestCase.php'     => 'test-case.php.stub',
			TestMirror::SUPPORT_DIR . '/PluginTestCase.php' => 'plugin-test-case.php.stub',
			TestMirror::SUPPORT_DIR . '/wp-cli-stubs.php' => 'wp-cli-stubs.php.stub',
			TestMirror::PHP_DIR . '/ExampleTest.php'      => 'example-test.php.stub',
		);

		foreach ( $files as $name => $stub ) {
			$this->write_file( $plugin_root, $name, 'tests/' . $stub, $values );
		}
	}

	/**
	 * Write one unit test against the plugin's own JavaScript, if it has any.
	 *
	 * The same test `wp zt make test` writes for that package, through the same
	 * mapping, so the example sits exactly where a second one would.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param array<string, string> $values      Placeholder values for the stubs.
	 * @return void
	 */
	private function write_unit_example( string $plugin_root, array $values ): void {
		$script = $this->find_example_script( $plugin_root );

		if ( null === $script ) {
			$this->log( 'No package under ' . implode( '/ or ', self::SCRIPT_PACKAGE_DIRS ) . '/ to write an example unit test for.' );
			return;
		}

		$test = TestMirror::get_js_test_path( $script );

		$this->write_file(
			$plugin_root,
			$test,
			'test.js.stub',
			array_merge(
				$values,
				array(
					'source'      => $script,
					'import_path' => TestMirror::get_import_path( $test, $script ),
				)
			)
		);
	}

	/**
	 * The first package whose entry resolves to exactly one script.
	 *
	 * A package that is ambiguous or empty is passed over rather than reported:
	 * this is an example, and `wp zt make test` is where the reason gets said.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return string|null The script, relative to the plugin root.
	 */
	private function find_example_script( string $plugin_root ): ?string {
		foreach ( self::SCRIPT_PACKAGE_DIRS as $packages ) {
			$found = glob( Str::join_path( $plugin_root, $packages ) . '/*', GLOB_ONLYDIR );
			$found = false === $found ? array() : $found;

			sort( $found );

			foreach ( $found as $package ) {
				try {
					return TestMirror::resolve_script( $plugin_root, $packages . '/' . basename( $package ) );
				} catch ( \InvalidArgumentException $exception ) {
					continue;
				}
			}
		}

		return null;
	}

	/**
	 * Render one stub into the plugin, unless that file already exists.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param string                $name        The file to write, relative to the plugin root.
	 * @param string                $stub        The stub, relative to src/DevTools/stubs/.
	 * @param array<string, string> $values      Placeholder values for the stub.
	 * @return void
	 */
	private function write_file( string $plugin_root, string $name, string $stub, array $values ): void {
		$destination = Str::join_path( $plugin_root, $name );

		if ( is_file( $destination ) ) {
			$this->log( $name . ' already exists -- left as it is.' );
			return;
		}

		if ( ! is_dir( dirname( $destination ) ) && ! wp_mkdir_p( dirname( $destination ) ) ) {
			$this->warning( 'Could not create ' . dirname( $name ) );
			return;
		}

		$contents = $this->with( StubRenderer::class )->render(
			$this->with( Path::class )->get_plugin_path( 'src/DevTools/stubs/' . $stub ),
			$values
		);

		if ( false === file_put_contents( $destination, $contents ) ) {
			$this->warning( 'Failed to write ' . $name );
			return;
		}

		// Generated rather than copied, so formatting it is right: nothing
		// records a hash of these, and `wp zt update` never reads them.
		$this->with( Formatter::class )->format( $plugin_root, array( $destination ) );

		$this->log( 'Wrote ' . $name );
	}

	/**
	 * What to run next, for the suites that were written.
	 *
	 * @param bool $php    Whether the PHPUnit suite was written.
	 * @param bool $unit   Whether Jest was set up.
	 * @param bool $e2e    Whether Playwright was set up.
	 * @param bool $wp_env Whether `.wp-env.test.json` was written.
	 * @return string
	 */
	private function get_next_steps( bool $php, bool $unit, bool $e2e, bool $wp_env ): string {
		$install = array_merge(
			$php ? array( '`composer update`' ) : array(),
			$unit || $e2e || $wp_env ? array( '`npm install`' ) : array(),
			$e2e ? array( '`npx playwright install chromium`' ) : array()
		);

		$run = array_merge(
			$php && $wp_env ? array( '`npm run env:start`', '`npm run test:php`' ) : array(),
			$php && ! $wp_env ? array( '`vendor/bin/phpunit` with WP_TESTS_DIR set' ) : array(),
			$unit ? array( '`npm run test:unit`' ) : array(),
			$e2e ? array( '`npm run test:e2e`' ) : array()
		);

		return 'Run ' . $this->join_list( $install ) . ', then ' . $this->join_list( $run ) . '.';
	}

	/**
	 * `a`, `a and b`, `a, b and c`.
	 *
	 * @param string[] $items What to list.
	 * @return string
	 */
	private function join_list( array $items ): string {
		$last = array_pop( $items );

		return array() === $items ? (string) $last : implode( ', ', $items ) . ' and ' . $last;
	}

	/**
	 * The entry file's own name, for the Plugin PluginTestCase builds.
	 *
	 * Only the basename is written into the generated file, which reaches the
	 * root through `get_shipped_path()` rather than an absolute path that would
	 * break on another machine.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $directory   The plugin's own directory name, used when there is no entry file yet.
	 * @return string
	 */
	private function get_entry_file_name( string $plugin_root, string $directory ): string {
		$entry_file = $this->with( ConsumerPlugin::class )->get_entry_file( $plugin_root );

		return null === $entry_file ? $directory . '.php' : basename( $entry_file );
	}

	/**
	 * The `use` lines and bootstrap entries for the modules this plugin has.
	 *
	 * Nothing is built that is not declared, so the generated base case has to
	 * name what a test may reach for. Read from disk the way `wp zt doctor`
	 * reads it -- every registry entry whose file exists under the plugin's own
	 * namespace -- rather than from the plugin's `bootstrap.php`: that file's
	 * configurators point at the real directories and its headings name the real
	 * slug, and the throwaway plugin is a temporary directory under a slug of
	 * its own precisely so it shares neither.
	 *
	 * Written into the file rather than re-read at run time. It becomes the
	 * consumer's own file the moment it is written, and a list they can edit is
	 * the point: a test that wants one module configured differently says so
	 * there.
	 *
	 * @param string                                 $plugin_root Absolute path to the consuming plugin's root.
	 * @param array{namespace: string, root: string} $config      The project's zestry.json.
	 * @param string                                 $slug        The consuming plugin's own slug.
	 * @return array<string, string> The two placeholder values.
	 */
	private function get_module_values( string $plugin_root, array $config, string $slug ): array {
		$registry  = Copier::normalize_registry( require $this->with( Path::class )->get_plugin_path( 'src/DevTools/registry.php' ) );
		$namespace = Copier::get_target_namespace( $config['namespace'] );
		$imports   = array();
		$plain     = array();
		$acting    = array();

		foreach ( $registry as $entry ) {
			if ( ! is_a( $entry['source'], Module::class, true ) ) {
				continue;
			}

			$class_name = $namespace . '\\' . Copier::get_relative_class( $entry['source'] );

			/*
			 * The path comes off the *target* class name, not off
			 * get_relative_class() alone: the latter is relative to this
			 * package's own namespace and so has no `Core/` segment, which is
			 * exactly where a copy lands. PSR-4 does the rest.
			 */
			$relative = trim( $config['root'], '/\\' ) . '/'
				. str_replace( '\\', '/', substr( $class_name, strlen( rtrim( $config['namespace'], '\\' ) ) + 1 ) ) . '.php';

			if ( ! is_file( Str::join_path( $plugin_root, $relative ) ) ) {
				continue;
			}

			$imports[] = 'use ' . $class_name . ';';
			$short     = substr( (string) strrchr( $class_name, '\\' ), 1 );

			// A module that acts on its own has to sit under a heading: one left
			// at the top level throws at run().
			if ( is_a( $entry['source'], Bootable::class, true ) ) {
				$acting[] = "\t\t\t\t" . $short . '::class,';
				continue;
			}

			$plain[] = "\t\t\t\t" . $short . '::class,';
		}

		if ( array() === $imports ) {
			return array();
		}

		sort( $imports );
		sort( $plain );
		sort( $acting );

		return array(
			'module_imports'      => implode( "\n", $imports ) . "\n",
			'module_declarations' => $this->get_declarations( $plain, $acting, $slug . '-test' ),
		);
	}

	/**
	 * The body of the generated `declare_multiple()` array.
	 *
	 * @param string[] $plain     Entries for modules that do nothing until asked.
	 * @param string[] $acting    Entries for modules that act on their own.
	 * @param string   $test_slug The throwaway plugin's slug, whose loaded hook heads the second group.
	 * @return string
	 */
	private function get_declarations( array $plain, array $acting, string $test_slug ): string {
		$lines = $plain;

		if ( array() !== $acting ) {
			if ( array() !== $lines ) {
				$lines[] = '';
			}

			$lines[] = "\t\t\t\t'" . $test_slug . "_loaded' => array(";

			foreach ( $acting as $entry ) {
				$lines[] = "\t" . $entry;
			}

			$lines[] = "\t\t\t\t),";
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Add PHPUnit, the tests autoload entry and the `composer test` script.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $plugin_namespace The plugin's own namespace.
	 * @return void
	 */
	private function update_composer( string $plugin_root, string $plugin_namespace ): void {
		$this->report(
			'Added to composer.json: ',
			$this->with( Tooling::class )->add_composer_dev_requires( $plugin_root, Tooling::PHPUNIT_PACKAGES )
		);

		$prefix = $plugin_namespace . '\\Tests';

		if ( $this->with( Tooling::class )->add_composer_autoload_dev( $plugin_root, $prefix, TestMirror::PHP_DIR ) ) {
			$this->log( 'Added autoload-dev: ' . $prefix . '\\ => ' . TestMirror::PHP_DIR . '/' );
		}

		$this->report(
			'Added scripts: composer ',
			$this->with( Tooling::class )->add_scripts( $plugin_root, 'composer.json', Tooling::PHPUNIT_SCRIPTS )
		);
	}

	/**
	 * Add the npm packages and scripts for whichever suites were written.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $directory   The consuming plugin's own directory name.
	 * @param bool   $php         Whether the PHPUnit suite was written.
	 * @param bool   $unit        Whether Jest was set up.
	 * @param bool   $e2e         Whether Playwright was set up.
	 * @param bool   $wp_env      Whether `.wp-env.test.json` was written.
	 * @return void
	 */
	private function update_package_json( string $plugin_root, string $directory, bool $php, bool $unit, bool $e2e, bool $wp_env ): void {
		$packages = array();
		$scripts  = array();

		if ( $wp_env ) {
			$packages = Tooling::WP_ENV_PACKAGES;
			$scripts  = $this->with( Tooling::class )->get_test_scripts( $directory );

			// The PHPUnit runner, which has nothing to run without the suite.
			if ( ! $php ) {
				unset( $scripts['test:php'] );
			}
		}

		if ( $unit ) {
			$packages = array_merge( $packages, Tooling::UNIT_TEST_PACKAGES );
			$scripts  = array_merge( $scripts, Tooling::UNIT_TEST_SCRIPTS );
		}

		if ( $e2e ) {
			$packages = array_merge( $packages, Tooling::E2E_TEST_PACKAGES );
			$scripts  = array_merge( $scripts, Tooling::E2E_TEST_SCRIPTS );
		}

		if ( array() === $packages ) {
			return;
		}

		$this->report(
			'Added to package.json: ',
			$this->with( Tooling::class )->add_npm_dev_dependencies( $plugin_root, array_values( array_unique( $packages ) ) )
		);

		$this->report(
			'Added scripts: npm ',
			$this->with( Tooling::class )->add_scripts( $plugin_root, 'package.json', $scripts )
		);
	}

	/**
	 * Report what a manifest gained, if anything.
	 *
	 * @param string   $lead  The message's opening, ending in a space.
	 * @param string[] $added What was actually added.
	 * @return void
	 */
	private function report( string $lead, array $added ): void {
		if ( array() === $added ) {
			return;
		}

		$this->log( $lead . implode( ', ', $added ) );
	}
};
