<?php

/**
 * Assets API: Assets module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Assets;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Services\Path;

/**
 * Registers what the JavaScript build produced, and composes plugin asset URLs.
 *
 * On `init` it registers every entry and shared package the build wrote into
 * its manifest, each under the handle the build composed for it -- so
 * `enqueue_entry( 'settings' )` works from anywhere with no registration call
 * first. For an asset the build did not produce, `register_script()` and
 * `register_style()` resolve a URL under the configured assets directory and
 * namespace the handle to the plugin slug, so `'app'` becomes
 * `'{plugin-slug}-app'` and cannot collide with core, a theme or another plugin.
 *
 * **Everything that returns a handle returns a real one**, ready to hand
 * straight to WordPress. Attaching inline code or data, adding registration
 * metadata, and enqueueing something registered by hand are WordPress's own
 * functions, called with that handle:
 *
 * ```
 * $handle = $assets->enqueue_entry( 'dashboard' );
 * wp_add_inline_script( $handle, 'window.dashboard = ' . wp_json_encode( $data ) . ';', 'before' );
 * ```
 *
 * `wp zestry add module assets` brings the build with it: a `webpack.config.js`
 * that compiles three directories, each with a different owner.
 *
 * | Source | Built to | Registered by |
 * | --- | --- | --- |
 * | `src/blocks/{name}/` | `{build}/blocks/{name}/` | WordPress, from `block.json` |
 * | `src/entries/{name}/` | `{build}/entries/{name}` | this module, as `{plugin-slug}-{name}` |
 * | `src/shared/{name}/` | `{build}/shared/{name}` | this module, as `{plugin-slug}-shared-{name}` |
 *
 * That merge is the reason the config exists. `@wordpress/scripts` decides
 * entry points three mutually exclusive ways -- files listed on the command
 * line, `block.json` scanning, or the `src/index` fallback -- each of which
 * disables the others, so a plugin with one block has no supported way to build
 * a script of its own.
 *
 * The build composes every handle, and this module reads them. An entry and a
 * shared package can therefore share a name -- `src/entries/collections` and
 * `src/shared/collections` -- without one silently displacing the other, which
 * is what the `shared` segment is there to prevent.
 *
 * @example Your own script, built and registered
 * `wp zestry make entry settings` writes `src/entries/settings/`. The build
 * compiles it, this module registers it on `init`, and using it is one call --
 * from an admin page, a shortcode, anywhere:
 *
 * ```
 * $assets->enqueue_entry( 'settings' );
 * ```
 *
 * The stylesheet the entry imports is registered under that same handle, so it
 * comes along. Nothing derives its filename: `@wordpress/scripts` writes a
 * source file called `style.scss` as `style-{entry}.css` and any other name as
 * `{entry}.css`, so the build records what it actually emitted -- including the
 * RTL variant, which is swapped in the way core does it for block styles.
 *
 * @example An asset the build did not produce
 * `$src` is resolved through `get_asset_url()` -- relative to the configured
 * assets directory (`assets` by default) -- into a full URL via the injected
 * Path service, so you never construct asset URLs by hand.
 *
 * ```
 * $app = $assets->register_script( 'app', 'app.js' );
 * $assets->register_script( 'widgets', 'widgets.js', array( $app ) );
 * wp_enqueue_script( $app );
 * ```
 *
 * @example Sharing code between entries
 * A directory under `src/shared/` is an npm workspace imported by name, built
 * once into `{build}/shared/` rather than copied into every entry that imports
 * it. There is usually nothing to call: an importer declares the package in its
 * own `.asset.php`, so loading the importer loads the package.
 *
 * ```
 * // Only for a package nothing imports, or one a hand-registered script needs.
 * $assets->enqueue_shared( 'formatting' );
 * $assets->register_script( 'legacy', 'legacy.js', array( $assets->get_shared_handle( 'formatting' ) ) );
 * ```
 *
 * @setup
 * Configure the module only to point it at directories other than the
 * defaults. The build directory (`build` by default) is separate and
 * independently configurable -- it is not required to live inside the
 * configured assets directory, since `@wordpress/scripts` projects commonly
 * build straight to their own top-level `build/`.
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Assets::class => static function ( Assets $assets ): void {
 *         $assets->set_assets_root( 'resources' );
 *         $assets->set_build_root( 'dist' );
 *     },
 * );
 * ```
 */
class Assets extends Module {

