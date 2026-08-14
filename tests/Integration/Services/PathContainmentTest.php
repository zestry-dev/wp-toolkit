<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Modules\Path;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Path traversal / containment behavior (review findings #18, #36).
 *
 * @covers \Zestry\WPToolkit\Modules\Path
 */
final class PathContainmentTest extends TestCase {

	private function path(): Path {
		return $this->plugin->get( Path::class );
	}

	public function test_builds_a_legitimate_nested_path(): void {
		$this->assertStringEndsWith(
			'/views/email.php',
			$this->path()->get_plugin_path( 'views/email.php' )
		);
	}

	public function test_builds_a_path_that_does_not_exist_yet(): void {
		// Path is a builder: a not-yet-created target must still resolve.
		$this->assertStringEndsWith(
			'/cache/generated.json',
			$this->path()->get_plugin_path( 'cache/generated.json' )
		);
	}

	public function test_empty_path_returns_the_base_dir(): void {
		$this->assertSame( rtrim( $this->plugin_dir, '/' ), $this->path()->get_plugin_path() );
	}

	public function test_a_dotted_name_is_not_treated_as_traversal(): void {
		$this->assertStringEndsWith( '/a..b.txt', $this->path()->get_plugin_path( 'a..b.txt' ) );
	}

	/**
	 * @dataProvider traversal_paths
	 */
	public function test_parent_directory_traversal_is_rejected( string $malicious ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->path()->get_plugin_path( $malicious );
	}

	public function traversal_paths(): array {
		return array(
			'leading ..'   => array( '../secret' ),
			'deep ..'      => array( 'a/../../secret' ),
			'backslash ..' => array( 'a\\..\\secret' ),
			'null byte'    => array( "views/x\0.php" ),
		);
	}

	public function test_symlink_escape_is_blocked(): void {
		// A symlink inside the plugin pointing outside must not resolve through.
		$outside = $this->make_temp_dir( 'zestry-outside-' );
		file_put_contents( $outside . '/secret.php', 'SECRET' );
		mkdir( $this->plugin_dir . '/views' );
		symlink( $outside . '/secret.php', $this->plugin_dir . '/views/escape.php' );

		try {
			$this->expectException( \InvalidArgumentException::class );
			$this->path()->get_plugin_path( 'views/escape.php' );
		} finally {
			$this->remove_dir( $outside );
		}
	}

	public function test_allow_escape_bypasses_containment(): void {
		$resolved = $this->path()->get_plugin_path( '../sibling', true );
		$this->assertStringContainsString( '..', $resolved );
	}

	public function test_url_methods_reject_traversal(): void {
		$path = $this->path();

		$rejected = 0;
		foreach ( array( 'get_plugin_url', 'get_plugin_upload_url' ) as $method ) {
			try {
				$path->{$method}( '../evil' );
			} catch ( \InvalidArgumentException $e ) {
				++$rejected;
			}
		}

		$this->assertSame( 2, $rejected, 'Both URL builders must reject traversal.' );
	}

	public function test_upload_url_encodes_segments(): void {
		$url = $this->path()->get_plugin_upload_url( 'sub dir/a b & c.png' );

		$this->assertStringContainsString( 'sub%20dir/a%20b%20%26%20c.png', $url );
	}
}
