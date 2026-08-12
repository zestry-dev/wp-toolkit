<?php

/**
 * DevTools: record of what was copied, and what has changed since
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Service;

/**
 * Records the exact bytes `init` and `add` wrote, so an update can say what it
 * would destroy.
 *
 * Copying is one-way: nothing upstream reaches a plugin that has already run
 * `wp zestry init`. That is the trade the copy model makes, and it is a good one
 * -- but only if taking a later release is possible at all, and it is not while
 * the only question anyone can answer is "does this file differ from the current
 * toolkit?". That single comparison conflates the two things a consumer needs
 * kept apart:
 *
 * - **you edited it** -- the file on disk differs from what was copied
 * - **upstream changed it** -- what the toolkit would write now differs from
 *   what it wrote then
 *
 * A file in the first state is work that an overwrite destroys. A file in the
 * second is the update you actually came for. A file in both is the only one
 * that needs a decision, and it is the one a blind re-copy silently resolves
 * against you.
 *
 * Recording a hash per file is what separates them, because it remembers the
 * third value the comparison needs: not the source and not the disk, but what
 * was written between them.
 *
 * ## Why a second file
 *
 * `zestry.json` holds three answers a human typed and may edit again. This holds
 * one line per copied file, generated, and would bury them. Composer draws the
 * same line for the same reason, and the resemblance is worth leaning on: this
 * is committed, like `composer.lock`, since a manifest that is not in the
 * repository cannot tell a colleague's checkout anything.
 *
 * Hashes are of the file **as written**, after the namespace and text-domain
 * rewrites. Hashing this package's own source instead would report every file
 * as changed, since rewriting is precisely what makes a copy differ from it.
 *
 * @see \Zestry\WPToolkit\DevTools\Copier::render() The rewrite these hashes are taken over.
 */
class Manifest extends Service {

	/**
	 * A file on disk matching the hash recorded when it was copied.
	 */
	public const UNCHANGED = 'unchanged';

	/**
	 * A file on disk differing from what was copied: edited since.
	 */
	public const EDITED = 'edited';

	/**
	 * A file the current toolkit would write differently than it did.
	 */
	public const UPSTREAM = 'upstream';

	/**
	 * Both at once -- edited locally, and changed upstream.
	 */
	public const CONFLICT = 'conflict';

	/**
	 * A recorded file that is no longer on disk.
	 */
	public const MISSING = 'missing';

	/**
	 * Whether a project has a manifest at all.
	 *
	 * Absent is not an error: every command that reads one has to keep working
	 * without it, degrading to a plain difference report.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return bool
	 */
	public function exists( string $plugin_root ): bool {
		return \is_file( $this->get_path( $plugin_root ) );
	}

	/**
	 * Read a project's manifest.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return array{version: string|null, files: array<string, string>} Empty `files` when there is no manifest.
	 * @throws \RuntimeException When the file exists but does not decode to an array.
	 */
	public function read( string $plugin_root ): array {
		$path = $this->get_path( $plugin_root );

		if ( ! \is_file( $path ) ) {
			return array(
				'version' => null,
				'files'   => array(),
			);
		}

		$content = \file_get_contents( $path );
		$data    = false === $content ? null : \json_decode( $content, true );

		if ( ! \is_array( $data ) ) {
			throw new \RuntimeException( 'zestry.lock.json is malformed: ' . $path );
		}

		$version = $data['version'] ?? null;
		$files   = $data['files'] ?? array();

		return array(
			'version' => \is_string( $version ) ? $version : null,
			'files'   => \is_array( $files ) ? \array_filter( $files, 'is_string' ) : array(),
		);
	}

	/**
	 * Drop files from the record.
	 *
	 * For work the plugin has undone. A module deleted from disk stays in the
	 * lock otherwise, and `update` reports it as missing on every run from then
	 * on -- while refusing to write it back, since an update copies what the
	 * plugin has rather than reintroducing what it removed.
	 *
	 * Nothing is deleted from disk here. The files are already gone; this is the
	 * record catching up.
	 *
	 * @param string   $plugin_root Absolute path to the consuming plugin's root.
	 * @param string[] $paths       Plugin-relative paths to forget.
	 * @return void
	 * @throws \RuntimeException When the manifest cannot be written.
	 */
	public function forget( string $plugin_root, array $paths ): void {
		if ( array() === $paths || ! $this->exists( $plugin_root ) ) {
			return;
		}

		$manifest = $this->read( $plugin_root );
		$files    = \array_diff_key( $manifest['files'], \array_flip( $paths ) );

		if ( \count( $files ) === \count( $manifest['files'] ) ) {
			return;
		}

		$this->write( $plugin_root, $manifest['version'], $files );
	}

