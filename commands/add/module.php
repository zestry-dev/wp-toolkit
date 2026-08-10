<?php

/**
 * Devtool command: `wp zestry add module <module>...`.
 *
 * Copies one or more feature modules into a project already set up with `wp zestry
 * init`, resolving each one's transitive dependencies against the registry
 * first (see registry.php) so a module never ends up copied without something
 * it needs. Every `namespace Zestry\WPToolkit\...;`/`use Zestry\WPToolkit\...;` in each copied file is
 * rewritten to the namespace zestry.json recorded during init. Never overwrites a
 * module already present on disk -- use `wp zestry overwrite module` for that.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\AddCommand;

return new class() extends AddCommand {

	/**
	 * Copy one or more feature modules into an initialized plugin.
	 *
	 * Requires `wp zestry init` to have already run in this plugin (it reads
	 * zestry.json for the namespace and destination directory chosen there).
	 * Resolves each requested module's dependencies before copying anything, so
	 * `rest-api`, for example, also brings in `path` without needing to be asked
	 * for by name. Every `namespace Zestry\WPToolkit\...;` declaration and `use Zestry\WPToolkit\...;`
	 * import in each copied file is rewritten to the project's own namespace. A
	 * module already present at its destination is left untouched and logged as
	 * skipped -- run `wp zestry overwrite module <module>` to replace it
	 * deliberately.
	 *
	 * Each copied module is also declared in the plugin's
	 * `bootstrap.php`, because copying the files is only half of adding one: a
	 * module is built because that file lists it, and until it is built it
	 * discovers nothing and binds no hooks. So a module works the moment it
	 * arrives, rather than after a hand-edit. With no `bootstrap.php` to append
	 * to, the entry line is printed for you to paste wherever the plugin
	 * declares its modules instead.
	 *
	 * Two modules write outside their own tree, because each needs a JavaScript
	 * build and a plugin that has never built JavaScript has nothing to run one
	 * with. Every file either writes is additive: anything already there is kept
	 * as it is and reported as such.
	 *
	 * `add module blocks` writes the toolchain -- the scripts and
	 * devDependencies in your package.json, a tsconfig.json, an
	 * eslint.config.mjs, a `.prettierrc.js` if you have no Prettier config
	 * already, and `build/`, `vendor/` and `node_modules/` in your .gitignore.
	 * It writes **no** `webpack.config.js`: `wp-scripts` finds every block by
	 * globbing for a `block.json` anywhere under `src/`, so blocks alone need
	 * no config file.
	 *
	 * `add module assets` writes the `webpack.config.js`, because shared
	 * packages and entries are what `wp-scripts` has no opinion about. Add both
	 * modules and you get one config that builds all three directories --
	 * blocks, entries and shared packages -- which is the point of it, since
	 * `wp-scripts` otherwise picks entry points three mutually exclusive ways
	 * and each one disables the others.
	 *
	 * **The `build` and `start` scripts come from one definition, so both write
	 * the same two.** Whichever module you add first writes them; the second
	 * finds them already there and says so. Order does not decide what they
	 * contain, and adding `assets` after `blocks` cannot leave the build without
	 * a flag the other would have passed.
	 *
	 * ## DEPENDENCIES CROSS THE TWO KINDS
	 *
	 * A module may depend on services, and most do: everything but `log` and
	 * `options` needs `path`, and `migrations` also needs `db`. Those arrive
	 * with it. This command asks which kind you are naming, not which kinds it
	 * is allowed to copy.
	 *
	 * To copy a service on its own, use `wp zestry add service <service>`.
	 *
	 * ## OPTIONS
	 *
	 * <module>...
	 * : One or more module names to copy in.
	 * Available modules: log, options, assets, ajax, admin-pages, rest-api, cli, cron, fields, meta-boxes, post-types, blocks, site-health, abilities, migrations.
	 *
	 * ## EXAMPLES
	 *
	 *     # Copy the REST API module, and the service it needs.
	 *     $ wp zestry add module rest-api
	 *     Also adding required dependencies: path
	 *     Added path
	 *     Added rest-api
	 *     Declared in bootstrap.php: rest-api
	 *     Success: Done.
	 *
	 *     # Copy several in one call.
	 *     $ wp zestry add module cli admin-pages
	 *     Success: Done.
	 *
	 *     # Already on disk, so it is left exactly as it is.
	 *     $ wp zestry add module cli
	 *     Skipped cli (already present)
	 *     Success: Done.
	 *
	 *     # Naming a service here says where to find it.
	 *     $ wp zestry add module path
	 *     Error: "path" is a service, not a module. Run `wp zestry add service path`.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	protected function filter_existing_modules( array $existing_names, array $destinations, array &$to_copy ): bool {
		foreach ( $existing_names as $name ) {
			$this->log( 'Skipped ' . $name . ' (already present)' );
			$to_copy = array_diff( $to_copy, array( $name ) );
		}

		return false;
	}

	protected static function get_word(): string {
		return 'add';
	}

	protected static function get_past_tense(): string {
		return 'Added';
	}

	protected static function get_kind(): string {
		return 'modules';
	}
};
