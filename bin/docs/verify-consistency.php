<?php

/**
 * DevTools: cross-page consistency checks
 */

declare( strict_types=1 );

/**
 * The tools whose config files `wp zestry init` and `wp zestry add` scaffold.
 *
 * Named once and used by every check here, so adding a scaffolded tool does not
 * mean remembering three regexes.
 */
const ZESTRY_SCAFFOLDED_TOOLS = 'prettier|eslint|phpcs|phpstan|stylelint|tsconfig|webpack|babel';

/**
 * Report a page stating something the source contradicts.
 *
 * The other five verifiers each check one page against the symbol it documents.
 * This one checks pages against each other and against the code that decides
 * the answer, which is where a different species of bug lives: a fact stated in
 * N places and updated in N-1.
 *
 * Every check here was written from a defect that actually shipped, all eight
 * of them at once, all found by a consumer rather than by a build:
 *
 * - `assets` was called a service on three pages after it became a module, so
 *   `wp zestry add service assets` was documented and errors.
 * - The `make` type list named 11 of the 20 generators, the missing half being
 *   everything added since it was written.
 * - `Zestry\WPToolkit\` appeared in consumer-facing examples -- namespaces that exist in
 *   this repository and in no consumer's plugin.
 * - `init` wrote `.prettierrc.js` while `add module blocks` wrote
 *   `prettier.config.mjs`, and both were documented; Prettier reads the first
 *   and ignores the second.
 *
 * None of these is catchable by reading one page. Each is catchable by asking
 * the source, which is what this does.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Human-readable problems, empty when the set is clean.
 */
function zestry_verify_consistency( string $root ): array {
	$pages = zestry_documentation_pages( $root . '/docs' );

	return array_merge(
		zestry_check_kind_claims( $root, $pages ),
		zestry_check_make_types( $root, $pages ),
		zestry_check_namespace_leaks( $pages ),
		zestry_check_written_files( $root, $pages ),
		zestry_check_rules_page( $root ),
		zestry_check_documented_constants( $root, $pages ),
		zestry_check_example_slugs( $root, $pages ),
		zestry_check_block_php_field( $pages ),
		zestry_check_stranded_docblocks( $root ),
		zestry_check_cheat_sheet_flags( $root, $pages )
	);
}

/**
 * Report a flag a command takes that its cheat-sheet row does not list.
 *
 * The cheat sheet is billed as every command on one page, which makes it where a
 * reader looks for a flag rather than the command's own page. `--yes` was missing
 * from all six rows whose command accepts it, and `wp zestry update` prompts -- so a
 * reader who trusted the sheet found the command unrunnable unattended, and read
 * `Cancelled.` from an empty stdin.
 *
 * The rows are hand-written, so regenerating cannot fix them. Each command's
 * synopsis is the source of truth: WP-CLI reads the `[--flag]` lines out of the
 * docblock, so what the sheet claims is checked against what the command will
 * actually accept.
 *
 * Both directions, for different failures. A row omitting a flag hides one that
 * works; a row claiming one the synopsis does not declare is worse, because
 * WP-CLI treats an undeclared flag as a fatal parameter error -- `add module`
 * and `add service` were listed with `--yes`, which neither declares and neither
 * needs, so the documented unattended invocation exited non-zero.
 *
 * A row is paired with its command by the page it links to, not by name: two
 * files are called `module.php` (`add` and `overwrite`), and matching on the
 * basename alone holds each to the other's flags.
 *
 * Values a row spells out (`--format=a|b`) are not claims about a second flag,
 * so only the flag name is compared.
 *
 * @param string                $root  Absolute path to the repository root.
 * @param array<string, string> $pages Repo-relative path => absolute path.
 * @return string[] Problems found.
 */