	/**
	 * Default plugin-relative directory of asset files.
	 */
	const DEFAULT_ASSETS_ROOT = 'assets';

	/**
	 * Default plugin-relative directory of `@wordpress/scripts` build output.
	 */
	const DEFAULT_BUILD_ROOT = 'build';

	/**
	 * The build manifests the generated `webpack.config.js` writes.
	 *
	 * One per compilation, since `--experimental-modules` runs two of them into
	 * the same directory and a single name would leave whichever finished last
	 * discarding the other's entries. Both are optional: a plugin that has never
	 * been built has neither, and a hand-rolled build may write neither.
	 *
	 * Each returns a **handle => fields** map, keyed by the name WordPress will
	 * know the thing as. Every field registering it needs is on the row:
	 * `source` (`entry` or `shared`) and `name` for looking it up, `kind` for
	 * which registry it belongs to, `js`/`css`/`rtl` as build-root-relative
	 * paths, `dependencies` and `version` from the extraction plugin, and a
	 * `global` for a shared script.
	 *
	 * That the key is the handle is the point. Composing one here as well would
	 * be a second opinion about a name the build has already written into every
	 * importer's own `.asset.php`, and the two silently disagreeing is how an
	 * entry and a shared package of the same name used to end up claiming one
	 * registration.
	 */
	const MANIFEST_FILENAMES = array( 'assets-manifest.php', 'assets-module-manifest.php' );

	/**
	 * Path service injected by the plugin to resolve asset URLs.
	 *
	 * @var Path
	 */
	public Path $path;

	/**
	 * Plugin-relative directory of asset files.
	 *
	 * @var string
	 */
	private string $assets_root = self::DEFAULT_ASSETS_ROOT;

	/**
	 * Plugin-relative directory of `@wordpress/scripts` build output.
	 *
	 * Independent of $assets_root: a build is not required to live inside the
	 * configured assets directory.
	 *
	 * @var string
	 */
	private string $build_root = self::DEFAULT_BUILD_ROOT;

	/**
	 * Everything the build manifests describe, keyed by entry name, once read.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $manifest = null;

	/**
	 * Set the plugin-relative directory that contains asset files.
	 *
	 * Call this from `configure()` in your entry file, before anything first
	 * asks for the service, to override the default `assets` directory.
	 *
	 * @param string $assets_root Plugin-relative directory of asset files.
	 * @return void
	 */
	public function set_assets_root( string $assets_root ): void {
		$this->assets_root = $assets_root;
	}

	/**
	 * Set the plugin-relative directory that contains `@wordpress/scripts`
	 * build output.
	 *
	 * Call this from `configure()` in your entry file, before anything first
	 * asks for the service, to override the default `build` directory.
	 *
	 * @param string $build_root Plugin-relative directory of build output.
	 * @return void
	 */
	public function set_build_root( string $build_root ): void {
		$this->build_root = $build_root;
	}

	/**
	 * The plugin-relative directory `@wordpress/scripts` builds into.
	 *
	 * Whatever `--output-path` the build was given, or `build` by default. It is
	 * also where {@see get_shared_packages()} looks, under
	 * {@see SHARED_SEGMENT}, so moving the build moves both without a second
	 * setting to keep in step.
	 *
	 * @return string
	 */
	public function get_build_root(): string {
		return $this->build_root;
	}

	/**
	 * Build the globally namespaced asset handle.
	 *
	 * @param string $name The local asset name.
	 * @return string The namespaced asset handle.
	 */
	public function get_asset_slug( string $name ): string {
		return $this->get_plugin()->get_namespaced_name( $name );
	}

	/**
	 * Get the URL of a file in the configured assets directory.
	 *
	 * @param string $path       The resource path relative to the assets directory.
	 * @param array  $query_args Optional query arguments to append to the URL.
	 * @return string The full asset URL.
	 * @throws \InvalidArgumentException When the path escapes the plugin root.
	 */
	public function get_asset_url( string $path, array $query_args = array() ): string {
		return $this->path->get_plugin_url( $this->assets_root . '/' . \ltrim( $path, '/\\' ), $query_args );
	}

	/**
	 * Get the URL of a file in the configured `@wordpress/scripts` build directory.
	 *
	 * @param string $path       The resource path relative to the build directory.
	 * @param array  $query_args Optional query arguments to append to the URL.
	 * @return string The full build asset URL.
	 * @throws \InvalidArgumentException When the path escapes the plugin root.
	 */
	public function get_build_url( string $path, array $query_args = array() ): string {
		return $this->path->get_plugin_url( $this->build_root . '/' . \ltrim( $path, '/\\' ), $query_args );
	}

