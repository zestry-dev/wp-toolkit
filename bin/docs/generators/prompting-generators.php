<?php

/**
 * DevTools: which `wp zestry make` types stop to ask
 */

declare( strict_types=1 );

/**
 * The generators that prompt for anything left out, as an inline list.
 *
 * Read from each command's own source, because the answer is "does this file
 * call `ask()` or `confirm()`" and a hand-kept list of five is a list that goes
 * to four. It was worse than that before: the warning existed on exactly one of
 * the five pages, and nothing anywhere said which types were affected.
 *
 * @param string $root Absolute path to the repository root.
 * @return string
 */
function zestry_generate_prompting_generators( string $root ): string {
	$files  = glob( $root . '/commands/make/*.php' );
	$asking = array();

	foreach ( false === $files ? array() : $files as $file ) {
		if ( ! preg_match( '/\$this->(ask|confirm)\(/', (string) file_get_contents( $file ) ) ) {
			continue;
		}

		$type     = basename( $file, '.php' );
		$asking[] = sprintf( '[`%s`](commands/make-%s.md)', $type, $type );
	}

	sort( $asking );

	$total = false === $files ? 0 : count( $files );

	return sprintf(
		'**%d of the %d generators ask for what you leave out** — %s. Give every option and none of'
			. ' them stops; `--yes` answers each with its documented default without reading input.'
			. ' The other %d never prompt.',
		count( $asking ),
		$total,
		implode( ', ', $asking ),
		$total - count( $asking )
	);
}
