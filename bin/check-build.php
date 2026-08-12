<?php

/**
 * Check the generated build configuration produces what the Assets module reads.
 *
 * `wp zt add module assets` writes a `webpack.config.js` whose whole job is
 * to merge three source directories `@wordpress/scripts` treats as mutually
 * exclusive, and to emit a manifest naming everything it built. Nothing in this
 * repository has ever run it. `composer lint:stubs` renders every stub and asks
 * only whether Prettier would leave it alone -- a config that builds the wrong
 * thing passes that easily.
 *
 * The gap is not hypothetical. Every PHP-side failure in `Assets` has a test;
 * three build-side ones reached a consumer's plugin instead, each presenting the
 * same way -- a green build and a broken page. A package silently bundled into
 * every importer rather than externalised. An entry and a shared package
 * silently claiming one handle. A stylesheet that deadlocks the chunk meant to
 * load it.
 *
 * So this renders the stub a consumer is handed, writes the plugin they would
 * write around it, runs their own build over it, and reads the manifest that
 * comes out. What it asserts is what `Assets` reads at run time, so the two
 * cannot drift apart without something failing here.
 *
 * Run with `composer test:build`.
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

require_once $root . '/bin/docs/module-pages.php';

/**
 * Where the fixture plugin is built, relative to the repository root.
 *
 * Inside the repository rather than a temp directory, because the generated
 * config `require`s `@wordpress/scripts` and its plugins: Node resolves those
 * by walking up from the config file, so a fixture anywhere else would need a
 * node_modules of its own. `/build/` is already git-ignored.
 */
const ZESTRY_BUILD_FIXTURE = 'build/build-fixture';

/**
 * The build a consumer runs, from `Tooling::BUILD_SCRIPTS`.
 *
 * Spelled out rather than required from that class, since this file is read
 * before any autoloader -- and the flags are load-bearing:
 * `--experimental-modules` is what builds ES module output at all.
 */
const ZESTRY_BUILD_COMMAND = 'wp-scripts build --webpack-copy-php --experimental-modules --blocks-manifest';

/**
 * Write a file, creating whatever directories it needs.
 *
 * @param string $path     Absolute path to write.
 * @param string $contents What to write.
 * @return void
 */
function zestry_build_write( string $path, string $contents ): void {
	$dir = dirname( $path );

	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}

	file_put_contents( $path, $contents );
}

/**
 * Delete a directory and everything under it.
 *
 * @param string $dir Absolute path.
 * @return void
 */
function zestry_build_remove( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $entries as $entry ) {
		// isDir() is true for a symlink pointing at one, and rmdir() cannot
		// remove those -- which is every linked workspace under node_modules.
		if ( $entry->isDir() && ! $entry->isLink() ) {
			rmdir( $entry->getPathname() );

			continue;
		}

		unlink( $entry->getPathname() );
	}

	rmdir( $dir );
}

/**
 * The plugin the build runs against, as path => contents.
 *
 * One of everything the manifest has to describe: a classic entry that imports
 * a shared package and a stylesheet, a style-only entry with no index at all, a
 * module entry, a shared script package and a shared module package. The last
 * two land in different manifests, which is the case a single manifest filename
 * used to lose.
 *
 * Each shared package is linked into node_modules afterwards, the way npm would
 * for a declared workspace -- see {@see zestry_build_link_workspaces()} for why
 * that link is what makes this fixture behave like a consumer's plugin.
 *
 * @return array<string, string> Plugin-relative path => file contents.
 */