	/**
	 * Register a script without enqueueing it.
	 *
	 * @param string           $handle    The local script handle.
	 * @param string           $src       The script path, relative to the configured assets directory, resolved via get_asset_url().
	 * @param string[]         $deps      Handles this script depends on, as WordPress knows them -- the return value of a previous register_script()/register_script_from_manifest() call for one of your own, or the plain handle ('jquery', 'wp-element') for anything registered outside this service. An external handle is passed straight through; running it through get_asset_slug() would namespace it to your plugin and leave the dependency unregistered.
	 * @param string|bool|null $version   Script version, false for the plugin's own, or null for none.
	 * @param array|bool       $args      Extra registration args, or a bool for the legacy in-footer flag.
	 * @return string The namespaced handle, for use in a dependent asset's $deps.
	 * @throws \InvalidArgumentException When $src escapes the plugin root.
	 */
	public function register_script( string $handle, string $src, array $deps = array(), $version = false, $args = array() ): string {
		$slug = $this->get_asset_slug( $handle );
		\wp_register_script( $slug, $this->get_asset_url( $src ), $deps, $this->get_asset_version( $version ), $args );
		return $slug;
	}

	/**
	 * Register a style without enqueueing it.
	 *
	 * @param string           $handle  The local style handle.
	 * @param string           $src     The style path, relative to the configured assets directory, resolved via get_asset_url().
	 * @param string[]         $deps    Handles this style depends on, as WordPress knows them -- the return value of a previous register_style() call for one of your own, or the plain handle ('wp-components') for anything registered outside this service. An external handle is passed straight through; running it through get_asset_slug() would namespace it to your plugin and leave the dependency unregistered.
	 * @param string|bool|null $version Style version, false for the plugin's own, or null for none.
	 * @param string           $media   The media type the style applies to.
	 * @return string The namespaced handle, for use in a dependent asset's $deps.
	 * @throws \InvalidArgumentException When $src escapes the plugin root.
	 */
	public function register_style( string $handle, string $src, array $deps = array(), $version = false, string $media = 'all' ): string {
		$slug = $this->get_asset_slug( $handle );
		\wp_register_style( $slug, $this->get_asset_url( $src ), $deps, $this->get_asset_version( $version ), $media );
		return $slug;
	}

	/**
	 * Register a `@wordpress/scripts`-built script (and its stylesheet, if
	 * the build produced one) without enqueueing either.
	 *
	 * Reads `{entry}.asset.php` next to `{entry}.js` in the configured build
	 * directory for the script's WordPress dependencies and content-hash
	 * version, rather than requiring them to be hand-maintained. Any extra
	 * $deps are merged in after the manifest's own dependencies. When the
	 * build also produced `{entry}.css` (an entry that imports a stylesheet),
	 * it is registered too, under the same handle -- scripts and styles are
	 * separate WordPress registries, so this cannot collide -- versioned from
	 * the same manifest, since `@wordpress/scripts` does not generate a
	 * separate one for the stylesheet. Defaults to `in_footer`, since a
	 * script depending on `wp-element`/`wp-api-fetch`/etc. almost always
	 * needs to run after the DOM and those dependencies are available;
	 * pass an explicit $args to opt out.
	 *
	 * @param string     $handle The local script handle.
	 * @param string     $entry  The build entry name, e.g. 'app' for 'app.js' + 'app.asset.php'.
	 * @param string[]   $deps   Extra handles to depend on, as WordPress knows them, merged after the manifest's dependencies.
	 * @param array|bool $args   Extra registration args, or a bool for the legacy in-footer flag; defaults to array( 'in_footer' => true ).
	 * @return string The namespaced handle, for use in a dependent asset's $deps.
	 * @throws \InvalidArgumentException When the entry's manifest file does not exist or is malformed.
	 */
	public function register_script_from_manifest( string $handle, string $entry, array $deps = array(), $args = null ): string {
		$slug     = $this->get_asset_slug( $handle );
		$manifest = $this->get_manifest( $entry );

		\wp_register_script(
			$slug,
			$this->get_build_url( $entry . '.js' ),
			\array_merge( $manifest['dependencies'], $deps ),
			$manifest['version'],
			$args ?? array( 'in_footer' => true )
		);

		$styles = $this->get_entry_styles( $entry );

		if ( array() !== $styles ) {
			// Scripts and styles are separate WordPress registries, so the
			// script and its stylesheet can share the same handle -- a caller
			// enqueues both with the same handle rather than having to remember
			// a second, differently suffixed name for the style.
			\wp_register_style(
				$slug,
				$this->get_build_url( $styles['css'] ),
				array(),
				$manifest['version']
			);

			// Mirrors WordPress core's own block-style registration
			// (wp_register_style( ..., 'rtl', 'replace' )): only opt a style
			// into RTL swapping when RTLCSS actually produced a distinct file
			// for it, not on every registration.
			if ( isset( $styles['rtl'] ) ) {
				\wp_style_add_data( $slug, 'rtl', 'replace' );
			}
		}

		return $slug;
	}