function zestry_check_cheat_sheet_flags( string $root, array $pages ): array {
	if ( ! isset( $pages['docs/cheat-sheet.md'] ) ) {
		return array();
	}

	$sheet = (string) file_get_contents( $pages['docs/cheat-sheet.md'] );
	$rows  = array();

	// Each `| [`wp zestry <command>`](<page>) | <description> |` row of the table.
	if ( preg_match_all( '~\|\s*\[`(wp zestry [^`]+)`\]\(([^)]*)\)\s*\|([^|]*)\|~', $sheet, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$rows[ trim( $match[1] ) ] = array(
				'page'        => basename( trim( $match[2] ) ),
				'description' => $match[3],
			);
		}
	}

	$problems = array();

	foreach ( zestry_command_files( $root ) as $file ) {
		$source = (string) file_get_contents( $file );
		$flags  = array();

		if ( preg_match_all( '~^\s*\*\s*\[--([a-z0-9-]+)~m', $source, $found ) ) {
			$flags = array_unique( $found[1] );
		}

		$relative = substr( $file, strlen( $root ) + 1 );

		// `commands/add/module.php` documents `docs/commands/add-module.md`.
		$page = str_replace( '/', '-', substr( $relative, strlen( 'commands/' ), -strlen( '.php' ) ) ) . '.md';

		foreach ( $rows as $command => $row ) {
			if ( $row['page'] !== $page ) {
				continue;
			}

			foreach ( $flags as $flag ) {
				if ( ! str_contains( $row['description'], '--' . $flag ) ) {
					$problems[] = sprintf(
						'docs/cheat-sheet.md: the row for `%s` omits `--%s`, which %s accepts -- and the sheet is where a reader looks for a flag.',
						$command,
						$flag,
						$relative
					);
				}
			}

			if ( ! preg_match_all( '~`--([a-z0-9-]+)~', $row['description'], $claimed ) ) {
				continue;
			}

			foreach ( array_unique( $claimed[1] ) as $flag ) {
				if ( in_array( $flag, $flags, true ) ) {
					continue;
				}

				$problems[] = sprintf(
					'docs/cheat-sheet.md: the row for `%s` offers `--%s`, which %s does not declare -- WP-CLI rejects an undeclared flag outright, so the documented command exits non-zero.',
					$command,
					$flag,
					$relative
				);
			}
		}
	}

	return $problems;
}

/**
 * Every DevTools command file, including each subcommand directory.
 *
 * `add` and `overwrite` are here for the same reason `make` is: each is a real
 * command with its own synopsis and its own row on the cheat sheet.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Absolute paths.
 */
function zestry_command_files( string $root ): array {
	return array_merge(
		glob( $root . '/commands/*.php' ) ?: array(),
		glob( $root . '/commands/make/*.php' ) ?: array(),
		glob( $root . '/commands/add/*.php' ) ?: array(),
		glob( $root . '/commands/overwrite/*.php' ) ?: array()
	);
}

/**
 * Report a docblock that documents the docblock below it.
 *
 * Inserting a method above an existing one, anchored on that one's docblock,
 * lands the new pair *between* the anchor and what it describes -- leaving two
 * docblocks stacked and the method below them silently undocumented. The file
 * still parses, the tests still pass, and the generated page simply omits the
 * description: `Arr::wrap()` shipped that way, and two test files each lost the
 * paragraph explaining what they were pinning.
 *
 * Nothing else catches it. `phpcs` reads `src/`, `bin/` and `commands/` only, so
 * `tests/` is unguarded entirely, and no sniff in the ruleset objects to a
 * docblock in a place a docblock may legally go.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Problems found.
 */
function zestry_check_stranded_docblocks( string $root ): array {
	$problems = array();

	foreach ( array( 'bin', 'commands', 'src', 'tests' ) as $dir ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$source   = (string) file_get_contents( $file->getPathname() );
			$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
			$matches  = array();

			// A docblock whose next non-blank line opens another docblock.
			if ( ! preg_match_all( '~^([ \t]*)/\*\*(?:(?!\*/).)*?\*/\n(?=[ \t]*/\*\*)~sm', $source, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $match ) {
				$line    = substr_count( $source, "\n", 0, (int) $match[1] ) + 1;
				$summary = trim( (string) strtok( trim( explode( "\n", $match[0] )[1] ), '' ), " \t*" );

				$problems[] = sprintf(
					'%s:%d: a docblock sits directly above another docblock, so whatever it describes is undocumented -- "%s"',
					$relative,
					$line,
					$summary
				);
			}
		}
	}

	return $problems;
}

/**
 * Report an example slug that `Plugin` would refuse.
 *
 * Every name a plugin registers is composed from its slug, so the constructor
 * holds it to a pattern -- and a guide handing the reader a slug outside that
 * pattern hands them a plugin that throws before their first test runs. Which is
 * what `docs/testing.md` did: it passed `acme_test` to the base test case it tells
 * you to paste into `tests/TestCase.php`.
 *
 * Nothing else catches this. The pages that name a slug are hand-written, so
 * regenerating cannot fix them, and a slug inside a fenced block is a string to
 * every other guard here.
 *
 * The pattern is read out of `Plugin.php` rather than repeated, so tightening the
 * constructor tightens this with it.
 *
 * @param string                $root  Absolute path to the repository root.
 * @param array<string, string> $pages Repo-relative path => absolute path.
 * @return string[] Problems found.
 */
