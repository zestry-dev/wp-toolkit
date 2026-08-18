<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Support;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Plugin;
use Yoast\WPTestUtils\WPIntegration\TestCase as WPTestCase;

/**
 * Base test case for the toolkit's integration tests.
 *
 * Each test gets a throwaway plugin directory on disk and a fresh Plugin instance
 * pointed at it, so Path, Views, and file discovery run against real files while
 * the WordPress test suite provides real hooks, options, and nonces.
 *
 * `WP_UnitTestCase` ships with the WordPress test suite rather than with a
 * Composer package, so the parent this chain reaches is not in `vendor/` and an
 * editor resolves nothing above it -- not `assertNotWPError()`, not PHPUnit's
 * own assertions. `php-stubs/wordpress-tests-stubs` is in `require-dev` to
 * close that: it declares the whole chain up to
 * `Yoast\PHPUnitPolyfills\TestCases\TestCase`, which is in `vendor/`, and
 * carries no autoloader of its own, so nothing loads it at run time.
 */
abstract class TestCase extends WPTestCase {

	/**
	 * Absolute path to this test's temporary plugin directory.
	 *
	 * @var string
	 */
	protected string $plugin_dir = '';

	/**
	 * Absolute path to this test's plugin entry file.
	 *
	 * @var string
	 */
	protected string $entry_file = '';

	/**
	 * The plugin instance under test.
	 *
	 * @var Plugin
	 */
	protected Plugin $plugin;

	public function set_up(): void {
		parent::set_up();

		$this->plugin_dir = $this->make_temp_dir( 'zestry-plugin-' );
		$this->entry_file = $this->plugin_dir . '/plugin.php';
		file_put_contents( $this->entry_file, "<?php\n/* Plugin Name: Zestry Test */\n" );

		$this->plugin = ( new Plugin( $this->entry_file, 'zestry-test' ) )->declare_multiple( $this->get_toolkit_modules() );
	}

	/**
	 * Every module this toolkit ships, declared but not built.
	 *
	 * Nothing is built without being declared, so a test asking for a module has
	 * to have declared it -- and a test harness is the one plugin that is made of
	 * everything. Read from `registry.php` rather than listed here, so a module
	 * added to the toolkit is reachable from a test without a second edit.
	 *
	 * Declaring is not building: each is constructed by the first `get()` that
	 * asks for it, and a test that never asks pays nothing.
	 *
	 * A module that acts on its own has to be listed under the hook it acts on,
	 * so those go under this plugin's own loaded action -- which `run()` fires
	 * as its last act, meaning a test that calls `run()` gets them booted and
	 * one that only calls `get()` builds them on the spot, exactly as before.
	 *
	 * @return array<array-key, mixed>
	 */
	protected function get_toolkit_modules(): array {
		/** @var array<string, array{source: class-string}> $registry */
		$registry = require dirname( __DIR__, 2 ) . '/src/DevTools/registry.php';

		$modules = array_column( $registry, 'source' );

		// The DevTools helpers are not in the registry -- nothing copies them
		// into a consuming plugin -- but the command tests reach for them the
		// same way `devtool.php` does.
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/src/DevTools/*.php' ) as $file ) {
			$class = 'Zestry\\WPToolkit\\DevTools\\' . basename( (string) $file, '.php' );

			if ( class_exists( $class ) && is_subclass_of( $class, Module::class ) ) {
				$modules[] = $class;
			}
		}

		$entries = array();
		$acting  = array();

		foreach ( $modules as $class ) {
			if ( is_a( $class, Bootable::class, true ) ) {
				$acting[] = $class;

				continue;
			}

			$entries[] = $class;
		}

		if ( array() !== $acting ) {
			$entries['zestry_test_loaded'] = $acting;
		}

		return $entries;
	}

	public function tear_down(): void {
		$this->remove_dir( $this->plugin_dir );
		parent::tear_down();
	}

	/**
	 * Create a file (and any parent directories) inside the plugin directory.
	 *
	 * @param string $relative_path Path relative to the plugin directory.
	 * @param string $contents      File contents.
	 * @return string The absolute path written.
	 */
	protected function write_plugin_file( string $relative_path, string $contents ): string {
		$absolute = $this->plugin_dir . '/' . ltrim( $relative_path, '/' );
		$dir      = dirname( $absolute );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		file_put_contents( $absolute, $contents );

		return $absolute;
	}

	/**
	 * Create a unique temporary directory.
	 *
	 * @param string $prefix Directory name prefix.
	 * @return string The absolute path of the created directory.
	 */
	protected function make_temp_dir( string $prefix ): string {
		/*
		 * Lowercase hex and dashes only: a directory made here stands in for a
		 * plugin directory, and `Plugin` derives its slug from that name, so the
		 * name has to be one a registered name could carry -- which rules out
		 * uniqid()'s own entropy suffix, since that adds a `.`. Random bytes on top
		 * of it because uniqid() alone is the microsecond clock, and two calls
		 * inside one microsecond return the same string: with a directory per test
		 * that collision is rare rather than impossible, and a shared directory
		 * fails whichever test expected an empty one.
		 *
		 * Held under Plugin::MAX_SLUG_LENGTH: the longest prefix in use is 12
		 * characters, plus 13 from uniqid() and 4 of random hex.
		 */
		$path = rtrim( sys_get_temp_dir(), '/\\' ) . '/' . $prefix . uniqid() . bin2hex( random_bytes( 2 ) );

		// Loud rather than silent: mkdir() answers false for a name already taken,
		// and a test that went on to share a directory would fail somewhere else.
		if ( ! mkdir( $path, 0777, true ) ) {
			$this->fail( 'Could not create the temporary directory ' . $path );
		}

		return $path;
	}

	/**
	 * Recursively remove a directory tree.
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	protected function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isLink() || $item->isFile() ) {
				unlink( $item->getPathname() );
			} else {
				rmdir( $item->getPathname() );
			}
		}

		rmdir( $dir );
	}
}