	/**
	 * Every built shared package, keyed by its local name.
	 *
	 * The local name is the package directory's -- `src/shared/formatting` is
	 * `formatting` -- not the npm name it publishes itself under. That is the
	 * name the methods here take, and the one `wp zestry make shared` was given.
	 *
	 * @return array<string, array<string, mixed>> Each package's build manifest, keyed by local name.
	 * @throws DiscoveryException When a shared package's manifest is unreadable or does not describe a loadable package.
	 */
	public function get_shared_packages(): array {
		return $this->get_built( 'shared' );
	}

	/**
	 * This plugin's own script entries, keyed by their local name.
	 *
	 * A directory under `src/entries/` -- `src/entries/settings/index.ts` is
	 * `settings`. Each is registered on `init` under the handle
	 * {@see get_asset_slug()} returns, so using one is a single call:
	 *
	 *     $assets->enqueue_entry( 'settings' );
	 *
	 * An entry is a classic script unless a `package.json` beside it declares a
	 * `kind` of `module`, which builds it as an ES module and registers it with
	 * `wp_register_script_module()` instead. The two are separate WordPress
	 * registries, which is why {@see enqueue_entry()} is worth preferring over
	 * {@see enqueue_script()} here.
	 *
	 * Blocks are not here: WordPress registers those from their own
	 * `block.json`, and registering them again under a second handle would
	 * load each twice.
	 *
	 * @return array<string, array<string, mixed>> Each entry's manifest fields, keyed by local name.
	 * @throws DiscoveryException When a manifest is present but does not describe entries.
	 */
	public function get_entries(): array {
		return $this->get_built( 'entry' );
	}

	/**
	 * Everything the build produced, keyed by the handle it registers under.
	 *
	 * Every entry and every shared package -- but no blocks, which WordPress
	 * registers from their own `block.json` and which a row here would only
	 * describe a second time.
	 *
	 * Empty when the plugin has never been built, or was built by a
	 * configuration that writes no manifest.
	 *
	 * @return array<string, array<string, mixed>>
	 * @throws DiscoveryException When a manifest is present but does not describe entries.
	 */
	public function get_build_manifest(): array {
		if ( null !== $this->manifest ) {
			return $this->manifest;
		}

		$this->manifest = array();

		foreach ( self::MANIFEST_FILENAMES as $filename ) {
			$relative = $this->build_root . '/' . $filename;

			if ( ! $this->path->plugin_file_exists( $relative ) ) {
				continue;
			}

			$path    = $this->path->get_plugin_path( $relative );
			$entries = require $path;

			if ( ! \is_array( $entries ) ) {
				throw new DiscoveryException(
					'The build manifest "' . $path . '" must return an array of entries.'
				);
			}

			$this->assert_current_manifest( $path, $entries );

			// Merged rather than replaced: the script and module builds each
			// write one, and both halves belong to the same plugin.
			$this->manifest += $entries;
		}

		return $this->manifest;
	}

	/**
	 * What WordPress knows a package as.
	 *
	 * A script's handle, or a module's id. Pass it as a dependency of a script
	 * registered by hand, the way `'wp-element'` would be.
	 *
	 * The build decided it, not this module: for a script it is
	 * `{plugin-slug}-shared-{name}`, and for a module the package's own npm
	 * name, because that is the specifier its importers import. Either way it is
	 * the string already written into every importer's `.asset.php`, which is
	 * why nothing here composes a second one.
	 *
	 * @param string $name The package's local name, e.g. `formatting`.
	 * @return string The registered handle or module id.
	 * @throws \InvalidArgumentException When no package of that name was built.
	 */
	public function get_shared_handle( string $name ): string {
		return $this->get_built_handle( 'shared', $name );
	}