function zestry_check_example_slugs( string $root, array $pages ): array {
	$source = (string) file_get_contents( $root . '/src/Kernel/Plugin.php' );

	if ( ! preg_match( "#preg_match\\(\\s*'(/\\^.*?\\$/)'#", $source, $found ) ) {
		return array( 'src/Kernel/Plugin.php — no slug pattern found; zestry_check_example_slugs() cannot run' );
	}

	$pattern  = $found[1];
	$problems = array();

	foreach ( $pages as $relative => $path ) {
		$lines = explode( "\n", (string) file_get_contents( $path ) );

		foreach ( $lines as $number => $line ) {
			// The second constructor argument, wherever a page builds a Plugin.
			if ( ! preg_match_all( "/new Plugin\\([^)]*,\\s*'([^']+)'/", $line, $slugs ) ) {
				continue;
			}

			foreach ( $slugs[1] as $slug ) {
				if ( 1 === preg_match( $pattern, $slug ) ) {
					continue;
				}

				$problems[] = sprintf(
					'%s:%d — the example slug "%s" is one Plugin::__construct() throws on',
					$relative,
					$number + 1,
					$slug
				);
			}
		}
	}

	return $problems;
}

/**
 * Report a `block.json` example declaring the render field at the root.
 *
 * The official block schema sets `additionalProperties: false` on the root, so
 * the field a dynamic block declares its PHP with lives under `supports`. Prose
 * on three pages says so; the worked `block.json` on a fourth said otherwise for
 * one release, because it is rendered from the stub with its own sample values
 * and only the stub was moved. A reader hand-writing one has no way to tell which
 * is right, and the wrong choice registers a block that works in the editor and
 * renders nothing.
 *
 * Matched on the rendered page rather than the stub, so a sample value that
 * drifts from the stub is caught the same way as a stub that drifts.
 *
 * @param array<string, string> $pages Repo-relative path => absolute path.
 * @return string[] Problems found.
 */
function zestry_check_block_php_field( array $pages ): array {
	$problems = array();

	foreach ( $pages as $relative => $path ) {
		$lines = explode( "\n", (string) file_get_contents( $path ) );

		foreach ( $lines as $number => $line ) {
			// One tab of indentation is a root-level key in these examples; the
			// entry under `supports` is indented twice.
			if ( ! preg_match( '/^\t"[a-z0-9-]+-php":/', $line ) ) {
				continue;
			}

			$problems[] = sprintf(
				'%s:%d — declares the block render field at the root of a block.json;'
					. ' it belongs under `supports`, which is the only object the schema allows extras on',
				$relative,
				$number + 1
			);
		}
	}

	return $problems;
}

/**
 * Report a page filing a module under services, or the reverse.
 *
 * The distinction decides whether a name works with `wp zestry add service` or
 * `wp zestry add module`, and getting it wrong hands a reader a command that
 * errors. It is also the toolkit's central idea, so a page contradicting it
 * teaches the wrong model before it wastes anyone's time.
 *
 * Two shapes carry the claim, and both are checked against `registry.php`:
 *
 *     ## Services                      <- a heading, or a `wp zestry add service`
 *     | [`assets`](modules/assets/) |     line, sets the kind for what follows
 *
 *     - a **service** works only when you call it -- `path`, `views`, `assets`
 *
 * A line naming both kinds ("a module may depend on services") states no claim
 * about either and is skipped. Only a table's first cell counts, since later
 * columns legitimately name the other kind -- a module's "Also copies" is full
 * of services.
 *
 * @param string                $root  Absolute path to the repository root.
 * @param array<string, string> $pages Repo-relative path => absolute path.
 * @return string[] Problems found.
 */
