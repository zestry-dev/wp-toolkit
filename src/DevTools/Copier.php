<?php

/**
 * DevTools: copy-and-rewrite engine
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\Kernel\Abstracts\Service;

/**
 * Copies toolkit source into a consuming project, rewriting its namespace
 * and text domain.
 *
 * This is the shadcn/ui-style mechanism the whole DevTools package exists
 * for: rather than requiring `wp-toolkit` as a locked runtime dependency, `wp
 * zestry init`/`wp zestry add` (see the `commands/` directory next to devtool.php)
 * copy the toolkit's own PHP source directly into the consuming project and
 * rewrite every `namespace Zestry\WPToolkit\...;` declaration and `use Zestry\WPToolkit\...;` import to
 * the project's own chosen namespace, and every `'zestry-toolkit'` text-domain
 * string literal to the project's own — the copied code becomes the
 * project's own code, not a dependency it imports.
 *
 * Both rewrites parse each file with `token_get_all()` rather than a blind
 * `str_replace()`, so a string literal or comment that happens to contain the
 * text `Zestry\WPToolkit` (for the namespace) is never touched by that rewrite, and only
 * a standalone string token whose content is exactly `wp-toolkit` (for the
 * text domain) is rewritten, not a substring match inside some other string.
 */
class Copier extends Service {

	/**
	 * The one segment separating copied source from the consumer's own code.
	 *
	 * Every file this class writes lands under it -- `lib/Core/Kernel/`,
	 * `lib/Core/Modules/Ajax/` -- while `wp zestry make` writes beside it, in
	 * `lib/Modules/` and `lib/Services/`. So the answer to "can `wp zestry update`
	 * replace this file?" is the path it is in, at every use site rather than
	 * only when the file is open, since PSR-4 puts the same segment in the
	 * namespace.
	 *
	 * It exists only where the two kinds of code meet, which is the consuming
	 * plugin. This repository is all toolkit, so its own tree stays
	 * `src/Kernel/`, `src/Modules/`, `src/Services/` and this is applied on the
	 * way out -- {@see get_target_namespace()} and {@see get_target_root()} are
	 * the only two places that know the word.
	 */
	public const COPIED_SEGMENT = 'Core';

	/**
	 * The namespace prefix every copied file is written under before rewriting.
	 */
	private const SOURCE_NAMESPACE = 'Zestry\\WPToolkit';

	/**
	 * The text domain every copied file's translation calls use before rewriting.
	 */
	private const SOURCE_TEXT_DOMAIN = 'zestry-toolkit';

	/**
	 * Recursively copy a directory, rewriting the namespace (and, if given, the
	 * text domain) of every `.php` file.
	 *
	 * @param string      $source              Absolute path to the source directory.
	 * @param string      $destination         Absolute path to the destination directory.
	 * @param string      $target_namespace    The namespace to rewrite `Zestry\WPToolkit` to in every copied PHP file.
	 * @param string|null $target_text_domain  The text domain to rewrite `wp-toolkit` to, or null to leave text-domain strings untouched.
	 * @return array<string, string> Every file written, as destination path => sha256 of what was written.
	 * @throws \InvalidArgumentException When the source directory does not exist.
	 * @throws \RuntimeException When the destination directory cannot be created.
	 */
	public function copy_directory( string $source, string $destination, string $target_namespace, ?string $target_text_domain = null ): array {
		if ( ! \is_dir( $source ) ) {
			throw new \InvalidArgumentException( 'Source directory does not exist: ' . $source );
		}

		if ( ! \is_dir( $destination ) && ! \wp_mkdir_p( $destination ) ) {
			// An I/O failure, not a bad argument: copy_file() reports the
			// byte-identical condition as \RuntimeException, as does
			// Path::get_upload_path().
			throw new \RuntimeException( 'Could not create destination directory: ' . $destination );
		}

		$written = array();

		foreach ( $this->collect_files( $source, $destination ) as $source_path => $destination_path ) {
			$written += $this->copy_file( $source_path, $destination_path, $target_namespace, $target_text_domain );
		}

		return $written;
	}

