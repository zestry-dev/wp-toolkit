<?php

/**
 * DevTools autoload shim.
 *
 * This is the file Composer's `autoload.files` actually registers (see
 * composer.json) — `devtool.php` itself is deliberately NOT registered
 * there. Composer's generated `autoload_real.php` dedupes every
 * `autoload.files` entry by a hash of the package name plus the file's path
 * *relative to the package* (e.g. `{package-name}/devtool.php`), stored in
 * the process-global `$GLOBALS['__composer_autoload_files']`. Every plugin
 * that installs this same package produces the *same* key, so only the
 * first plugin Composer happens to initialize would ever actually run
 * `devtool.php` — every other active plugin that also installed it would
 * silently get no `wp zt` at all, with no way for `devtool.php` itself to
 * detect or react, since its body would simply never run.
 *
 * This shim sidesteps that: its own content is identical everywhere too, so
 * Composer's dedup still applies to it and it only ever runs once per
 * request — which is fine, since all it does is resolve and `require` the
 * *specific* `devtool.php` belonging to whichever plugin the current working
 * directory is actually inside, by ordinary absolute file path. An ordinary
 * `require` is keyed by real path, not Composer's coarse relative-path hash,
 * so it runs correctly regardless of how many other plugins also loaded
 * (and were skipped for) their own copy of this exact shim.
 *
 * Each gate below explains itself through `WP_CLI::debug()` before returning.
 * Every one of them produces the same visible symptom -- `'zt' is not a
 * registered wp command` -- so without that line several unrelated
 * misconfigurations are indistinguishable. Run any `wp` command with `--debug`
 * to see which one applies.
 *
 * One case those lines cannot reach: if the consuming plugin is not active,
 * WordPress never loads its `vendor/autoload.php`, so this file does not run
 * at all and has nothing to report with. The same applies when the package is
 * installed through a Composer path repository whose symlink does not resolve
 * -- inside a container, say, where the target is not mounted. Both look
 * identical from the outside, and both are fixed by making the plugin's
 * `vendor/` genuinely reachable from where WordPress is running.
 *
 * Three checks gate this before requiring anything:
 *
 * - Only under WP-CLI (there is no reason to build a Plugin instance, or
 *   even resolve a plugin root, on an ordinary web request).
 * - The current working directory must be inside a plugin directory under
 *   `WP_PLUGIN_DIR` — a developer runs `wp zt init`/`add` from inside (or
 *   below) the plugin they are working on, exactly as they would run
 *   `composer require` there.
 * - That plugin must actually have this package installed — checked by the
 *   presence of its own `vendor/{package-name}/devtool.php` on disk, not by
 *   parsing its composer.json/composer.lock, since the file's existence is
 *   what actually matters for requiring it. `{package-name}` is read from
 *   this package's own composer.json (next to this file), rather than
 *   hardcoded, so a rename or fork of the package does not silently break
 *   this shim.
 */

declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'WP_PLUGIN_DIR' ) ) {
	// No debug line: on an ordinary web request this is the expected path, and
	// WP_CLI is not there to log to in the first place.
	return;
}

$zestry_devtool_composer = json_decode( (string) file_get_contents( __DIR__ . '/composer.json' ), true );
$zestry_devtool_package  = is_array( $zestry_devtool_composer ) && is_string( $zestry_devtool_composer['name'] ?? null )
	? $zestry_devtool_composer['name']
	: null;

if ( null === $zestry_devtool_package ) {
	WP_CLI::debug( 'zt: could not read a package name from ' . __DIR__ . '/composer.json.', 'zt' );
	return;
}

$zestry_devtool_plugins_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
$zestry_devtool_cwd         = trailingslashit( wp_normalize_path( (string) getcwd() ) );

if ( ! str_starts_with( $zestry_devtool_cwd, $zestry_devtool_plugins_dir ) ) {
	WP_CLI::debug(
		sprintf(
			'zt: run this from inside your plugin directory. Current directory %s is not under %s.',
			$zestry_devtool_cwd,
			$zestry_devtool_plugins_dir
		),
		'zt'
	);
	return;
}

$zestry_devtool_relative      = substr( $zestry_devtool_cwd, strlen( $zestry_devtool_plugins_dir ) );
$zestry_devtool_plugin_folder = explode( '/', $zestry_devtool_relative )[0];

if ( '' === $zestry_devtool_plugin_folder ) {
	WP_CLI::debug(
		'zt: run this from inside a plugin directory, not from ' . $zestry_devtool_plugins_dir . ' itself.',
		'zt'
	);
	return;
}

$zestry_devtool_target = $zestry_devtool_plugins_dir . $zestry_devtool_plugin_folder
	. '/vendor/' . $zestry_devtool_package . '/devtool.php';

if ( ! is_file( $zestry_devtool_target ) ) {
	WP_CLI::debug(
		sprintf(
			'zt: %s does not require %s -- expected %s. Run `composer require %s --dev` in that plugin.',
			$zestry_devtool_plugin_folder,
			$zestry_devtool_package,
			$zestry_devtool_target,
			$zestry_devtool_package
		),
		'zt'
	);
	return;
}

/*
 * Every gate above has passed, so this is a `wp` run from inside a plugin that
 * requires this package: the one context where a devtool command may want the
 * consumer's *running* plugin rather than the files it was built from.
 *
 * Defined here rather than in `devtool.php` because of when each runs. This
 * file is reached through Composer's `autoload.files`, which fires while the
 * consuming plugin's entry file is still on its `require vendor/autoload.php`
 * line -- before it builds its own Plugin and calls `run()`. A constant defined
 * any later would be defined after the thing it is meant to gate.
 *
 * Nothing outside WP-CLI ever sees it, so a web request carries no trace of
 * this and `Plugin::run()` does not branch on a plugin's own hot path.
 */
if ( ! defined( 'ZESTRY_DEVTOOL' ) ) {
	define( 'ZESTRY_DEVTOOL', true );
}

require_once $zestry_devtool_target;
