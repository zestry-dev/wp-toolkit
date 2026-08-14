<?php

/**
 * DevTools: registry/docblock module-list verification
 */

declare( strict_types=1 );

/**
 * Check that `add` and `overwrite` name every installable module.
 *
 * `add` and `overwrite` each list the names they install in their own
 * `## OPTIONS` docblock, which WP-CLI reads verbatim for `--help` -- so the list
 * has to be literal text and cannot be computed from `registry.php` at runtime.
 * That makes it a copy, and a copy drifts.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Human-readable problems, empty when every list matches.
 */
function zestry_verify_module_lists( string $root ): array {
	$problems = array();
	$registry = require $root . '/src/DevTools/registry.php';

	$expected = array_keys( $registry );

	foreach ( array( 'add', 'overwrite' ) as $command ) {
		{
			$names    = $expected;
			$relative = 'commands/' . $command . '.php';
			$source   = (string) file_get_contents( $root . '/' . $relative );
			$label    = 'Available modules';

		if ( ! preg_match( '/' . $label . ':(.*?)\./s', $source, $match ) ) {
			$problems[] = sprintf( '%s — no "%s:" list to check.', $relative, $label );

			continue;
		}

			// The list wraps across docblock lines, so the ` * ` prefixes come
			// out before anything is split on the separator.
			$listed = preg_split(
				'/\s*,\s*/',
				trim( (string) preg_replace( '/\s*\*\s*/', ' ', $match[1] ) )
			);
			$listed = array_values( array_filter( array_map( 'trim', $listed ?: array() ), 'strlen' ) );

			$missing = array_diff( $names, $listed );
			$unknown = array_diff( $listed, $names );

		if ( array() !== $missing ) {
			$problems[] = sprintf( '%s — "%s" is missing: %s', $relative, $label, implode( ', ', $missing ) );
		}

		if ( array() !== $unknown ) {
			$problems[] = sprintf(
				'%s — "%s" names something the registry does not: %s',
				$relative,
				$label,
				implode( ', ', $unknown )
			);
		}
		}
	}

	return array_merge(
		$problems,
		zestry_verify_cheat_sheet( $root, $expected ),
		zestry_verify_front_page( $root, $expected )
	);
}

/**
 * Check the front page's inventory against the registry.
 *
 * The page a reader opens first says what the toolkit contains, and a name
 * missing from it is a component nobody looks for: the index pages behind the
 * links are generated and correct, but nothing sends you to them for something
 * you have no reason to think exists.
 *
 * Each name is checked as written in the registry — `meta-boxes`, not "meta
 * boxes" — because that is what `wp zt add` takes, and because a prose
 * rendering is something this cannot check and a reader cannot type.
 *
 * @param string                $root     Absolute path to the repository root.
 * @param string[] $expected Every registry name.
 * @return string[] Problems found.
 */
function zestry_verify_front_page( string $root, array $expected ): array {
	$relative = 'docs/README.md';
	$path     = $root . '/' . $relative;

	if ( ! is_file( $path ) ) {
		return array( $relative . ' — missing, so nothing tells a reader what is here.' );
	}

	$lines    = explode( "\n", (string) file_get_contents( $path ) );
	$problems = array();

	{
		$names  = $expected;
		$listed = array();

	foreach ( $lines as $number => $line ) {
		if ( ! str_starts_with( $line, '- [' ) || ! str_contains( $line, '](modules/)' ) ) {
			continue;
		}

		preg_match_all( '/`([a-z0-9-]+)`/', zestry_bullet_text( $lines, $number ), $matches );
		$listed = $matches[1];
	}

	if ( array() === $listed ) {
		$problems[] = $relative . ' — has no module inventory to check.';

		return $problems;
	}

		$missing = array_diff( $names, $listed );
		$unknown = array_diff( $listed, $names );

	if ( array() !== $missing ) {
		$problems[] = sprintf( '%1$s — does not list the modules: %2$s', $relative, implode( ', ', $missing ) );
	}

	if ( array() !== $unknown ) {
		$problems[] = sprintf( '%1$s — lists a module that does not exist: %2$s', $relative, implode( ', ', $unknown ) );
	}
	}

	return $problems;
}

/**
 * Check the cheat sheet's tables against the registry.
 *
 * The command help is generated from a docblock and drifts loudly; this page is
 * hand-written and drifts silently. It has done so twice -- `fields` and
 * `site-health` were absent for a day, `meta-boxes` for an hour -- which is
 * long enough to stop relying on remembering.
 *
 * Only presence is checked. What each row says about a module is prose, and
 * prose is not something a script can keep true.
 *
 * @param string                $root     Absolute path to the repository root.
 * @param array<string, string[]> $expected Registry names, by section.
 * @return string[] Problems, empty when the page lists everything.
 */
function zestry_verify_cheat_sheet( string $root, array $expected ): array {
	$relative = 'docs/cheat-sheet.md';
	$path     = $root . '/' . $relative;

	if ( ! is_file( $path ) ) {
		return array( $relative . ' — missing, so nothing links the registry to a reader.' );
	}

	$source   = (string) file_get_contents( $path );
	$problems = array();

	foreach ( $expected as $name ) {
		// The page links each one at least once; a name it never mentions is one
		// a reader scanning this page will not discover exists.
		if ( ! str_contains( $source, '](modules/' . $name . '/' ) ) {
			$problems[] = sprintf( '%1$s — does not list the module "%2$s".', $relative, $name );
		}
	}

	return $problems;
}

/**
 * A list item's whole text, including the lines it continues onto.
 *
 * A bullet used to hold its names inline. They are generated now, which puts
 * them on the lines after it -- so reading only the bullet's own line found an
 * empty inventory and reported the front page as having none.
 *
 * Checked against the rendered result rather than against the placeholder, on
 * purpose: this asks what a reader sees, and must keep answering that whether
 * the list was generated or typed by hand.
 *
 * @param string[] $lines  The page's lines.
 * @param int      $number The index of the line the item starts on.
 * @return string The item's text, newlines included.
 */
function zestry_bullet_text( array $lines, int $number ): string {
	$text = $lines[ $number ];

	for ( $next = $number + 1; $next < count( $lines ); $next++ ) {
		$line = $lines[ $next ];

		// Anything indented under the item, or the markers wrapping what was
		// generated into it. A new item at column zero, or a blank line, ends it.
		if ( '' === trim( $line ) || ( ! str_starts_with( $line, ' ' ) && ! str_starts_with( $line, "\t" ) ) ) {
			break;
		}

		$text .= "\n" . $line;
	}

	return $text;
}