	/**
	 * What copying a directory would write, without writing any of it.
	 *
	 * The read-only twin of {@see copy_directory()}, keyed identically, so
	 * `wp zestry update` can hold the current toolkit's output beside the manifest's
	 * record of the last one and compare the two without touching the consumer's
	 * files. Sharing {@see collect_files()} with the copy is what keeps the two
	 * sets of keys in step: a traversal difference would silently report files as
	 * new or missing.
	 *
	 * @param string      $source             Absolute path to the source directory.
	 * @param string      $destination        Absolute path a copy would write to.
	 * @param string      $target_namespace   The namespace to rewrite `Zestry\WPToolkit` to.
	 * @param string|null $target_text_domain The text domain to rewrite `wp-toolkit` to, or null to leave them untouched.
	 * @return array<string, string> Destination path => sha256 of what a copy would write there.
	 * @throws \InvalidArgumentException When the source directory does not exist.
	 */
	public function render_directory( string $source, string $destination, string $target_namespace, ?string $target_text_domain = null ): array {
		if ( ! \is_dir( $source ) ) {
			throw new \InvalidArgumentException( 'Source directory does not exist: ' . $source );
		}

		$rendered = array();

		foreach ( $this->collect_files( $source, $destination ) as $source_path => $destination_path ) {
			$rendered[ $destination_path ] = \hash( 'sha256', $this->render( $source_path, $target_namespace, $target_text_domain ) );
		}

		return $rendered;
	}

	/**
	 * Copy a single file, rewriting its namespace (and, if given, text domain)
	 * if it is a `.php` file.
	 *
	 * @param string      $source             Absolute path to the source file.
	 * @param string      $destination        Absolute path to the destination file.
	 * @param string      $target_namespace   The namespace to rewrite `Zestry\WPToolkit` to, if this is a PHP file.
	 * @param string|null $target_text_domain The text domain to rewrite `wp-toolkit` to, or null to leave text-domain strings untouched.
	 * @return array<string, string> The one file written, as destination path => sha256 of what was written.
	 * @throws \InvalidArgumentException When the source file does not exist.
	 * @throws \RuntimeException When the file cannot be copied.
	 */
	public function copy_file( string $source, string $destination, string $target_namespace, ?string $target_text_domain = null ): array {
		$rendered = $this->render( $source, $target_namespace, $target_text_domain );

		$destination_dir = \dirname( $destination );
		if ( ! \is_dir( $destination_dir ) && ! \wp_mkdir_p( $destination_dir ) ) {
			throw new \RuntimeException( 'Could not create destination directory: ' . $destination_dir );
		}

		if ( false === \file_put_contents( $destination, $rendered ) ) {
			throw new \RuntimeException( \sprintf( 'Failed to copy "%s" to "%s".', $source, $destination ) );
		}

		return array( $destination => \hash( 'sha256', $rendered ) );
	}

	/**
	 * Exactly what copying one source file would write, without writing it.
	 *
	 * The rewrites are what make a copied file differ from its source, so
	 * comparing a consumer's file against this package's own would report every
	 * file as changed. Rendering first is what lets `wp zestry update` ask the two
	 * questions that matter separately -- has the file on disk drifted from what
	 * was copied (you edited it), and has this rendering drifted from what was
	 * recorded (upstream changed it).
	 *
	 * A non-PHP file is returned byte for byte: neither rewrite applies, and a
	 * stub or a JSON file must not be run through `token_get_all()`.
	 *
	 * @param string      $source             Absolute path to the source file.
	 * @param string      $target_namespace   The namespace to rewrite `Zestry\WPToolkit` to, if this is a PHP file.
	 * @param string|null $target_text_domain The text domain to rewrite `wp-toolkit` to, or null to leave text-domain strings untouched.
	 * @return string The bytes a copy would write.
	 * @throws \InvalidArgumentException When the source file does not exist or cannot be read.
	 */
	public function render( string $source, string $target_namespace, ?string $target_text_domain = null ): string {
		if ( ! \is_file( $source ) ) {
			throw new \InvalidArgumentException( 'Source file does not exist: ' . $source );
		}

		$code = \file_get_contents( $source );

		if ( false === $code ) {
			throw new \InvalidArgumentException( 'Source file could not be read: ' . $source );
		}

		if ( 'php' !== \pathinfo( $source, PATHINFO_EXTENSION ) ) {
			return $code;
		}

		$code = $this->rewrite_namespace( $code, $target_namespace );

		return null === $target_text_domain ? $code : $this->rewrite_text_domain( $code, $target_text_domain );
	}

