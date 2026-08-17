<!--
    Generated from src/Modules/Options.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Options

Persists plugin configuration in the WordPress options table.

The whole plugin shares one `wp_options` row, so adding a setting costs no extra database row and no extra query. The row is read the first time you touch the group and not before, so a request that never asks for a setting never queries for one.

**Nothing is written until you call `save()`.** `set()` and `delete()` change the copy in memory; `save()` is the only thing that reaches the database. Call it once, at the point the work is finished and correct — after a form has validated, at the end of a migration step — so a request that dies halfway leaves the stored settings exactly as they were.

A key is a dotted path, so a group holds structure rather than a flat list: `set( 'mail.from.name', 'Acme' )` writes `['mail']['from']['name']`. A key with a dot already in it still *reads* back, since `get()` and `has()` try the whole string as a literal key before splitting it.

[Adding it](#adding-it) &nbsp;·&nbsp; [Reading and writing settings](#reading-and-writing-settings) &nbsp;·&nbsp; [Isolating settings in a group](#isolating-settings-in-a-group) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add options
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Options` does nothing until something asks, so it goes at the top level — which `wp zt add` writes for you. Left out, the first `with( Options::class )` throws rather than building it, which [`wp zt doctor`](../../commands/doctor.md) catches before a request does.

```php
// bootstrap.php
return array(
    Options::class,
);
```

## Reading and writing settings

`save()` writes; without it nothing leaves memory.

```php
$options = $plugin->get( Options::class );

$options->set( 'api_key', $key );
$options->set( 'mail.from.name', 'Acme' );
$options->save();

$key     = $options->get( 'api_key' );
$name    = $options->get( 'mail.from.name' );
$timeout = $options->get( 'timeout', 15 );  // with fallback

if ( $options->has( 'api_key' ) ) { ... }
```

## Isolating settings in a group

`group( 'api' )` returns a separate instance backed by its own option row, for settings worth isolating from the plugin's main blob. Each group is saved on its own.

```php
$api = $options->group( 'api' );
$api->set( 'endpoint', 'https://example.test' );
$api->save();
```

## Changing the defaults

**The plugin's own settings autoload; a group does not.** The default (ungrouped) row is what a plugin reads on ordinary requests, so it is loaded with the rest of WordPress's autoloaded options and costs no query. A `group()` is the opposite by construction — worth isolating means read by fewer requests — so it is written not-autoloaded and read on demand.

Name a group that *is* read on most requests through `add_autoloaded_groups()` — a static, per-request registry `save()` consults live at write time, so it can be declared from more than one place (a module declaring its own group, your own `configure( Options::class, ... )` declaring further groups) without either caller needing to know about the other's list.

```php
// bootstrap.php
return array(
    Options::class => static function ( Options $options ): void {
        $options->add_autoloaded_groups( array( 'my_frequently_read_group' ) );
    },
);
```

Both answers are written as WordPress's `auto-on`/`auto-off` rather than `on`/`off`: this module is choosing on your behalf from where the settings live, not stating a decision you made about this particular row, and the `auto-` values are the ones core is allowed to reconsider under its own autoloaded-size limits.

## Constants

### `DEFAULT_GROUP_NAME`

```php
const DEFAULT_GROUP_NAME = '_options_';
```

Group name the default (ungrouped) instance uses.

## Methods

### `set( $key, $value )`

Set a configuration value.

```php
public function set( string $key, $value ): void
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The configuration key, or a dotted path<br>`$value` — The value to store |
| **Return** | — |
| **Throws** | — |

Changes the copy in memory and marks the group dirty. Nothing reaches the database until `save()`.

`$key` is a dotted path, so `set( 'mail.from.name', 'Acme' )` writes `['mail']['from']['name']`. Unlike `get()`, a path is always split: there is no existing key to prefer, so the nesting you wrote is the nesting you get.

<br>

### `get( $key, $fallback )`

Get a configuration value.

```php
public function get( string $key, $fallback = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The configuration key, or a dotted path<br>`$fallback` — Returned when the path does not resolve |
| **Return** | The stored value, or `$fallback` |
| **Throws** | — |

Reads the group's row on the first call and keeps it for the rest of the request.

<br>

### `has( $key )`

Check whether a key is present.

```php
public function has( string $key ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The configuration key, or a dotted path |
| **Return** | True when the path resolves, whatever the value is |
| **Throws** | — |

Distinct from `null !== get( ... )`: a key stored as `null` answers true here, so a setting deliberately set to nothing is not mistaken for one that was never set.

<br>

### `delete( $key )`

Remove a key.

```php
public function delete( string $key ): void
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The configuration key, or a dotted path |
| **Return** | — |
| **Throws** | — |

Removing something that was never there is not an error, and leaves the group clean rather than queueing a write that would change nothing. Like `set()`, this only reaches the database through `save()`.

<br>

### `set_group_name( $group_name )`

Set the group namespace before the group is first read.

```php
public function set_group_name( string $group_name ): void
```

|  | Details |
|---|---|
| **Parameters** | `$group_name` — The namespace identifier |
| **Return** | — |
| **Throws** | — |

Used by `group()` through `make()`'s configurator, so the instance knows which option it is before anything asks it for a value. Setting it after a read has happened does not re-read: the values already in memory belong to the previous name, and `save()` would write them under the new one.

<br>

### `add_autoloaded_groups( $group_names )`

Declare group names that autoload, for the whole plugin.

```php
public function add_autoloaded_groups( array $group_names ): void
```

|  | Details |
|---|---|
| **Parameters** | `$group_names` — Group names that should autoload |
| **Return** | — |
| **Throws** | — |

Only groups: the default (ungrouped) row always autoloads, since it is the one a plugin reads on ordinary requests. A `group()` is the deliberate exception, so it does not autoload unless it is named here.

Adds to the registry rather than replacing it, since more than one caller may need to declare a group autoloaded independently. A module can name a group of its own worth autoloading — `Migrations` documents `Migrations::OPTIONS_GROUP_NAME` for that, leaving the call to you — while your own `configure( Options::class, ... )` declares further groups, neither call needing to know about the other's list. `save()` checks the registry live at write time (see there), so call order relative to `group()` does not matter — only that a name is registered before the save it should apply to.

## Whether a group is worth autoloading

Autoloading trades memory on *every* request for a query on the requests that read the group, so the question is what fraction of requests read it:

- **Read on most requests** — a setting the front end consults on every
page — is worth autoloading. One row in the autoloaded bundle beats one query per request.
- **Read on a few** — a setting only a form submission or an admin screen
looks at — is not. It loads on every request including the ones that never touch it, and a query on the rare request that does is cheaper.
- **Large either way** is not, whatever reads it. The autoloaded bundle is
fetched and unserialized whole, so a big group taxes every request.

Not autoloading costs nothing but a query when the group is first read, so it is the safe answer for a group and this is the deliberate exception.

<br>

### `group( $group_name )`

Returns a separate configuration instance for the specified options group, allowing logical separation of configuration groups.

```php
public function group( string $group_name ): self
```

|  | Details |
|---|---|
| **Parameters** | `$group_name` — The namespace identifier |
| **Return** | A separate Options instance backed by the group's own option row |
| **Throws** | — |

<br>

### `save()`

Write this group's pending changes to the database.

```php
public function save(): void
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | — |
| **Throws** | `RuntimeException` — When the write fails for a value that is actually changing |

**The only thing that writes.**

Each group saves on its own; saving the default instance does not save a `group()` reached from it.

Does nothing when there is nothing to write, so calling it on every path out of a handler costs at most one `get_option()` against the object cache.

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

- [`Module`](../module.md) — what every module inherits
- [`wp zt add options`](../../commands/add.md) — the command that copies it
