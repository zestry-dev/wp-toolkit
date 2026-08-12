<!--
    Generated from src/Services/Path.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Path

Provides utilities for accessing plugin files, directories, and URLs.

It derives all locations from the entry file held by the plugin.

Resource paths are contained within the plugin root: a leading separator is trimmed, any parent-directory (`..`) segment and any NUL byte is rejected, and for a file that exists the real (symlink-resolved) target must still sit inside the plugin — so you cannot resolve a path outside the plugin, even through a symlink. A rejected path throws rather than answering falsy; pass `$allow_escape = true` to opt out of containment for a deliberate case, on the methods that offer it.

[Adding it](#adding-it) &nbsp;·&nbsp; [Resolving paths and URLs](#resolving-paths-and-urls) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add service path
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

`Path` takes no configuration, so it needs no `bootstrap.php` entry at all. It is built the first time something asks for it:

```php
$path = $plugin->get( Path::class );

// Or, from any service, module, command or action:
public Path $path;   // injected before your code runs
```

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

### `get_plugin()`

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

Use it to reach something you did not declare a property for — a module you need in one method only, or one you look up by a name computed at runtime. For anything you use throughout the class, declare a typed property instead and let it be injected.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```

## See also

- [`Service`](../service.md) — what every service inherits
- [`wp zt add service path`](../../commands/add-service.md) — the command that copies it