	/**
	 * Resolve a set of requested registry module names into the full,
	 * duplicate-free list of modules that must be copied, including every
	 * transitive dependency.
	 *
	 * @param string[]                                       $requested Registry keys the caller asked for.
	 * @param array<string, array{source: string, depends: string[]}> $registry As returned by requiring registry.php.
	 * @return string[] Every registry key to copy, each appearing once, dependencies before dependents.
	 * @throws \InvalidArgumentException When a requested or depended-on name is not in the registry.
	 */
	public function resolve_dependencies( array $requested, array $registry ): array {
		$resolved = array();

		foreach ( $requested as $name ) {
			$this->resolve_one( $name, $registry, $resolved );
		}

		return \array_keys( $resolved );
	}

	/**
	 * Depth-first add one module and its dependencies to the resolved set.
	 *
	 * @param string                                                $name     Registry key to resolve.
	 * @param array<string, array{source: string, depends: string[]}>  $registry As returned by requiring registry.php.
	 * @param array<string, true>                                   $resolved Accumulator; mutated in place, keyed by name to de-duplicate and preserve dependency-first order.
	 * @return void
	 * @throws \InvalidArgumentException When $name is not in the registry.
	 */
	private function resolve_one( string $name, array $registry, array &$resolved ): void {
		if ( isset( $resolved[ $name ] ) ) {
			return;
		}

		if ( ! isset( $registry[ $name ] ) ) {
			throw new \InvalidArgumentException( 'Unknown module: ' . $name );
		}

		foreach ( $registry[ $name ]['depends'] as $dependency ) {
			$this->resolve_one( $dependency, $registry, $resolved );
		}

		$resolved[ $name ] = true;
	}

	/**
	 * Every file under a source directory, paired with where it would land.
	 *
	 * Flat rather than recursive in its result: `copy_file()` creates whatever
	 * intermediate directories a path needs, so nothing here has to.
	 *
	 * @param string $source      Absolute path to the source directory.
	 * @param string $destination Absolute path the directory maps to.
	 * @return array<string, string> Source file path => destination file path.
	 */
	private function collect_files( string $source, string $destination ): array {
		$files   = array();
		$entries = \array_diff( (array) \scandir( $source ), array( '.', '..' ) );

		foreach ( $entries as $entry ) {
			$source_path      = $source . '/' . $entry;
			$destination_path = $destination . '/' . $entry;

			if ( \is_dir( $source_path ) ) {
				$files += $this->collect_files( $source_path, $destination_path );
				continue;
			}

			$files[ $source_path ] = $destination_path;
		}

		return $files;
	}

	/**
	 * Rewrite every `Zestry\WPToolkit\...` name in a PHP file to a target namespace.
	 *
	 * Three kinds of name are rewritten, and they are the three ways a class
	 * can be named in executable code: the file's own `namespace` declaration,
	 * each `use` import, and a name written out in full where it is used --
	 * `\Zestry\WPToolkit\Services\Request\Request::class`, a return type, an enum case.
	 *
	 * That last one is why this cannot skip it: a copied file naming
	 * `\Zestry\WPToolkit\...` inline is naming a class the plugin does not have, and the
	 * failure waits until the line runs.
	 *
	 * Parses the file with `token_get_all()` rather than a blind
	 * `str_replace()`: only real name tokens are rewritten, so `Zestry\WPToolkit` appearing
	 * in a string literal, a comment, or a docblock example is left untouched.
	 *
	 * @param string $code             The PHP source to rewrite.
	 * @param string $target_namespace The namespace to rewrite `Zestry\WPToolkit` to.
	 * @return string The rewritten source.
	 */
	private function rewrite_namespace( string $code, string $target_namespace ): string {
		$tokens = \token_get_all( $code );
		$result = '';

		for ( $i = 0, $count = \count( $tokens ); $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( \is_array( $token ) && T_NAMESPACE === $token[0] ) {
				$result .= $token[1];
				++$i;

				$namespace_name = '';
				while ( $i < $count ) {
					$next = $tokens[ $i ];
					if ( \is_array( $next ) && T_WHITESPACE === $next[0] ) {
						$result .= $next[1];
					} elseif ( \is_array( $next ) && \in_array( $next[0], array( T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED ), true ) ) {
						$namespace_name .= $next[1];
					} else {
						break;
					}
					++$i;
				}

				$result .= $this->rewrite_namespace_name( $namespace_name, $target_namespace );
				--$i;
				continue;
			}

			if ( \is_array( $token ) && T_USE === $token[0] ) {
				$result .= $token[1];
				++$i;

				$use_segment = '';
				while ( $i < $count && ';' !== $tokens[ $i ] ) {
					$use_segment .= \is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
					++$i;
				}

				$use_segment = \str_replace(
					array( '\\' . self::SOURCE_NAMESPACE . '\\', self::SOURCE_NAMESPACE . '\\' ),
					array( '\\' . $target_namespace . '\\', $target_namespace . '\\' ),
					$use_segment
				);

				$result .= $use_segment . ';';
				continue;
			}

			/*
			 * A name written out where it is used, rather than imported. One
			 * token whatever its length, and never a comment or a string --
			 * those are their own token types and fall through below.
			 */
			if ( \is_array( $token ) && \in_array( $token[0], array( T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ), true ) ) {
				// The leading separator is part of the token and not part of the
				// name, so it is set aside and put back rather than rewritten.
				$leading = \str_starts_with( $token[1], '\\' ) ? '\\' : '';

				$result .= $leading . $this->rewrite_namespace_name( \ltrim( $token[1], '\\' ), $target_namespace );
				continue;
			}

			$result .= \is_array( $token ) ? $token[1] : $token;
		}

		return $result;
	}

