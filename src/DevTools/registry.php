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
use Zestry\WPToolkit\Modules\Log;
use Zestry\WPToolkit\Modules\MetaBoxes\MetaBoxes;
use Zestry\WPToolkit\Modules\Migrations\Migrations;
use Zestry\WPToolkit\Modules\Options;
use Zestry\WPToolkit\Modules\PostTypes\PostTypes;
use Zestry\WPToolkit\Modules\RestApi\RestApi;
use Zestry\WPToolkit\Modules\SiteHealth\SiteHealth;
use Zestry\WPToolkit\Services\Cookie;
use Zestry\WPToolkit\Services\Transients;
use Zestry\WPToolkit\Services\DB;
use Zestry\WPToolkit\Services\Globals;
use Zestry\WPToolkit\Services\Path;
use Zestry\WPToolkit\Services\Request\Request;
use Zestry\WPToolkit\Services\Views;

/**
 * What `wp zt add <name>` can install, grouped the way `bootstrap.php` is.
 *
 * Two sections, `services` and `modules`, matching the two base classes and the
 * two directories they live in. A name is unique across both -- `wp zt add
 * path` needs no section, since the commands take a flat name.
 *
 * `source` is the class itself, and the rest is derived from it rather than
 * restated: PSR-4 gives the file (`Zestry\WPToolkit\Services\Path` -> `src/Services/Path.php`),
 * and the last namespace segment matching the class name marks a module with a
 * directory of its own (`Modules\Ajax\Ajax` -> copy `src/Modules/Ajax/`,
 * `Services\Path` -> copy the one file).
 *
 * `depends` names other entries that must be copied alongside, split the same
 * way. `add` resolves the full transitive closure before copying anything, so
 * requesting `rest-api` also brings in `path` without the caller knowing it
 * exists.
 *
 * > [!IMPORTANT]
 * > **The section a class is filed under has to match what it extends.** The
 * > file says it here and the class says it in its `extends`; nothing enforces
 * > agreement, and only the class is load-bearing -- `Plugin::bootstrap()` and
 * > `AddCommand` both derive behaviour from `is_a( ..., Module::class, true )`,
 * > never from these headings. A misfiled entry therefore still *works*, and
 * > will simply read wrong. {@see \Zestry\WPToolkit\DevTools\Copier::flatten_registry()}
 * > reports the section it was filed under so a caller can say which it is.
 *
 * `::class` resolves at compile time from the literal name, so this file still
 * loads none of the classes it names -- it stays plain data, read before any
 * autoloader for the *target* project necessarily exists.
 *
 * @return array<string, array<string, array{source: class-string, depends: array{services: string[], modules: string[]}}>>
 */
return array(
	'services' => array(
		'path'       => array(
			'source'  => Path::class,
			'depends' => array(
				'services' => array(),
				'modules'  => array(),
			),
		),
		'request'    => array(
			'source'  => Request::class,
			'depends' => array(
				'services' => array(),
				'modules'  => array(),
			),
		),
		'cookie'     => array(
			'source'  => Cookie::class,
			'depends' => array(
				// `transients` only for a flash too large to fit in a cookie; the
				// common case never touches the database.
				'services' => array( 'transients' ),
				'modules'  => array(),
			),
		),
		'globals'    => array(
			'source'  => Globals::class,
			'depends' => array(
				'services' => array(),
				'modules'  => array(),
			),
		),
		'transients' => array(
			'source'  => Transients::class,
			'depends' => array(
				'services' => array(),
				'modules'  => array(),
			),
		),
		'db'         => array(
			'source'  => DB::class,
			'depends' => array(
				'services' => array(),
				'modules'  => array(),
			),
		),
		'views'      => array(
			'source'  => Views::class,
			'depends' => array(
				'services' => array( 'path' ),
				'modules'  => array(),
			),
		),
	),
	'modules'  => array(
		'assets'      => array(
			'source'  => Assets::class,
			'depends' => array(
				'services' => array( 'path' ),
				'modules'  => array(),
			),
		),
		'log'         => array(
			'source'  => Log::class,
			'depends' => array(
				'services' => array(),
				'modules'  => array(),
			),
		),
		'options'     => array(
			'source'  => Options::class,
			'depends' => array(
				'services' => array(),
				'modules'  => array(),
			),
		),
		'ajax'        => array(
			'source'  => Ajax::class,
			'depends' => array(
				'services' => array( 'path', 'request' ),
				'modules'  => array(),
			),
		),
		'admin-pages' => array(
			'source'  => AdminPages::class,
			'depends' => array(
				// `views` because AdminPage::view() is the default way a page
				// renders, and a page whose markup is a concatenated string
				// stops being reviewable long before it stops growing.
				'services' => array( 'cookie', 'path', 'request', 'views' ),
				'modules'  => array(),
			),
		),
		'rest-api'    => array(
			'source'  => RestApi::class,
			'depends' => array(
				'services' => array( 'path', 'request' ),
				'modules'  => array(),
			),
		),
		'cli'         => array(
			'source'  => CLI::class,
			'depends' => array(
				'services' => array( 'path' ),
				'modules'  => array(),
			),
		),
		'cron'        => array(
			'source'  => Cron::class,
			'depends' => array(
				'services' => array( 'path' ),
				'modules'  => array(),
			),
		),
		'fields'      => array(
			'source'  => Fields::class,
			'depends' => array(
				'services' => array( 'path' ),
				'modules'  => array(),
			),
		),
		'post-types'  => array(
			'source'  => PostTypes::class,
			'depends' => array(
				'services' => array( 'path' ),
				'modules'  => array(),
			),
		),
		'blocks'      => array(
			'source'  => Blocks::class,
			'depends' => array(
				'services' => array( 'path' ),
				'modules'  => array(),
			),
		),
		'meta-boxes'  => array(
			'source'  => MetaBoxes::class,
			'depends' => array(
				'services' => array( 'path' ),
				'modules'  => array( 'fields' ),
			),
		),
		'site-health' => array(
			'source'  => SiteHealth::class,
			'depends' => array(
				'services' => array( 'path' ),
				'modules'  => array(),
			),
		),
		'abilities'   => array(
			'source'  => Abilities::class,
			'depends' => array(
				'services' => array( 'path', 'request' ),
				'modules'  => array(),
			),
		),
		'migrations'  => array(
			'source'  => Migrations::class,
			'depends' => array(
				'services' => array( 'path', 'db' ),
				'modules'  => array( 'options', 'cli' ),
			),
		),
	),
);
