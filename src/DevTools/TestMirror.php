<?php

/**
 * DevTools API: TestMirror class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Helpers\Str;

/**
 * Where a consumer's tests live, and which test belongs to which source file.
 *
 * One `tests/` root split by runner: `tests/php/` for PHPUnit, `tests/js/` for
 * Jest, `tests/e2e/` for Playwright. Each runner owns one directory, so no
 * config has to tell one runner's files from another's by name. A test mirrors
 * the path of the file it covers, so finding one from the other is never a
 * search.
 *
 * Shared by `wp zt tests` and `wp zt make test`, which is the reason it exists:
 * both have to agree on where a test goes, and a second copy of the mapping
 * would be the first thing to drift.
 *
 * @rationale
 * PHP mirrors with every segment PascalCased, because a test class autoloads
 * through PSR-4 and a namespace segment cannot hold a dash:
 * `resources/post-types/book.php` becomes `PostTypes/Book`. JavaScript mirrors
 * exactly, since Jest collects `*.test.*` wherever it sits.
 *
 * A directory's entry is resolved without a priority order. The generated
 * `webpack.config.js` holds one (`INDEX_FILES`), and copying it here would be a
 * second list that the consumer's own config -- written once and then theirs --
 * is free to leave behind. The order only ever decides between two index files
 * in one directory, and that is asked rather than guessed.
 */
final class TestMirror {

	/**
	 * PHPUnit's directory: the tests, and `Support/` beside them.
	 */
	public const PHP_DIR = 'tests/php';

	/**
	 * Where the PHP suite's base test case and doubles live.
	 */
	public const SUPPORT_DIR = self::PHP_DIR . '/Support';

	/**
	 * Jest's directory, mirroring `src/`.
	 */
	public const JS_DIR = 'tests/js';

	/**
	 * Playwright's directory: its config, and the specs under `specs/`.
	 */
	public const E2E_DIR = 'tests/e2e';

	/**
	 * Script extensions a unit test can be written for.
	 *
	 * Exactly what `@wordpress/jest-preset-default` both collects and
	 * transforms (`[jt]sx?`). A test for an `.mjs` file would sit there never
	 * collected, passing by never running.
	 *
	 * @var array<int, string>
	 */
	public const SCRIPT_EXTENSIONS = array( 'js', 'jsx', 'ts', 'tsx' );

	/**
	 * Where JavaScript source lives, dropped from the front of a mirrored path.
	 */
	private const SCRIPT_ROOT = 'src';

	/**
	 * Where discovered feature files live, dropped from a mirrored PHP path.
	 */
	private const RESOURCES_ROOT = 'resources';

	/**
	 * The class name of the test for a PHP source file, without its suffix.
	 *
	 * Relative to {@see PHP_DIR}, with `/` between segments: `lib/Modules/Reports.php`
	 * gives `Modules/Reports`, `resources/post-types/book.php` gives
	 * `PostTypes/Book`. The source root and `resources/` are dropped, since
	 * every test would otherwise start with the same segment.
	 *
	 * @param string $relative    The source file, relative to the plugin root.
	 * @param string $source_root The plugin's class root, from zestry.json.
	 * @return string
	 * @throws \InvalidArgumentException When a segment cannot become a class name.
	 */
	public static function get_php_test_name( string $relative, string $source_root ): string {
		$path = self::without_prefix(
			self::without_prefix( self::normalize( $relative ), \trim( $source_root, '/\\' ) ),
			self::RESOURCES_ROOT
		);

		$path     = (string) \preg_replace( '/\.php$/i', '', $path );
		$segments = array();

		foreach ( \explode( '/', $path ) as $segment ) {
			$class_segment = Str::pascal( $segment );

			if ( 1 !== \preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $class_segment ) ) {
				throw new \InvalidArgumentException(
					\sprintf(
						'"%s" in %s cannot become part of a class name. Give the test a class name instead: `wp zt make test <ClassName>`.',
						$segment,
						$relative
					)
				);
			}

			$segments[] = $class_segment;
		}