function zestry_check_kind_claims( string $root, array $pages ): array {
	$sections = zestry_registry_sections( $root );
	$problems = array();

	foreach ( $pages as $relative => $path ) {
		$lines = explode( "\n", (string) file_get_contents( $path ) );
		$kind  = null;
		$fence = false;

		foreach ( $lines as $number => $line ) {
			if ( str_starts_with( ltrim( $line ), '```' ) ) {
				$fence = ! $fence;
				continue;
			}

			if ( $fence ) {
				continue;
			}

			$stated = zestry_stated_kind( $line );

			// A heading resets the kind even when it names neither, so a table
			// under "## What throws what" does not inherit "## Services".
			if ( str_starts_with( $line, '#' ) ) {
				$kind = $stated;
				continue;
			}

			// A line defining a kind states it for everything it then lists, and
			// is checked whole rather than by column. Before the `wp zestry add`
			// branch below, because the definitional bullets end with exactly
			// that command -- setting the context first skipped the very line
			// most worth checking.
			$defined = zestry_defined_kind( $line );

			if ( null !== $defined ) {
				$problems = array_merge(
					$problems,
					zestry_kind_mismatches(
						$relative,
						$number + 1,
						zestry_backticked_registry_names( $line, $sections ),
						$defined,
						$sections
					)
				);

				$kind = $defined;
				continue;
			}

			// The most literal form of the claim, and the one worth checking
			// hardest: a command a reader will copy, paste and watch fail. Named
			// before the context is set from it, since setting the context and
			// moving on is what let `wp zestry add service assets` pass green.
			$problems = array_merge( $problems, zestry_add_command_mismatches( $relative, $number + 1, $line, $sections ) );

			if ( null !== $stated && str_contains( $line, 'wp zestry add ' ) ) {
				$kind = $stated;
				continue;
			}

			if ( null === $kind || ! str_starts_with( ltrim( $line ), '|' ) ) {
				continue;
			}

			$cells = explode( '|', trim( $line, "| \t" ) );
			$first = zestry_backticked_registry_names( $cells[0] ?? '', $sections );

			$problems = array_merge(
				$problems,
				zestry_kind_mismatches( $relative, $number + 1, $first, $kind, $sections )
			);
		}
	}

	return $problems;
}

/**
 * Report a `make` type documented but absent, or present but undocumented.
 *
 * Both directions matter and they fail differently. A documented type that does
 * not exist is a command someone runs and cannot; an undocumented one is a
 * generator nobody finds, which is how half of them became invisible.
 *
 * The index pages are named explicitly because they are the two that promise
 * completeness -- a type mentioned in passing on a module page is not a claim
 * to have listed them all.
 *
 * @param string                $root  Absolute path to the repository root.
 * @param array<string, string> $pages Repo-relative path => absolute path.
 * @return string[] Problems found.
 */
function zestry_check_make_types( string $root, array $pages ): array {
	$types = array();

	foreach ( glob( $root . '/commands/make/*.php' ) ?: array() as $file ) {
		$types[] = basename( $file, '.php' );
	}

	sort( $types );

	$problems = array();
	$indexes  = array( 'docs/cheat-sheet.md', 'docs/commands/README.md' );

	foreach ( $pages as $relative => $path ) {
		$markdown = (string) file_get_contents( $path );

		if ( ! preg_match_all( '/wp zestry make ([a-z][a-z-]*)/', $markdown, $matches ) ) {
			continue;
		}

		foreach ( array_unique( $matches[1] ) as $type ) {
			// `<type>` is the placeholder in the generic form, not a type.
			if ( 'type' === $type || in_array( $type, $types, true ) ) {
				continue;
			}

			$problems[] = sprintf(
				'%s — documents `wp zestry make %s`, which does not exist (no commands/make/%s.php)',
				$relative,
				$type,
				$type
			);
		}
	}

	foreach ( $indexes as $index ) {
		if ( ! isset( $pages[ $index ] ) ) {
			continue;
		}

		$markdown = (string) file_get_contents( $pages[ $index ] );

		foreach ( $types as $type ) {
			if ( str_contains( $markdown, 'make ' . $type ) || str_contains( $markdown, 'make-' . $type . '.md' ) ) {
				continue;
			}

			$problems[] = sprintf(
				'%s — never mentions `wp zestry make %s`, and this page lists every type',
				$index,
				$type
			);
		}
	}

	return $problems;
}

