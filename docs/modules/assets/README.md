<!--
    Generated from src/Modules/Assets/Assets.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Assets

Reads from `assets/`, `build/` &nbsp;·&nbsp; Dependencies [`path`](../../services/path/)

Registers what the JavaScript build produced, and composes plugin asset URLs.

On `init` it registers every entry and shared package the build wrote into its manifest, each under the handle the build composed for it — so `enqueue_entry( 'settings' )` works from anywhere with no registration call first. For an asset the build did not produce, `register_script()` and `register_style()` resolve a URL under the configured assets directory and namespace the handle to the plugin slug, so `'app'` becomes `'{plugin-slug}-app'` and cannot collide with core, a theme or another plugin.

**Everything that returns a handle returns a real one**, ready to hand straight to WordPress. Attaching inline code or data, adding registration metadata, and enqueueing something registered by hand are WordPress's own functions, called with that handle:

```php
$handle = $assets->enqueue_entry( 'dashboard' );

wp_add_inline_script(
    $handle,
    sprintf( 'acmeDashboard.initialize( %s );', wp_json_encode( $data ) ),
    'after'
);
```

**Hand the data to the script, rather than leaving it on a global for the script to find.** Both work. This one fails better: printed `after`, it calls a function the bundle defined, so a bundle that did not load throws `initialize is not a function` in the console instead of leaving an unread global and a screen with nothing on it. It is core's own shape — `wp.editWidgets.initialize( ... )`, `wp.editSite.initialize( ... )`.

`after` is safe because an entry registers blocking, in the footer. Give a script a `defer` strategy of your own and the inline code needs core's `wp_add_inline_script( $handle, 'wp.domReady( ... )' )` wrapper too.

`wp_json_encode()` rather than `wp_localize_script()`, which casts every scalar it passes to a string — `bindable: false` arrives as `""`, and every field reads as bindable.

`wp zt add module assets` brings the build with it: a `webpack.config.js` that compiles three directories, each with a different owner.

| Source | Built to | Registered by |
| --- | --- | --- |
| `src/blocks/{name}/` | `{build}/blocks/{name}/` | WordPress, from `block.json` |
| `src/entries/{name}/` | `{build}/entries/{name}` | this module, as `{plugin-slug}-{name}` |
| `src/shared/{name}/` | `{build}/shared/{name}` | this module, as `{plugin-slug}-shared-{name}` |

That merge is the reason the config exists. `@wordpress/scripts` decides entry points three mutually exclusive ways — files listed on the command line, `block.json` scanning, or the `src/index` fallback — each of which disables the others, so a plugin with one block has no supported way to build a script of its own.

The build composes every handle, and this module reads them. An entry and a shared package can therefore share a name — `src/entries/collections` and `src/shared/collections` — without one silently displacing the other, which is what the `shared` segment is there to prevent.