function zestry_build_sources(): array {
	return array(
		// No `workspaces` key: nothing is installed, and declaring one would
		// only invite npm to try.
		'package.json'                         => "{\n\t\"name\": \"acme-plugin\",\n\t\"private\": true\n}\n",

		// Declares only `kind`: the handle and the global are the build's to
		// compose, which is what this fixture is here to prove.
		'src/shared/formatting/package.json'   => "{\n\t\"name\": \"@acme-plugin/formatting\",\n\t\"version\": \"1.0.0\",\n\t\"private\": true,\n\t\"main\": \"index.ts\",\n\t\"wordpress\": {\n\t\t\"kind\": \"script\"\n\t}\n}\n",
		'src/shared/formatting/index.ts'       => "import './style.scss';\n\nexport function greet( name: string ): string {\n\treturn 'Hello, ' + name;\n}\n",
		'src/shared/formatting/style.scss'     => ".acme-formatting {\n\tcolor: green;\n}\n",

		'src/shared/runtime/package.json'      => "{\n\t\"name\": \"@acme-plugin/runtime\",\n\t\"version\": \"1.0.0\",\n\t\"private\": true,\n\t\"main\": \"index.ts\",\n\t\"wordpress\": {\n\t\t\"kind\": \"module\"\n\t}\n}\n",
		'src/shared/runtime/index.ts'          => "export const ready = true;\n",

		// Imports the shared package, so its handle has to arrive in this
		// entry's own dependencies rather than its code being copied in.
		'src/entries/settings/index.ts'        => "import { greet } from '@acme-plugin/formatting';\nimport './style.scss';\n\nexport const label = greet( 'settings' );\n",
		'src/entries/settings/style.scss'      => ".acme-settings {\n\tcolor: red;\n}\n",

		// No index: a style-only entry, whose JavaScript the build deletes.
		'src/entries/panel/style.scss'         => ".acme-panel {\n\tcolor: blue;\n}\n",

		'src/entries/interactive/package.json' => "{\n\t\"private\": true,\n\t\"wordpress\": {\n\t\t\"kind\": \"module\"\n\t}\n}\n",
		'src/entries/interactive/index.ts'     => "export const mounted = true;\n",
	);
}

/**
 * Link each shared package into the fixture's node_modules, as npm would.
 *
 * A consumer's `package.json` declares `src/shared/*` as a workspace, so
 * `npm install` symlinks each package under `node_modules/` and webpack can
 * resolve it. That resolvability is exactly what makes the failure this guard
 * exists for silent: a package the build declines to externalise still
 * resolves, and is bundled into every importer rather than reported. Without
 * these links the same mistake surfaces as "Can't resolve", which is a louder
 * failure than any consumer gets and would make this check prove the wrong thing.
 *
 * @param string                $target Absolute path to the fixture plugin.
 * @param array<string, string> $names  Package name => its directory under `src/shared/`.
 * @return void
 */
function zestry_build_link_workspaces( string $target, array $names ): void {
	foreach ( $names as $package => $directory ) {
		$link = $target . '/node_modules/' . $package;
		$dir  = dirname( $link );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		symlink( $target . '/src/shared/' . $directory, $link );
	}
}

/**
 * What each manifest has to say, as entry name => the fields that matter.
 *
 * Deliberately not a full equality assertion: a content hash changes on every
 * source edit, and pinning one would make this a test of nothing but its own
 * fixture. Each value is either a literal to match or `true`, meaning the key
 * has to be present with a non-empty value.
 *
 * @return array<string, array<string, array<string, mixed>>> Manifest filename => entry => expected fields.
 */
function zestry_build_expectations(): array {
	return array(
		'assets-manifest.php'        => array(
			'acme-plugin-settings'          => array(
				'source'       => 'entry',
				'name'         => 'settings',
				'kind'         => 'script',
				'js'           => 'entries/settings.js',
				'css'          => 'entries/style-settings.css',
				'dependencies' => true,
				'version'      => true,
			),
			// A style-only entry: the build deleted the runtime-only JavaScript
			// webpack generated, and no `js` key is what tells PHP so.
			'acme-plugin-panel'             => array(
				'source' => 'entry',
				'name'   => 'panel',
				'css'    => 'entries/style-panel.css',
				'js'     => false,
			),
			// The `shared` segment is what keeps a package's handle out of the
			// namespace this plugin's own entries register in. The stylesheet is
			// the package's own: `@wordpress/scripts` splits `style.scss` into a
			// chunk of its own, which is the arrangement that can leave the
			// package's entry module waiting on a chunk nothing reports in --
			// see zestry_check_globals(), which executes it and asks.
			'acme-plugin-shared-formatting' => array(
				'source'  => 'shared',
				'name'    => 'formatting',
				'kind'    => 'script',
				'global'  => array( 'acmePlugin', 'formatting' ),
				'js'      => 'shared/formatting.js',
				'css'     => 'shared/style-formatting.css',
				'rtl'     => 'shared/style-formatting-rtl.css',
				'version' => true,
			),
		),
		'assets-module-manifest.php' => array(
			'acme-plugin-interactive' => array(
				'source' => 'entry',
				'name'   => 'interactive',
				'kind'   => 'module',
				'js'     => 'entries/interactive.js',
			),
			// A module's id is the specifier its importers write, so it stays
			// the npm name rather than a handle of the build's making.
			'@acme-plugin/runtime'    => array(
				'source' => 'shared',
				'name'   => 'runtime',
				'kind'   => 'module',
				'js'     => 'shared/runtime.js',
			),
		),
	);
}