	/**
	 * Whether a package is loaded as an ES module rather than a classic script.
	 *
	 * The two are separate WordPress registries that cannot depend on each
	 * other, so this is what decides which one a caller is dealing with.
	 *
	 * @param string $name The package's local name.
	 * @return bool
	 * @throws \InvalidArgumentException When no package of that name was built.
	 */
	public function is_shared_module( string $name ): bool {
		$this->get_shared_handle( $name );

		return 'module' === $this->get_shared_packages()[ $name ]['kind'];
	}

	/**
	 * Enqueue a package, and its stylesheet when the build produced one.
	 *
	 * Rarely needed: an entry that imports a package already declares it as a
	 * dependency, so enqueuing the entry loads both. This is for a package
	 * nothing imports -- one loaded for its side effects, or shared with a
	 * script built outside this plugin.
	 *
	 * @param string $name The package's local name.
	 * @return string The handle or module id that was enqueued.
	 * @throws \InvalidArgumentException When no package of that name was built.
	 */
	public function enqueue_shared( string $name ): string {
		return $this->enqueue_built( 'shared', $name );
	}

	/**
	 * Register everything the build produced with WordPress.
	 *
	 * One loop over the manifest, with nothing to branch on but the `kind` each
	 * row states. Entries and shared packages used to be registered by two
	 * methods composing their handles two different ways -- which is exactly how
	 * an entry and a package of the same name came to claim one registration,
	 * with the loser silently dropped. A row now carries the handle it was built
	 * for, so there is one way to read it and no name to compose.
	 *
	 * @return void
	 * @throws DiscoveryException When a manifest is present but does not describe entries.
	 *
	 * @internal
	 */
	public function register_built(): void {
		foreach ( $this->get_build_manifest() as $handle => $fields ) {
			// No script survived the build: a style-only entry, or one whose
			// JavaScript compiled to nothing but webpack's own runtime.
			if ( isset( $fields['js'] ) ) {
				$this->register_built_script( $handle, $fields );
			}

			$this->register_built_style( $handle, $fields );
		}
	}

	/**
	 * Enqueue one of this plugin's entries, whichever kind it is.
	 *
	 * A classic script and an ES module are separate WordPress registries with
	 * separate enqueue functions, so this picks the right one -- and changing an
	 * entry's kind stays a one-line change in its own `package.json`.
	 *
	 * @param string $name The entry's local name, e.g. `settings`.
	 * @return string The handle or module id that was enqueued.
	 * @throws \InvalidArgumentException When no entry of that name was built.
	 */
	public function enqueue_entry( string $name ): string {
		return $this->enqueue_built( 'entry', $name );
	}

	/**
	 * Register everything the build produced, on every request.
	 *
	 * Deferred to `init` because that is the first point WordPress accepts a
	 * registration, and it is still before every hook that enqueues one -- front
	 * end, admin and block editor alike, so one registration serves all three.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function on_boot(): void {
		$this->run_at_init(
			static function ( self $module ): void {
				$module->register_built();
			}
		);
	}

	/**
	 * Resolve the version an asset registers with.
	 *
	 * `false` means the plugin's own version, read from its entry file header.
	 * Passing `false` through to WordPress instead would version the asset with
	 * *WordPress's* version, so a plugin release that changed a script would
	 * ship it behind an unchanged cache key -- invisible in development, where
	 * caches are off, and a support ticket in production.
	 *
	 * A string is used as given, and `null` still means "no version at all".
	 * Only `false` is reinterpreted, and only because WordPress's own meaning
	 * for it is of no use to a plugin.
	 *
	 * A plugin whose entry file declares no `Version:` header has nothing to
	 * fall back to, so the asset registers unversioned rather than carrying a
	 * version that says nothing.
	 *
	 * @param string|bool|null $version The version as the caller gave it.
	 * @return string|null The version to register with.
	 */
	private function get_asset_version( $version ) {
		if ( false !== $version ) {
			return $version;
		}

		return $this->get_plugin()->get_version();
	}

