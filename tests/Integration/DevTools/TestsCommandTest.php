<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `wp zt tests`, run against a throwaway plugin.
 *
 * The fixture is hand-written rather than produced by running `wp zt init`:
 * this command reads `zestry.json` and looks for module files on disk, and
 * copying the whole kernel per test to provide those would be most of the run
 * time for none of the coverage.
 *
 * @covers \Zestry\WPToolkit\DevTools\Tooling
 */
final class TestsCommandTest extends TestCase {

	/**
	 * Every file the command writes, relative to the plugin root.
	 *
	 * @var array<int, string>
	 */
	private const WRITTEN = array(
		'phpunit.xml.dist',
		'tests/php/bootstrap.php',
		'tests/php/Support/TestCase.php',
		'tests/php/Support/PluginTestCase.php',
		'tests/php/Support/wp-cli-stubs.php',
		'tests/php/ExampleTest.php',
		'tests/e2e/playwright.config.js',
		'tests/e2e/specs/plugin.spec.js',
		'.wp-env.test.json',
	);

	private string $target_plugin_dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->target_plugin_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/zestry-tests-demo';
		$this->remove_dir( $this->target_plugin_dir );
		mkdir( $this->target_plugin_dir, 0777, true );

		$this->write_target_file( 'composer.json', (string) json_encode( array( 'name' => 'acme/demo' ) ) );
		$this->write_target_file(
			'zestry.json',
			(string) json_encode(
				array(
					'namespace'   => 'Acme\\Demo',
					'root'        => 'lib',
					'text_domain' => 'acme-demo',
				)
			)
		);
	}

	public function tear_down(): void {
		$this->remove_dir( $this->target_plugin_dir );
		parent::tear_down();
	}

	public function test_it_writes_every_file_of_the_suite(): void {
		$this->run_tests();

		foreach ( self::WRITTEN as $relative ) {
			$this->assertFileExists( $this->target_plugin_dir . '/' . $relative );
		}
	}

	/**
	 * The second case is the whole point of the file: a Plugin on the real
	 * entry file, so every module walks the directories the plugin ships
	 * instead of a fixture standing in for them.
	 */
	public function test_the_plugin_test_case_points_at_the_real_entry_file(): void {
		$this->write_target_file( 'demo-plugin.php', "<?php\n/* Plugin Name: Demo Plugin */\n" );

		$this->run_tests();

		$this->assertStringContainsString(
			"self::get_shipped_path( 'demo-plugin.php' )",
			$this->read_target_file( 'tests/php/Support/PluginTestCase.php' ),
			'The entry file is reached relative to the suite, not by an absolute path.'
		);
	}

	/**
	 * A plugin that has not written its entry file yet cannot be asked for it,
	 * and the directory name is the same answer get_slug_or_default() gives.
	 */
	public function test_the_plugin_test_case_falls_back_to_the_directory_name(): void {
		$this->run_tests();

		$this->assertStringContainsString(
			"self::get_shipped_path( 'zestry-tests-demo.php' )",
			$this->read_target_file( 'tests/php/Support/PluginTestCase.php' )
		);
	}

	/**
	 * Reading the real directories is safe only because the slug is still the
	 * test one: every name a module composes carries it, so what these tests
	 * write is never the row the real plugin reads.
	 */
	public function test_the_plugin_test_case_keeps_the_test_slug(): void {
		$this->run_tests();

		$contents = $this->read_target_file( 'tests/php/Support/PluginTestCase.php' );

		$this->assertStringContainsString( "'zestry-tests-demo-test'", $contents );
		$this->assertStringNotContainsString(
			"'zestry-tests-demo'",
			str_replace( "'zestry-tests-demo-test'", '', $contents ),
			'Nothing names the real slug.'
		);
	}

	/**
	 * It extends the base rather than repeating it, so a test gets the
	 * throwaway directory and the real one at once.
	 */
	public function test_the_plugin_test_case_extends_the_base_case(): void {
		$this->run_tests();

		$this->assertStringContainsString(
			'abstract class PluginTestCase extends TestCase {',
			$this->read_target_file( 'tests/php/Support/PluginTestCase.php' )
		);
	}

	/**
	 * The generated files are the plugin's own code, so they carry its
	 * namespace and import its copy of the kernel rather than this package's.
	 */
	public function test_the_generated_files_carry_the_configured_namespace(): void {
		$this->run_tests();

		$test_case = $this->read_target_file( 'tests/php/Support/TestCase.php' );

		$this->assertStringContainsString( 'namespace Acme\\Demo\\Tests\\Support;', $test_case );
		$this->assertStringContainsString( 'use Acme\\Demo\\Core\\Kernel\\Plugin;', $test_case );
		$this->assertStringNotContainsString( 'Zestry\\WPToolkit', $test_case );

		$this->assertStringContainsString(
			'namespace Acme\\Demo\\Tests;',
			$this->read_target_file( 'tests/php/ExampleTest.php' )
		);
	}

	/**
	 * The throwaway plugin a test builds gets a slug of its own, since every
	 * name a module registers is composed from it -- so sharing the real
	 * plugin's would put a test's writes where the real plugin reads.
	 */
	public function test_the_generated_test_case_uses_a_slug_of_its_own(): void {
		$this->run_tests();

		$this->assertStringContainsString(
			"'zestry-tests-demo-test'",
			$this->read_target_file( 'tests/php/Support/TestCase.php' )
		);
	}

	/**
	 * Nothing is built that is not declared, so the base case has to name what
	 * a test may reach for -- and only what is actually on disk.
	 */
	public function test_it_declares_the_modules_that_are_installed(): void {
		$this->install_module( 'Modules/Views.php' );

		$this->run_tests();

		$test_case = $this->read_target_file( 'tests/php/Support/TestCase.php' );

		$this->assertStringContainsString( 'use Acme\\Demo\\Core\\Modules\\Views;', $test_case );
		$this->assertStringContainsString( 'Views::class,', $test_case );
	}

	public function test_it_declares_nothing_for_a_module_that_is_not_installed(): void {
		$this->run_tests();

		$test_case = $this->read_target_file( 'tests/php/Support/TestCase.php' );

		$this->assertStringNotContainsString( 'Views::class', $test_case );
		$this->assertStringNotContainsString( 'PostTypes::class', $test_case );
	}

	/**
	 * A module that acts on its own has to sit under a heading: one left at the
	 * top level throws at run(), which would make the generated file the one
	 * thing in the plugin that cannot be run.
	 */
	public function test_a_bootable_module_is_declared_under_a_hook_heading(): void {
		$this->install_module( 'Modules/PostTypes/PostTypes.php' );
		$this->install_module( 'Modules/Views.php' );

		$this->run_tests();

		$test_case = $this->read_target_file( 'tests/php/Support/TestCase.php' );

		$this->assertMatchesRegularExpression(
			"/'zestry-tests-demo-test_loaded' => array\(\s*PostTypes::class,/",
			$test_case,
			'A Bootable module belongs under the throwaway plugin\'s own loaded hook.'
		);

		// And the plain one stays at the top level, above that heading.
		$this->assertLessThan(
			(int) strpos( $test_case, '_loaded' ),
			(int) strpos( $test_case, 'Views::class,' ),
			'A module that does nothing until asked has no timing to declare.'
		);
	}

	public function test_it_adds_phpunit_and_the_tests_autoload_entry_to_composer_json(): void {
		$this->run_tests();

		$composer = $this->read_target_json( 'composer.json' );

		$this->assertArrayHasKey( 'phpunit/phpunit', $composer['require-dev'] );
		$this->assertArrayHasKey( 'yoast/phpunit-polyfills', $composer['require-dev'] );

		// The two an editor needs: the base the generated TestCase names, and
		// the stubs declaring everything between that and PHPUnit's own
		// assertions -- without which `assertNotWPError()` reads as undefined.
		$this->assertArrayHasKey( 'yoast/wp-test-utils', $composer['require-dev'] );
		$this->assertArrayHasKey( 'php-stubs/wordpress-tests-stubs', $composer['require-dev'] );
		$this->assertSame( 'tests/php/', $composer['autoload-dev']['psr-4']['Acme\\Demo\\Tests\\'] );
		$this->assertSame( 'phpunit', $composer['scripts']['test'] );
	}

	public function test_it_adds_wp_env_and_its_scripts_to_package_json(): void {
		$this->run_tests();

		$package = $this->read_target_json( 'package.json' );

		$this->assertArrayHasKey( '@wordpress/env', $package['devDependencies'] );
		$this->assertSame( 'wp-env --config .wp-env.test.json start', $package['scripts']['env:start'] );
		$this->assertStringContainsString( '--config .wp-env.test.json', $package['scripts']['test:php'] );
		$this->assertStringContainsString(
			"--env-cwd='wp-content/plugins/zestry-tests-demo'",
			$package['scripts']['test:php']
		);
	}

	/**
	 * The config names the plugin's own directory, since that is where wp-env
	 * mounts it and where the test command has to change into.
	 */
	public function test_the_wp_env_config_maps_the_plugin_directory(): void {
		$this->run_tests();

		$config = $this->read_target_json( '.wp-env.test.json' );

		$this->assertArrayHasKey( 'wp-content/plugins/zestry-tests-demo', $config['mappings'] );
		$this->assertFalse( $config['testsEnvironment'] );
	}

	public function test_no_wp_env_skips_the_config_and_its_scripts(): void {
		$this->run_tests( array( 'wp-env' => false ) );

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/.wp-env.test.json' );

		$package = $this->read_target_json( 'package.json' );

		$this->assertArrayNotHasKey( '@wordpress/env', $package['devDependencies'] );
		$this->assertArrayNotHasKey( 'env:start', $package['scripts'] );
		$this->assertArrayNotHasKey( 'test:php', $package['scripts'] );

		// The PHP half is still written: it is what WP_TESTS_DIR is for.
		$this->assertFileExists( $this->target_plugin_dir . '/tests/php/bootstrap.php' );
	}

	/**
	 * Additive throughout, like every other scaffold here. Running it a second
	 * time is how a plugin picks up a file a later toolkit release added, and
	 * it must not cost anything already written.
	 */
	public function test_running_it_twice_changes_nothing(): void {
		$this->run_tests();

		$before = $this->hash_target_tree();

		$this->run_tests();

		$this->assertSame( $before, $this->hash_target_tree() );
	}

	public function test_an_existing_file_is_left_exactly_as_it_is(): void {
		$this->write_target_file( 'phpunit.xml.dist', '<phpunit><!-- mine --></phpunit>' );

		$this->run_tests();

		$this->assertStringContainsString( 'mine', $this->read_target_file( 'phpunit.xml.dist' ) );
	}

	/**
	 * The generated files are written under the plugin's namespace and import
	 * its copy of Plugin, neither of which exists before `init` has run.
	 */
	public function test_it_refuses_a_plugin_that_was_never_initialized(): void {
		unlink( $this->target_plugin_dir . '/zestry.json' );

		$this->run_tests();

		$this->assertStringContainsString(
			'zestry.json does not exist',
			(string) ( \WP_CLI::last( 'error' )[0] ?? '' )
		);

		foreach ( self::WRITTEN as $relative ) {
			$this->assertFileDoesNotExist( $this->target_plugin_dir . '/' . $relative );
		}
	}

	public function test_it_ignores_the_result_cache(): void {
		$this->run_tests();

		$this->assertStringContainsString(
			'.phpunit.result.cache',
			$this->read_target_file( '.gitignore' )
		);
	}

	public function test_it_ignores_playwright_artifacts(): void {
		$this->run_tests();

		$this->assertStringContainsString( 'artifacts/', $this->read_target_file( '.gitignore' ) );
	}

	/**
	 * Each runner owns one directory, so PHPUnit must collect `tests/php/`
	 * alone -- and not the support code that sits inside it.
	 */
	public function test_phpunit_collects_the_php_directory_and_excludes_support(): void {
		$this->run_tests();

		$config = $this->read_target_file( 'phpunit.xml.dist' );

		$this->assertStringContainsString( 'bootstrap="tests/php/bootstrap.php"', $config );
		$this->assertStringContainsString( '<directory suffix="Test.php">tests/php</directory>', $config );
		$this->assertStringContainsString( '<exclude>tests/php/Support</exclude>', $config );
	}

	/**
	 * The bootstrap sits two levels down now, and the autoloader is at the root.
	 */
	public function test_the_bootstrap_reaches_the_root_autoloader(): void {
		$this->run_tests();

		$this->assertStringContainsString(
			"dirname( __DIR__, 2 ) . '/vendor/autoload.php'",
			$this->read_target_file( 'tests/php/bootstrap.php' )
		);
	}

	public function test_it_adds_the_javascript_runners_and_their_scripts(): void {
		$this->run_tests();

		$package = $this->read_target_json( 'package.json' );

		$this->assertArrayHasKey( '@wordpress/scripts', $package['devDependencies'] );
		$this->assertArrayHasKey( '@wordpress/e2e-test-utils-playwright', $package['devDependencies'] );
		$this->assertArrayHasKey( '@playwright/test', $package['devDependencies'] );
		$this->assertSame( 'wp-scripts test-unit-js --passWithNoTests', $package['scripts']['test:unit'] );
		$this->assertSame(
			'playwright test --config tests/e2e/playwright.config.js',
			$package['scripts']['test:e2e']
		);
	}

	/**
	 * The default config starts the development site with `npm run wp-env
	 * start`; the suite runs against the test one.
	 */
	public function test_the_playwright_config_starts_the_test_wordpress(): void {
		$this->run_tests();

		$config = $this->read_target_file( 'tests/e2e/playwright.config.js' );

		$this->assertStringContainsString( "require( '@wordpress/scripts/config/playwright.config.js' )", $config );
		$this->assertStringContainsString( "command: 'npm run env:start'", $config );

		// Its port, read before the base config takes its URL from the variable.
		$this->assertStringContainsString( "'.wp-env.test.json'", $config );
		$this->assertLessThan(
			(int) strpos( $config, "require( '@wordpress/scripts/config/playwright.config.js' )" ),
			(int) strpos( $config, 'process.env.WP_BASE_URL =' )
		);
		$this->assertStringContainsString( "testDir: './specs'", $config );
	}

	/**
	 * Identified by its file, which is exact, rather than by a slug derived
	 * from its Plugin Name header.
	 */
	public function test_the_example_spec_names_the_plugin_by_its_file(): void {
		$this->write_target_file( 'demo-plugin.php', "<?php\n/* Plugin Name: Demo Plugin */\n" );

		$this->run_tests();

		$this->assertStringContainsString(
			"const PLUGIN_FILE = 'zestry-tests-demo/demo-plugin.php';",
			$this->read_target_file( 'tests/e2e/specs/plugin.spec.js' )
		);
	}

	public function test_it_writes_an_example_unit_test_for_a_shared_package(): void {
		$this->write_target_file( 'src/shared/collections/index.ts', "export const x = 1;\n" );

		$this->run_tests();

		$test = $this->read_target_file( 'tests/js/shared/collections/index.test.ts' );

		$this->assertStringContainsString( "from '../../../../src/shared/collections/index';", $test );
		$this->assertStringContainsString( "describe( 'src/shared/collections/index.ts'", $test );
	}

	/**
	 * Nothing of the plugin's own to test means no test: a fake package would
	 * be one more file to delete.
	 */
	public function test_it_writes_no_unit_test_without_a_package(): void {
		$this->run_tests();

		$this->assertDirectoryDoesNotExist( $this->target_plugin_dir . '/tests/js' );
		$this->assertArrayHasKey( 'test:unit', $this->read_target_json( 'package.json' )['scripts'] );
	}

	/**
	 * Two index files is a question for `make test`, which asks it; the
	 * example moves on to a package that has one answer.
	 */
	public function test_an_ambiguous_package_is_passed_over_for_the_example(): void {
		$this->write_target_file( 'src/shared/alpha/index.ts', "export {};\n" );
		$this->write_target_file( 'src/shared/alpha/index.js', "export {};\n" );
		$this->write_target_file( 'src/entries/dashboard/index.js', "export {};\n" );

		$this->run_tests();

		$this->assertDirectoryDoesNotExist( $this->target_plugin_dir . '/tests/js/shared' );
		$this->assertFileExists( $this->target_plugin_dir . '/tests/js/entries/dashboard/index.test.js' );
	}

	public function test_no_php_skips_the_phpunit_suite_and_composer(): void {
		$this->run_tests( array( 'php' => false ) );

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/phpunit.xml.dist' );
		$this->assertDirectoryDoesNotExist( $this->target_plugin_dir . '/tests/php' );
		$this->assertArrayNotHasKey( 'require-dev', $this->read_target_json( 'composer.json' ) );

		// The end-to-end tests still want a WordPress, without a PHP runner.
		$scripts = $this->read_target_json( 'package.json' )['scripts'];

		$this->assertArrayHasKey( 'env:start', $scripts );
		$this->assertArrayNotHasKey( 'test:php', $scripts );
	}

	public function test_no_unit_and_no_e2e_leave_the_php_suite_alone(): void {
		$this->write_target_file( 'src/shared/collections/index.ts', "export {};\n" );

		$this->run_tests(
			array(
				'unit' => false,
				'e2e'  => false,
			)
		);

		$this->assertDirectoryDoesNotExist( $this->target_plugin_dir . '/tests/js' );
		$this->assertDirectoryDoesNotExist( $this->target_plugin_dir . '/tests/e2e' );
		$this->assertFileExists( $this->target_plugin_dir . '/tests/php/ExampleTest.php' );

		$package = $this->read_target_json( 'package.json' );

		$this->assertArrayNotHasKey( '@wordpress/scripts', $package['devDependencies'] );
		$this->assertArrayNotHasKey( 'test:unit', $package['scripts'] );
		$this->assertArrayNotHasKey( 'test:e2e', $package['scripts'] );
	}

	/**
	 * A Jest-only suite needs no WordPress, so none is provisioned.
	 */
	public function test_unit_alone_provisions_no_wordpress(): void {
		$this->run_tests(
			array(
				'php' => false,
				'e2e' => false,
			)
		);

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/.wp-env.test.json' );
		$this->assertArrayNotHasKey( '@wordpress/env', $this->read_target_json( 'package.json' )['devDependencies'] );
	}

	public function test_turning_every_suite_off_is_refused(): void {
		$this->run_tests(
			array(
				'php'  => false,
				'unit' => false,
				'e2e'  => false,
			)
		);

		$this->assertStringContainsString( 'Nothing to write', (string) ( \WP_CLI::last( 'error' )[0] ?? '' ) );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/package.json' );
	}

	/**
	 * Stand a copied module file up on disk, which is all the command reads.
	 *
	 * @param string $relative_class_path The file's path under `lib/Core/`.
	 * @return void
	 */
	private function install_module( string $relative_class_path ): void {
		$this->write_target_file( 'lib/Core/' . $relative_class_path, "<?php\n" );
	}

	/**
	 * Write a file, and its parent directories, into the throwaway plugin.
	 *
	 * @param string $relative Path relative to the throwaway plugin's root.
	 * @param string $contents What to write.
	 * @return void
	 */
	private function write_target_file( string $relative, string $contents ): void {
		$absolute = $this->target_plugin_dir . '/' . $relative;

		if ( ! is_dir( dirname( $absolute ) ) ) {
			mkdir( dirname( $absolute ), 0777, true );
		}

		file_put_contents( $absolute, $contents );
	}

	/**
	 * Read a file back out of the throwaway plugin.
	 *
	 * @param string $relative Path relative to the throwaway plugin's root.
	 * @return string
	 */
	private function read_target_file( string $relative ): string {
		return (string) file_get_contents( $this->target_plugin_dir . '/' . $relative );
	}

	/**
	 * Read and decode a JSON file from the throwaway plugin.
	 *
	 * @param string $relative Path relative to the throwaway plugin's root.
	 * @return array<string, mixed>
	 */
	private function read_target_json( string $relative ): array {
		return (array) json_decode( $this->read_target_file( $relative ), true );
	}

	/**
	 * Every file in the throwaway plugin, with the hash of its contents.
	 *
	 * @return array<string, string> Relative path => md5.
	 */
	private function hash_target_tree(): array {
		$hashes = array();
		$items  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->target_plugin_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $items as $item ) {
			if ( $item->isFile() ) {
				$relative            = substr( $item->getPathname(), strlen( $this->target_plugin_dir ) + 1 );
				$hashes[ $relative ] = (string) md5_file( $item->getPathname() );
			}
		}

		ksort( $hashes );

		return $hashes;
	}

	/**
	 * Run the command against the throwaway plugin.
	 *
	 * @param array<string, mixed> $overrides Named arguments, merged over the defaults.
	 * @return void
	 */
	private function run_tests( array $overrides = array() ): void {
		\WP_CLI::reset();

		$package_plugin = ( new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-tests-demo' ) )
			->declare_multiple( $this->get_toolkit_modules() );

		$command = require dirname( __DIR__, 3 ) . '/resources/commands/tests.php';
		$package_plugin->wire( $command );

		$assoc_args = array_merge( array( 'yes' => true ), $overrides );

		$command->set_arguments( array(), $assoc_args );

		$previous_cwd = (string) getcwd();
		chdir( $this->target_plugin_dir );

		try {
			$command->handle( array(), $assoc_args );
		} finally {
			chdir( $previous_cwd );
		}
	}
}
