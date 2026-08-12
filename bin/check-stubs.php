<?php

/**
 * Check every stub is what a consumer's own tooling would leave behind.
 *
 * A `.stub` is not a `.php` or a `.ts`, so nothing sees these: `composer lint`
 * scopes phpcs to `src/`, `bin/` and `commands/`, and neither ESLint nor
 * Prettier is pointed at them either. They are the only source in the
 * repository nothing checked, and they are the source a consumer reads first.
 *
 * Two arms, one rendering step.
 *
 * **Prettier**, for what a consumer's `npm run format` would rewrite. A
 * misformatted stub is noise in someone's first commit.
 *
 * **PHP**, for what their `composer lint` would flag: that the rendered file
 * parses at all, and that phpcs finds no error in it. A stub that stops
 * compiling against a refactored base class should fail here, not in a
 * consumer's plugin -- and a generated file that is flagged on its first lint
 * teaches that the toolkit's own standard is optional.
 *
 * Neither arm is about a broken plugin: `wp zt make` runs the consumer's own
 * formatters over what it writes, so most of this is fixed on the way out. It
 * is work the generator should not have to do, on a file that is read before it
 * is ever formatted.
 *
 * Each stub is rendered first, with the same sample values the docs build uses,
 * because a stub is not valid source until its placeholders are filled: a
 * `block.json.stub` has a bare `{{extra_metadata}}` where a comma and a field
 * belong. Rendering means this checks what a consumer actually receives.
 *
 * Run with `composer lint:stubs`.
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

require_once $root . '/bin/docs/extractor.php';
require_once $root . '/bin/docs/module-pages.php';

/**
 * Extensions Prettier is asked about, matching Formatter::PRETTIER_EXTENSIONS.
 */
const ZESTRY_PRETTIER_EXTENSIONS = array( 'ts', 'tsx', 'js', 'jsx', 'mjs', 'cjs', 'json', 'css', 'scss', 'sass' );

/**
 * Extensions phpcs is asked about, matching Formatter::PHP_EXTENSIONS.
 */
const ZESTRY_PHP_EXTENSIONS = array( 'php' );

/**
 * Every `.stub` file under a directory.
 *
 * @param string $dir Directory to walk.
 * @return string[] Absolute paths.
 */
function zestry_stub_files( string $dir ): array {
	$found = array();

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && str_ends_with( $file->getFilename(), '.stub' ) ) {
			$found[] = $file->getPathname();
		}
	}

	sort( $found );

	return $found;
}

$stubs_root = $root . '/src/DevTools/stubs';
$target     = sys_get_temp_dir() . '/zestry-stub-check-' . getmypid();
$rendered   = array();
$prettier   = array();
$php        = array();

foreach ( zestry_stub_files( $stubs_root ) as $stub ) {
	$name      = substr( basename( $stub ), 0, -strlen( '.stub' ) );
	$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

	if ( ! in_array( $extension, ZESTRY_PRETTIER_EXTENSIONS, true )
		&& ! in_array( $extension, ZESTRY_PHP_EXTENSIONS, true ) ) {
		continue;
	}

	// A multi-file type is a directory of stubs, and its values belong to the
	// type: `stubs/block/edit.tsx.stub` is the `block` type, `stubs/route.php.stub`
	// is `route`.
	$relative = substr( $stub, strlen( $stubs_root ) + 1 );
	$type     = str_contains( $relative, '/' ) ? strtok( $relative, '/' ) : substr( $name, 0, (int) strpos( $name . '.', '.' ) );

	$contents = strtr( (string) file_get_contents( $stub ), zestry_stub_values( $root, (string) $type ) );

	if ( preg_match( '/\{\{(\w+)\}\}/', $contents, $match ) ) {
		fwrite( STDERR, sprintf( "%s renders with an unfilled placeholder: %s\n", $relative, $match[0] ) );
		fwrite( STDERR, sprintf( "Add it to bin/docs/stub-values/%s.php.\n", $type ) );
		exit( 1 );
	}

	/*
	 * A view sample is written into `views/`, because that is where the command
	 * that generates it puts the real thing, and the consumer's ruleset exempts
	 * that directory by path. Placing the sample by name is a fixture decision:
	 * put it in the wrong place and the guard reports something loudly. The
	 * alternative -- leaving it flat and relaxing the rule by name instead --
	 * was how the unlinted view stub shipped, because it meant the guard ran
	 * against a ruleset no consumer is ever given.
	 */
	$directory = str_contains( $name, 'view' ) ? 'views' : dirname( $relative );
	$path      = $target . '/' . $directory . '/' . $name;

	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0777, true );
	}

	file_put_contents( $path, $contents );

	$rendered[ $path ] = $relative;

	if ( in_array( $extension, ZESTRY_PHP_EXTENSIONS, true ) ) {
		$php[ $path ] = $relative;
	} else {
		$prettier[ $path ] = $relative;
	}
}

if ( array() === $rendered ) {
	fwrite( STDERR, "No stubs to check.\n" );
	exit( 1 );
}

