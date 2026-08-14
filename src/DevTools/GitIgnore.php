<?php

/**
 * DevTools: .gitignore writer
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;

/**
 * Keeps a consuming plugin's `.gitignore` covering what the toolkit generates.
 *
 * `wp zt init` writes the file, and `wp zt add module blocks` extends it once a build
 * directory exists to ignore. Both go through here so the two never disagree
 * about what an entry looks like.
 *
 * Always additive, and never rewrites: a plugin's `.gitignore` is its own, and
 * an entry already present -- however it was worded -- is left alone rather than
 * duplicated. Nothing is ever removed.
 */
class GitIgnore extends Module {

	/**
	 * What a generated plugin ignores before it has anything else to say.
	 *
	 * `vendor/` and `node_modules/` are installed from a lockfile, and `build/`
	 * is `wp-scripts` output -- committing any of them puts a copy of something
	 * reproducible into history. The rest is per-machine noise.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_ENTRIES = array(
		'build/',
		'vendor/',
		'node_modules/',
		'',
		'# Editor / OS noise',
		'.DS_Store',
		'*.log',
	);

	/**
	 * Add entries to a plugin's `.gitignore`, creating it when absent.
	 *
	 * A blank string is a spacer and a `#` line a comment: both are written only
	 * when the file is being created, since appending them to a file someone
	 * else laid out would just be noise between their entries.
	 *
	 * @param string        $plugin_root Absolute path to the consuming plugin's root.
	 * @param array<string> $entries     Patterns to ensure are present, in order.
	 * @return string[] The patterns actually written, empty when everything was already there.
	 */
	public function add_entries( string $plugin_root, array $entries = self::DEFAULT_ENTRIES ): array {
		$file     = \rtrim( $plugin_root, '/\\' ) . '/.gitignore';
		$existed  = \is_file( $file );
		$contents = $existed ? (string) \file_get_contents( $file ) : '';
		$present  = \array_map( 'trim', \explode( "\n", $contents ) );
		$added    = array();
		$appended = '';

		foreach ( $entries as $entry ) {
			$is_decoration = '' === $entry || \str_starts_with( $entry, '#' );

			if ( $is_decoration ) {
				if ( ! $existed ) {
					$appended .= $entry . "\n";
				}

				continue;
			}

			if ( \in_array( $entry, $present, true ) ) {
				continue;
			}

			$appended .= $entry . "\n";
			$added[]   = $entry;

			// Recorded so a list naming the same pattern twice adds it once.
			$present[] = $entry;
		}

		if ( '' === $appended ) {
			return array();
		}

		$separator = ( '' === $contents || \str_ends_with( $contents, "\n" ) ) ? '' : "\n";

		\file_put_contents( $file, $contents . $separator . $appended );

		return $added;
	}
}