	/**
	 * Everything the build produced from one source, keyed by local name.
	 *
	 * The manifest is keyed by handle, which is what registering wants and what
	 * looking something up by name does not. Each row already says where it came
	 * from and what it is called, so this is a filter rather than a second
	 * reading of the key.
	 *
	 * @param string $source Which the caller wants: `entry` or `shared`.
	 * @return array<string, array<string, mixed>> Manifest fields plus `handle`, keyed by local name.
	 * @throws DiscoveryException When a manifest is present but does not describe entries.
	 */
	private function get_built( string $source ): array {
		$found = array();

		foreach ( $this->get_build_manifest() as $handle => $fields ) {
			if ( $source !== ( $fields['source'] ?? null ) ) {
				continue;
			}

			$found[ $fields['name'] ] = $fields + array( 'handle' => $handle );
		}

		return $found;
	}

	/**
	 * The handle one built thing registered under.
	 *
	 * @param string $source Which set to look in: `entry` or `shared`.
	 * @param string $name   Its local name.
	 * @return string The registered handle or module id.
	 * @throws \InvalidArgumentException When nothing of that name was built.
	 */
	private function get_built_handle( string $source, string $name ): string {
		$built = $this->get_built( $source );

		if ( ! isset( $built[ $name ] ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'No built %s named "%s". Built: %s',
					'shared' === $source ? 'package' : 'entry',
					$name,
					array() === $built ? 'none' : \implode( ', ', \array_keys( $built ) )
				)
			);
		}