// The config a consumer gets: `wp zt init` writes a `.prettierrc.js`
// re-exporting this exact package.
file_put_contents(
	$target . '/.prettierrc.cjs',
	sprintf( "module.exports = require( '%s' );\n", $root . '/node_modules/@wordpress/prettier-config' )
);

/*
 * The ruleset a consumer gets, rendered from the stub `wp zt init` writes.
 *
 * Not this repository's own `phpcs.xml`, which would only ever prove the stubs
 * suit *us*. A generated file lands in a consumer's plugin and is linted by
 * their `composer lint`, so their ruleset is the one that decides. That the two
 * rulesets say the same thing is a separate question, and
 * {@see zestry_check_rulesets()} is what asks it.
 *
 * Written out verbatim, with nothing added. An earlier version appended an
 * exemption of its own for template variables, which quietly made this a test
 * of a ruleset no consumer is ever handed -- and is why a generated view
 * reporting every variable it was passed went unnoticed. Anything a sample
 * needs, the shipped ruleset has to provide.
 */
file_put_contents(
	$target . '/phpcs.xml',
	strtr(
		(string) file_get_contents( $stubs_root . '/phpcs.xml.stub' ),
		zestry_stub_values( $root, 'init' )
	)
);

$problems = array_merge(
	zestry_check_rulesets( $root ),
	zestry_check_prettier( $root, $target, $prettier ),
	zestry_check_php( $root, $target, $php )
);

array_map( 'unlink', array_keys( $rendered ) );

if ( array() === $problems ) {
	printf( "Every stub parses, lints and formats as a consumer's own tooling would leave it (%d checked).\n", count( $rendered ) );
	exit( 0 );
}

fwrite( STDERR, "\nStubs a consumer's own tooling would not leave alone:\n" );

foreach ( $problems as $problem ) {
	fwrite( STDERR, '  ' . $problem . "\n" );
}

exit( 1 );

/**
 * Report every rendered stub a consumer's Prettier would reformat.
 *
 * @param string                $root     Absolute path to the repository root.
 * @param string                $target   Where the rendered stubs were written.
 * @param array<string, string> $rendered Absolute path => stub path, relative to the stub root.
 * @return string[] Problems found.
 */
function zestry_check_prettier( string $root, string $target, array $rendered ): array {
	if ( array() === $rendered ) {
		return array();
	}

	$output = array();
	$status = 0;

	exec(
		sprintf(
			'%s --config %s --check %s 2>&1',
			escapeshellarg( $root . '/node_modules/.bin/prettier' ),
			escapeshellarg( $target . '/.prettierrc.cjs' ),
			implode( ' ', array_map( 'escapeshellarg', array_keys( $rendered ) ) )
		),
		$output,
		$status
	);

	if ( 0 === $status ) {
		return array();
	}

	$problems = array();

	foreach ( $output as $line ) {
		foreach ( $rendered as $path => $relative ) {
			// The full path only: two types can both have a `package.json`, and
			// matching on the name alone would report the innocent one too.
			if ( str_contains( $line, $path ) ) {
				$problems[ $relative ] = $relative
					. ' — Prettier would reformat this. Render it to its real extension, run Prettier, copy it back.';
			}
		}
	}

	return array_values( $problems );
}

/**
 * Report every rendered PHP stub that does not parse, or that phpcs faults.
 *
 * Parsing is checked first and separately, because a stub that does not compile
 * is a different failure from one that is merely untidy -- and phpcs reports a
 * syntax error as one unhelpful line about an unexpected token.
 *
 * Warnings count too, and that is not fussiness: phpcs exits non-zero on a
 * warning, so `wp zt init` promising that phpcs "passes on the code you were
 * just handed" is false the moment a stub raises one. Counting only errors is
 * how three of them shipped -- an unused `$input` on every generated ability,
 * and every variable in a generated view reported as undefined.
 *
 * @param string                $root     Absolute path to the repository root.
 * @param string                $target   Where the rendered stubs were written.
 * @param array<string, string> $rendered Absolute path => stub path, relative to the stub root.
 * @return string[] Problems found.
 */