[Adding it](#adding-it) &nbsp;·&nbsp; [Your own script, built and registered](#your-own-script-built-and-registered) &nbsp;·&nbsp; [An asset the build did not produce](#an-asset-the-build-did-not-produce) &nbsp;·&nbsp; [Sharing code between entries](#sharing-code-between-entries) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add module assets
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Assets` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    Assets::class,
);
```

## Your own script, built and registered

`wp zt make entry settings` writes `src/entries/settings/`. The build compiles it, this module registers it on `init`, and using it is one call — from an admin page, a shortcode, anywhere:

```php
$assets->enqueue_entry( 'settings' );
```

The stylesheet the entry imports is registered under that same handle, so it comes along. Nothing derives its filename: `@wordpress/scripts` writes a source file called `style.scss` as `style-{entry}.css` and any other name as `{entry}.css`, so the build records what it actually emitted — including the RTL variant, which is swapped in the way core does it for block styles.

## An asset the build did not produce

`$src` is resolved through `get_asset_url()` — relative to the configured assets directory (`assets` by default) — into a full URL via the injected Path service, so you never construct asset URLs by hand.

```php
$app = $assets->register_script( 'app', 'app.js' );
$assets->register_script( 'widgets', 'widgets.js', array( $app ) );
wp_enqueue_script( $app );
```

## Sharing code between entries

A directory under `src/shared/` is an npm workspace imported by name, built once into `{build}/shared/` rather than copied into every entry that imports it. There is usually nothing to call: an importer declares the package in its own `.asset.php`, so loading the importer loads the package.

```php
// Only for a package nothing imports, or one a hand-registered script needs.
$assets->enqueue_shared( 'formatting' );
$assets->register_script( 'legacy', 'legacy.js', array( $assets->get_shared_handle( 'formatting' ) ) );
```

## Changing the defaults

Configure the module only to point it at directories other than the defaults. The build directory (`build` by default) is separate and independently configurable — it is not required to live inside the configured assets directory, since `@wordpress/scripts` projects commonly build straight to their own top-level `build/`.

```php
// bootstrap.php
return array(
    Assets::class => static function ( Assets $assets ): void {
        $assets->set_assets_root( 'resources' );
        $assets->set_build_root( 'dist' );
    },
);
```

## Constants

### `DEFAULT_ASSETS_ROOT`

```php
const DEFAULT_ASSETS_ROOT = 'assets';
```

Default plugin-relative directory of asset files.

### `DEFAULT_BUILD_ROOT`

```php
const DEFAULT_BUILD_ROOT = 'build';
```

Default plugin-relative directory of `@wordpress/scripts` build output.

### `MANIFEST_FILENAMES`

```php
const MANIFEST_FILENAMES = array( 'assets-manifest.php', 'assets-module-manifest.php' );
```

The build manifests the generated `webpack.config.js` writes.

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

### `on_boot()`

What this module does on its own.

```php
abstract protected function on_boot(): void
```

Runs once, when the plugin builds the module. Abstract rather than optional: a module with nothing to do here is a `Service`.

**Bind hooks here; do the work in them.** An entry file that calls `run()` as it loads — which is the documented shape, and what `ActivationHandler` requires — reaches this before WordPress has required `pluggable.php`, so there is no current user yet: `current_user_can()`, `wp_mail()` and the nonce functions are not defined and calling one is a fatal. It is also before `init`, so `__()` here asks for a text domain nothing has loaded. `$wpdb` *is* up, so a query works — but it runs on every request, including the ones that never needed it.

`run_at_init()` is the way out of all three, and where anything a module registers belongs.

## Methods you can use

### `set_assets_root( $assets_root )`

Set the plugin-relative directory that contains asset files.

```php
public function set_assets_root( string $assets_root ): void
```

|  | Details |
|---|---|
| **Parameters** | `$assets_root` — Plugin-relative directory of asset files |
| **Return** | — |
| **Throws** | — |

Call this from `configure()` in your entry file, before anything first asks for the service, to override the default `assets` directory.

<br>

### `set_build_root( $build_root )`

Set the plugin-relative directory that contains `@wordpress/scripts` build output.

```php
public function set_build_root( string $build_root ): void
```

|  | Details |
|---|---|
| **Parameters** | `$build_root` — Plugin-relative directory of build output |
| **Return** | — |
| **Throws** | — |

Call this from `configure()` in your entry file, before anything first asks for the service, to override the default `build` directory.

<br>

### `get_build_root()`

The plugin-relative directory `@wordpress/scripts` builds into.

```php
public function get_build_root(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Whatever `--output-path` the build was given, or `build` by default. The manifest every entry and shared package is read from lives there too, so moving the build moves both without a second setting to keep in step.

<br>

### `get_asset_slug( $name )`

Build the globally namespaced asset handle.

```php
public function get_asset_slug( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local asset name |
| **Return** | The namespaced asset handle |
| **Throws** | — |

<br>

### `get_asset_url( $path, $query_args )`

Get the URL of a file in the configured assets directory.

```php
public function get_asset_url( string $path, array $query_args = array() ): string
```

|  | Details |
|---|---|
| **Parameters** | `$path` — The resource path relative to the assets directory<br>`$query_args` — Optional query arguments to append to the URL |
| **Return** | The full asset URL |
| **Throws** | `InvalidArgumentException` — When the path escapes the plugin root |

<br>

### `get_build_url( $path, $query_args )`

Get the URL of a file in the configured `@wordpress/scripts` build directory.

```php
public function get_build_url( string $path, array $query_args = array() ): string
```

|  | Details |
|---|---|
| **Parameters** | `$path` — The resource path relative to the build directory<br>`$query_args` — Optional query arguments to append to the URL |
| **Return** | The full build asset URL |
| **Throws** | `InvalidArgumentException` — When the path escapes the plugin root |

<br>

### `register_script( $handle, $src, $deps, $version, $args )`

Register a script without enqueueing it.

```php
public function register_script( string $handle, string $src, array $deps = array(), $version = false, $args = array() ): string
```

|  | Details |
|---|---|
| **Parameters** | `$handle` — The local script handle<br>`$src` — The script path, relative to the configured assets directory, resolved via get_asset_url()<br>`$deps` — Handles this script depends on, as WordPress knows them — the return value of a previous register_script()/register_script_from_manifest() call for one of your own, or the plain handle ('jquery', 'wp-element') for anything registered outside this service. An external handle is passed straight through; running it through get_asset_slug() would namespace it to your plugin and leave the dependency unregistered<br>`$version` — Script version, false for the plugin's own, or null for none<br>`$args` — Extra registration args, or a bool for the legacy in-footer flag |
| **Return** | The namespaced handle, for use in a dependent asset's $deps |
| **Throws** | `InvalidArgumentException` — When $src escapes the plugin root |

<br>

### `register_style( $handle, $src, $deps, $version, $media )`

Register a style without enqueueing it.

```php
public function register_style( string $handle, string $src, array $deps = array(), $version = false, string $media = 'all' ): string
```

|  | Details |
|---|---|
| **Parameters** | `$handle` — The local style handle<br>`$src` — The style path, relative to the configured assets directory, resolved via get_asset_url()<br>`$deps` — Handles this style depends on, as WordPress knows them — the return value of a previous register_style() call for one of your own, or the plain handle ('wp-components') for anything registered outside this service. An external handle is passed straight through; running it through get_asset_slug() would namespace it to your plugin and leave the dependency unregistered<br>`$version` — Style version, false for the plugin's own, or null for none<br>`$media` — The media type the style applies to |
| **Return** | The namespaced handle, for use in a dependent asset's $deps |
| **Throws** | `InvalidArgumentException` — When $src escapes the plugin root |

<br>

### `register_script_from_manifest( $handle, $entry, $deps, $args )`

Register a `@wordpress/scripts`-built script (and its stylesheet, if the build produced one) without enqueueing either.

```php
public function register_script_from_manifest( string $handle, string $entry, array $deps = array(), $args = null ): string
```

|  | Details |
|---|---|
| **Parameters** | `$handle` — The local script handle<br>`$entry` — The build entry name, e.g. 'app' for 'app.js' + 'app.asset.php'<br>`$deps` — Extra handles to depend on, as WordPress knows them, merged after the manifest's dependencies<br>`$args` — Extra registration args, or a bool for the legacy in-footer flag; defaults to array( 'in_footer' => true ) |
| **Return** | The namespaced handle, for use in a dependent asset's $deps |
| **Throws** | `InvalidArgumentException` — When the entry's manifest file does not exist or is malformed |

Reads `{entry}.asset.php` next to `{entry}.js` in the configured build directory for the script's WordPress dependencies and content-hash version, rather than requiring them to be hand-maintained. Any extra $deps are merged in after the manifest's own dependencies. When the build also produced `{entry}.css` (an entry that imports a stylesheet), it is registered too, under the same handle — scripts and styles are separate WordPress registries, so this cannot collide — versioned from the same manifest, since `@wordpress/scripts` does not generate a separate one for the stylesheet. Defaults to `in_footer`, since a script depending on `wp-element`/`wp-api-fetch`/etc. almost always needs to run after the DOM and those dependencies are available; pass an explicit $args to opt out.

<br>

### `get_shared_packages()`

Every built shared package, keyed by its local name.

```php
public function get_shared_packages(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Each package's build manifest, keyed by local name |
| **Throws** | `DiscoveryException` — When a manifest is present but does not describe entries |

The local name is the package directory's — `src/shared/formatting` is `formatting` — not the npm name it publishes itself under. That is the name the methods here take, and the one `wp zt make shared` was given.

<br>

### `get_entries()`

This plugin's own script entries, keyed by their local name.

```php
public function get_entries(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Each entry's manifest fields, keyed by local name |
| **Throws** | `DiscoveryException` — When a manifest is present but does not describe entries |

A directory under `src/entries/` — `src/entries/settings/index.ts` is `settings`. Each is registered on `init` under the handle `get_asset_slug()` returns, so using one is a single call:

```php
$assets->enqueue_entry( 'settings' );
```

An entry is a classic script unless a `package.json` beside it declares a `kind` of `module`, which builds it as an ES module and registers it with `wp_register_script_module()` instead. The two are separate WordPress registries, which is why `enqueue_entry()` is worth preferring over `wp_enqueue_script()` here.

Blocks are not here: WordPress registers those from their own `block.json`, and registering them again under a second handle would load each twice.

<br>

### `get_build_manifest()`

Everything the build produced, keyed by the handle it registers under.

```php
public function get_build_manifest(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | `DiscoveryException` — When a manifest is present but does not describe entries |

Every entry and every shared package — but no blocks, which WordPress registers from their own `block.json` and which a row here would only describe a second time.

Empty when the plugin has never been built, or was built by a configuration that writes no manifest.

<br>

### `get_shared_handle( $name )`

What WordPress knows a package as.

```php
public function get_shared_handle( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The package's local name, e.g. `formatting` |
| **Return** | The registered handle or module id |
| **Throws** | `InvalidArgumentException` — When no package of that name was built |

A script's handle, or a module's id. Pass it as a dependency of a script registered by hand, the way `'wp-element'` would be.

The build decided it, not this module: for a script it is `{plugin-slug}-shared-{name}`, and for a module the package's own npm name, because that is the specifier its importers import. Either way it is the string already written into every importer's `.asset.php`, which is why nothing here composes a second one.

<br>

### `is_shared_module( $name )`

Whether a package is loaded as an ES module rather than a classic script.

```php
public function is_shared_module( string $name ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The package's local name |
| **Return** | `bool` |
| **Throws** | `InvalidArgumentException` — When no package of that name was built |

The two are separate WordPress registries that cannot depend on each other, so this is what decides which one a caller is dealing with.

<br>

### `enqueue_shared( $name )`

Enqueue a package, and its stylesheet when the build produced one.

```php
public function enqueue_shared( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The package's local name |
| **Return** | The handle or module id that was enqueued |
| **Throws** | `InvalidArgumentException` — When no package of that name was built |

Rarely needed: an entry that imports a package already declares it as a dependency, so enqueuing the entry loads both. This is for a package nothing imports — one loaded for its side effects, or shared with a script built outside this plugin.

<br>

### `enqueue_entry( $name )`

Enqueue one of this plugin's entries, whichever kind it is.

```php
public function enqueue_entry( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The entry's local name, e.g. `settings` |
| **Return** | The handle or module id that was enqueued |
| **Throws** | `InvalidArgumentException` — When no entry of that name was built |

A classic script and an ES module are separate WordPress registries with separate enqueue functions, so this picks the right one — and changing an entry's kind stays a one-line change in its own `package.json`.

<br>

### `run_at_init( $callback )`

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function run_at_init( callable $callback ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run |
| **Return** | — |
| **Throws** | — |

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be resolved on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook — or a `get()` during a request — is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, matching the initializer signature, so a closure declared elsewhere needs no `use` to reach it:

```php
protected function on_boot(): void {
    $this->run_at_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

## See also

- [`path`](../../services/path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add module assets`](../../commands/add-module.md) — the command that copies it
