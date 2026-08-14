<?php

/**
 * DevTools: module registry
 */

declare( strict_types=1 );

// Required by the DevTools commands, never requested directly.
defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Abilities\Abilities;
use Zestry\WPToolkit\Modules\AdminPages\AdminPages;
use Zestry\WPToolkit\Modules\Assets\Assets;
use Zestry\WPToolkit\Modules\Ajax\Ajax;
use Zestry\WPToolkit\Modules\Blocks\Blocks;
use Zestry\WPToolkit\Modules\CLI\CLI;
use Zestry\WPToolkit\Modules\Cron\Cron;
use Zestry\WPToolkit\Modules\Fields\Fields;
use Zestry\WPToolkit\Modules\IconsLibrary\IconsLibrary;
use Zestry\WPToolkit\Modules\Log;
use Zestry\WPToolkit\Modules\MetaBoxes\MetaBoxes;
use Zestry\WPToolkit\Modules\Migrations\Migrations;
use Zestry\WPToolkit\Modules\Options;
use Zestry\WPToolkit\Modules\PostTypes\PostTypes;
use Zestry\WPToolkit\Modules\RestApi\RestApi;
use Zestry\WPToolkit\Modules\SiteHealth\SiteHealth;
use Zestry\WPToolkit\Modules\Cookie;
use Zestry\WPToolkit\Modules\Transients;
use Zestry\WPToolkit\Modules\DB;
use Zestry\WPToolkit\Modules\Globals;
use Zestry\WPToolkit\Modules\Path;
use Zestry\WPToolkit\Modules\Request\Request;
use Zestry\WPToolkit\Modules\Views;

/**
 * What `wp zt add <name>` can install.
 *
 * One flat list keyed by the name the commands take, matching `bootstrap.php`:
 * everything here is a module, so there is nothing to file anything under and
 * `wp zt add path` needs no qualifier.
 *
 * `source` is the class itself, and the rest is derived from it rather than
 * restated: PSR-4 gives the file (`Zestry\WPToolkit\Modules\Path` -> `src/Modules/Path.php`),
 * and the last namespace segment matching the class name marks a module with a
 * directory of its own (`Modules\Ajax\Ajax` -> copy `src/Modules/Ajax/`,
 * `Modules\Path` -> copy the one file).
 *
 * `depends` names other entries that must be copied alongside. `add` resolves
 * the full transitive closure before copying anything, so requesting `rest-api`
 * also brings in `path` without the caller knowing it exists.
 *
 * `requires` is the oldest WordPress the entry works on, and is omitted by
 * everything that works on any. It is measured against the consuming plugin's
 * own `Requires at least:` header rather than against the WordPress a developer
 * happens to be running: `wp zt add` refuses an entry the plugin does not
 * promise a new enough WordPress for -- including one pulled in as a dependency
 * -- and `wp zt doctor` reports one already on disk.
 *
 * `::class` resolves at compile time from the literal name, so this file still
 * loads none of the classes it names -- it stays plain data, read before any
 * autoloader for the *target* project necessarily exists.
 *
 * @return array<string, array{source: class-string, requires?: string, depends?: string[]}>
 */
return array(
	'path'          => array(
		'source'  => Path::class,
		'depends' => array(),
	),
	'request'       => array(
		'source'  => Request::class,
		'depends' => array(),
	),
	'cookie'        => array(
		'source'  => Cookie::class,
		// `transients` only for a flash too large to fit in a cookie; the
		// common case never touches the database.
		'depends' => array( 'transients' ),
	),
	'globals'       => array(
		'source'  => Globals::class,
		'depends' => array(),
	),
	'transients'    => array(
		'source'  => Transients::class,
		'depends' => array(),
	),
	'db'            => array(
		'source'  => DB::class,
		'depends' => array(),
	),
	'views'         => array(
		'source'  => Views::class,
		'depends' => array( 'path' ),
	),
	'assets'        => array(
		'source'  => Assets::class,
		'depends' => array( 'path' ),
	),
	'log'           => array(
		'source'  => Log::class,
		'depends' => array(),
	),
	'options'       => array(
		'source'  => Options::class,
		'depends' => array(),
	),
	'ajax'          => array(
		'source'  => Ajax::class,
		'depends' => array( 'path', 'request' ),
	),
	'admin-pages'   => array(
		'source'  => AdminPages::class,
		// `views` because AdminPage::view() is the default way a page
		// renders, and a page whose markup is a concatenated string
		// stops being reviewable long before it stops growing.
		'depends' => array( 'cookie', 'path', 'views' ),
	),
	'rest-api'      => array(
		'source'  => RestApi::class,
		'depends' => array( 'path', 'request' ),
	),
	'cli'           => array(
		'source'  => CLI::class,
		'depends' => array( 'path' ),
	),
	'cron'          => array(
		'source'  => Cron::class,
		'depends' => array( 'path' ),
	),
	'fields'        => array(
		'source'  => Fields::class,
		'depends' => array( 'path' ),
	),
	'post-types'    => array(
		'source'  => PostTypes::class,
		'depends' => array( 'path' ),
	),
	'blocks'        => array(
		'source'  => Blocks::class,
		'depends' => array( 'path' ),
	),
	'meta-boxes'    => array(
		'source'  => MetaBoxes::class,
		'depends' => array( 'path', 'fields' ),
	),
	'site-health'   => array(
		'source'  => SiteHealth::class,
		'depends' => array( 'path' ),
	),
	'abilities'     => array(
		'source'   => Abilities::class,
		'requires' => '6.9',
		'depends'  => array( 'path', 'request' ),
	),
	'icons-library' => array(
		'source'   => IconsLibrary::class,
		'requires' => '7.1',
		'depends'  => array( 'path' ),
	),
	'migrations'    => array(
		'source'  => Migrations::class,
		'depends' => array( 'path', 'db', 'options', 'cli' ),
	),
);