	/**
	 * Merge newly copied files into a project's manifest.
	 *
	 * Additive, because `add` copies one module at a time: a run that recorded
	 * only what it just wrote would drop every module added before it, and the
	 * next `update` would report those as never copied.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param array<string, string> $written     Absolute destination path => sha256, as the Copier returns it.
	 * @return void
	 * @throws \RuntimeException When the manifest cannot be written.
	 */
	public function record( string $plugin_root, array $written ): void {
		$manifest = $this->read( $plugin_root );
		$root     = \rtrim( \wp_normalize_path( $plugin_root ), '/' ) . '/';

		foreach ( $written as $absolute => $hash ) {
			$absolute = \wp_normalize_path( $absolute );
			$relative = \str_starts_with( $absolute, $root ) ? \substr( $absolute, \strlen( $root ) ) : $absolute;

			$manifest['files'][ $relative ] = $hash;
		}

		\ksort( $manifest['files'] );

		$this->write( $plugin_root, $this->get_toolkit_version(), $manifest['files'] );
	}

	/**
	 * Classify every recorded file against the disk and the current toolkit.
	 *
	 * `$rendered` is what the toolkit would write now, keyed the same way the
	 * manifest is. A caller that cannot render a file (its module is no longer
	 * in the registry, say) simply leaves it out, and the file is judged on the
	 * disk comparison alone.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param array<string, string> $rendered    Plugin-relative path => sha256 of what a copy would write now.
	 * @return array<string, string> Plugin-relative path => one of this class's status constants.
	 */
	public function compare( string $plugin_root, array $rendered ): array {
		$recorded = $this->read( $plugin_root )['files'];
		$root     = \rtrim( $plugin_root, '/\\' ) . '/';
		$statuses = array();

		foreach ( $recorded as $relative => $hash ) {
			if ( ! \is_file( $root . $relative ) ) {
				$statuses[ $relative ] = self::MISSING;
				continue;
			}

			$on_disk  = (string) \file_get_contents( $root . $relative );
			$edited   = \hash( 'sha256', $on_disk ) !== $hash;
			$upstream = \array_key_exists( $relative, $rendered ) && $rendered[ $relative ] !== $hash;

			$statuses[ $relative ] = $this->get_status( $edited, $upstream );
		}

		// A file the toolkit would write that was never recorded: new upstream
		// since this plugin's copy, so an update is what puts it there.
		foreach ( $rendered as $relative => $hash ) {
			if ( ! \array_key_exists( $relative, $recorded ) ) {
				$statuses[ $relative ] = self::UPSTREAM;
			}
		}

		\ksort( $statuses );

		return $statuses;
	}

	/**
	 * Write a project's manifest.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param string|null           $version     The toolkit version the files came from.
	 * @param array<string, string> $files       Plugin-relative path => sha256.
	 * @return void
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function write( string $plugin_root, ?string $version, array $files ): void {
		$path    = $this->get_path( $plugin_root );
		$content = \json_encode(
			array(
				'version' => $version,
				'files'   => $files,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		if ( false === $content || false === \file_put_contents( $path, $content . "\n" ) ) {
			throw new \RuntimeException( 'Failed to write zestry.lock.json: ' . $path );
		}
	}

	/**
	 * The version of this package the copy is being made from.
	 *
	 * Composer knows, and nothing else does: this package declares no `version`
	 * in its own composer.json, since for a library the tag is the version and
	 * restating it in the file is how the two drift apart.
	 *
	 * Null when Composer cannot say -- a path repository, or a checkout being
	 * developed against directly. Informational either way: the hashes are what
	 * `compare()` reads, and they work whether or not anything can be named.
	 *
	 * @return string|null
	 */
	public function get_toolkit_version(): ?string {
		if ( ! \class_exists( \Composer\InstalledVersions::class ) ) {
			return null;
		}

		try {
			return \Composer\InstalledVersions::getPrettyVersion( 'zestry-dev/wp-toolkit' );
		} catch ( \OutOfBoundsException $exception ) {
			// Not installed under that name, which is the path-repository case.
			return null;
		}
	}

	/**
	 * Which of the two drifts happened, given whether each did.
	 *
	 * @param bool $edited   Whether the file on disk differs from what was copied.
	 * @param bool $upstream Whether the toolkit would now write it differently.
	 * @return string One of this class's status constants.
	 */
	private function get_status( bool $edited, bool $upstream ): string {
		if ( $edited && $upstream ) {
			return self::CONFLICT;
		}

		if ( $edited ) {
			return self::EDITED;
		}

		return $upstream ? self::UPSTREAM : self::UNCHANGED;
	}

	/**
	 * Build the absolute path to a project's manifest.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return string
	 */
	private function get_path( string $plugin_root ): string {
		return \rtrim( $plugin_root, '/\\' ) . '/zestry.lock.json';
	}
}
