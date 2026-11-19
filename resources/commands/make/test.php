<?php

/**
 * Devtool command: `wp zt make test <name>`.
 *
 * Generates one test: a PHPUnit class extending the suite `wp zt tests` writes,
 * or a Jest test for a script. Given a source file, the test lands at the path
 * that mirrors it.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\TestMirror;
use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\Kernel\Helpers\Str;

return new class() extends MakeCommand {

	/**
	 * Where the suite's base test case sits, relative to the plugin root.
	 */
	private const BASE_TEST_CASE = TestMirror::SUPPORT_DIR . '/TestCase.php';

	/**
	 * The script a JavaScript test is being written for, relative to the
	 * plugin root. Null while writing a PHP test.
	 *
	 * @var string|null
	 */
	private ?string $script = null;

	/**
	 * Generate a test, for a class name or for a source file.
	 *
	 * Given a file you already have, the test lands at the path that mirrors
	 * it, so finding one from the other is never a search:
	 *
	 * - A PHP file becomes a PHPUnit class under `tests/php/`, every segment
	 * turned into a class name: `resources/post-types/book.php` gives
	 * `tests/php/PostTypes/BookTest.php`, `lib/Modules/Reports.php` gives
	 * `tests/php/Modules/ReportsTest.php`.
	 *
	 * - A script becomes a Jest test under `tests/js/`, mirroring `src/` and
	 * keeping its extension: `src/shared/collections/index.ts` gives
	 * `tests/js/shared/collections/index.test.ts`. Only `.js`, `.jsx`, `.ts` and
	 * `.tsx` are accepted -- Jest collects nothing else, so a test for an
	 * `.mjs` file would pass by never running.
	 *
	 * - A directory is a package: its `package.json` `main`, or else its one
	 * `index` script. With both `index.ts` and `index.js` there, you are asked
	 * to name the one you mean rather than have one picked for you.
	 *
	 * Anything else is a class name, written under `tests/php/` as before.
	 *
	 * A PHP test needs `wp zt tests` to have run: it extends that suite's
	 * `TestCase`, which is what gives it `$this->plugin` and a throwaway
	 * directory to write fixtures into. A Jest test needs nothing of it but the
	 * `test:unit` script.
	 *
	 * The PHP class name carries the `Test` suffix `phpunit.xml.dist` collects
	 * on -- so `make test Reports` writes `ReportsTest.php`, and giving the
	 * suffix yourself is accepted rather than doubled.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : A source file or package directory to test, or a class name. A class
	 * name is written exactly as given; qualify it to group, so
	 * `Modules/Reports` writes `tests/php/Modules/ReportsTest.php`.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # A test for a post type you ship.
	 *     $ wp zt make test resources/post-types/book.php
	 *     Success: Created tests/php/PostTypes/BookTest.php
	 *
	 *     # A test for a script, in its own language.
	 *     $ wp zt make test src/shared/collections/index.ts
	 *     Success: Created tests/js/shared/collections/index.test.ts
	 *
	 *     # A package, by its directory.
	 *     $ wp zt make test src/shared/collections
	 *     Success: Created tests/js/shared/collections/index.test.ts
	 *
	 *     # A test for something you are about to write.
	 *     $ wp zt make test Reports
	 *     Success: Created tests/php/ReportsTest.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$this->script = null;

		if ( \count( $args ) < 1 ) {
			parent::handle( $args, $assoc_args );
			return;
		}

		$plugin_root = $this->with( ConsumerPlugin::class )->get_plugin_root();
		$given       = (string) $args[0];

		if ( $this->is_script_target( $plugin_root, $given ) ) {
			try {
				$this->script = TestMirror::resolve_script( $plugin_root, $given );
			} catch ( \InvalidArgumentException $exception ) {
				$this->error( $exception->getMessage() );
				return;
			}

			if ( ! $this->has_unit_script( $plugin_root ) ) {
				$this->log( 'There is no `test:unit` script in package.json yet. Run `wp zt tests` to add one.' );
			}

			/*
			 * The mirrored path, less the directory and the `.test.{ext}` that
			 * get_destination_path() puts back -- so the name the base class
			 * carries through is the one the file is named for.
			 */
			$test = TestMirror::get_js_test_path( $this->script );
			$name = (string) \preg_replace( '/\.test\.[^.]+$/', '', \substr( $test, \strlen( TestMirror::JS_DIR ) + 1 ) );

			parent::handle( array( $name ), $assoc_args );
			return;
		}

		/*
		 * Refused rather than written, because the failure is a fatal rather
		 * than a failing test: a class extending one that does not exist stops
		 * PHPUnit before it collects anything, and the message names the parent
		 * rather than what to do about it.
		 */
		if ( ! \is_file( Str::join_path( $plugin_root, self::BASE_TEST_CASE ) ) ) {
			$this->error(
				'There is no test suite here yet -- ' . self::BASE_TEST_CASE
					. ' is what this extends. Run `wp zt tests` first.'
			);
			return;
		}

		if ( \str_ends_with( \strtolower( $given ), '.php' ) && \is_file( Str::join_path( $plugin_root, $given ) ) ) {
			try {
				$config = $this->with( ZestryConfig::class )->read( $plugin_root );
				$given  = TestMirror::get_php_test_name( $given, $config['root'] );
			} catch ( \RuntimeException | \InvalidArgumentException $exception ) {
				// No zestry.json, or a segment no class name can carry: both say what to do.
				$this->error( $exception->getMessage() );
				return;
			}
		}

		parent::handle( array( $given ), $assoc_args );
	}

	/**
	 * Fill in the class and its namespace, or the script and how to import it.
	 *
	 * @param string $name       The name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array<string, string>
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		if ( null !== $this->script ) {
			return array(
				'source'      => $this->script,
				'import_path' => TestMirror::get_import_path( TestMirror::get_js_test_path( $this->script ), $this->script ),
			);
		}

		$segments   = $this->get_name_segments( $name );
		$class_name = (string) \array_pop( $segments );
		$config     = $this->with( ZestryConfig::class )->read( $this->with( ConsumerPlugin::class )->get_plugin_root() );

		// The namespace its folder gives it, which is what PSR-4 expects of a
		// class under `tests/php/Modules/`.
		$namespace = \implode( '\\', \array_merge( array( \rtrim( $config['namespace'], '\\' ), 'Tests' ), $segments ) );

		return array(
			'class_name'     => $class_name,
			'test_namespace' => $namespace,
		);
	}

	protected function get_stub(): string {
		return null === $this->script ? 'test.php.stub' : 'test.js.stub';
	}

	/**
	 * The runner's own directory, fixed rather than read from zestry.json.
	 *
	 * A test is not a class the plugin reaches by namespace: a PHP one is
	 * autoloaded through the `autoload-dev` entry `wp zt tests` writes, which
	 * maps `tests/php/` whatever the plugin's own source root is called.
	 *
	 * @param array{namespace: string, root: string} $config The project's zestry.json.
	 * @return string
	 */
	protected function get_default_dir( array $config ): string {
		return null === $this->script ? TestMirror::PHP_DIR : TestMirror::JS_DIR;
	}

	/**
	 * Append the suffix the runner collects on.
	 *
	 * The second type to override this, after `migration`, and for the same
	 * reason: the filename is not the name that was given. `Test.php` for
	 * PHPUnit, and `.test.` before the script's own extension for Jest.
	 *
	 * @param string $dir  The type's own destination directory.
	 * @param string $name The name given on the command line.
	 * @return string
	 */
	protected function get_destination_path( string $dir, string $name ): string {
		if ( null !== $this->script ) {
			return TestMirror::get_js_test_path( $this->script );
		}

		return \trim( $dir, '/\\' ) . '/' . $name . 'Test.php';
	}

	/**
	 * Drop a `Test` suffix that was given, so the destination never doubles it.
	 *
	 * A script's name is a path, mirrored as it is, so it is left alone.
	 *
	 * @param string $name The name given on the command line.
	 * @return string
	 */
	protected function normalize_name( string $name ): string {
		return null === $this->script ? (string) \preg_replace( '/Test$/', '', $name ) : $name;
	}

	protected function get_name_constraint(): string {
		return 'the file is named `{name}Test.php`, and the suffix is added for you.';
	}

	/**
	 * Whether the name given points at JavaScript rather than PHP.
	 *
	 * A script by its extension, whether or not it exists, so a mistyped path
	 * is refused as missing rather than written as a class name. A directory
	 * by being one -- a class name never names a real folder in the plugin.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $given       The name as typed.
	 * @return bool
	 */
	private function is_script_target( string $plugin_root, string $given ): bool {
		return TestMirror::is_script_path( $given ) || \is_dir( Str::join_path( $plugin_root, $given ) );
	}

	/**
	 * Whether package.json can run what this writes.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return bool
	 */
	private function has_unit_script( string $plugin_root ): bool {
		$manifest = Str::join_path( $plugin_root, 'package.json' );
		$data     = \is_file( $manifest ) ? \json_decode( (string) \file_get_contents( $manifest ), true ) : null;

		return \is_array( $data ) && isset( $data['scripts']['test:unit'] );
	}

	protected static function get_type(): string {
		return 'test';
	}
};