function zestry_check_php( string $root, string $target, array $rendered ): array {
	if ( array() === $rendered ) {
		return array();
	}

	$problems = array();

	foreach ( $rendered as $path => $relative ) {
		$output = array();
		$status = 0;

		exec( sprintf( '%s -l %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( $path ) ), $output, $status );

		if ( 0 !== $status ) {
			$problems[] = $relative . ' — does not parse: ' . trim( (string) ( $output[0] ?? 'unknown error' ) );
		}
	}

	// A file that does not compile has nothing useful to say to phpcs.
	if ( array() !== $problems ) {
		return $problems;
	}

	$output = array();
	$status = 0;

	// To a file rather than to stdout: the ruleset turns progress reporting on
	// (`<arg value="ps" />`), which prints dots ahead of the JSON and makes it
	// unparseable.
	$report_file = $target . '/phpcs-report.json';

	exec(
		sprintf(
			'%s -q --standard=%s --report-json=%s %s 2>&1',
			escapeshellarg( $root . '/vendor/bin/phpcs' ),
			escapeshellarg( $target . '/phpcs.xml' ),
			escapeshellarg( $report_file ),
			implode( ' ', array_map( 'escapeshellarg', array_keys( $rendered ) ) )
		),
		$output,
		$status
	);

	$report = is_file( $report_file )
		? json_decode( (string) file_get_contents( $report_file ), true )
		: null;

	if ( ! is_array( $report ) || ! isset( $report['files'] ) ) {
		return array( 'phpcs could not be read: ' . implode( ' ', array_slice( $output, 0, 3 ) ) );
	}

	$named = array();

	foreach ( $rendered as $path => $relative ) {
		$named[ (string) realpath( $path ) ] = $relative;
	}

	foreach ( $report['files'] as $path => $file ) {
		foreach ( $file['messages'] ?? array() as $message ) {
			$problems[] = sprintf(
				'%s:%d — %s (%s)',
				$named[ $path ] ?? $path,
				$message['line'] ?? 0,
				$message['message'] ?? '',
				$message['source'] ?? ''
			);
		}
	}

	return $problems;
}

/**
 * Report a sniff decided one way here and the other way in a consumer's plugin.
 *
 * `wp zt init` writes a ruleset mirroring this repository's own, deliberately:
 * the copied source in a consumer's tree is written to this standard, and a
 * thinner one would flag the code they were just handed. Mirroring by hand is
 * what drifts, and it did -- six exclusions were tightened here and left in the
 * stub, which would have handed the next plugin a looser standard than the code
 * it was linting.
 *
 * Only the sniff names are compared, in both directions. Everything else
 * legitimately differs: the files each covers, the text domain, the description,
 * and the views exemption a consumer needs and this repository does not.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Problems found.
 */
function zestry_check_rulesets( string $root ): array {
	$ours   = zestry_ruleset_decisions( $root . '/phpcs.xml' );
	$theirs = zestry_ruleset_decisions( $root . '/src/DevTools/stubs/phpcs.xml.stub' );

	$problems = array();

	foreach ( array_diff( $ours, $theirs ) as $missing ) {
		$problems[] = sprintf(
			'src/DevTools/stubs/phpcs.xml.stub — does not carry `%s`, which phpcs.xml does. A plugin `wp zt init` sets up would be held to a different standard than the source it is given.',
			$missing
		);
	}

	foreach ( array_diff( $theirs, $ours ) as $extra ) {
		$problems[] = sprintf(
			'src/DevTools/stubs/phpcs.xml.stub — carries `%s`, which phpcs.xml does not.',
			$extra
		);
	}

	return $problems;
}

/**
 * Every sniff a ruleset turns on, off, or changes the severity of.
 *
 * Only which sniffs are named and how -- never shape. The two files are allowed
 * to differ in whitespace, ordering and comments, and a structural diff would
 * report all three as findings.
 *
 * A rule scoped to one directory is skipped, because these describe a layout
 * rather than a standard and the two sides do not share a layout: `bin/` is this
 * package\'s own docs build, scripts that run outside WordPress entirely, where
 * `WP_Filesystem` does not exist and the sniffs steering file operations towards
 * it have nothing to steer towards; `views/` is a consuming plugin\'s templates,
 * which are handed their variables by `extract()` and so cannot be read by a
 * sniff that resolves variables statically. Neither side holds the other\'s
 * directory, so neither can carry the other\'s rule.
 *
 * @param string $path Absolute path to a ruleset, or to the stub of one.
 * @return string[] Sorted `rule:Name` / `exclude:Name` decisions.
 */
function zestry_ruleset_decisions( string $path ): array {
	$previous = libxml_use_internal_errors( true );
	$xml      = simplexml_load_string( (string) file_get_contents( $path ) );

	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	if ( false === $xml ) {
		return array( 'unparseable:' . basename( $path ) );
	}

	$decisions = array();

	foreach ( $xml->xpath( '//rule[@ref]' ) ?: array() as $rule ) {
		/*
		 * Skipped only when the carve-out is the whole rule. A rule that also
		 * carries properties or excludes is a shared decision that happens to
		 * exempt a directory -- `DeclareStrictTypes` is configured identically on
		 * both sides and simply spares a markup template -- and dropping it would
		 * report the two as disagreeing about a sniff they agree about.
		 *
		 * Read as XML rather than by pattern: a `<rule ref="X" />` carries no
		 * closing tag, so a regex walking from one rule to the next runs straight
		 * through every self-closing one in between and swallows their names.
		 */
		$only_a_carve_out = count( $rule->{'exclude-pattern'} ) > 0
			&& 0 === count( $rule->properties )
			&& 0 === count( $rule->exclude );

		if ( $only_a_carve_out ) {
			continue;
		}

		$decisions[] = 'rule:' . (string) $rule['ref'];
	}

	foreach ( $xml->xpath( '//exclude[@name]' ) ?: array() as $exclude ) {
		$decisions[] = 'exclude:' . (string) $exclude['name'];
	}

	$decisions = array_unique( $decisions );
	sort( $decisions );

	return $decisions;
}
