<?php

/**
 * DevTools: documentation generator
 */

declare( strict_types=1 );

/**
 * Generates `docs/commands/` and `docs/modules/` from the source itself.
 *
 * The rule this enforces: **the code is the only source of truth.** A fact that
 * exists in a docblock, a class constant or `registry.php` is never restated in
 * a hand-written `.md` file, because the copy would silently go stale. The only
 * hand-written pages left are the ones describing something no single symbol
 * owns -- why the toolkit exists, and the order the commands are run in.
 *
 * Extracted per module: the summary and prose from the class docblock, the
 * discovery directory from its `DEFAULT_*_ROOT` constants, the base class it
 * expects from its `instanceof` guard, the usage example from the docblock's
 * indented block, and its dependencies from `src/DevTools/registry.php`.
 *
 * Hand-written pages may embed real source with a placeholder:
 *
 *     <!-- zestry:include file="src/DevTools/stubs/action.php.stub" lang="php" -->
 *
 * which is replaced by that file's contents on every build, so a sample can
 * never drift from the stub it demonstrates.
 *
 * Run via `composer docs:generate`.
 */

$root = dirname( __DIR__ );

/*
 * Every source file guards against direct access with `defined( 'ABSPATH' )`,
 * and this generator `require`s some of them (registry.php) to read their data.
 * Defining it here is what lets a source file be read outside WordPress -- the
 * guard would otherwise exit mid-run, silently and with a zero status.
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . '/' );

require_once __DIR__ . '/docs/extractor.php';
require_once __DIR__ . '/docs/command-pages.php';
require_once __DIR__ . '/docs/module-pages.php';
require_once __DIR__ . '/docs/includes.php';
require_once __DIR__ . '/docs/verify-examples.php';
require_once __DIR__ . '/docs/verify-signatures.php';
require_once __DIR__ . '/docs/verify-module-lists.php';
require_once __DIR__ . '/docs/verify-quality.php';
require_once __DIR__ . '/docs/verify-consistency.php';

$commands = zestry_generate_command_pages( $root );
printf( "Generated %d command pages into docs/commands/\n", $commands );

$modules = zestry_generate_module_pages( $root );
printf( "Generated %d module pages into docs/modules/\n", $modules );

$missing = zestry_record_missing_base();

if ( array() !== $missing ) {
	fwrite( STDERR, "\nBase classes with no page (docs/ is now incomplete):\n" );

	foreach ( array_unique( $missing ) as $base ) {
		fwrite( STDERR, '  ' . $base . " -- add its location to zestry_find_class_file().\n" );
	}

	exit( 1 );
}

$includes = zestry_expand_includes( $root );
printf( "Expanded %d source includes in hand-written pages\n", $includes );

$problems = zestry_verify_examples( $root );

if ( array() !== $problems ) {
	fwrite( STDERR, "\nBroken docblock examples:\n" );

	foreach ( $problems as $problem ) {
		fwrite( STDERR, '  ' . $problem . "\n" );
	}

	exit( 1 );
}

echo "All docblock examples resolve.\n";

$problems = zestry_verify_signatures( $root );

if ( array() !== $problems ) {
	fwrite( STDERR, "\nDocumented subclasses their base class would reject:\n" );

	foreach ( $problems as $problem ) {
		fwrite( STDERR, '  ' . $problem . "\n" );
	}

	exit( 1 );
}

echo "Every documented and generated subclass matches the base it extends.\n";

$problems = zestry_verify_imports( $root );

if ( array() !== $problems ) {
	fwrite( STDERR, "\nImports naming a class that does not exist:\n" );

	foreach ( $problems as $problem ) {
		fwrite( STDERR, '  ' . $problem . "\n" );
	}

	exit( 1 );
}

echo "All example and stub imports resolve.\n";

$problems = zestry_verify_module_lists( $root );

if ( array() !== $problems ) {
	fwrite( STDERR, "\nModule lists out of step with the registry:\n" );

	foreach ( $problems as $problem ) {
		fwrite( STDERR, '  ' . $problem . "\n" );
	}

	exit( 1 );
}

echo "Command module lists match the registry.\n";

$problems = zestry_verify_quality( $root );

if ( array() !== $problems ) {
	fwrite( STDERR, "\nPublished pages that would not read well:\n" );

	foreach ( $problems as $problem ) {
		fwrite( STDERR, '  ' . $problem . "\n" );
	}

	exit( 1 );
}

echo "Every page links somewhere real and reads as consumer documentation.\n";

$problems = zestry_verify_consistency( $root );

if ( array() !== $problems ) {
	fwrite( STDERR, "\nPages contradicting the source, or each other:\n" );

	foreach ( $problems as $problem ) {
		fwrite( STDERR, '  ' . $problem . "\n" );
	}

	exit( 1 );
}

echo "Every page agrees with the registry, the generators and the scaffolder.\n";