/**
 * Report this package's own namespace inside a consumer-facing example.
 *
 * `Zestry\WPToolkit\Modules\RestApi\Route` resolves -- in this repository, which is the one
 * place a reader is not. A copy lands under their namespace and a `Core`
 * segment, so an import they paste names nothing.
 *
 * Deliberately narrower than {@see zestry_verify_imports()}, which accepts all
 * three spellings because it asks whether a class exists. This asks who the
 * line is written for, which is why the leak passed that guard green.
 *
 * Prose may name `Zestry\WPToolkit\` freely -- describing what `wp zestry add` rewrites is
 * exactly the case for it -- so only fenced blocks are checked.
 *
 * @param array<string, string> $pages Repo-relative path => absolute path.
 * @return string[] Problems found.
 */
function zestry_check_namespace_leaks( array $pages ): array {
	$problems = array();

	foreach ( $pages as $relative => $path ) {
		$lines = explode( "\n", (string) file_get_contents( $path ) );
		$fence = false;

		foreach ( $lines as $number => $line ) {
			if ( str_starts_with( ltrim( $line ), '```' ) ) {
				$fence = ! $fence;
				continue;
			}

			if ( ! $fence || ! preg_match( '/\bZestry\\WPToolkit\\\\\w/', $line ) ) {
				continue;
			}

			$problems[] = sprintf(
				'%s:%d — names `Zestry\\WPToolkit\\` in an example; a consumer\'s copy is `Acme\\Plugin\\Core\\...`',
				$relative,
				$number + 1
			);
		}
	}

	return $problems;
}

/**
 * Report two commands scaffolding one tool's config under different names, and
 * a page naming a config file the source has never heard of.
 *
 * The first is the check that matters, and it reads the source alone. `init`
 * wrote `.prettierrc.js` while `add module blocks` wrote `prettier.config.mjs`;
 * Prettier resolves the first and ignores the rest, so the second was a file
 * that read as configuration to everyone but Prettier. Both pages documented
 * their own command correctly -- neither was wrong on its own, and the defect
 * existed in the source before it reached either. So catch it there: **one
 * tool, one scaffolded name.**
 *
 * The documentation half is deliberately weaker. A page may legitimately name a
 * config file nothing writes -- `init.md` says to rename `.prettierrc.js` to
 * `.prettierrc.cjs` under `"type": "module"`, which is advice, not a claim --
 * so this only flags a name the source has never mentioned at all. That catches
 * an invented name and leaves rename advice alone.
 *
 * Scoped to the tools this toolkit scaffolds. A broader filename sweep would
 * flag every `block.json` and `style.scss` a page legitimately names.
 *
 * @param string                $root  Absolute path to the repository root.
 * @param array<string, string> $pages Repo-relative path => absolute path.
 * @return string[] Problems found.
 */
function zestry_check_written_files( string $root, array $pages ): array {
	$written    = zestry_scaffolded_write_sites( $root );
	$recognised = zestry_scaffolded_config_names( zestry_devtools_source( $root ) );
	$problems   = array();
	$by_tool    = array();

	foreach ( $written as $name ) {
		$by_tool[ zestry_config_tool( $name ) ][] = $name;
	}

	foreach ( $by_tool as $tool => $names ) {
		if ( count( $names ) < 2 ) {
			continue;
		}

		$problems[] = sprintf(
			'src/DevTools — scaffolds %s under %d names (%s). %s reads the first it resolves and ignores the rest,'
				. ' so the others are files that read as configuration to everyone but %s',
			$tool,
			count( $names ),
			implode( ', ', $names ),
			ucfirst( $tool ),
			ucfirst( $tool )
		);
	}

	foreach ( $pages as $relative => $path ) {
		$markdown = (string) file_get_contents( $path );

		if ( ! preg_match_all( '/`([^`\s]+)`/', $markdown, $matches ) ) {
			continue;
		}

		foreach ( zestry_scaffolded_config_names( implode( "\n", $matches[1] ) ) as $name ) {
			if ( in_array( $name, $recognised, true ) ) {
				continue;
			}

			$problems[] = sprintf(
				'%s — names `%s`, which no source file mentions. The scaffolded name is `%s`',
				$relative,
				$name,
				implode( '`, `', $by_tool[ zestry_config_tool( $name ) ] ?? array( '(none)' ) )
			);
		}
	}

	return $problems;
}

/**
 * Which tool a config filename belongs to.
 *
 * @param string $name A config filename.
 * @return string The tool's name, lowercase.
 */
function zestry_config_tool( string $name ): string {
	preg_match( '/(' . ZESTRY_SCAFFOLDED_TOOLS . ')/i', $name, $match );

	return strtolower( $match[1] ?? 'unknown' );
}

