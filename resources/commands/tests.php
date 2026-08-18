<?php

/**
 * Devtool command: `wp zt tests`.
 *
 * Writes the PHPUnit suite a plugin built on this toolkit needs: a bootstrap
 * that finds WordPress's own test suite, a base test case handing every test a
 * throwaway `Plugin` on a temporary directory, WP-CLI doubles so a command file
 * is loadable, a `phpunit.xml.dist`, a first example test, and a
 * `.wp-env.test.json` providing the WordPress to run it all against. Additive
 * throughout: anything already on disk is left exactly as it is.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\DevTools\Formatter;
use Zestry\WPToolkit\DevTools\GitIgnore;
use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\DevTools\StubRenderer;
use Zestry\WPToolkit\DevTools\Tooling;
use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Modules\Path;

return new class() extends Command {

	/**
	 * Where the suite's own support code goes, relative to the plugin root.
	 */
	private const SUPPORT_DIR = 'tests/Support';

	/**
	 * Where the tests themselves go, relative to the plugin root.
	 */
	private const TESTS_DIR = 'tests/Integration';

	/**
	 * Set this plugin up to run PHPUnit tests.
	 *
	 * Your features are files in a directory, and the module that discovers them
	 * needs real files, real hooks and a real database -- so tests run against
	 * WordPress's own PHPUnit suite, and each one builds a throwaway `Plugin`
	 * pointed at a temporary directory it can write fixtures into.
	 *
	 * That takes six files and a handful of manifest entries. This writes all of
	 * them.
	 *
	 * Requires `wp zt init` to have already run, since the generated files are
	 * written under your namespace and import your copy of `Plugin`.
	 *
	 * ## WHAT IT WRITES
	 *
	 * - `phpunit.xml.dist`, collecting `tests/Integration/`. Support code lives
	 * in `tests/Support/` and is deliberately outside the suite, so the base
	 * test case is never collected as a test.
	 *
	 * - `tests/bootstrap.php`, which locates WordPress's test suite through
	 * `WP_TESTS_DIR` and then the `/wordpress-phpunit` mount, and loads your
	 * autoloader before it. It never loads your plugin's entry file: that
	 * builds and runs the real `Plugin` against the directories you ship, and
	 * your autoloader already provides every class without it.
	 *
	 * - `tests/Support/TestCase.php`, the base every test extends. It declares
	 * the modules you have installed, since nothing is built that is not
	 * declared, and gives each test `$this->plugin`, `$this->plugin_dir` and
	 * `write_plugin_file()`. The declarations are a list in your own file --
	 * add to it as you add modules.
	 *
	 * - `tests/Support/wp-cli-stubs.php`, recording doubles for `WP_CLI` and
	 * `WP_CLI_Command`. PHPUnit runs without the WP-CLI phar, so any test
	 * touching a command file fatals without them.
	 *
	 * - `tests/Integration/ExampleTest.php`, one passing test to prove the suite
	 * runs. Delete it once you have written your own.
	 *
	 * - `.wp-env.test.json`, the WordPress the suite runs against. Its own file
	 * rather than `.wp-env.json`, on a port of its own, so a plugin already
	 * keeping one for development keeps it and both can run at once.
	 *
	 * It then adds PHPUnit, the polyfills and `wp-test-utils` to
	 * `composer.json`, an `autoload-dev` PSR-4 entry mapping `Tests\` to
	 * `tests/`, `@wordpress/env` to `package.json`, the `env:start` /
	 * `env:stop` / `test:php` scripts, and `.phpunit.result.cache` to
	 * `.gitignore`.
	 *
	 * `wp-test-utils` is what the base test case extends, and the reason is
	 * your editor rather than the run: `WP_UnitTestCase` ships with the
	 * WordPress test suite rather than with a Composer package, so extending it
	 * directly leaves nothing in `vendor/` to resolve -- and every assertion
	 * your tests call reads as undefined.
	 *
	 * Nothing here is overwritten. A file already on disk is left exactly as it
	 * is, a dependency already required keeps whatever constraint it has, and an
	 * existing script is never rewritten -- so running this a second time
	 * changes nothing, and running it after adding a module is how the example
	 * of what to declare gets refreshed.
	 *
	 * ## RUNNING IT
	 *
	 * Install what was just added, start the WordPress, run the suite:
	 *
	 *     npm install && composer update
	 *     npm run env:start
	 *     npm run test:php
	 *
	 * Docker is what `wp-env` needs, and nothing else is. Without it, install
	 * WordPress's test suite locally with `install-wp-tests.sh` and export
	 * `WP_TESTS_DIR`; the generated bootstrap reads it and the rest is unchanged.
	 *
	 * ## OPTIONS
	 *
	 * [--no-wp-env]
	 * : Skip `.wp-env.test.json`, `@wordpress/env` and the npm scripts. For a
	 * plugin that already has a WordPress to test against.
	 *
	 * [--yes]
	 * : Assume yes to any prompt, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # The whole suite.
	 *     $ wp zt tests
	 *     Wrote phpunit.xml.dist
	 *     Wrote tests/bootstrap.php
	 *     Wrote tests/Support/TestCase.php
	 *     Wrote tests/Support/wp-cli-stubs.php
	 *     Wrote tests/Integration/ExampleTest.php
	 *     Wrote .wp-env.test.json
	 *     Added to composer.json: phpunit/phpunit, yoast/phpunit-polyfills, yoast/wp-test-utils
	 *     Added autoload-dev: Acme\Plugin\Tests\ => tests/
	 *     Added scripts: composer test
	 *     Added to package.json: @wordpress/env
	 *     Added scripts: npm env:start, env:stop, test:php
	 *     Added .phpunit.result.cache to .gitignore
	 *     Success: Run `composer update`, then `npm run env:start && npm run test:php`.
	 *
	 *     # Against a WordPress you already have.
	 *     $ wp zt tests --no-wp-env
	 *     ...
	 *     Success: Run `composer update`, then `vendor/bin/phpunit` with WP_TESTS_DIR set.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		try {
			$plugin_root = $this->with( ConsumerPlugin::class )->get_plugin_root();
			$config      = $this->with( ZestryConfig::class )->read( $plugin_root );
		} catch ( \RuntimeException $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}

		$namespace = rtrim( $config['namespace'], '\\' );
		$directory = basename( rtrim( $plugin_root, '/\\' ) );
		$slug      = $this->with( RuntimePlugin::class )->get_slug_or_default( $plugin_root );
		$wp_env    = false !== ( $assoc_args['wp-env'] ?? null );

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
			'module_imports'      => '',
			'module_declarations' => '',
		);

		$values = array_merge( $values, $this->get_module_values( $plugin_root, $config, $slug ) );

		$this->write_suite( $plugin_root, $values, $wp_env );
		$this->update_composer( $plugin_root, $namespace );

		if ( $wp_env ) {
			$this->update_package_json( $plugin_root, $directory );
		}

		foreach ( $this->with( GitIgnore::class )->add_entries( $plugin_root, array( '.phpunit.result.cache' ) ) as $entry ) {
			$this->log( 'Added ' . $entry . ' to .gitignore' );
		}

		$this->success(
			$wp_env
				? 'Run `composer update`, then `npm run env:start && npm run test:php`.'
				: 'Run `composer update`, then `vendor/bin/phpunit` with WP_TESTS_DIR set.'
		);
	}

	/**
	 * Render each stub into the plugin, skipping any file already there.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param array<string, string> $values      Placeholder values for the stubs.
	 * @param bool                  $wp_env      Whether the wp-env config is wanted.
	 * @return void
	 */
	private function write_suite( string $plugin_root, array $values, bool $wp_env ): void {
		$files = array(
			'phpunit.xml.dist'                      => 'phpunit.xml.dist.stub',
			'tests/bootstrap.php'                   => 'bootstrap.php.stub',
			self::SUPPORT_DIR . '/TestCase.php'     => 'test-case.php.stub',
			self::SUPPORT_DIR . '/wp-cli-stubs.php' => 'wp-cli-stubs.php.stub',
			self::TESTS_DIR . '/ExampleTest.php'    => 'example-test.php.stub',
		);

		if ( $wp_env ) {
			$files[ Tooling::WP_ENV_CONFIG ] = 'wp-env.test.json.stub';
		}

		foreach ( $files as $name => $stub ) {
			$this->write_file( $plugin_root, $name, $stub, $values );
		}
	}

	/**
	 * Render one stub into the plugin, unless that file already exists.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param string                $name        The file to write, relative to the plugin root.
	 * @param string                $stub        The stub's file name within src/DevTools/stubs/tests/.
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
			$this->with( Path::class )->get_plugin_path( 'src/DevTools/stubs/tests/' . $stub ),
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

		if ( $this->with( Tooling::class )->add_composer_autoload_dev( $plugin_root, $prefix, 'tests' ) ) {
			$this->log( 'Added autoload-dev: ' . $prefix . '\\ => tests/' );
		}

		$this->report(
			'Added scripts: composer ',
			$this->with( Tooling::class )->add_scripts( $plugin_root, 'composer.json', Tooling::PHPUNIT_SCRIPTS )
		);
	}

	/**
	 * Add wp-env and the scripts that drive it.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $directory   The consuming plugin's own directory name.
	 * @return void
	 */
	private function update_package_json( string $plugin_root, string $directory ): void {
		$this->report(
			'Added to package.json: ',
			$this->with( Tooling::class )->add_npm_dev_dependencies( $plugin_root, Tooling::WP_ENV_PACKAGES )
		);

		$this->report(
			'Added scripts: npm ',
			$this->with( Tooling::class )->add_scripts(
				$plugin_root,
				'package.json',
				$this->with( Tooling::class )->get_test_scripts( $directory )
			)
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
