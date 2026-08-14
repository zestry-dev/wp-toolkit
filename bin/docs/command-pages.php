<?php

/**
 * DevTools: command reference page generation
 */

declare( strict_types=1 );

/**
 * Split a command docblock into its summary and WP-CLI sections.
 *
 * @param string $body The stripped docblock body.
 * @return array{summary: string, options: string|null, examples: string|null}
 */
function zestry_parse_command_sections( string $body ): array {
	// A section runs until the next `##` heading or the first annotation tag.
	preg_match( '/## OPTIONS\n(.*?)(?=\n## |\n@|\z)/s', $body, $options );
	preg_match( '/## EXAMPLES\n(.*?)(?=\n## |\n@|\z)/s', $body, $examples );

	/*
	 * Everything before the first `##` heading is the command's own prose, and
	 * only its first paragraph -- the summary -- used to reach the page. The
	 * paragraphs after it are where a command says what it requires before it
	 * may be run and what it writes besides the file it reports, so generating
	 * them away left that discoverable only by reading resources/commands/.
	 */
	$lead = preg_split( '/\n## /', $body, 2 );

	// Punctuation included: a heading like `## WHAT IS NOT CHECKED` is ordinary,
	// and a pattern that quietly skips it drops the section from the page with
	// nothing to show that it did.
	preg_match_all( '/## ([A-Z][A-Z0-9 ?\/-]*)\n(.*?)(?=\n## |\n@|\z)/s', $body, $matches, PREG_SET_ORDER );

	/*
	 * Any heading beyond the two WP-CLI defines. Rendered as prose in the order
	 * written, so a command can explain something structural -- `make module`'s
	 * AUTOLOAD -- without this generator learning each heading by name.
	 */
	$extra = array();

	foreach ( $matches as $match ) {
		if ( in_array( trim( $match[1] ), array( 'OPTIONS', 'EXAMPLES' ), true ) ) {
			continue;
		}

		/*
		 * Split into prose and indented-sample blocks like every other page,
		 * rather than emitted verbatim: prose is then unwrapped to one line per
		 * paragraph the way the rest of the docs are, and an indented sample
		 * becomes a fenced block instead of markdown's bare four-space code.
		 */
		$extra[] = array(
			'heading' => ucfirst( strtolower( trim( $match[1] ) ) ),
			'blocks'  => zestry_split_example( trim( $match[2] ) )['blocks'],
		);
	}

	return array(
		'summary'     => zestry_summary( $body ),
		'description' => zestry_description( false === $lead ? $body : $lead[0] ),
		'sections'    => $extra,
		'options'     => isset( $options[1] ) ? trim( $options[1] ) : null,
		'examples'    => isset( $examples[1] ) ? trim( $examples[1] ) : null,
	);
}

/**
 * Turn WP-CLI's `<arg>` / `[--flag=<value>]` block into a markdown list.
 *
 * Each entry is an argument line followed by a `:`-prefixed description that
 * may wrap over several lines.
 *
 * An entry may also carry WP-CLI's own YAML block, fenced by `---`, listing
 * the values the flag accepts. That is what makes `wp` reject anything else,
 * so it is real information rather than noise -- but joined into the prose it
 * reads as "Defaults to none. --- options: - none - script - module ---". The
 * values are lifted out and rendered after the description instead.
 *
 * @param string $options The raw OPTIONS section.
 * @return string Rendered markdown.
 */
