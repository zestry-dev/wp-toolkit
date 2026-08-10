<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Support;

use Zestry\WPToolkit\Kernel\Plugin;
use Yoast\WPTestUtils\WPIntegration\TestCase as WPTestCase;

/**
 * Base test case for the toolkit's integration tests.
 *
 * Each test gets a throwaway plugin directory on disk and a fresh Plugin instance
 * pointed at it, so Path, Views, and file discovery run against real files while
 * the WordPress test suite provides real hooks, options, and nonces.
 *
 * The inherited API is annotated below because nothing in this repository
 * declares it: the chain reaches `WP_UnitTestCase`, which ships with the
 * WordPress test suite installed inside the wp-env containers, so an editor
 * resolving only `vendor/` sees the parent — and everything above it, PHPUnit's
 * assertions included — as undefined. These are the ones this suite calls;
 * `PHPUnit\Framework\Assert` and `WP_UnitTestCase_Base` carry many more.
 *
 * @method static \WP_UnitTest_Factory factory()
 * @method void setExpectedIncorrectUsage( string $doing_it_wrong )
 * @method void expectException( string $exception )
 * @method void expectExceptionMessage( string $message )
 * @method void expectExceptionMessageMatches( string $regular_expression )
 * @method void expectNotToPerformAssertions()
 * @method void expectOutputString( string $expected )
 * @method void fail( string $message = '' )
 * @method void markTestSkipped( string $message = '' )
 * @method void assertArrayHasKey( int|string $key, array $array, string $message = '' )
 * @method void assertArrayNotHasKey( int|string $key, array $array, string $message = '' )
 * @method void assertContains( mixed $needle, iterable $haystack, string $message = '' )
 * @method void assertNotContains( mixed $needle, iterable $haystack, string $message = '' )
 * @method void assertCount( int $expected_count, \Countable|iterable $haystack, string $message = '' )
 * @method void assertDirectoryDoesNotExist( string $directory, string $message = '' )
 * @method void assertFileExists( string $filename, string $message = '' )
 * @method void assertFileDoesNotExist( string $filename, string $message = '' )
 * @method void assertMatchesRegularExpression( string $pattern, string $string, string $message = '' )
 * @method void assertDoesNotMatchRegularExpression( string $pattern, string $string, string $message = '' )
 * @method void assertEquals( mixed $expected, mixed $actual, string $message = '' )
 * @method void assertSame( mixed $expected, mixed $actual, string $message = '' )
 * @method void assertNotSame( mixed $expected, mixed $actual, string $message = '' )
 * @method void assertTrue( mixed $condition, string $message = '' )
 * @method void assertFalse( mixed $condition, string $message = '' )
 * @method void assertNotFalse( mixed $condition, string $message = '' )
 * @method void assertNull( mixed $actual, string $message = '' )
 * @method void assertNotNull( mixed $actual, string $message = '' )
 * @method void assertNotEmpty( mixed $actual, string $message = '' )
 * @method void assertInstanceOf( string $expected, mixed $actual, string $message = '' )
 * @method void assertIsArray( mixed $actual, string $message = '' )
 * @method void assertIsCallable( mixed $actual, string $message = '' )
 * @method void assertIsInt( mixed $actual, string $message = '' )
 * @method void assertIsString( mixed $actual, string $message = '' )
 * @method void assertGreaterThan( mixed $expected, mixed $actual, string $message = '' )
 * @method void assertGreaterThanOrEqual( mixed $expected, mixed $actual, string $message = '' )
 * @method void assertLessThan( mixed $expected, mixed $actual, string $message = '' )
 * @method void assertLessThanOrEqual( mixed $expected, mixed $actual, string $message = '' )
 * @method void assertStringContainsString( string $needle, string $haystack, string $message = '' )
 * @method void assertStringNotContainsString( string $needle, string $haystack, string $message = '' )
 * @method void assertStringStartsWith( string $prefix, string $string, string $message = '' )
 * @method void assertStringEndsWith( string $suffix, string $string, string $message = '' )
 * @method void assertStringEndsNotWith( string $suffix, string $string, string $message = '' )
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

		$this->plugin = new Plugin( $this->entry_file, 'zestry-test' );
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