/**
 * The registry's two sections, as name => section.
 *
 * @param string $root Absolute path to the repository root.
 * @return array<string, string> Entry name => 'services' or 'modules'.
 */
function zestry_registry_sections( string $root ): array {
	$registry = require $root . '/src/DevTools/registry.php';
	$sections = array();

	foreach ( array( 'services', 'modules' ) as $section ) {
		foreach ( array_keys( $registry[ $section ] ?? array() ) as $name ) {
			$sections[ $name ] = $section;
		}
	}

	return $sections;
}

/**
 * Which kind a line claims, or null when it claims neither or both.
 *
 * Link targets are stripped first. A generated "Dependencies" line points at
 * `../../services/path/`, which says nothing about the page it is on -- reading
 * the word out of a URL made every module page claim to be about services.
 *
 * @param string $line A line of markdown.
 * @return string|null 'services', 'modules', or null.
 */
function zestry_stated_kind( string $line ): ?string {
	$prose   = (string) preg_replace( '/\]\([^)]*\)/', ']', $line );
	$service = (bool) preg_match( '/\bservices?\b/i', $prose );
	$module  = (bool) preg_match( '/\bmodules?\b/i', $prose );

	if ( $service === $module ) {
		return null;
	}

	return $service ? 'services' : 'modules';
}

/**
 * Which kind a line *defines*, as opposed to mentioning in passing.
 *
 * The definitional bullets bold the word being defined -- `a **service** works
 * only when you call it -- ...` -- and those are the lines whose list has to
 * match. An incidental mention does not make a claim about everything else on
 * the line: "`globals`, `transients` and the `options` module are the same four
 * verbs" names a kind for one of three names, and reading it as a claim about
 * all three is how this check first reported two false positives.
 *
 * @param string $line A line of markdown.
 * @return string|null 'services', 'modules', or null.
 */
function zestry_defined_kind( string $line ): ?string {
	if ( ! preg_match( '/\*\*\[?(services?|modules?)\b/i', $line, $match ) ) {
		return null;
	}

	return str_starts_with( strtolower( $match[1] ), 'service' ) ? 'services' : 'modules';
}

/**
 * The registry entry names appearing in backticks in a fragment of markdown.
 *
 * @param string                $fragment A line, or one table cell.
 * @param array<string, string> $sections Entry name => section.
 * @return string[]
 */
function zestry_backticked_registry_names( string $fragment, array $sections ): array {
	if ( ! preg_match_all( '/`([a-z][a-z-]*)`/', $fragment, $matches ) ) {
		return array();
	}

	return array_values( array_intersect( array_unique( $matches[1] ), array_keys( $sections ) ) );
}

/**
 * Report each name whose registry section is not the one claimed.
 *
 * @param string                $relative The page's repo-relative path.
 * @param int                   $line     The line number, 1-indexed.
 * @param string[]              $names    Registry names claimed.
 * @param string                $kind     The section claimed for them.
 * @param array<string, string> $sections Entry name => section.
 * @return string[] Problems found.
 */
function zestry_kind_mismatches( string $relative, int $line, array $names, string $kind, array $sections ): array {
	$problems = array();

	foreach ( $names as $name ) {
		if ( $sections[ $name ] === $kind ) {
			continue;
		}

		$problems[] = sprintf(
			'%s:%d — files `%s` under %s; registry.php has it as a %s, so `wp zestry add %s %s` errors',
			$relative,
			$line,
			$name,
			$kind,
			rtrim( $sections[ $name ], 's' ),
			rtrim( $kind, 's' ),
			$name
		);
	}

	return $problems;
}

/**
 * Every config filename for a tool this package scaffolds, found in a blob.
 *
 * A `.stub` is the template a config is rendered from, not a config, so
 * `prettierrc.js.stub` is dropped rather than counted as a second Prettier
 * name -- which it would otherwise be, since it sits in the same call as the
 * real one.
 *
 * @param string $text Source or documentation text.
 * @return string[] Unique names, sorted.
 */
function zestry_scaffolded_config_names( string $text ): array {
	if ( ! preg_match_all( '/(?:^|[\s\'"`\/])(\.?(?:' . ZESTRY_SCAFFOLDED_TOOLS . ')[\w.-]*\.\w+)/im', $text, $matches ) ) {
		return array();
	}

	$names = array_filter(
		array_unique( $matches[1] ),
		static function ( string $name ): bool {
			return ! str_ends_with( $name, '.stub' );
		}
	);

	sort( $names );

	return array_values( $names );
}