function zestry_render_options( string $options ): string {
	$rendered = array();

	foreach ( preg_split( '/\n(?=[<\[])/', $options ) ?: array() as $block ) {
		$block = trim( $block );

		// Pull the YAML block out before the rest is flattened into prose.
		$accepts = array();

		if ( preg_match( '/^\s*:?\s*---\s*$.*?^\s*\*?\s*options:\s*$(.*?)^\s*:?\s*---\s*$/ms', $block, $yaml ) ) {
			preg_match_all( '/^\s*-\s+(\S+)\s*$/m', $yaml[1], $items );
			$accepts = $items[1];
			$block   = trim( (string) preg_replace( '/^\s*:?\s*---\s*$.*?^\s*:?\s*---\s*$/ms', '', $block ) );
		}

		$lines = array_values( array_filter( explode( "\n", $block ), 'strlen' ) );

		if ( array() === $lines ) {
			continue;
		}

		$argument    = trim( (string) array_shift( $lines ) );
		$description = trim(
			implode(
				' ',
				array_map(
					static function ( string $line ): string {
						return trim( (string) preg_replace( '/^\s*:\s?/', '', $line ) );
					},
					$lines
				)
			)
		);

		/*
		 * A trailing "Defaults to ..." sentence states what the flag does when
		 * omitted, which is a fact about the flag rather than part of the prose
		 * describing it -- so it breaks onto its own line, above the accepted
		 * values, which are the same kind of fact.
		 */
		$description = (string) preg_replace(
			'/\s+(Defaults to\b)/',
			"  \n  $1",
			$description,
			1
		);

		if ( array() !== $accepts ) {
			$description .= sprintf(
				"  \n  Accepts %s.",
				implode(
					', ',
					array_map(
						static function ( string $value ): string {
							return '`' . $value . '`';
						},
						$accepts
					)
				)
			);
		}

		$rendered[] = sprintf( "- **`%s`**  \n  %s", $argument, $description );
	}

	return implode( "\n\n", $rendered );
}

/**
 * Write one reference page per `wp zt` command, plus the index.
 *
 * The command docblocks are already in WP-CLI's own reference format, since
 * that is what `wp help` renders. Generating from them means the published
 * reference and `wp help` are the same text by construction.
 *
 * @param string $root Absolute path to the repository root.
 * @return int The number of command pages written.
 */
function zestry_generate_command_pages( string $root ): int {
	$commands_dir = $root . '/resources/commands';
	$output_dir   = $root . '/docs/commands';

	if ( ! is_dir( $commands_dir ) ) {
		fwrite( STDERR, "Commands directory does not exist: {$commands_dir}\n" );
		exit( 1 );
	}

	if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0755, true ) && ! is_dir( $output_dir ) ) {
		fwrite( STDERR, "Could not create {$output_dir}\n" );
		exit( 1 );
	}

	foreach ( glob( $output_dir . '/*.md' ) ?: array() as $stale ) {
		unlink( $stale );
	}

	$directory = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $commands_dir ) );
	$files     = array();

	foreach ( $directory as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}

	sort( $files );

	$index = array();

	foreach ( $files as $path ) {
		$relative = substr( $path, strlen( $commands_dir ) + 1 );
		$source   = (string) file_get_contents( $path );
		$docblock = zestry_docblock_before( $source, 'public function handle\(' );

		if ( null === $docblock ) {
			fwrite( STDERR, "Skipped (no handle() docblock): {$relative}\n" );
			continue;
		}

		$command  = 'wp zt ' . str_replace( '/', ' ', substr( $relative, 0, -4 ) );
		$sections = zestry_parse_command_sections( $docblock );
		$slug     = str_replace( '/', '-', substr( $relative, 0, -4 ) );

		$page = zestry_generated_banner( 'resources/commands/' . $relative );

		$page[] = '# ' . $command;
		$page[] = '';
		$page[] = $sections['summary'];
		$page[] = '';

		if ( '' !== $sections['description'] ) {
			$page[] = $sections['description'];
			$page[] = '';
		}

		foreach ( $sections['sections'] as $section ) {
			$page[] = '## ' . $section['heading'];
			$page[] = '';
			$page   = array_merge( $page, zestry_render_blocks( $section['blocks'] ) );
		}

		$page[] = '## Options';
		$page[] = '';
		$page[] = null !== $sections['options']
			? zestry_render_options( $sections['options'] )
			: 'This command takes no arguments; it prompts for what it needs.';
		$page[] = '';

		$page = array_merge( $page, zestry_render_prompt_note( $relative, $source ) );

		if ( null !== $sections['examples'] ) {
			$page[] = '## Examples';
			$page[] = '';
			$page[] = '```bash';

			foreach ( explode( "\n", $sections['examples'] ) as $line ) {
				$page[] = rtrim( (string) preg_replace( '/^ {0,4}/', '', $line ) );
			}

			$page[] = '```';
			$page[] = '';
		}

		zestry_write_page( $output_dir . '/' . $slug . '.md', zestry_insert_toc( $page ) );

		$index[ $relative ] = sprintf( '- [`%s`](%s.md) — %s', $command, $slug, $sections['summary'] );
	}

	$page = zestry_generated_banner( 'the docblocks in resources/commands/' );

	$page[] = '# Command reference';
	$page[] = '';
	/*
	 * What actually gates availability, stated as the two separate conditions
	 * it is. This used to say every command needs a plugin that has run
	 * `wp zt init` -- which cannot be true of `init` itself, the command a
	 * reader arrives here to run first.
	 */
	$page[] = '`zt` is short for *zestry toolkit*.';
	$page[] = '';
	$page[] = 'Run these from inside your own plugin\'s directory, with the plugin active.';
	$page[] = 'Every command except [`wp zt init`](init.md) also needs that plugin to have';
	$page[] = 'been initialized — see [Getting started](../getting-started.md).';
	$page[] = '';

	/*
	 * Grouped by when you reach for it, not alphabetically. Sorted, `wp zt add
	 * module` came first and `wp zt init` eighth, so the one command that has
	 * to run before any of the others sat in the middle of the list.
	 */
	$page = array_merge( $page, zestry_group_command_index( $index ) );

	zestry_write_page( $output_dir . '/README.md', $page );

	return count( $index );
}

