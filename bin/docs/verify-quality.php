<?php

/**
 * DevTools: published documentation quality checks
 */

declare( strict_types=1 );

/**
 * Check the rendered pages, not the source they came from.
 *
 * The two existing verifiers check that the docs are *correct* -- that an
 * example's method calls resolve, that a hand-written module list matches the
 * registry. Nothing checked that a page is *readable*, and a docs build can
 * pass every correctness guard while publishing a dead link, a raw `--` where
 * an em dash was meant, or a paragraph written to the toolkit's maintainer
 * rather than to the person reading it.
 *
 * Run against `docs/` after generation, so it sees exactly what a reader sees:
 * a defect introduced by a template is caught the same way as one typed into a
 * docblock, and neither needs a rule of its own here.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Human-readable problems, empty when the set is clean.
 */
function zestry_verify_quality( string $root ): array {
	$problems = array();

	foreach ( zestry_documentation_pages( $root . '/docs' ) as $relative => $path ) {
		$lines = explode( "\n", (string) file_get_contents( $path ) );

		$problems = array_merge(
			$problems,
			zestry_check_links( $root, $relative, $path, $lines ),
			zestry_check_prose( $relative, $lines )
		);
	}

	return $problems;
}

/**
 * Every markdown page under a directory, keyed by its path relative to the root.
 *
 * @param string $directory Absolute path to search.
 * @return array<string, string> Repo-relative path => absolute path.
 */
function zestry_documentation_pages( string $directory ): array {
	if ( ! is_dir( $directory ) ) {
		return array();
	}

	$pages = array();
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory ) );

	foreach ( $files as $file ) {
		if ( $file->isFile() && 'md' === $file->getExtension() ) {
			$pages[ 'docs/' . substr( $file->getPathname(), strlen( $directory ) + 1 ) ] = $file->getPathname();
		}
	}

	ksort( $pages );

	return $pages;
}

/**
 * Report every relative link on a page whose target does not exist.
 *
 * A dead link is the defect a reader hits hardest and an author never sees:
 * nine module pages shipped a `../path/` that resolved nowhere, because `path`
 * is a service and lives in the other tree. GitHub renders a directory link as
 * that directory's README, so `services/` is resolved the same way here.
 *
 * @param string   $root     Absolute path to the repository root.
 * @param string   $relative The page's repo-relative path, for the message.
 * @param string   $path     The page's absolute path.
 * @param string[] $lines    The page's lines.
 * @return string[] Problems found.
 */
function zestry_check_links( string $root, string $relative, string $path, array $lines ): array {
	$problems = array();
	$fenced   = false;
	$headings = array();

	foreach ( $lines as $line ) {
		if ( preg_match( '/^\s*```/', $line ) ) {
			$fenced = ! $fenced;
			continue;
		}

		if ( ! $fenced && preg_match( '/^#+\s+(.*)$/', $line, $heading ) ) {
			$headings[] = zestry_heading_anchor( $heading[1] );
		}
	}

	$fenced = false;

	foreach ( $lines as $number => $line ) {
		if ( preg_match( '/^\s*```/', $line ) ) {
			$fenced = ! $fenced;
			continue;
		}

		if ( $fenced ) {
			continue;
		}

		if ( ! preg_match_all( '/\[[^\]]*\]\(([^)\s]+)\)/', $line, $links ) ) {
			continue;
		}

		foreach ( $links[1] as $target ) {
			if ( str_starts_with( $target, 'http' ) || str_starts_with( $target, 'mailto:' ) ) {
				continue;
			}

			// A bare `#anchor` points within this page.
			if ( str_starts_with( $target, '#' ) ) {
				if ( ! in_array( substr( $target, 1 ), $headings, true ) ) {
					$problems[] = sprintf(
						'%s:%d — links to %s, which is not a heading on this page',
						$relative,
						$number + 1,
						$target
					);
				}

				continue;
			}

			$file = strtok( $target, '#' );
			$full = realpath( dirname( $path ) . '/' . $file );

			if ( false !== $full && is_dir( $full ) ) {
				$full = realpath( $full . '/README.md' );
			}

			if ( false === $full || ! is_file( $full ) ) {
				$problems[] = sprintf(
					'%s:%d — links to %s, which does not exist',
					$relative,
					$number + 1,
					$target
				);
			}
		}
	}

	return $problems;
}

/**
 * GitHub's anchor for a heading: lowercased, punctuation dropped, spaces hyphenated.
 *
 * @param string $heading The heading text, without its leading hashes.
 * @return string The anchor, without its leading `#`.
 */
function zestry_heading_anchor( string $heading ): string {
	// Inline markup is not part of the anchor: `` `run()` `` anchors as `run`.
	$text = (string) preg_replace( '/[`*_]/', '', $heading );
	$text = (string) preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', $text );
	$text = strtolower( trim( $text ) );
	$text = (string) preg_replace( '/[^\p{L}\p{N} \-]/u', '', $text );

	return str_replace( ' ', '-', $text );
}

/**
 * Report published prose that reads as a note to the toolkit's own maintainer.
 *
 * A docblock is read both by someone working on this package and by whoever
 * reads the page generated from it, and the second audience is easy to forget
 * while writing for the first. These are the phrases that gave it away: design
 * history the reader did not live through, a decision defended against an
 * objection nobody raised, and the reader referred to in the third person.
 *
 * Checked against the rendered page rather than the docblock, so a phrase
 * hardcoded in a page template is caught the same way as one typed into source.
 *
 * @param string   $relative The page's repo-relative path, for the message.
 * @param string[] $lines    The page's lines.
 * @return string[] Problems found.
 */
function zestry_check_prose( string $relative, array $lines ): array {
	$banned = array(
		'the consumer'          => 'address the reader as "you"',
		'the consuming plugin'  => 'address the reader as "your plugin"',
		'consumers of'          => 'address the reader as "you"',
		'used to be'            => 'a reader did not see the old behaviour; state the current one',
		'used to return'        => 'a reader did not see the old behaviour; state the current one',
		'deliberately does not' => 'say what it does, not what it was decided against',
		'was removed'           => 'design history; the reader did not see it',
		'the reasoning is'      => 'design history; say what the reader should do',
		'worth knowing'         => 'if it is worth knowing, state it without the preamble',
		'proves the line'       => 'internal argument; state the rule instead',
	);

	$problems = array();
	$fenced   = false;

	foreach ( $lines as $number => $line ) {
		if ( preg_match( '/^\s*```/', $line ) ) {
			$fenced = ! $fenced;
			continue;
		}

		// Code, and the generated banner, are not prose a reader is asked to read.
		if ( $fenced || preg_match( '/^(?: {4}|\t|<!--|    )/', $line ) ) {
			continue;
		}

		foreach ( $banned as $phrase => $instead ) {
			if ( stripos( $line, $phrase ) !== false ) {
				$problems[] = sprintf(
					'%s:%d — "%s" reads as a note to this toolkit\'s maintainer; %s',
					$relative,
					$number + 1,
					$phrase,
					$instead
				);
			}
		}

		if ( preg_match( '/(?<=\S) -- (?=\S)/', zestry_strip_code_spans( $line ) ) ) {
			$problems[] = sprintf(
				'%s:%d — a literal `--` reached the page; prose is normalised by zestry_write_page()',
				$relative,
				$number + 1
			);
		}
	}

	return $problems;
}

/**
 * A line with its inline code spans removed.
 *
 * @param string $line One line of markdown.
 * @return string The line, without anything between backticks.
 */
function zestry_strip_code_spans( string $line ): string {
	return (string) preg_replace( '/`[^`]*`/', '', $line );
}
