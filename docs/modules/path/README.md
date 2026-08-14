<!--
    Generated from src/Modules/Path.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Path

Provides utilities for accessing plugin files, directories, and URLs.

It derives all locations from the entry file held by the plugin.

Resource paths are contained within the plugin root: a leading separator is trimmed, any parent-directory (`..`) segment and any NUL byte is rejected, and for a file that exists the real (symlink-resolved) target must still sit inside the plugin — so you cannot resolve a path outside the plugin, even through a symlink. A rejected path throws rather than answering falsy; pass `$allow_escape = true` to opt out of containment for a deliberate case, on the methods that offer it.

[Adding it](#adding-it) &nbsp;·&nbsp; [Resolving paths and URLs](#resolving-paths-and-urls) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add path
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Path` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    Path::class,
);
```

## Resolving paths and URLs

```php
$path = $plugin->get( Path::class );

// Absolute filesystem path to a file inside the plugin:
require $path->get_plugin_path( 'views/email.php' );

// Browser URL for the same plugin:
wp_enqueue_script( 'app', $path->get_plugin_url( 'assets/app.js' ) );

// The plugin's own directory inside wp-content/uploads:
$dir = $path->get_plugin_uploads_dir();
```

## Changing the defaults

`Path` takes no configuration. The bare `modules` entry above is all it needs — reach it with `$plugin->get( Path::class )`, or declare a property of its type and have it injected.

## Methods

### `get_plugin_url( $path, $query_args, $allow_escape )`

Get a plugin resource URL.

```php
public function get_plugin_url( string $path = '', array $query_args = array(), bool $allow_escape = false ): string
```

|  | Details |
|---|---|
| **Parameters** | `$path` — The resource path relative to the plugin directory<br>`$query_args` — Optional query arguments to append to the URL<br>`$allow_escape` — When true, skip the containment checks |
| **Return** | The full resource URL |
| **Throws** | `InvalidArgumentException` — When the path escapes the plugin root and escape is not allowed |

Constructs a full URL to a plugin resource with optional query arguments. Path components are automatically URL-encoded. The resource path is contained within the plugin root unless containment is explicitly waived. The returned URL must still pass through esc_url()/esc_url_raw() at output time.

<br>

### `get_plugin_path( $path, $allow_escape )`

Get a plugin resource file path.

```php
public function get_plugin_path( string $path = '', bool $allow_escape = false ): string
```

|  | Details |
|---|---|
| **Parameters** | `$path` — The resource path relative to the plugin directory<br>`$allow_escape` — When true, skip the containment checks |
| **Return** | The full file system path |
| **Throws** | `InvalidArgumentException` — When the path escapes the plugin root and escape is not allowed |

Constructs a full file system path to a plugin resource. The resource path is contained within the plugin root unless containment is explicitly waived.

<br>

### `plugin_file_exists( $path )`

Check if a plugin resource exists.

```php
public function plugin_file_exists( string $path = '' ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$path` — The resource path relative to the plugin directory |
| **Return** | True if the resource exists, false otherwise |
| **Throws** | `InvalidArgumentException` — When the path escapes the plugin root |

A path that escapes the plugin root throws rather than answering false, and there is no `$allow_escape` opt-out here — so validate anything that came from request input before passing it in.

<br>

### `is_plugin_dir( $path )`

Check if a plugin resource is a directory.

```php
public function is_plugin_dir( string $path = '' ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$path` — The resource path relative to the plugin directory |
| **Return** | True if the resource exists and is a directory, false otherwise |
| **Throws** | `InvalidArgumentException` — When the path escapes the plugin root |

A path that escapes the plugin root throws rather than answering false, and there is no `$allow_escape` opt-out here — so validate anything that came from request input before passing it in.

<br>

### `get_uploads_dir()`

Get the WordPress uploads directory.

```php
public function get_uploads_dir(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The uploads directory path without trailing slash |
| **Throws** | — |

<br>

### `get_plugin_uploads_dir()`

Get the plugin-specific uploads directory.

```php
public function get_plugin_uploads_dir(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin uploads directory path |
| **Throws** | `RuntimeException` — When the directory cannot be created |

Creates the directory if it does not exist.

<br>

### `get_plugin_upload_url( $path, $query_args, $allow_escape )`

Get a plugin-specific upload URL.

```php
public function get_plugin_upload_url( string $path = '', array $query_args = array(), bool $allow_escape = false ): string
```

|  | Details |
|---|---|
| **Parameters** | `$path` — The resource path relative to the plugin uploads directory<br>`$query_args` — Optional query arguments to append to the URL<br>`$allow_escape` — When true, skip the containment checks |
| **Return** | The full upload resource URL |
| **Throws** | `InvalidArgumentException` — When the path escapes the uploads directory and escape is not allowed |

Constructs a URL to a resource in the plugin uploads directory. Path components are URL-encoded, matching get_plugin_url(). The resource path is contained within that directory unless containment is explicitly waived. The returned URL must still pass through esc_url()/esc_url_raw() at output time.

<br>

### `include_file( $file, $data, $scope )`

Include a PHP file, keeping both halves of what it produced.

```php
public function include_file( string $file, array $data = array(), ?object $scope = null ): array
```

|  | Details |
|---|---|
| **Parameters** | `$file` — Absolute path to an existing PHP file<br>`$data` — Variables to make available to it<br>`$scope` — What `$this` is inside the file. Defaults to this service |
| **Return** | What it returned, and what it printed |
| **Throws** | — |

A PHP file that is data rather than a class produces two things and PHP throws one of them away depending on how you call it: `include` hands back what the file returned and lets its output escape to the page, while output buffering keeps the output and discards the return. This keeps both — `returned` is the value, `buffer` is the output, exactly as written and not trimmed.

That pairing is what lets one file be a picture *and* say what it is called: a template echoes its markup and returns `array( 'label' => __( … ) )`, so the label is translated in the file it describes rather than derived from a filename, which cannot be translated at all.

`returned` is exactly what PHP reports, which for a file that returns nothing is the integer `1` — so check the type you expected before reading anything out of it.

Inside the file, `$this` is `$scope`, or this service when none is given. `Views` passes itself, which is what makes a subview `$this->render( … )` from inside a template.

Each key in `$data` becomes a local variable. Only names beginning `__include_` are reserved — the scope holds two of them and nothing else — so every ordinary key arrives, `file` and `data` included.

The path is used as given and is not resolved against the plugin root: a caller that took the name from anything but its own source should resolve and contain it first, the way `Views` does before calling here.

<br>

### `on_wp_init( $callback, $priority )`

*Inherited from [`Module`](../module.md).*

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function on_wp_init( callable $callback, int $priority = 10 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run<br>`$priority` — WordPress hook priority, honoured only while `init` is still ahead |
| **Return** | — |
| **Throws** | — |

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be built on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, so a closure declared elsewhere needs no `use` to reach it:

```php
public function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only while `init` is still ahead**: a module built after `init` has fired runs its callback immediately, in registration order, whatever priority it asked for. Ordering that has to hold either way belongs inside one callback.

<br>

### `get_plugin()`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

For the plugin's own answers — its slug, its entry file, the headers it declares. To reach another module, `with()` is shorter and says what it is doing.

<br>

### `with( $name )`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Reach another module.

```php
final public function with( string $name ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The module class to reach |
| **Return** | The shared instance |
| **Throws** | `ModuleException` — If it is not declared, or has not booted yet |

The one way anything in a plugin reaches anything else. Returns the same instance every time, so two callers asking for `Options` share its state:

```php
$this->with( Options::class )->get( 'api_key' );
```

**The module has to be listed in `bootstrap.php`.** Asking for one that is not throws, naming the class and the file to add it to — nothing is built because something asked for it, so that file stays the whole inventory of what the plugin is made of.

A module that names a `boots_on` also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.

## See also

- [`Module`](../module.md) — what every module inherits
- [`wp zt add path`](../../commands/add.md) — the command that copies it
