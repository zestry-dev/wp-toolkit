<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Services\Path;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Non-containment behavior of the Path module: URL/path builders, existence
 * checks, uploads directories, and base-value caching.
 *
 * Traversal / symlink / '..' rejection and URL traversal are covered by
 * PathContainmentTest and are intentionally not duplicated here.
 *
 * @covers \Zestry\WPToolkit\Services\Path
 */
final class PathTest extends TestCase {

	private function path(): Path {
		return $this->plugin->get( Path::class );
	}

	public function test_get_plugin_url_empty_path_returns_base_url(): void {
		$expected = untrailingslashit( plugin_dir_url( $this->entry_file ) );

		$this->assertSame( $expected, $this->path()->get_plugin_url() );
	}

	public function test_get_plugin_url_builds_encoded_resource_url(): void {
		$base = untrailingslashit( plugin_dir_url( $this->entry_file ) );
		$url  = $this->path()->get_plugin_url( 'assets/app.js' );

		$this->assertSame( $base . '/assets/app.js', $url );
	}

	public function test_get_plugin_url_encodes_each_segment(): void {
		$url = $this->path()->get_plugin_url( 'sub dir/a b.js' );

		$this->assertStringContainsString( '/sub%20dir/a%20b.js', $url );
	}

	public function test_get_plugin_url_appends_query_args(): void {
		$url = $this->path()->get_plugin_url( 'assets/app.js', array( 'v' => '2' ) );

		$this->assertStringContainsString( '/assets/app.js', $url );
		$this->assertStringContainsString( 'v=2', $url );
	}

	public function test_get_plugin_url_allow_escape_skips_containment(): void {
		// With containment waived the escaping segment is encoded and appended
		// instead of throwing, exercising the $allow_escape = true branch.
		$base = untrailingslashit( plugin_dir_url( $this->entry_file ) );

		$this->assertSame(
			$base . '/../evil',
			$this->path()->get_plugin_url( '../evil', array(), true )
		);
	}

	public function test_get_plugin_upload_url_empty_path_appends_no_segment(): void {
		$upload   = wp_upload_dir();
		$expected = untrailingslashit( $upload['baseurl'] ) . '/' . $this->plugin->get_slug();

		$this->assertSame( $expected, $this->path()->get_plugin_upload_url() );
	}

	public function test_get_plugin_upload_url_appends_path_segment(): void {
		$upload   = wp_upload_dir();
		$expected = untrailingslashit( $upload['baseurl'] ) . '/' . $this->plugin->get_slug() . '/file.png';

		$this->assertSame( $expected, $this->path()->get_plugin_upload_url( 'file.png' ) );
	}

	public function test_get_plugin_upload_url_allow_escape_skips_containment(): void {
		// Waiving containment must skip the is_escaping_root() check and still encode
		// each segment, exercising the $allow_escape = true branch on this builder.
		$upload = wp_upload_dir();
		$base   = untrailingslashit( $upload['baseurl'] ) . '/' . $this->plugin->get_slug();

		$this->assertSame(
			$base . '/../evil',
			$this->path()->get_plugin_upload_url( '../evil', array(), true )
		);
	}

	public function test_plugin_file_exists_is_true_for_a_created_file(): void {
		$this->write_plugin_file( 'assets/app.js', '// app' );

		$this->assertTrue( $this->path()->plugin_file_exists( 'assets/app.js' ) );
	}

	public function test_plugin_file_exists_is_false_for_a_missing_file(): void {
		$this->assertFalse( $this->path()->plugin_file_exists( 'assets/missing.js' ) );
	}

	public function test_is_plugin_dir_is_true_for_a_directory(): void {
		$this->write_plugin_file( 'assets/app.js', '// app' );

		$this->assertTrue( $this->path()->is_plugin_dir( 'assets' ) );
	}

	public function test_is_plugin_dir_is_false_for_a_missing_path(): void {
		$this->assertFalse( $this->path()->is_plugin_dir( 'no-such-dir' ) );
	}

	public function test_is_plugin_dir_is_false_for_a_file(): void {
		$this->write_plugin_file( 'assets/app.js', '// app' );

		$this->assertFalse( $this->path()->is_plugin_dir( 'assets/app.js' ) );
	}

	public function test_get_uploads_dir_returns_a_non_empty_string(): void {
		$dir = $this->path()->get_uploads_dir();

		$this->assertIsString( $dir );
		$this->assertNotSame( '', $dir );
		$this->assertStringEndsNotWith( '/', $dir );
	}

	public function test_get_plugin_uploads_dir_creates_and_returns_the_slug_directory(): void {
		$dir = $this->path()->get_plugin_uploads_dir();

		$this->assertSame(
			$this->path()->get_uploads_dir() . '/' . $this->plugin->get_slug(),
			$dir
		);
		$this->assertTrue( is_dir( $dir ) );

		// Do not leave the created directory behind for other tests.
		$this->remove_dir( $dir );
	}

	public function test_get_plugin_uploads_dir_throws_when_directory_cannot_be_created(): void {
		// Occupy the exact target path with a regular file so wp_mkdir_p() fails,
		// exercising the RuntimeException branch. wp_mkdir_p returns false when the
		// path exists but is not a directory.
		$target = $this->path()->get_uploads_dir() . '/' . $this->plugin->get_slug();

		if ( is_dir( $target ) ) {
			$this->remove_dir( $target );
		}

		file_put_contents( $target, 'not a directory' );

		try {
			$this->expectException( \RuntimeException::class );
			$this->path()->get_plugin_uploads_dir();
		} finally {
			unlink( $target );
		}
	}

	public function test_get_plugin_path_empty_returns_base_dir(): void {
		$expected = untrailingslashit( plugin_dir_path( $this->entry_file ) );

		$this->assertSame( $expected, $this->path()->get_plugin_path() );
	}

	public function test_base_dir_is_cached_across_calls(): void {
		$path = $this->path();

		// Second call hits the cached ( property already set ) branch.
		$this->assertSame( $path->get_plugin_path(), $path->get_plugin_path() );
	}

	public function test_base_url_is_cached_across_calls(): void {
		$path = $this->path();

		// Second call hits the cached ( property already set ) branch.
		$this->assertSame( $path->get_plugin_url(), $path->get_plugin_url() );
	}

	public function test_containment_skips_the_symlink_check_when_the_base_dir_is_gone(): void {
		// If the plugin base directory cannot be resolved (realpath() === false), the
		// symlink-containment step has no root to compare against and is skipped; the
		// lexical '..' guard still protects traversal. Build a Path bound to a temp
		// plugin dir, then delete that dir so realpath(base) returns false.
		$dir = $this->make_temp_dir( 'zestry-gone-' );
		file_put_contents( $dir . '/plugin.php', "<?php\n" );
		$plugin = new \Zestry\WPToolkit\Kernel\Plugin( $dir . '/plugin.php', 'zestry-gone' );
		$path   = $plugin->get( \Zestry\WPToolkit\Services\Path::class );

		$this->remove_dir( $dir );

		// A plain relative path still builds (no exception): is_resolved_escaping_root()
		// returns false early because the base dir no longer resolves.
		$built = $path->get_plugin_path( 'views/x.php' );
		$this->assertStringEndsWith( '/views/x.php', $built );

		// The lexical traversal guard is unaffected.
		$this->expectException( \InvalidArgumentException::class );
		$path->get_plugin_path( '../escape' );
	}
}