/**
 * Every line of `src/DevTools/` and `commands/`, for a name-recognition scan.
 *
 * @param string $root Absolute path to the repository root.
 * @return string The concatenated source.
 */
function zestry_devtools_source( string $root ): string {
	$text  = '';
	$files = array_merge(
		glob( $root . '/src/DevTools/*.php' ) ?: array(),
		glob( $root . '/src/DevTools/Abstracts/*.php' ) ?: array(),
		glob( $root . '/src/DevTools/stubs/*.stub' ) ?: array(),
		glob( $root . '/commands/*.php' ) ?: array()
	);

	foreach ( $files as $file ) {
		$text .= (string) file_get_contents( $file ) . "\n";
	}

	return $text;
}

/**
 * Every config filename the scaffolder actually writes.
 *
 * Deliberately not "every config filename the source mentions". Reading the
 * whole of `src/DevTools/` finds {@see \Zestry\WPToolkit\DevTools\Tooling::PRETTIER_CONFIG_FILES},
 * which lists the twelve names Prettier *resolves* so that an existing config is
 * never doubled -- so a source scan reports `prettier.config.mjs` as written and
 * the check passes on the exact bug it exists to catch. Only a `write_*()` call
 * site counts, plus the stub directory, since a stub is a file that becomes one.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Unique names, sorted.
 */
function zestry_scaffolded_write_sites( string $root ): array {
	$source = '';
	$files  = array_merge(
		glob( $root . '/src/DevTools/*.php' ) ?: array(),
		glob( $root . '/src/DevTools/Abstracts/*.php' ) ?: array(),
		glob( $root . '/commands/*.php' ) ?: array()
	);

	foreach ( $files as $file ) {
		$source .= (string) file_get_contents( $file ) . "\n";
	}

	// The call and its arguments, which for a multi-line call is more than the
	// line the name sits on.
	preg_match_all( '/write_\w*\s*\([^;]{0,400}/s', $source, $calls );

	$names = zestry_scaffolded_config_names( implode( "\n", $calls[0] ) );

	sort( $names );

	return array_values( $names );
}

/**
 * Report a malformed rule on the page every other page defers to.
 *
 * `docs/rules.md` is the one page written to be memorised and reread, and it is
 * also the source a consumer's own `AGENTS.md` is rendered from -- so a rule
 * that cannot be parsed is a rule that silently stops reaching an agent, and a
 * rule with nothing to click is an absolute with no argument anywhere.
 *
 * Three things, all cheap and all structural. Whether a rule is *true* is not
 * checkable here; the pages it links to are what keep that honest.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Problems found.
 */
function zestry_check_rules_page( string $root ): array {
	$relative = 'docs/rules.md';
	$path     = $root . '/' . $relative;

	if ( ! is_file( $path ) ) {
		return array( $relative . ' — missing, and every page defers to it.' );
	}

	$problems = array();
	$expected = 1;

	foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $number => $line ) {
		if ( ! preg_match( '/^(\d+)\.\s+(.*)$/', $line, $match ) ) {
			continue;
		}

		if ( (int) $match[1] !== $expected ) {
			$problems[] = sprintf(
				'%s:%d — is rule %s, but the one before it was %d. Numbering has to run unbroken from 1',
				$relative,
				$number + 1,
				$match[1],
				$expected - 1
			);
		}

		++$expected;

		if ( ! str_starts_with( $match[2], '**' ) ) {
			$problems[] = sprintf(
				'%s:%d — rule %s does not open with its statement in bold, so nothing can tell the rule from its elaboration',
				$relative,
				$number + 1,
				$match[1]
			);
		}

		if ( ! preg_match( '/\[[^\]]+\]\([^)]+\)\s*$/', $line ) ) {
			$problems[] = sprintf(
				'%s:%d — rule %s links nowhere, so nothing on the site argues for it',
				$relative,
				$number + 1,
				$match[1]
			);
		}
	}

	if ( 1 === $expected ) {
		$problems[] = $relative . ' — has no numbered rules on it.';
	}

	return $problems;
}