/**
 * Order the command index by the order the commands are run in.
 *
 * The lifecycle is the thing a reader does not know yet and cannot infer from
 * the list: `init` once, `add` per module, `make` per feature, then `update`
 * and `doctor` as needed. A heading per stage says it without a paragraph.
 *
 * Keyed by each command's own path under `resources/commands/`, so a new file lands in
 * the right group by name rather than needing a list edited here. Anything
 * unrecognised falls into the last group rather than being dropped.
 *
 * @param array<string, string> $index Rendered list items, keyed by relative path.
 * @return string[] Markdown lines.
 */
function zestry_group_command_index( array $index ): array {
	$groups = array(
		'Set the plugin up'      => array( 'init.php' ),
		'Add toolkit code'       => array( 'add/', 'overwrite/' ),
		'Generate your own code' => array( 'make/' ),
		'Keep it healthy'        => array( 'update.php', 'doctor.php' ),
	);

	$lines  = array();
	$placed = array();

	foreach ( $groups as $heading => $prefixes ) {
		$items = array();

		foreach ( $index as $relative => $item ) {
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $relative, $prefix ) ) {
					$items[]             = $item;
					$placed[ $relative ] = true;
					continue 2;
				}
			}
		}

		if ( array() === $items ) {
			continue;
		}

		$lines[] = '## ' . $heading;
		$lines[] = '';
		$lines[] = implode( "\n", $items );
		$lines[] = '';
	}

	$rest = array_diff_key( $index, $placed );

	if ( array() !== $rest ) {
		$lines[] = '## Everything else';
		$lines[] = '';
		$lines[] = implode( "\n", $rest );
		$lines[] = '';
	}

	return $lines;
}

/**
 * The note saying whether a generator stops to ask, and how to stop it.
 *
 * Derived from the command's own source rather than written on each page,
 * because it was written on exactly one: `make block` warned that an unattended
 * run "must pass both, or it will hang here", and the other four generators
 * that prompt said nothing at all. Nothing listed which ones do.
 *
 * `--yes` takes the documented default for every one of them without reading
 * input. That is true through two different mechanisms -- `Command::ask()`
 * returns its fallback, and `make block` deliberately sidesteps
 * `Command::confirm()`'s own yes-handling because answering *yes* to "render
 * this in PHP?" would build something nobody asked for -- and a reader needs
 * the guarantee, not the mechanism.
 *
 * Generators only. The other commands that prompt are confirming an action
 * rather than choosing what to write, and each already documents its own
 * `--yes` in the options above.
 *
 * A generator that never asks gets no note: a reassurance repeated on twenty
 * pages is noise on the fifteen it does not apply to.
 *
 * @param string $relative The command file's path, relative to `resources/commands/`.
 * @param string $source   The command file's full source.
 * @return string[] Lines to append, empty when the command never asks.
 */
function zestry_render_prompt_note( string $relative, string $source ): array {
	if ( ! str_starts_with( $relative, 'make/' ) ) {
		return array();
	}

	if ( ! preg_match( '/\$this->(ask|confirm)\(/', $source ) ) {
		return array();
	}

	return array(
		'> [!NOTE]',
		'> **This generator asks for anything you leave out.** Give every option above and it'
			. ' never stops.',
		'>',
		'> `--yes` answers every prompt with the documented default, without reading input --'
			. ' which is what an unattended run wants. Without it, and with nothing on standard'
			. ' input, the command waits.',
		'',
	);
}
