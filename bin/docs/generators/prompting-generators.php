<?php

/**
 * DevTools: which `wp zt make` types stop to ask
 */

declare( strict_types=1 );

/**
 * The generators that prompt for anything left out, as an inline list.
 *
 * Read from each command's own source, because the answer is "does this file
 * call `ask()` or `confirm()`" and a hand-kept list of five is a list that goes
 * to four.
 *
 * Only the per-type prompts are counted here. Every type can also stop at the
 * two `MakeCommand` asks -- overwrite an existing file, and add the module the
 * generated file needs -- so "never prompts" would be wrong about all of them.
 * That is said in the sentence rather than folded into the count, which is
 * about the options a reader can pass ahead of time.
 *
 * @param string $root Absolute path to the repository root.
 * @return string
 */
function zestry_generate_prompting_generators( string $root ): string {
	$files  = glob( $root . '/resources/commands/make/*.php' );
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
			. ' them stops. The other %d take no options they could ask about — but *any* generator'
			. ' stops to ask before overwriting a file, or to offer the module the generated file'
			. ' needs. `--yes` answers all of it without reading input, which is what an unattended'
			. ' run wants.',
		count( $asking ),
		$total,
		implode( ', ', $asking ),
		$total - count( $asking )
	);
}