		return \implode( '/', $segments );
	}

	/**
	 * The script a unit test should cover, given a file or a directory.
	 *
	 * A file is taken as given, as long as Jest would collect a test for it. A
	 * directory is resolved the way a package is: its `package.json` `main`,
	 * otherwise its one `index` file.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $relative    A file or directory, relative to the plugin root.
	 * @return string The script, relative to the plugin root.
	 * @throws \InvalidArgumentException When nothing, or more than one thing, could be meant.
	 */
	public static function resolve_script( string $plugin_root, string $relative ): string {
		$relative = self::normalize( $relative );
		$absolute = Str::join_path( $plugin_root, $relative );

		if ( \is_file( $absolute ) ) {
			self::assert_script_extension( $relative );

			return $relative;
		}

		if ( ! \is_dir( $absolute ) ) {
			throw new \InvalidArgumentException( \sprintf( 'There is no "%s" in this plugin.', $relative ) );
		}

		$main = self::read_main( $absolute );

		if ( null !== $main ) {
			$script = self::normalize( $relative . '/' . $main );

			if ( ! \is_file( Str::join_path( $plugin_root, $script ) ) ) {
				throw new \InvalidArgumentException(
					\sprintf( '%s/package.json names "%s" as its main, and there is no such file.', $relative, $main )
				);
			}

			self::assert_script_extension( $script );

			return $script;
		}

		$found = array();

		foreach ( self::SCRIPT_EXTENSIONS as $extension ) {
			if ( \is_file( $absolute . '/index.' . $extension ) ) {
				$found[] = 'index.' . $extension;
			}
		}

		if ( array() === $found ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'Nothing to test in %s: it has no package.json "main" and no index.{%s}.',
					$relative,
					\implode( ',', self::SCRIPT_EXTENSIONS )
				)
			);
		}

		if ( \count( $found ) > 1 ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'%s has %s. Name the one to test: `wp zt make test %s/%s`.',
					$relative,
					\implode( ' and ', $found ),
					$relative,
					$found[0]
				)
			);
		}

		return $relative . '/' . $found[0];
	}

	/**
	 * The unit test's path for a script, relative to the plugin root.
	 *
	 * `src/shared/collections/index.ts` gives
	 * `tests/js/shared/collections/index.test.ts`: `src/` is dropped, and the
	 * test keeps its source's extension, so a TypeScript file gets a
	 * TypeScript test.
	 *
	 * @param string $script The script, relative to the plugin root.
	 * @return string
	 */
	public static function get_js_test_path( string $script ): string {
		$path      = self::without_prefix( self::normalize( $script ), self::SCRIPT_ROOT );
		$extension = (string) \pathinfo( $path, PATHINFO_EXTENSION );

		return self::JS_DIR . '/' . \substr( $path, 0, -\strlen( $extension ) - 1 ) . '.test.' . $extension;
	}

	/**
	 * The import specifier a test uses to reach its script.
	 *
	 * Relative, and without the extension, the way Jest resolves it.
	 *
	 * @param string $test   The test file, relative to the plugin root.
	 * @param string $script The script, relative to the plugin root.
	 * @return string
	 */
	public static function get_import_path( string $test, string $script ): string {
		$depth  = \substr_count( self::normalize( $test ), '/' );
		$target = (string) \preg_replace( '/\.[^.\/]+$/', '', self::normalize( $script ) );

		return \str_repeat( '../', $depth ) . $target;
	}

	/**
	 * Whether a path names a script rather than a PHP file or a class name.
	 *
	 * @param string $relative A path as typed.
	 * @return bool
	 */
	public static function is_script_path( string $relative ): bool {
		$extension = \strtolower( (string) \pathinfo( $relative, PATHINFO_EXTENSION ) );

		return \in_array( $extension, self::SCRIPT_EXTENSIONS, true )
			|| \in_array( $extension, array( 'mjs', 'cjs', 'mts', 'cts' ), true );
	}

	/**
	 * Refuse a script Jest would never collect a test for.
	 *
	 * @param string $relative The script, relative to the plugin root.
	 * @return void
	 * @throws \InvalidArgumentException When the extension is not one Jest handles.
	 */
	private static function assert_script_extension( string $relative ): void {
		$extension = \strtolower( (string) \pathinfo( $relative, PATHINFO_EXTENSION ) );

		if ( \in_array( $extension, self::SCRIPT_EXTENSIONS, true ) ) {
			return;
		}

		throw new \InvalidArgumentException(
			\sprintf(
				'%s is not a script Jest runs a test for: only .%s are collected, so a test for it would pass by never running.',
				$relative,
				\implode( ', .', self::SCRIPT_EXTENSIONS )
			)
		);
	}

	/**
	 * A package directory's declared `main`, if it has one.
	 *
	 * @param string $directory Absolute path to the directory.
	 * @return string|null
	 */
	private static function read_main( string $directory ): ?string {
		$manifest = $directory . '/package.json';

		if ( ! \is_file( $manifest ) ) {
			return null;
		}

		$data = \json_decode( (string) \file_get_contents( $manifest ), true );
		$main = \is_array( $data ) ? ( $data['main'] ?? null ) : null;

		return \is_string( $main ) && '' !== $main ? $main : null;
	}

	/**
	 * Forward slashes, no leading `./`, no surrounding slashes.
	 *
	 * @param string $path A relative path as typed.
	 * @return string
	 */
	private static function normalize( string $path ): string {
		$path = \str_replace( '\\', '/', $path );
		$path = (string) \preg_replace( '#^(\./)+#', '', $path );

		return \trim( (string) \preg_replace( '#/+#', '/', $path ), '/' );
	}

	/**
	 * A path with one leading directory removed, when it starts with it.
	 *
	 * @param string $path   A normalized relative path.
	 * @param string $prefix The directory to drop.
	 * @return string
	 */
	private static function without_prefix( string $path, string $prefix ): string {
		return '' !== $prefix && \str_starts_with( $path, $prefix . '/' ) ? \substr( $path, \strlen( $prefix ) + 1 ) : $path;
	}
}
