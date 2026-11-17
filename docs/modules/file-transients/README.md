<!--
    Generated from src/Modules/FileTransients.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# FileTransients

Dependencies [`path`](../path/)

Keeps big, read-often values in PHP files that OPcache holds in memory.

The same four verbs as `Transients` — `get`, `set`, `has`, `delete` — for a value too large to unserialize on every request, such as an index of a remote catalog. Each value is a PHP file returning an array; with OPcache that array already sits in shared memory, so reading it takes a fraction of a millisecond and no memory of the request's own, where a transient of the same size is read from the database and unserialized every time.

**Files or nothing.** Without OPcache, each file is compiled again on every request, which is slower than the transient it replaces. So the files are used only when OPcache is on and the folder is writable, and otherwise nothing is stored at all: `set()` does nothing, `get()` returns your fallback, and `has()` is false. Rebuild the value, as for any cache miss. `is_available()` says which case you are in, and `get_unavailable_reason()` says why, in a sentence you can show an administrator. The `{slug}_file_transients_enabled` filter (with the slug's dashes as underscores) can turn the files off; it is asked once per request.

**Values:** arrays, strings, numbers, booleans and null, nested. They come back exactly as stored, `false` and `null` included, and `has()` tells a stored `null` from a missing key. An object, a closure or a resource is refused with a `_doing_it_wrong()` notice and not stored, since a file can only hold what `var_export()` writes. An empty key throws, as it does for `Transients`.

**No transient hooks.** `Transients` goes through `set_transient()`, so WordPress's own `pre_transient_{$key}`, `set_transient_{$key}` and the rest fire, and an object-cache plugin sees every call. These never reach that API, so none of those hooks fire and no object cache is involved.

**Where:** `wp-content/cache/{slug}/{blog id}/`, outside uploads, where security scanners flag PHP files and backups copy everything. Each site of a multisite network gets its own folder.

**Treat every value as optional**, as with any transient: `flush()` or a deploy can remove it. Expired files are deleted by `prune()`, which runs with WordPress's own daily cleanup of expired transients.

[Adding it](#adding-it) &nbsp;·&nbsp; [Caching a large index](#caching-a-large-index) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add file-transients
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `FileTransients` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'acme_plugin_loaded' => array(
        FileTransients::class,
    ),
);
```

`acme_plugin_loaded` is your plugin's own action, fired at the end of `run()` once every module is built — `{slug}_loaded`, so a plugin slugged `acme-crm` spells it `acme_crm_loaded`. It is the earliest heading that still has the whole plugin behind it.

## Caching a large index

```php
$files = $this->with( FileTransients::class );

$index = $files->get( 'catalog' );

if ( null === $index ) {
    $index = $this->build_index();

    // Where the files are off this stores nothing, and the index is rebuilt next time.
    $files->set( 'catalog', $index, DAY_IN_SECONDS );
}
```

## Changing the defaults

`FileTransients` takes no configuration. The entry above is all it needs — reach it with `$this->with( FileTransients::class )` from any module or discovered file, or `$plugin->get( FileTransients::class )` from your entry file.

## Constants

### `CACHE_FOLDER`

```php
const CACHE_FOLDER = 'cache';
```

The folder inside wp-content that holds the plugin's own folder.

## Methods

### `on_boot()`

Delete expired files with WordPress's daily cleanup of expired transients.

```php
public function on_boot(): void
```

<br>

### `get( $key, $fallback )`

Read a stored value.

```php
public function get( string $key, mixed $fallback = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$key` — Your own key. Anything goes; it is hashed into the file name<br>`$fallback` — Returned when nothing is stored, it expired, or the files are off |
| **Return** | The stored value, or `$fallback` |
| **Throws** | `InvalidArgumentException` — When the key is empty |

<br>

### `set( $key, $value, $ttl )`

Store a value.

```php
public function set( string $key, mixed $value, int $ttl = 0 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$key` — Your own key<br>`$value` — Arrays, strings, numbers, booleans and null, nested<br>`$ttl` — Seconds to keep it. 0 means no expiry; a negative number is already expired. Nothing is stored where the files are off (`is_available()`), and a write the file system refuses is reported by PHP's own warning |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the key is empty |

<br>

### `has( $key )`

Whether a value is stored under this key.

```php
public function has( string $key ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$key` — Your own key |
| **Return** | True when something is stored and has not expired, whatever its value |
| **Throws** | `InvalidArgumentException` — When the key is empty |

Distinct from `null !== get()`, which cannot tell a stored `null` from a missing key.

<br>

### `delete( $key )`

Delete a stored value. Deleting what was never there is not an error.

```php
public function delete( string $key ): void
```

|  | Details |
|---|---|
| **Parameters** | `$key` — Your own key |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the key is empty |

<br>

### `flush()`

Delete every value of the current site.

```php
public function flush(): void
```

Unlike `Transients`, this one can: the files are in a folder of their own, so nothing of another plugin's is anywhere near them.

<br>

### `prune()`

Delete the expired values of the current site, and any file that is not one of them.

```php
public function prune(): int
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | How many files were deleted |
| **Throws** | — |

Reads only the first line of each file, where the expiry is, so a large value is never loaded just to find out it can be deleted. Also deletes a temp file an interrupted write left behind, once it is an hour old.

<br>

### `is_available()`

Whether the files are used on this request.

```php
public function is_available(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | True when values can be stored |
| **Throws** | — |

True when OPcache is on and the folder is writable, unless the `{slug}_file_transients_enabled` filter turned them off. Decided once, on the first call.

<br>

### `get_unavailable_reason()`

Why the files are off, as a sentence for an administrator; empty when they are on.

```php
public function get_unavailable_reason(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The reason, or an empty string |
| **Throws** | — |

<br>

### `get_folder()`

The folder of the current site's files.

```php
public function get_folder(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Absolute path, without a trailing slash |
| **Throws** | `RuntimeException` — When the plugin's cache folder cannot be created |

<br>

### `get_file( $key )`

The file a key is stored in.

```php
public function get_file( string $key ): string
```

|  | Details |
|---|---|
| **Parameters** | `$key` — Your own key |
| **Return** | Absolute path |
| **Throws** | `InvalidArgumentException` — When the key is empty<br>`RuntimeException` — When the plugin's cache folder cannot be created |

A readable part of the key, then a short hash of all of it, so keys that read alike (`a b` and `a-b`) stay two files.

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

## See also

- [`path`](../path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add file-transients`](../../commands/add.md) — the command that copies it