/**
 * Report a literal `wp zestry add` naming something the other section holds.
 *
 * The narrowest and most valuable form of a kind claim: not a name filed under
 * a heading, but a command line a reader copies, pastes and watches fail. The
 * defect that started all of this was documented exactly this way.
 *
 * A placeholder (`wp zestry add service <name>`) names nothing and is skipped, as
 * is any word that is not a registry entry -- the run after the kind is read
 * greedily so that `wp zestry add module cli admin-pages` checks both.
 *
 * @param string                $relative The page's repo-relative path.
 * @param int                   $line     The line number, 1-indexed.
 * @param string                $text     The line.
 * @param array<string, string> $sections Entry name => section.
 * @return string[] Problems found.
 */
function zestry_add_command_mismatches( string $relative, int $line, string $text, array $sections ): array {
	if ( ! preg_match_all( '/\badd (service|module)s?\s+((?:[a-z][a-z0-9-]*[ \t]*)+)/', $text, $matches, PREG_SET_ORDER ) ) {
		return array();
	}

	$problems = array();

	foreach ( $matches as $match ) {
		$kind  = $match[1] . 's';
		$names = preg_split( '/\s+/', trim( $match[2] ) ) ?: array();

		$problems = array_merge(
			$problems,
			zestry_kind_mismatches(
				$relative,
				$line,
				array_values( array_intersect( $names, array_keys( $sections ) ) ),
				$kind,
				$sections
			)
		);
	}

	return $problems;
}

/**
 * Report a page documenting a constant the class it describes does not declare.
 *
 * The generator reads constants out of the source, so a `## Constants` entry is
 * something it decided rather than something anyone wrote -- and a wrong one is
 * therefore invisible in review, which is exactly how `const form =
 * document.querySelector(...)` inside a fenced JavaScript example was published
 * as a class constant of `Block`, complete with an entry in the page's nav.
 *
 * Reading the source with `token_get_all()` rather than a pattern is what fixed
 * that, and this is the check that says so stayed fixed. A pattern cannot tell
 * PHP from something that merely looks like it inside a comment; a tokenizer
 * can, and nothing else here needs to be clever.
 *
 * @param string                $root  Absolute path to the repository root.
 * @param array<string, string> $pages Repo-relative path => absolute path.
 * @return string[] Problems found.
 */
function zestry_check_documented_constants( string $root, array $pages ): array {
	$problems = array();

	foreach ( $pages as $relative => $path ) {
		$markdown = (string) file_get_contents( $path );

		if ( ! preg_match( '/^## Constants$(.*?)(?=^## |\z)/ms', $markdown, $section ) ) {
			continue;
		}

		if ( ! preg_match_all( '/^### `(\w+)`$/m', $section[1], $named ) ) {
			continue;
		}

		$class  = zestry_page_class( $root, $relative );
		$source = null === $class ? '' : (string) file_get_contents( $class );

		foreach ( $named[1] as $constant ) {
			if ( '' !== $source && zestry_declares_constant( $source, $constant ) ) {
				continue;
			}

			$problems[] = sprintf(
				'%s — documents a constant `%s` that %s does not declare. The generator invented it,'
					. ' which means it read something that only looked like PHP.',
				$relative,
				$constant,
				null === $class ? 'its class' : basename( $class )
			);
		}
	}

	return $problems;
}

/**
 * The source file a generated page was rendered from, or null.
 *
 * @param string $root     Absolute path to the repository root.
 * @param string $relative The page's repo-relative path.
 * @return string|null Absolute path to the class file.
 */
function zestry_page_class( string $root, string $relative ): ?string {
	$markdown = (string) file_get_contents( $root . '/' . $relative );

	if ( ! preg_match( '/Generated from ([\w\/.]+\.php)/', $markdown, $from ) ) {
		return null;
	}

	return is_file( $root . '/' . $from[1] ) ? $root . '/' . $from[1] : null;
}

/**
 * Whether a source file declares a constant as PHP, rather than inside a comment.
 *
 * @param string $source The class file's source.
 * @param string $name   The constant's name.
 * @return bool
 */
function zestry_declares_constant( string $source, string $name ): bool {
	$tokens = token_get_all( $source );

	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) || T_CONST !== $token[0] ) {
			continue;
		}

		for ( $next = $index + 1; $next < count( $tokens ); $next++ ) {
			if ( is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0] ) {
				if ( $tokens[ $next ][1] === $name ) {
					return true;
				}

				break;
			}
		}
	}

	return false;
}