	/**
	 * Rewrite a single parsed namespace name if it is (or starts with) the
	 * source namespace.
	 *
	 * @param string $name             The namespace name as written in the source file.
	 * @param string $target_namespace The namespace to rewrite it to.
	 * @return string The rewritten namespace name.
	 */
	private function rewrite_namespace_name( string $name, string $target_namespace ): string {
		$source_prefix = self::SOURCE_NAMESPACE . '\\';

		if ( \str_starts_with( $name, $source_prefix ) ) {
			return $target_namespace . \substr( $name, \strlen( self::SOURCE_NAMESPACE ) );
		}

		if ( self::SOURCE_NAMESPACE === $name ) {
			return $target_namespace;
		}

		return $name;
	}

	/**
	 * Rewrite every `'zestry-toolkit'` text-domain string literal in a PHP file to
	 * a target text domain.
	 *
	 * A text domain must be a static string literal at the call site --
	 * `wp i18n make-pot`/`make-json` and `load_plugin_textdomain()` scan
	 * source statically, so a copied module's `__( '...', 'zestry-toolkit' )`
	 * calls must be rewritten the same way its namespace is, not resolved at
	 * runtime from some Plugin-level setting. Parses with `token_get_all()`
	 * rather than a blind `str_replace()`: only a standalone
	 * `T_CONSTANT_ENCAPSED_STRING` token whose content (quotes stripped) is
	 * exactly `wp-toolkit` is rewritten, so a comment or an unrelated string
	 * that happens to contain the substring is left untouched.
	 *
	 * @param string $code               The PHP source to rewrite.
	 * @param string $target_text_domain The text domain to rewrite `wp-toolkit` to.
	 * @return string The rewritten source.
	 */
	private function rewrite_text_domain( string $code, string $target_text_domain ): string {
		$tokens        = \token_get_all( $code );
		$result        = '';
		$source_quoted = array( "'" . self::SOURCE_TEXT_DOMAIN . "'", '"' . self::SOURCE_TEXT_DOMAIN . '"' );

		foreach ( $tokens as $token ) {
			if ( \is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && \in_array( $token[1], $source_quoted, true ) ) {
				$result .= $token[1][0] . $target_text_domain . $token[1][0];
				continue;
			}

			$result .= \is_array( $token ) ? $token[1] : $token;
		}

		return $result;
	}

	/**
	 * The registry as one lookup table, keyed by name.
	 *
	 * `registry.php` groups entries under `services` and `modules` so it reads
	 * like `bootstrap.php` does. Every consumer wants a flat lookup, though --
	 * the commands take a bare name (`wp zestry add path`), and dependency
	 * resolution walks a closure that crosses the two freely: nine of the ten
	 * modules depend on `path`, a service. Flattening once here keeps that
	 * structure in the file without pushing a section search into every caller,
	 * and `depends` comes back merged for the same reason.
	 *
	 * The section each entry was filed under is carried through as `section`,
	 * which is the one thing the grouping is actually good for: telling a reader
	 * which kind they just added.
	 *
	 * A name appearing in both sections throws rather than being flattened. The
	 * commands take a bare name, so two entries answering to one would make
	 * `wp zestry add <name>` install whichever section happened to be read last --
	 * and each section reads correctly on its own, so nothing about the file
	 * would look wrong. A single array could not hide this; two can.
	 *
	 * @param array<string, array<string, array{source: class-string, depends: array{services: string[], modules: string[]}}>> $registry The registry as declared.
	 * @return array<string, array{source: class-string, depends: string[], section: string}> Keyed by entry name.
	 * @throws \InvalidArgumentException When one name is declared in more than one section.
	 */
	public static function flatten_registry( array $registry ): array {
		$flat = array();

		foreach ( $registry as $section => $entries ) {
			foreach ( $entries as $name => $entry ) {
				if ( isset( $flat[ $name ] ) ) {
					throw new \InvalidArgumentException(
						\sprintf(
							'Registry name "%s" is declared in both "%s" and "%s". Every name must be unique across sections, since `wp zestry add %1$s` names no section.',
							$name,
							$flat[ $name ]['section'],
							$section
						)
					);
				}

				$depends = $entry['depends'] ?? array();

				$flat[ $name ] = array(
					'source'  => $entry['source'],
					'depends' => \array_merge(
						$depends['services'] ?? array(),
						$depends['modules'] ?? array()
					),
					'section' => $section,
				);
			}
		}

		return $flat;
	}

