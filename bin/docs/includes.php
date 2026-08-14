<?php

/**
 * DevTools: source-include expansion for hand-written pages
 */

declare( strict_types=1 );

/**
 * Replace `zestry:include` placeholders in hand-written pages with real source.
 *
 * A hand-written page keeps narrative that no symbol owns, but the parts of it
 * that a source file already decides must come from that file. Two forms.
 *
 * A **file** include, for a code sample:
 *
 *     <!-- zestry:include file="src/DevTools/stubs/action.php.stub" lang="php" -->
 *     ```php
 *     ...previously generated content...
 *     ```
 *     <!-- /zestry:include -->
 *
 * so a stub change cannot leave a stale sample behind. `lines="3-18"` narrows
 * it to a range when the whole file is more than the page needs.
 *
 * A **generator** include, for a fact no single file holds verbatim:
 *
 *     <!-- zestry:include generator="module-names" -->
 *     `abilities`, `admin-pages`, `ajax`, ...
 *     <!-- /zestry:include -->
 *
 * which runs `zestry_generate_module_names()` from `bin/docs/generators/` and
 * writes what it returns, as markdown rather than as a code block. That is the
 * difference between a list going stale and a list that cannot: adding a module
 * to `registry.php` updates every page naming them, with nothing to remember.
 *
 * Reserved for things whose *content* is derivable. An editorial table --
 * the cheat sheet's `make` types, each with a hand-written line on what it is
 * for -- is not, and stays hand-written under
 * {@see zestry_check_make_types()}, which fails the build when one goes missing.
 * Generating it would trade a sentence a person wrote for a docblock summary
 * nobody wrote for that spot.
 *
 * @param string $root Absolute path to the repository root.
 * @return int The number of placeholders expanded.
 */
function zestry_expand_includes( string $root ): int {
	$expanded = 0;
	$pages    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/docs' ) );

	foreach ( $pages as $page ) {
		if ( ! $page->isFile() || 'md' !== $page->getExtension() ) {
			continue;
		}

		$markdown = (string) file_get_contents( $page->getPathname() );

		if ( ! str_contains( $markdown, 'zestry:include' ) ) {
			continue;
		}

		$updated = (string) preg_replace_callback(
			'/^([ \t]*)<!-- zestry:include generator="([\w-]+)" -->.*?<!-- \/zestry:include -->/ms',
			static function ( array $matches ) use ( $root, $page, &$expanded ): string {
				$generated = zestry_run_generator( $root, $matches[2] );

				if ( null === $generated ) {
					fwrite(
						STDERR,
						sprintf(
							"Unknown include generator: %s (in %s)\nAdd bin/docs/generators/%s.php defining zestry_generate_%s().\n",
							$matches[2],
							$page->getFilename(),
							$matches[2],
							str_replace( '-', '_', $matches[2] )
						)
					);

					exit( 1 );
				}

				++$expanded;

				// The placeholder's own indentation, applied to everything it
				// emits. A generator has no idea where it was included from, and
				// a list written flush against the margin stops being part of the
				// bullet it was nested under -- which markdown renders as a
				// separate paragraph and the front-page check reads as an empty
				// inventory.
				$indent = $matches[1];
				$block  = sprintf(
					"<!-- zestry:include generator=\"%s\" -->\n%s\n<!-- /zestry:include -->",
					$matches[2],
					$generated
				);

				return $indent . implode( "\n" . $indent, explode( "\n", $block ) );
			},
			$markdown
		);

		$updated = (string) preg_replace_callback(
			'/<!-- zestry:include file="([^"]+)"(?: lang="([^"]*)")?(?: lines="(\d+)-(\d+)")? -->.*?<!-- \/zestry:include -->/s',
			static function ( array $matches ) use ( $root, $page, &$expanded ): string {
				$file = $root . '/' . $matches[1];

				if ( ! is_file( $file ) ) {
					fwrite( STDERR, sprintf( "Include target missing: %s (in %s)\n", $matches[1], $page->getFilename() ) );
					exit( 1 );
				}

				$lang     = $matches[2] ?? '';
				$contents = (string) file_get_contents( $file );
				$lines    = explode( "\n", rtrim( $contents ) );

				if ( isset( $matches[3], $matches[4] ) && '' !== $matches[3] ) {
					$from  = max( 1, (int) $matches[3] );
					$to    = (int) $matches[4];
					$lines = array_slice( $lines, $from - 1, $to - $from + 1 );
				}

				++$expanded;

				return sprintf(
					"<!-- zestry:include file=\"%s\"%s%s -->\n```%s\n%s\n```\n<!-- /zestry:include -->",
					$matches[1],
					'' !== $lang ? sprintf( ' lang="%s"', $lang ) : '',
					isset( $matches[3] ) && '' !== $matches[3] ? sprintf( ' lines="%s-%s"', $matches[3], $matches[4] ) : '',
					$lang,
					implode( "\n", $lines )
				);
			},
			$updated
		);

		if ( $updated !== $markdown ) {
			file_put_contents( $page->getPathname(), $updated );
		}
	}

	return $expanded;
}

/**
 * Run a named generator, or null when no such generator exists.
 *
 * One file per generator under `bin/docs/generators/`, each defining a single
 * `zestry_generate_{name}()` taking the repository root and returning markdown.
 * Adding one is adding a file -- there is no list here to keep in step, which
 * is the same reason a discovered file is a feature everywhere else in this
 * toolkit.
 *
 * @param string $root Absolute path to the repository root.
 * @param string $name The generator's name, as written in the placeholder.
 * @return string|null The generated markdown, or null when unknown.
 */
function zestry_run_generator( string $root, string $name ): ?string {
	$file = $root . '/bin/docs/generators/' . $name . '.php';

	if ( ! is_file( $file ) ) {
		return null;
	}

	require_once $file;

	$function = 'zestry_generate_' . str_replace( '-', '_', $name );

	if ( ! function_exists( $function ) ) {
		return null;
	}

	return rtrim( (string) $function( $root ) );
}

/**
 * One registry section's names, comma-separated and in backticks.
 *
 * The shape both name generators want, and the only thing they differ on is
 * which section to read.
 *
 * Sorted, not left in registry order. The registry is ordered by what depends
 * on what, which is the right order for resolving and the wrong one for
 * reading: these lists exist to be scanned for a name someone is about to type.
 * Sorting also means adding an entry moves nothing else, so the diff on a page
 * is the one line that changed.
 *
 * @param string $root Absolute path to the repository root.
 * @return string
 */
function zestry_generate_registry_names( string $root ): string {
	$registry = require $root . '/src/DevTools/registry.php';
	$names    = array_keys( $registry );

	sort( $names );

	return '`' . implode( '`, `', $names ) . '`';
}