		return $built[ $name ]['handle'];
	}

	/**
	 * Enqueue one built thing, into whichever registry it belongs to.
	 *
	 * @param string $source Which set to look in: `entry` or `shared`.
	 * @param string $name   Its local name.
	 * @return string The handle or module id that was enqueued.
	 * @throws \InvalidArgumentException When nothing of that name was built.
	 */
	private function enqueue_built( string $source, string $name ): string {
		$handle = $this->get_built_handle( $source, $name );

		if ( 'module' === ( $this->get_built( $source )[ $name ]['kind'] ?? 'script' ) ) {
			\wp_enqueue_script_module( $handle );

			return $handle;
		}

		// A style-only entry has no script to enqueue, and asking for one would
		// be WordPress's "dependency is not registered" notice.
		if ( \wp_script_is( $handle, 'registered' ) ) {
			\wp_enqueue_script( $handle );
		}

		if ( \wp_style_is( $handle, 'registered' ) ) {
			\wp_enqueue_style( $handle );
		}

		return $handle;
	}

	/**
	 * Register one built row's script, into whichever registry its kind names.
	 *
	 * @param string               $handle The handle the build assigned it.
	 * @param array<string, mixed> $fields Its manifest fields.
	 * @return void
	 */
	private function register_built_script( string $handle, array $fields ): void {
		$source       = $this->get_build_url( $fields['js'] );
		$dependencies = $fields['dependencies'] ?? array();
		$version      = $fields['version'] ?? null;

		if ( 'module' === ( $fields['kind'] ?? 'script' ) ) {
			\wp_register_script_module( $handle, $source, $dependencies, $version );

			return;
		}

		// Defaults to the footer: a script depending on wp-element or
		// wp-api-fetch almost always needs to run after the DOM and after those
		// are available.
		\wp_register_script( $handle, $source, $dependencies, $version, array( 'in_footer' => true ) );
	}

	/**
	 * Register one built row's stylesheet, when the build produced one.
	 *
	 * Under the same handle as its script: scripts and styles are separate
	 * WordPress registries, so the two cannot collide, and a caller enqueues
	 * both with one name.
	 *
	 * A style-only entry has no content hash to version by -- that lives in the
	 * `.asset.php` written beside a script, and there is no script here to have
	 * one -- so it falls back to the plugin's own version.
	 *
	 * @param string               $handle The handle the build assigned it.
	 * @param array<string, mixed> $fields Its manifest fields.
	 * @return void
	 */
	private function register_built_style( string $handle, array $fields ): void {
		if ( ! isset( $fields['css'] ) ) {
			return;
		}

		\wp_register_style(
			$handle,
			$this->get_build_url( $fields['css'] ),
			array(),
			$fields['version'] ?? $this->get_plugin()->get_version()
		);

		// Mirrors WordPress core's own block-style registration
		// (wp_register_style( ..., 'rtl', 'replace' )): only opt a style into
		// RTL swapping when RTLCSS actually produced a distinct file for it.
		if ( isset( $fields['rtl'] ) ) {
			\wp_style_add_data( $handle, 'rtl', 'replace' );
		}
	}

	/**
	 * Refuse a manifest an older build configuration wrote.
	 *
	 * `wp zestry update` refreshes the copied PHP but leaves `webpack.config.js`
	 * alone -- it is generated once and yours to edit -- so an updated plugin can
	 * arrive at a manifest written to the shape before this one. Every row now
	 * says where it came from; a row that does not is from a build that keyed by
	 * entry path and nested its dependencies under `asset`, and reading it would
	 * register nothing while looking like a plugin with no JavaScript.
	 *
	 * @param string               $path    The manifest that was read.
	 * @param array<string, mixed> $entries What it returned.
	 * @return void
	 * @throws DiscoveryException When the manifest predates the current shape.
	 */
	private function assert_current_manifest( string $path, array $entries ): void {
		foreach ( $entries as $handle => $fields ) {
			if ( ! \is_array( $fields ) || ! isset( $fields['source'] ) ) {
				throw new DiscoveryException(
					\sprintf(
						'The build manifest "%s" was written by an older build configuration. Re-run '
							. '`wp zestry add module assets --overwrite` to refresh webpack.config.js, then rebuild.',
						$path
					)
				);
			}

			// The handle is how WordPress finds it; the name is how you do. A row
			// without one registers fine and answers to nothing.
			if ( ! isset( $fields['name'] ) || ! \is_string( $fields['name'] ) ) {
				throw new DiscoveryException(
					\sprintf(
						'The build manifest row "%s" in "%s" declares no "name" to look it up by.',
						$handle,
						$path
					)
				);
			}
		}
	}

	/**
	 * The stylesheet one entry produced, and whether it has an RTL variant.
	 *
	 * Probed rather than read from the manifest, because this serves the one
	 * path the manifest does not describe: a build entry named by hand through
	 * {@see register_script_from_manifest()}, which may be a block's script or
	 * anything else outside `src/entries/`. Everything the manifest *does*
	 * describe records the stylesheet it actually emitted, and is registered
	 * from that.
	 *
	 * Only `{entry}.css` is looked for. `@wordpress/scripts` splits a source file
	 * called `style.scss` into a chunk of its own and writes it as
	 * `style-{entry}.css`, which is not derivable from the entry name -- an entry
	 * whose stylesheet is named that way needs the manifest, and has it.
	 *
	 * @param string $entry The build entry name.
	 * @return array{css?: string, rtl?: string} Build-root-relative paths, empty when there is no stylesheet.
	 */
	private function get_entry_styles( string $entry ): array {
		if ( ! $this->path->plugin_file_exists( $this->build_root . '/' . $entry . '.css' ) ) {
			return array();
		}

		$styles = array( 'css' => $entry . '.css' );

		if ( $this->path->plugin_file_exists( $this->build_root . '/' . $entry . '-rtl.css' ) ) {
			$styles['rtl'] = $entry . '-rtl.css';
		}

		return $styles;
	}

	/**
	 * Read a `@wordpress/scripts`-generated `{entry}.asset.php` manifest.
	 *
	 * The per-entry file, not the build manifest: this serves callers naming a
	 * build entry by its path, which is the one thing the build manifest no
	 * longer keys by. Everything it does describe is registered from the row
	 * itself, which already carries these two fields.
	 *
	 * @param string $entry The build entry name, e.g. 'app' for 'app.asset.php'.
	 * @return array{dependencies: string[], version: string} The manifest's dependencies and version.
	 * @throws \InvalidArgumentException When the manifest file does not exist or is malformed.
	 */
	private function get_manifest( string $entry ): array {
		$manifest_path = $this->path->get_plugin_path( $this->build_root . '/' . $entry . '.asset.php' );

		if ( ! \is_file( $manifest_path ) ) {
			throw new \InvalidArgumentException( 'Asset manifest does not exist: ' . $manifest_path );
		}

		$manifest = require $manifest_path;

		if ( ! \is_array( $manifest ) || ! isset( $manifest['dependencies'], $manifest['version'] )
			|| ! \is_array( $manifest['dependencies'] ) || ! \is_string( $manifest['version'] )
		) {
			throw new \InvalidArgumentException(
				\sprintf(
					'Asset manifest "%s" must return an array with "dependencies" (array) and "version" (string) keys. Got: %s',
					$manifest_path,
					\is_object( $manifest ) ? $manifest::class : \gettype( $manifest )
				)
			);
		}

		return $manifest;
	}
}