/**
 * Problems in one emitted manifest.
 *
 * @param string               $filename The manifest's name, for the messages.
 * @param array<string, mixed> $manifest What the build emitted.
 * @param array<string, mixed> $expected What it had to contain.
 * @return string[] One sentence per problem.
 */
function zestry_check_manifest( string $filename, array $manifest, array $expected ): array {
	$problems = array();

	foreach ( $expected as $entry => $fields ) {
		if ( ! isset( $manifest[ $entry ] ) ) {
			$problems[] = sprintf(
				'%s describes no "%s". It describes: %s',
				$filename,
				$entry,
				array() === $manifest ? 'nothing' : implode( ', ', array_keys( $manifest ) )
			);

			continue;
		}

		foreach ( $fields as $key => $value ) {
			$problems = array_merge(
				$problems,
				zestry_check_field( $filename, $entry, $key, $value, $manifest[ $entry ] )
			);
		}
	}

	return $problems;
}

/**
 * Problems with one field of one manifest entry.
 *
 * @param string               $filename The manifest's name, for the messages.
 * @param string               $entry    The entry name.
 * @param string               $key      The field being checked.
 * @param mixed                $value    The literal expected, or a bool for presence.
 * @param array<string, mixed> $actual   The entry's emitted fields.
 * @return string[] One sentence per problem.
 */
function zestry_check_field( string $filename, string $entry, string $key, $value, array $actual ): array {
	$present = isset( $actual[ $key ] ) && array() !== $actual[ $key ] && '' !== $actual[ $key ];

	if ( false === $value ) {
		return $present
			? array( sprintf( '%s: "%s" carries a "%s" it should not have.', $filename, $entry, $key ) )
			: array();
	}

	if ( ! $present ) {
		return array( sprintf( '%s: "%s" has no "%s".', $filename, $entry, $key ) );
	}

	if ( true === $value || $value === $actual[ $key ] ) {
		return array();
	}

	return array(
		sprintf(
			'%s: "%s" has a "%s" of %s; expected %s.',
			$filename,
			$entry,
			$key,
			json_encode( $actual[ $key ] ),
			json_encode( $value )
		),
	);
}

/**
 * Whether each built script package actually publishes the global it declares.
 *
 * A manifest can be entirely correct about a package that never finishes
 * evaluating. `@wordpress/scripts` splits a source file called `style.scss`
 * into a chunk of its own, and `webpack-remove-empty-scripts` then deletes the
 * JavaScript webpack generated for it -- so a package whose entry waits on that
 * chunk waits forever, and the global it publishes is left `undefined`. Nothing
 * about that shows in the output: the files are all there, the manifest names
 * them correctly, and every importer reads its exports off `undefined` and
 * throws somewhere far away.
 *
 * There is no reading of the emitted files that answers this. So the built file
 * is executed, with a bare `window` for it to publish onto, and asked.
 *
 * @param string                $target Absolute path to the fixture plugin.
 * @param array<string, string> $globals Built file, relative to the build root => the global path it should define.
 * @return string[] One sentence per problem.
 */
function zestry_check_globals( string $target, array $globals ): array {
	$runner = $target . '/assert-globals.cjs';

	zestry_build_write(
		$runner,
		"const fs = require( 'node:fs' );\n\n"
			. "console.log( JSON.stringify( process.argv.slice( 2 ).map( ( spec ) => {\n"
			. "\tconst [ file, path ] = spec.split( '::' );\n"
			. "\tglobal.window = {};\n\n"
			. "\ttry {\n"
			. "\t\t( 0, eval )( fs.readFileSync( file, 'utf8' ) );\n\n"
			. "\t\tconst value = path\n"
			. "\t\t\t.split( '.' )\n"
			. "\t\t\t.reduce( ( carry, key ) => ( carry === undefined ? undefined : carry[ key ] ), global.window );\n\n"
			. "\t\treturn { path, defined: value !== undefined && Object.keys( value ).length > 0 };\n"
			. "\t} catch ( error ) {\n"
			. "\t\treturn { path, defined: false, error: error.message };\n"
			. "\t}\n"
			. "} ) ) );\n"
	);

	$specs = array();

	foreach ( $globals as $file => $path ) {
		$specs[] = escapeshellarg( $target . '/build/' . $file . '::' . $path );
	}

	exec(
		sprintf( 'node %s %s 2>&1', escapeshellarg( $runner ), implode( ' ', $specs ) ),
		$output,
		$status
	);

	$results = 0 === $status ? json_decode( (string) end( $output ), true ) : null;

	if ( ! is_array( $results ) ) {
		return array( 'Could not execute the built packages: ' . implode( ' ', $output ) );
	}

	$problems = array();

	foreach ( $results as $result ) {
		if ( $result['defined'] ) {
			continue;
		}

		$problems[] = sprintf(
			'The built package never defined window.%s%s. Its entry module did not finish evaluating, '
				. 'so every importer reads its exports off undefined.',
			$result['path'],
			isset( $result['error'] ) ? ' (' . $result['error'] . ')' : ''
		);
	}

	return $problems;
}

