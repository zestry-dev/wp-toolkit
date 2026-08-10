<?php

/**
 * Core API: WithFolderWalker trait
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Traits;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Provides recursive, depth-limited, convention-based file discovery.
 *
 * A module using this trait can enumerate the PHP (or other) files under one
 * of its own folders without hand-rolling directory recursion. Discovery
 * honors a simple naming convention instead of a manifest or config file: any
 * file or directory whose name begins with `.` or `-` is treated as
 * private and excluded, which lets a module ship a folder such as
 * `-partials/` or `.disabled/` that is never picked up as a discoverable
 * unit. This is the shared file-walking primitive behind every file-discovery
 * module, each of which finds its own units by scanning a folder rather than
 * requiring explicit registration.
 */
trait WithFolderWalker {

	/**
	 * Recursively enumerate non-hidden files with one of the requested extensions.
	 *
	 * Results are relative to `$root_dir`, which lets callers safely prepend their
	 * own base path. Any entry whose name begins with `.` or `-` is treated as
	 * private and pruned: a private directory is never descended into, so
	 * `_disabled/` genuinely disables everything beneath it.
	 *
	 * Results are sorted, so discovery order is deterministic across machines.
	 * A leaf sorts before its own namespace (`a.php` before `a/b.php`).
	 *
	 * @param string   $root_dir   The root directory to start walking from.
	 * @param string[] $extensions File extensions to include (default: ['php']).
	 * @param int      $depth      Maximum depth to traverse, using this method's own
	 *                             convention: `1` examines only the root directory,
	 *                             `0` walks all descendants, and any other positive
	 *                             value limits how many directory levels below the
	 *                             root are visited. Internally this is translated to
	 *                             RecursiveIteratorIterator::setMaxDepth(), which uses
	 *                             a different convention where the root directory
	 *                             itself is depth zero — so this method passes
	 *                             `$depth - 1` to it.
	 * @return string[] Relative file paths matching the requested extensions.
	 *
	 * @internal
	 */
	protected function walk_folder( string $root_dir, array $extensions = array( 'php' ), int $depth = 0 ): array {
		$result = array();

		$normalized_root = \wp_normalize_path( $root_dir );

		$directory_iterator = new \RecursiveDirectoryIterator( $root_dir, \FilesystemIterator::SKIP_DOTS );

		$filtered = new \RecursiveCallbackFilterIterator(
			$directory_iterator,
			function ( \SplFileInfo $current ): bool {
				return ! $this->is_private_name( $current->getFilename() );
			}
		);

		$iterator = new \RecursiveIteratorIterator( $filtered, \RecursiveIteratorIterator::SELF_FIRST );

		if ( $depth > 0 ) {
			$iterator->setMaxDepth( $depth - 1 );
		}

		foreach ( $iterator as $item ) {
			if ( $item->isFile() && \in_array( $item->getExtension(), $extensions, true ) ) {
				$normalized_path = \wp_normalize_path( $item->getPathname() );
				$result[]        = \str_replace( $normalized_root . '/', '', $normalized_path );
			}
		}

		// The iterator yields filesystem order, which differs per machine.
		// Migrations depends on this sort for correctness (the timestamp prefix
		// is the run order), PostTypes for admin-menu ordering, CLI for a
		// reproducible collision message. Not natsort(): it preserves keys
		// (breaking the string[] contract) and silently rescues mismatched
		// migration prefix widths that should fail visibly.
		\sort( $result );

		return $result;
	}

	/**
	 * Determine whether a file or directory name is conventionally private.
	 *
	 * This is the single source of truth for the naming convention that
	 * walk_folder() uses to decide what to skip; keeping the rule here (rather
	 * than inline in the filter callback) makes it reusable and independently
	 * testable.
	 *
	 * A leading underscore is **not** private, despite being a common convention
	 * elsewhere. WordPress gives it meaning of its own -- a meta key starting
	 * with `_` is protected meta -- and a file named for what it registers
	 * cannot then be named at all. Use a leading `-` for a file the walker
	 * should skip.
	 *
	 * @param string $name The base name to test.
	 * @return bool True when the name begins with `.` or `-`.
	 */
	private function is_private_name( string $name ): bool {
		return $name !== '' && ( $name[0] === '.' || $name[0] === '-' );
	}
}