	/**
	 * A registry class name with this package's own root namespace removed.
	 *
	 * `Zestry\WPToolkit\Modules\Ajax\Ajax` becomes `Modules\Ajax\Ajax`, which is
	 * exactly what a consumer's `{namespace}\Core\` is joined to -- the copied
	 * file keeps everything below the root and nothing above it.
	 *
	 * @param string $class_name Fully qualified class name from the registry.
	 * @return string The name below the root namespace.
	 */
	public static function get_relative_class( string $class_name ): string {
		$prefix = self::SOURCE_NAMESPACE . '\\';

		return \str_starts_with( $class_name, $prefix )
			? \substr( $class_name, \strlen( $prefix ) )
			: $class_name;
	}

	/**
	 * Where a registry class lives, relative to a source or destination root.
	 *
	 * One string serves both ends: prefixed with this package's `src/` it is what
	 * to copy, prefixed with a project's {@see get_target_root()} it is where that
	 * lands. So a class moving between `Services/` and `Modules/` needs no registry
	 * edit and no second rule -- the destination mirrors the source because both
	 * are this same path.
	 *
	 * A module with a directory of its own puts the class inside it under the
	 * same name (`Modules\Ajax\Ajax`), and the whole directory is what gets
	 * copied. Anything else is the one file (`Services\Path`).
	 *
	 * @param string $class_name Fully qualified class name from the registry.
	 * @return string A path relative to a root, e.g. `Modules/Ajax` or `Services/Path.php`.
	 */
	public static function get_relative_source( string $class_name ): string {
		// The whole root namespace, not up to the first backslash: that was the
		// same thing while the root was one segment, and stopped being when it
		// became two -- yielding a path a segment too deep for every copy.
		$relative = self::get_relative_class( $class_name );

		$segments = \explode( '\\', $relative );
		$short    = (string) \array_pop( $segments );

		if ( array() !== $segments && \end( $segments ) === $short ) {
			return \implode( '/', $segments );
		}

		return \implode( '/', \array_merge( $segments, array( $short ) ) ) . '.php';
	}

	/**
	 * The namespace copied source is rewritten to, given a project's own.
	 *
	 * `Acme\Plugin` in, `Acme\Plugin\Core` out -- so `Zestry\WPToolkit\Modules\Ajax\Ajax`
	 * becomes `Acme\Plugin\Core\Modules\Ajax\Ajax` from this one argument, and
	 * the consumer's own `Acme\Plugin\Modules\Shortcode` cannot collide with it.
	 *
	 * @param string $target_namespace The project's own namespace, from zestry.json.
	 * @return string The namespace to rewrite `Zestry\WPToolkit` to.
	 */
	public static function get_target_namespace( string $target_namespace ): string {
		return \rtrim( $target_namespace, '\\' ) . '\\' . self::COPIED_SEGMENT;
	}

	/**
	 * The directory copied source is written into, given a project's own root.
	 *
	 * PSR-4 keeps this in step with {@see get_target_namespace()} for free: one
	 * appends the segment to the namespace, the other to the path, and the pair
	 * has to agree for the copied files to autoload at all.
	 *
	 * @param string $root Absolute path to the project's source root, e.g. `/…/acme-plugin/lib`.
	 * @return string Absolute path to the directory copied source belongs in.
	 */
	public static function get_target_root( string $root ): string {
		return Str::join_path( $root, self::COPIED_SEGMENT );
	}
}
