<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\GitIgnore;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The shared .gitignore writer, used by `init` and by `add blocks`.
 *
 * A plugin's .gitignore is its own, so everything here is additive: entries are
 * appended, never reordered or removed, and one already present is left alone
 * however the file words the rest.
 *
 * @covers \Zestry\WPToolkit\DevTools\GitIgnore
 */
final class GitIgnoreTest extends TestCase {

	private function gitignore(): GitIgnore {
		return $this->plugin->get( GitIgnore::class );
	}

	private function contents(): string {
		return (string) file_get_contents( $this->plugin_dir . '/.gitignore' );
	}

	public function test_it_creates_the_file_with_the_defaults(): void {
		$added = $this->gitignore()->add_entries( $this->plugin_dir );

		$this->assertSame(
			array( 'build/', 'vendor/', 'node_modules/', '.DS_Store', '*.log' ),
			$added
		);

		// Comments and spacing come along when the file is being written from
		// nothing, since there is no existing layout to disturb.
		$this->assertSame(
			"build/\nvendor/\nnode_modules/\n\n# Editor / OS noise\n.DS_Store\n*.log\n",
			$this->contents()
		);
	}

	public function test_it_leaves_an_entry_that_is_already_there(): void {
		file_put_contents( $this->plugin_dir . '/.gitignore', "vendor/\n" );

		$added = $this->gitignore()->add_entries( $this->plugin_dir, array( 'vendor/', 'build/' ) );

		$this->assertSame( array( 'build/' ), $added );
		$this->assertSame( "vendor/\nbuild/\n", $this->contents() );
	}

	public function test_running_twice_changes_nothing_the_second_time(): void {
		$this->gitignore()->add_entries( $this->plugin_dir );
		$first = $this->contents();

		$this->assertSame( array(), $this->gitignore()->add_entries( $this->plugin_dir ) );
		$this->assertSame( $first, $this->contents() );
	}

	/**
	 * Appending a comment to a file someone else laid out would drop a heading
	 * between their own entries, so decoration is only written on creation.
	 */
	public function test_it_does_not_append_comments_to_an_existing_file(): void {
		file_put_contents( $this->plugin_dir . '/.gitignore', "*.sql\n" );

		$this->gitignore()->add_entries( $this->plugin_dir );

		$this->assertStringNotContainsString( '# Editor / OS noise', $this->contents() );
		$this->assertStringStartsWith( "*.sql\n", $this->contents() );
		$this->assertStringContainsString( '.DS_Store', $this->contents() );
	}

	/**
	 * A file not ending in a newline would otherwise have the first appended
	 * entry run onto its last line, silently changing that pattern.
	 */
	public function test_it_does_not_join_onto_an_unterminated_last_line(): void {
		file_put_contents( $this->plugin_dir . '/.gitignore', '*.sql' );

		$this->gitignore()->add_entries( $this->plugin_dir, array( 'build/' ) );

		$this->assertSame( "*.sql\nbuild/\n", $this->contents() );
	}
}
