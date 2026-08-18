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
		'tests/bootstrap.php',
		'tests/Support/TestCase.php',
		'tests/Support/wp-cli-stubs.php',
		'tests/Integration/ExampleTest.php',
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
	 * The generated files are the plugin's own code, so they carry its
	 * namespace and import its copy of the kernel rather than this package's.
	 */
	public function test_the_generated_files_carry_the_configured_namespace(): void {
		$this->run_tests();

		$test_case = $this->read_target_file( 'tests/Support/TestCase.php' );

		$this->assertStringContainsString( 'namespace Acme\\Demo\\Tests\\Support;', $test_case );
		$this->assertStringContainsString( 'use Acme\\Demo\\Core\\Kernel\\Plugin;', $test_case );
		$this->assertStringNotContainsString( 'Zestry\\WPToolkit', $test_case );

		$this->assertStringContainsString(
			'namespace Acme\\Demo\\Tests\\Integration;',
			$this->read_target_file( 'tests/Integration/ExampleTest.php' )
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
			$this->read_target_file( 'tests/Support/TestCase.php' )
		);
	}

	/**
	 * Nothing is built that is not declared, so the base case has to name what
	 * a test may reach for -- and only what is actually on disk.
	 */
	public function test_it_declares_the_modules_that_are_installed(): void {
		$this->install_module( 'Modules/Views.php' );

		$this->run_tests();

		$test_case = $this->read_target_file( 'tests/Support/TestCase.php' );

		$this->assertStringContainsString( 'use Acme\\Demo\\Core\\Modules\\Views;', $test_case );
		$this->assertStringContainsString( 'Views::class,', $test_case );
	}

	public function test_it_declares_nothing_for_a_module_that_is_not_installed(): void {
		$this->run_tests();

		$test_case = $this->read_target_file( 'tests/Support/TestCase.php' );

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

		$test_case = $this->read_target_file( 'tests/Support/TestCase.php' );

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

		// The base the generated TestCase extends, so an editor has something
		// in vendor/ to resolve the assertions through.
		$this->assertArrayHasKey( 'yoast/wp-test-utils', $composer['require-dev'] );
		$this->assertSame( 'tests/', $composer['autoload-dev']['psr-4']['Acme\\Demo\\Tests\\'] );
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
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/package.json' );

		// The PHP half is still written: it is what WP_TESTS_DIR is for.
		$this->assertFileExists( $this->target_plugin_dir . '/tests/bootstrap.php' );
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
