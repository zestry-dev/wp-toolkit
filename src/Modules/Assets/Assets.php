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
 * Composes plugin asset URLs and wraps WordPress's script/style APIs.
 *
 * A thin wrapper over WordPress's own script and style functions --
 * `wp_register_script()`, `wp_enqueue_style()`, `wp_add_inline_script()` and
 * the rest.
 *
 * Every method takes a plain, unprefixed handle like `'app'` and namespaces it
 * to the plugin slug before calling WordPress, so `'app'` becomes
 * `'{plugin-slug}-app'`. A plugin's handles therefore cannot collide with
 * WordPress core, a theme, or another plugin.
 *
 * Registering and enqueueing stay separate steps. `register_script()` and
 * `register_style()` declare an asset and return its namespaced handle;
 * `enqueue_script()` and `enqueue_style()` take that handle and queue the
 * asset for output.
 *
 * On `init` it registers everything the JavaScript build produced, so
 * `enqueue_script( 'settings' )` works from anywhere with no registration call
 * first.
 *
 * `wp zestry add module assets` brings the build with it: a `webpack.config.js`
 * that compiles three directories, each with a different owner.
 *
 * | Source | Built to | Registered by |
 * | --- | --- | --- |
 * | `src/blocks/{name}/` | `{build}/blocks/{name}/` | WordPress, from `block.json` |
 * | `src/entries/{name}/` | `{build}/entries/{name}` | this module, as `{plugin-slug}-{name}` |
 * | `src/shared/{name}/` | `{build}/shared/{name}` | this module, under the handle the build declared |
 *
 * That merge is the reason the config exists. `@wordpress/scripts` decides
 * entry points three mutually exclusive ways -- files listed on the command
 * line, `block.json` scanning, or the `src/index` fallback -- each of which
 * disables the others, so a plugin with one block has no supported way to build
 * a script of its own.
 *
 * @example Registering and enqueueing
 * `$src` is resolved through `get_asset_url()` -- relative to the configured
 * assets directory (`assets` by default) -- into a full URL via the injected
 * Path service, so you never construct asset URLs by hand.
 *
 * ```
 * $app = $assets->register_script( 'app', 'app.js' );
 * $assets->register_script( 'widgets', 'widgets.js', array( $app ) );
 * $assets->enqueue_script( 'widgets' );
 * ```
 *
 * @example Your own script, built and registered
 * `wp zestry make entry settings` writes `src/entries/settings/`. The build
 * compiles it, this module registers it on `init`, and using it is one call --
 * from an admin page, a shortcode, anywhere:
 *
 * ```
 * $assets->enqueue_script( 'settings' );
 * ```
 *
 * The stylesheet the entry imports is registered under that same handle, so it
 * comes along. Nothing derives its filename: `@wordpress/scripts` writes a
 * source file called `style.scss` as `style-{entry}.css` and any other name as
 * `{entry}.css`, so the build records what it actually emitted -- including the
 * RTL variant, which is swapped in the way core does it for block styles.
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
 * @example Registering something the build did not produce
 * `register_script_from_manifest()` takes a build entry name and reads its
 * dependencies and content-hash version from the build itself, rather than
 * having them hand-maintained. Reach for it when an entry needs a handle of
 * your choosing, or lives outside `src/entries/`:
 *
 * ```
 * $assets->register_script_from_manifest( 'legacy-editor', 'entries/settings' );
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
	 * Directory within the build root that holds built shared packages.
	 *
	 * Fixed rather than configurable, and derived from the build root rather
	 * than named separately: the generated `webpack.config.js` compiles
	 * `src/shared/{name}` to `shared/{name}` under whatever `--output-path` it
	 * was given, so one setting already decides both ends. A second one could
	 * only disagree.
	 */
	const SHARED_SEGMENT = 'shared';

	/**
	 * Directory within the build root that holds this plugin's own entries.
	 *
	 * `@wordpress/scripts` decides entry points three mutually exclusive ways --
	 * files listed on the command line, `block.json` scanning, or the
	 * `src/index` fallback -- so adding one block silently stops `src/index`
	 * being built and there is no supported way to have both. The generated
	 * `webpack.config.js` merges them, compiling each `src/entries/{name}/index`
	 * to `{build_root}/entries/{name}`, and everything found here is registered
	 * on `init` under the plugin-namespaced handle {@see get_asset_slug()}
	 * returns -- so `enqueue_script( 'settings' )` needs no registration call.
	 */
	const ENTRIES_SEGMENT = 'entries';

	/**
	 * The build manifests the generated `webpack.config.js` writes.
	 *
	 * One per compilation, since `--experimental-modules` runs two of them into
	 * the same directory and a single name would leave whichever finished last
	 * discarding the other's entries. Both are optional: a plugin that has never
	 * been built has neither, and a hand-rolled build may write neither.
	 *
	 * Each returns an entry name => `{ asset, kind?, id? }` map, where `asset` is
	 * the extraction plugin's own `{ dependencies, version }` copied verbatim --
	 * so one `require` answers for every entry, rather than one read per entry.
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
		return $this->register_manifest_script( $this->get_asset_slug( $handle ), $entry, $deps, $args );
	}

	/**
	 * Register a built script under a handle WordPress takes verbatim.
	 *
	 * {@see register_script_from_manifest()} with the namespacing left out --
	 * everything else is identical, and that method is this one with
	 * {@see get_asset_slug()} applied first.
	 *
	 * Reach for it when the handle is not yours to choose: something else has
	 * already written it down, and a name of your own making would leave that
	 * reference pointing at nothing. A JavaScript package is the case this
	 * exists for -- the build records the handle in every importer's own
	 * `.asset.php`, long before any of this runs.
	 *
	 * Prefer {@see register_script_from_manifest()} everywhere else. A handle
	 * that is not namespaced is one another plugin can collide with.
	 *
	 * @param string     $slug  The handle exactly as WordPress should know it.
	 * @param string     $entry The build entry name, e.g. 'app' for 'app.js' + 'app.asset.php'.
	 * @param string[]   $deps  Extra handles to depend on, merged after the manifest's dependencies.
	 * @param array|bool $args  Extra registration args, or a bool for the legacy in-footer flag; defaults to array( 'in_footer' => true ).
	 * @return string The handle it was registered under, unchanged.
	 * @throws \InvalidArgumentException When the entry's manifest file does not exist or is malformed.
	 */
	public function register_manifest_script( string $slug, string $entry, array $deps = array(), $args = null ): string {
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
			// enqueues both with the same local name, enqueue_script( 'app' )
			// and enqueue_style( 'app' ), rather than having to remember a
			// second, differently suffixed name for the style.
			\wp_register_style(
				$slug,
				$this->path->get_plugin_url( $this->build_root . '/' . $styles['css'] ),
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
	 * Enqueue a script already registered with register_script() or
	 * register_script_from_manifest().
	 *
	 * @param string $handle The local script handle.
	 * @return string The namespaced handle.
	 */
	public function enqueue_script( string $handle ): string {
		$slug = $this->get_asset_slug( $handle );
		\wp_enqueue_script( $slug );
		return $slug;
	}

	/**
	 * Enqueue a style already registered with register_style().
	 *
	 * @param string $handle The local style handle.
	 * @return string The namespaced handle.
	 */
	public function enqueue_style( string $handle ): string {
		$slug = $this->get_asset_slug( $handle );
		\wp_enqueue_style( $slug );
		return $slug;
	}

	/**
	 * Attach inline JavaScript to a registered or enqueued script.
	 *
	 * @param string $handle   The local script handle the inline code attaches to.
	 * @param string $data     The inline JavaScript, without surrounding <script> tags.
	 * @param string $position Whether to print 'before' or 'after' the script.
	 * @return bool True on success.
	 */
	public function add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
		return \wp_add_inline_script( $this->get_asset_slug( $handle ), $data, $position );
	}

	/**
	 * Attach inline CSS to a registered or enqueued style.
	 *
	 * @param string $handle The local style handle the inline CSS attaches to.
	 * @param string $data   The inline CSS.
	 * @return bool True on success.
	 */
	public function add_inline_style( string $handle, string $data ): bool {
		return \wp_add_inline_style( $this->get_asset_slug( $handle ), $data );
	}

	/**
	 * Expose data to a registered or enqueued script as a global JavaScript object.
	 *
	 * @param string               $handle      The local script handle to attach the data to.
	 * @param string               $object_name The JavaScript global variable name the data is exposed as.
	 * @param array<string, mixed> $l10n        The data, made available to JavaScript as $object_name.
	 * @return bool True on success.
	 */
	public function localize_script( string $handle, string $object_name, array $l10n ): bool {
		return \wp_localize_script( $this->get_asset_slug( $handle ), $object_name, $l10n );
	}

	/**
	 * Attach extra metadata to a registered script, such as 'conditional' or 'strategy'.
	 *
	 * @param string $handle The local script handle to attach data to.
	 * @param string $key    The data key, for example 'conditional' or 'strategy'.
	 * @param mixed  $value  The data value.
	 * @return bool True on success.
	 */
	public function script_add_data( string $handle, string $key, $value ): bool {
		return \wp_script_add_data( $this->get_asset_slug( $handle ), $key, $value );
	}

	/**
	 * Attach extra metadata to a registered style, such as 'conditional' or 'rtl'.
	 *
	 * @param string $handle The local style handle to attach data to.
	 * @param string $key    The data key, for example 'conditional' or 'rtl'.
	 * @param mixed  $value  The data value.
	 * @return bool True on success.
	 */
	public function style_add_data( string $handle, string $key, $value ): bool {
		return \wp_style_add_data( $this->get_asset_slug( $handle ), $key, $value );
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
		$shared = array();

		$prefix = self::SHARED_SEGMENT . '/';

		foreach ( $this->get_build_manifest() as $entry => $fields ) {
			// By where it was built, not by carrying a `kind`: an entry declares
			// one too, and reading that as a shared package would demand a
			// handle it has no reason to have.
			if ( ! \str_starts_with( $entry, $prefix ) ) {
				continue;
			}

			if ( ! \in_array( $fields['kind'] ?? null, array( 'script', 'module' ), true ) ) {
				throw new DiscoveryException(
					\sprintf(
						'The build manifest entry "%s" declares a "kind" of %s; expected "script" or "module".',
						$entry,
						\wp_json_encode( $fields['kind'] )
					)
				);
			}

			if ( empty( $fields['id'] ) || ! \is_string( $fields['id'] ) ) {
				throw new DiscoveryException(
					'The build manifest entry "' . $entry . '" declares no "id" to register it under.'
				);
			}

			// Keyed by the local name rather than the entry: `shared/formatting`
			// is how the build addresses it, `formatting` is how a caller does.
			$shared[ \substr( $entry, \strlen( $prefix ) ) ] = $fields + array( 'entry' => $entry );
		}

		return $shared;
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
		$entries = array();
		$prefix  = self::ENTRIES_SEGMENT . '/';

		foreach ( $this->get_build_manifest() as $entry => $fields ) {
			if ( \str_starts_with( $entry, $prefix ) ) {
				$entries[ \substr( $entry, \strlen( $prefix ) ) ] = $fields + array( 'entry' => $entry );
			}
		}

		return $entries;
	}

	/**
	 * Every entry the build produced, keyed by entry name.
	 *
	 * `index`, each block's scripts, each shared package. What a caller can do
	 * with one is register it: {@see register_script_from_manifest()} takes an
	 * entry name, and reads its dependencies and version from right here.
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
	 * Unlike every other handle here it is **not** namespaced to the plugin
	 * slug: it is whatever the build wrote into each importer's own
	 * `.asset.php`, and a name of this service's making would leave those
	 * references pointing at nothing.
	 *
	 * @param string $name The package's local name, e.g. `formatting`.
	 * @return string The registered handle or module id.
	 * @throws \InvalidArgumentException When no package of that name was built.
	 */
	public function get_shared_handle( string $name ): string {
		$packages = $this->get_shared_packages();

		if ( ! isset( $packages[ $name ] ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'No built package named "%s". Built: %s',
					$name,
					array() === $packages ? 'none' : \implode( ', ', \array_keys( $packages ) )
				)
			);
		}

		return $packages[ $name ]['id'];
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
		$id = $this->get_shared_handle( $name );

		if ( $this->is_shared_module( $name ) ) {
			\wp_enqueue_script_module( $id );

			return $id;
		}

		\wp_enqueue_script( $id );

		if ( \wp_style_is( $id, 'registered' ) ) {
			\wp_enqueue_style( $id );
		}

		return $id;
	}

	/**
	 * Register every built package with WordPress.
	 *
	 * @return void
	 * @throws DiscoveryException When a shared package's manifest is unreadable or does not describe a loadable package.
	 *
	 * @internal
	 */
	public function register_shared(): void {
		foreach ( $this->get_shared_packages() as $package ) {
			$entry = $package['entry'];

			if ( 'module' === $package['kind'] ) {
				$manifest = $this->get_manifest( $entry );

				\wp_register_script_module(
					$package['id'],
					$this->get_build_url( $entry . '.js' ),
					$manifest['dependencies'],
					$manifest['version']
				);

				continue;
			}

			// The handle exactly as the build wrote it, which is why this goes
			// through the un-namespaced twin of register_script_from_manifest().
			$this->register_manifest_script( $package['id'], $entry );
		}
	}

	/**
	 * Register this plugin's own entries under their namespaced handles.
	 *
	 * Registering, not enqueuing: it costs nothing on a request that never uses
	 * one, and it is what makes `enqueue_script( 'settings' )` work from
	 * anywhere without a registration call first.
	 *
	 * @return void
	 * @throws DiscoveryException When a manifest is present but does not describe entries.
	 *
	 * @internal
	 */
	public function register_entries(): void {
		foreach ( $this->get_entries() as $name => $entry ) {
			// No script survived the build: the entry was only a stylesheet, or
			// its JavaScript compiled to nothing but webpack's own runtime.
			if ( ! isset( $entry['asset'] ) ) {
				$this->register_entry_style( $name, $entry );

				continue;
			}

			if ( 'module' !== ( $entry['kind'] ?? 'script' ) ) {
				$this->register_script_from_manifest( $name, $entry['entry'] );

				continue;
			}

			$manifest = $this->get_manifest( $entry['entry'] );

			\wp_register_script_module(
				$this->get_asset_slug( $name ),
				$this->get_build_url( $entry['entry'] . '.js' ),
				$manifest['dependencies'],
				$manifest['version']
			);
		}
	}

	/**
	 * Enqueue one of this plugin's entries, whichever kind it is.
	 *
	 * A classic script and an ES module are separate WordPress registries with
	 * separate enqueue functions, so this picks the right one -- worth
	 * preferring over {@see enqueue_script()} for an entry, since changing an
	 * entry's kind then stays a one-line change in its own `package.json`.
	 *
	 * @param string $name The entry's local name, e.g. `settings`.
	 * @return string The handle or module id that was enqueued.
	 * @throws \InvalidArgumentException When no entry of that name was built.
	 */
	public function enqueue_entry( string $name ): string {
		$entries = $this->get_entries();

		if ( ! isset( $entries[ $name ] ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'No built entry named "%s". Built: %s',
					$name,
					array() === $entries ? 'none' : \implode( ', ', \array_keys( $entries ) )
				)
			);
		}

		$slug = $this->get_asset_slug( $name );

		if ( 'module' === ( $entries[ $name ]['kind'] ?? 'script' ) ) {
			\wp_enqueue_script_module( $slug );

			return $slug;
		}

		// A style-only entry has no script to enqueue, and asking for one would
		// be WordPress's "dependency is not registered" notice.
		if ( \wp_script_is( $slug, 'registered' ) ) {
			$this->enqueue_script( $name );
		}

		if ( \wp_style_is( $slug, 'registered' ) ) {
			$this->enqueue_style( $name );
		}

		return $slug;
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
				$module->register_shared();
				$module->register_entries();
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
	 * Register an entry that produced a stylesheet and no script.
	 *
	 * The version is the plugin's own rather than a content hash: the hash lives
	 * in the `.asset.php` the build writes beside a script, and there is no
	 * script here to have one.
	 *
	 * @param string               $name  The entry's local name.
	 * @param array<string, mixed> $entry Its manifest fields.
	 * @return void
	 */
	private function register_entry_style( string $name, array $entry ): void {
		if ( ! isset( $entry['css'] ) ) {
			return;
		}

		$slug = $this->get_asset_slug( $name );

		\wp_register_style(
			$slug,
			$this->path->get_plugin_url( $this->build_root . '/' . $entry['css'] ),
			array(),
			$this->get_plugin()->get_version()
		);

		if ( isset( $entry['rtl'] ) ) {
			\wp_style_add_data( $slug, 'rtl', 'replace' );
		}
	}

	/**
	 * The stylesheet one entry produced, and whether it has an RTL variant.
	 *
	 * Taken from the build manifest, because the name is not derivable from the
	 * entry: `@wordpress/scripts` splits a source file called `style.scss` into
	 * a chunk of its own and writes it as `style-{entry}.css`, while any other
	 * name lands as `{entry}.css`. The build records what it actually emitted,
	 * so both work and neither is guessed at.
	 *
	 * Falls back to looking for `{entry}.css` when the build wrote no manifest,
	 * which is the convention that held before there was one.
	 *
	 * @param string $entry The build entry name.
	 * @return array{css?: string, rtl?: string} Build-root-relative paths, empty when there is no stylesheet.
	 */
	private function get_entry_styles( string $entry ): array {
		$recorded = $this->get_build_manifest()[ $entry ] ?? null;

		if ( isset( $recorded['css'] ) ) {
			return \array_intersect_key( $recorded, \array_flip( array( 'css', 'rtl' ) ) );
		}

		if ( null !== $recorded || ! $this->path->plugin_file_exists( $this->build_root . '/' . $entry . '.css' ) ) {
			// The manifest knows this entry and recorded no stylesheet, or there
			// is no manifest and no file: either way there is nothing to add.
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
	 * @param string $entry The build entry name, e.g. 'app' for 'app.asset.php'.
	 * @return array{dependencies: string[], version: string} The manifest's dependencies and version.
	 * @throws \InvalidArgumentException When the manifest file does not exist or is malformed.
	 */
	private function get_manifest( string $entry ): array {
		// The build manifest answers for every entry in one read, so the
		// per-entry file is only reached when the build wrote no manifest.
		$manifest = $this->get_build_manifest()[ $entry ]['asset'] ?? null;

		if ( \is_array( $manifest ) && isset( $manifest['dependencies'], $manifest['version'] ) ) {
			return $manifest;
		}

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
