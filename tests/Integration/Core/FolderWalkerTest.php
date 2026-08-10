<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Directory-level pruning of private entries (review findings #20, #26).
 *
 * @covers \Zestry\WPToolkit\Kernel\Traits\WithFolderWalker
 */
final class FolderWalkerTest extends TestCase {

	public function test_discovers_files_and_prunes_private_directories(): void {
		$this->write_plugin_file( 'scan/a.php', '' );
		$this->write_plugin_file( 'scan/sub/b.php', '' );
		$this->write_plugin_file( 'scan/-disabled/dangerous.php', '' );
		$this->write_plugin_file( 'scan/.git/hook.php', '' );
		$this->write_plugin_file( 'scan/-old/legacy.php', '' );
		$this->write_plugin_file( 'scan/-ignored.php', '' );
		$this->write_plugin_file( 'scan/_protected.php', '' );
		$this->write_plugin_file( 'scan/c.txt', '' );

		$found = $this->walker()->walk( $this->plugin_dir . '/scan' );

		$this->assertContains( 'a.php', $found );
		$this->assertContains( 'sub/b.php', $found );

		$this->assertNotContains( '-disabled/dangerous.php', $found, 'A private directory must not be descended into.' );
		$this->assertNotContains( '.git/hook.php', $found );
		$this->assertNotContains( '-old/legacy.php', $found );
		$this->assertNotContains( '-ignored.php', $found, 'A private file must be skipped.' );
		$this->assertContains( '_protected.php', $found, 'A leading underscore is not private: WordPress uses it for protected meta.' );
		$this->assertNotContains( 'c.txt', $found, 'Non-matching extensions are excluded.' );
	}

	/**
	 * Discovery order is guaranteed here rather than by each caller.
	 *
	 * Migrations depends on it for correctness (the filename's timestamp prefix
	 * is the run order), PostTypes for admin-menu ordering among post types
	 * sharing a menu_position, and CLI for reporting a reproducible pair in its
	 * command-name collision error. Files are written in deliberately reversed
	 * order so a filesystem that happens to return creation order would fail.
	 */
	public function test_results_are_sorted_regardless_of_creation_order(): void {
		$this->write_plugin_file( 'scan/zeta.php', '' );
		$this->write_plugin_file( 'scan/20260102000000-second.php', '' );
		$this->write_plugin_file( 'scan/alpha.php', '' );
		$this->write_plugin_file( 'scan/20260101000000-first.php', '' );

		$found = $this->walker()->walk( $this->plugin_dir . '/scan' );

		// The exact expected order, not a comparison against its own sorted
		// copy -- that would pass trivially and assert nothing.
		$this->assertSame(
			array(
				'20260101000000-first.php',
				'20260102000000-second.php',
				'alpha.php',
				'zeta.php',
			),
			$found,
			'walk_folder() returns paths in sorted order, not creation order.'
		);
	}

	/**
	 * A leaf sorts before its own namespace, so nesting-sensitive callers
	 * (AdminPages' menu hierarchy, CLI's namespaces) see the same order the
	 * previously hand-sorting callers produced. `.` (46) precedes `/` (47).
	 */
	public function test_a_leaf_file_sorts_before_its_own_subdirectory(): void {
		$this->write_plugin_file( 'scan/cache/clear.php', '' );
		$this->write_plugin_file( 'scan/cache.php', '' );

		$found = $this->walker()->walk( $this->plugin_dir . '/scan' );

		$this->assertSame( array( 'cache.php', 'cache/clear.php' ), $found );
	}

	public function test_depth_one_examines_only_the_root(): void {
		$this->write_plugin_file( 'scan/top.php', '' );
		$this->write_plugin_file( 'scan/nested/deep.php', '' );

		$found = $this->walker()->walk( $this->plugin_dir . '/scan', 1 );

		$this->assertContains( 'top.php', $found );
		$this->assertNotContains( 'nested/deep.php', $found );
	}

	/**
	 * Expose the protected walk_folder() through a throwaway consumer.
	 *
	 * @return object
	 */
	private function walker() {
		return new class() {
			use WithFolderWalker;

			/**
			 * Deliberately returns walk_folder()'s result untouched.
			 *
			 * This helper used to sort() before returning, which made the
			 * ordering assertions below vacuous -- they passed whether or not
			 * walk_folder() guaranteed anything. Ordering is the primitive's
			 * job now, so the helper must not paper over it.
			 */
			public function walk( string $root, int $depth = 0 ): array {
				return $this->walk_folder( $root, array( 'php' ), $depth );
			}
		};
	}
}