/**
 * Problems with what an entry declares it depends on.
 *
 * The one relationship a manifest cannot show by itself: an entry importing a
 * shared package has to come out depending on that package's handle. If it does
 * not, the package was bundled into the entry instead -- which works, twice,
 * until two copies of a registry disagree.
 *
 * @param array<string, mixed> $manifest What the build emitted.
 * @return string[] One sentence per problem.
 */
function zestry_check_externalised( array $manifest ): array {
	$dependencies = $manifest['acme-plugin-settings']['dependencies'] ?? array();

	if ( in_array( 'acme-plugin-shared-formatting', (array) $dependencies, true ) ) {
		return array();
	}

	return array(
		sprintf(
			'assets-manifest.php: "acme-plugin-settings" imports @acme-plugin/formatting but does not depend on '
				. 'its handle, so the package was bundled into it rather than externalised. It depends on: %s',
			array() === $dependencies ? 'nothing' : implode( ', ', (array) $dependencies )
		),
	);
}

$target = $root . '/' . ZESTRY_BUILD_FIXTURE;

zestry_build_remove( $target );

/*
 * The config a consumer is handed, rendered from the same stub
 * `add module assets` writes and with the sample values the docs build uses --
 * so this checks the file they receive, not one written for the occasion.
 */
zestry_build_write(
	$target . '/webpack.config.js',
	strtr(
		(string) file_get_contents( $root . '/src/DevTools/stubs/webpack.config.js.stub' ),
		zestry_stub_values( $root, 'assets' )
	)
);

foreach ( zestry_build_sources() as $relative => $contents ) {
	zestry_build_write( $target . '/' . $relative, $contents );
}

zestry_build_link_workspaces(
	$target,
	array(
		'@acme-plugin/formatting' => 'formatting',
		'@acme-plugin/runtime'    => 'runtime',
	)
);

$binary = $root . '/node_modules/.bin/wp-scripts';

if ( ! is_file( $binary ) ) {
	fwrite( STDERR, "@wordpress/scripts is not installed. Run `npm install` first.\n" );
	exit( 1 );
}

exec(
	sprintf(
		'cd %s && %s 2>&1',
		escapeshellarg( $target ),
		str_replace( 'wp-scripts', escapeshellarg( $binary ), ZESTRY_BUILD_COMMAND )
	),
	$output,
	$status
);

if ( 0 !== $status ) {
	fwrite( STDERR, "The generated build configuration did not build:\n\n" );
	fwrite( STDERR, implode( "\n", $output ) . "\n" );
	exit( 1 );
}

$problems  = array();
$manifests = array();

foreach ( zestry_build_expectations() as $filename => $expected ) {
	$path = $target . '/build/' . $filename;

	if ( ! is_file( $path ) ) {
		$problems[] = sprintf( 'The build wrote no %s.', $filename );

		continue;
	}

	$manifest = require $path;

	if ( ! is_array( $manifest ) ) {
		$problems[] = sprintf( '%s does not return an array.', $filename );

		continue;
	}

	$manifests[ $filename ] = $manifest;
	$problems               = array_merge( $problems, zestry_check_manifest( $filename, $manifest, $expected ) );
}

if ( isset( $manifests['assets-manifest.php'] ) ) {
	$problems = array_merge(
		$problems,
		zestry_check_externalised( $manifests['assets-manifest.php'] ),
		zestry_check_globals( $target, array( 'shared/formatting.js' => 'acmePlugin.formatting' ) )
	);
}

if ( array() === $problems ) {
	zestry_build_remove( $target );

	printf(
		"The generated build configuration builds, and its manifests describe every entry Assets reads (%d checked).\n",
		array_sum( array_map( 'count', zestry_build_expectations() ) )
	);
	exit( 0 );
}

fwrite( STDERR, "\nWhat the generated build configuration produced:\n" );

foreach ( $problems as $problem ) {
	fwrite( STDERR, '  ' . $problem . "\n" );
}

fwrite( STDERR, sprintf( "\nThe fixture is left at %s for inspection.\n", ZESTRY_BUILD_FIXTURE ) );

exit( 1 );
