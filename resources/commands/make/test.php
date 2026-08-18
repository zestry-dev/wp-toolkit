<?php

/**
 * Devtool command: `wp zt make test <name>`.
 *
 * Generates one PHPUnit test class into the suite `wp zt tests` writes, extending
 * that suite's own base test case.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\Kernel\Helpers\Str;

return new class() extends MakeCommand {

	/**
	 * Where the suite's base test case sits, relative to the plugin root.
	 */
	private const BASE_TEST_CASE = 'tests/Support/TestCase.php';

	/**
	 * Generate a test class.
	 *
	 * Requires `wp zt tests` to have already run: the generated class extends
	 * that suite's `TestCase`, which is what gives it `$this->plugin` and a
	 * throwaway directory to write fixtures into.
	 *
	 * The file lands in `tests/Integration/` with `Test` appended to the name,
	 * which is the suffix `phpunit.xml.dist` collects on -- so `make test
	 * Reports` writes `ReportsTest.php` and giving the suffix yourself is
	 * accepted rather than doubled.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The class name, e.g. 'Reports'. Written exactly as given -- this is a
	 * class name, not a kebab-case local one. Qualify it to group:
	 * `Modules/Reports` writes `tests/Integration/Modules/ReportsTest.php`.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # A test for something you are about to write.
	 *     $ wp zt make test Reports
	 *     Success: Created tests/Integration/ReportsTest.php
	 *
	 *     # The suffix is optional, and never doubled.
	 *     $ wp zt make test ReportsTest
	 *     Success: Created tests/Integration/ReportsTest.php
	 *
	 *     # Grouped, the way the suite grows.
	 *     $ wp zt make test Modules/Reports
	 *     Success: Created tests/Integration/Modules/ReportsTest.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$plugin_root = $this->with( ConsumerPlugin::class )->get_plugin_root();

		/*
		 * Refused rather than written, because the failure is a fatal rather
		 * than a failing test: a class extending one that does not exist stops
		 * PHPUnit before it collects anything, and the message names the parent
		 * rather than what to do about it.
		 */
		if ( ! is_file( Str::join_path( $plugin_root, self::BASE_TEST_CASE ) ) ) {
			$this->error(
				'There is no test suite here yet -- ' . self::BASE_TEST_CASE
					. ' is what this extends. Run `wp zt tests` first.'
			);
			return;
		}

		parent::handle( $args, $assoc_args );
	}

	/**
	 * Fill in the class name, without the suffix the filename carries.
	 *
	 * @param string $name       The name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array<string, string>
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$segments = $this->get_name_segments( $name );

		return array( 'class_name' => (string) array_pop( $segments ) );
	}

	protected function get_stub(): string {
		return 'test.php.stub';
	}

	/**
	 * The suite's own directory, fixed rather than read from zestry.json.
	 *
	 * A test is not a class the plugin reaches by namespace: it is autoloaded
	 * through the `autoload-dev` entry `wp zt tests` writes, which maps `tests/`
	 * whatever the plugin's own source root is called.
	 *
	 * @param array{namespace: string, root: string} $config The project's zestry.json.
	 * @return string
	 */
	protected function get_default_dir( array $config ): string {
		return 'tests/Integration';
	}

	/**
	 * Append the suffix `phpunit.xml.dist` collects on.
	 *
	 * The second type to override this, after `migration`, and for the same
	 * reason: the filename is not the name that was given. A name that already
	 * ends in `Test` keeps exactly one.
	 *
	 * @param string $dir  The type's own destination directory.
	 * @param string $name The name given on the command line.
	 * @return string
	 */
	protected function get_destination_path( string $dir, string $name ): string {
		return trim( $dir, '/\\' ) . '/' . $name . 'Test.php';
	}

	/**
	 * Drop a `Test` suffix that was given, so the destination never doubles it.
	 *
	 * @param string $name The name given on the command line.
	 * @return string
	 */
	protected function normalize_name( string $name ): string {
		return (string) preg_replace( '/Test$/', '', $name );
	}

	protected function get_name_constraint(): string {
		return 'the file is named `{name}Test.php`, and the suffix is added for you.';
	}

	protected static function get_type(): string {
		return 'test';
	}
};
