<?php

/**
 * DevTools: zestry.json reader/writer
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Service;

/**
 * Reads and writes a consuming project's `zestry.json`.
 *
 * `wp zt init` writes this file once, recording the namespace the copied
 * source was rewritten to, the directory it was copied into (both relative
 * to the consuming plugin's root), and the text domain its translation calls
 * were rewritten to; `wp zt add` reads it back to know where, under what
 * namespace, and under what text domain a further module should be copied,
 * without asking again.
 *
 * ```php
 * {
 *     "namespace": "Vendor\\MyPlugin",
 *     "root": "lib",
 *     "text_domain": "my-plugin"
 * }
 * ```
 *
 * `text_domain` is optional on read. A missing value comes back as `null`,
 * which `Copier` treats as "leave text-domain strings untouched" rather than as
 * a malformed file.
 */
class ZestryConfig extends Service {

	/**
	 * Check whether a project has already been initialized.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return bool True when `zestry.json` already exists there.
	 */
	public function exists( string $plugin_root ): bool {
		return \is_file( $this->get_path( $plugin_root ) );
	}

	/**
	 * Read a project's `zestry.json`.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return array{namespace: string, root: string, text_domain: string|null} The stored namespace, copy-destination root, and text domain.
	 * @throws \RuntimeException When the file is missing or malformed.
	 */
	public function read( string $plugin_root ): array {
		$path = $this->get_path( $plugin_root );

		if ( ! \is_file( $path ) ) {
			throw new \RuntimeException( 'zestry.json does not exist: ' . $path . '. Run `wp zt init` first.' );
		}

		$content = \file_get_contents( $path );
		$config  = false === $content ? null : \json_decode( $content, true );

		if ( ! \is_array( $config ) || ! isset( $config['namespace'], $config['root'] )
			|| ! \is_string( $config['namespace'] ) || ! \is_string( $config['root'] )
		) {
			throw new \RuntimeException( 'zestry.json is malformed: ' . $path );
		}

		$text_domain = $config['text_domain'] ?? null;

		return array(
			'namespace'   => $config['namespace'],
			'root'        => $config['root'],
			'text_domain' => \is_string( $text_domain ) ? $text_domain : null,
		);
	}

	/**
	 * Write a project's `zestry.json`.
	 *
	 * @param string      $plugin_root       Absolute path to the consuming plugin's root.
	 * @param string      $target_namespace  The namespace the copied source was rewritten to.
	 * @param string      $root              The plugin-relative directory the source was copied into.
	 * @param string|null $target_text_domain The text domain the copied source's translation calls were rewritten to, or null if not configured.
	 * @return void
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function write( string $plugin_root, string $target_namespace, string $root, ?string $target_text_domain = null ): void {
		$path    = $this->get_path( $plugin_root );
		$content = \json_encode(
			array(
				'namespace'   => $target_namespace,
				'root'        => $root,
				'text_domain' => $target_text_domain,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		if ( false === $content || false === \file_put_contents( $path, $content ) ) {
			throw new \RuntimeException( 'Failed to write zestry.json: ' . $path );
		}
	}

	/**
	 * Build the absolute path to a project's `zestry.json`.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return string The absolute `zestry.json` path.
	 */
	private function get_path( string $plugin_root ): string {
		return \rtrim( $plugin_root, '/\\' ) . '/zestry.json';
	}
}
