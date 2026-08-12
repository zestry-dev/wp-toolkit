<!--
    Generated from src/Modules/Options.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Options

Persists plugin configuration in the WordPress options table.

The whole plugin shares one `wp_options` row, so adding a setting costs no extra database row and no extra query.

Writes are deferred: `set()` only marks the value dirty, and everything is persisted once on `shutdown`. Call `save()` to force an early write before a redirect or a long-running task.

[Adding it](#adding-it) &nbsp;·&nbsp; [Reading and writing settings](#reading-and-writing-settings) &nbsp;·&nbsp; [Isolating settings in a group](#isolating-settings-in-a-group) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add module options
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Options` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    Options::class,
);
```

## Reading and writing settings

```php
$options = $plugin->get( Options::class );

$options->set( 'api_key', $key );

$key     = $options->get( 'api_key' );
$timeout = $options->get( 'timeout', 15 );  // with fallback

if ( $options->has( 'api_key' ) ) { ... }
```

## Isolating settings in a group

`group( 'api' )` returns a separate instance backed by its own option row, for settings worth isolating from the plugin's main blob.

```php
$api = $options->group( 'api' );
$api->set( 'endpoint', 'https://example.test' );
```

## Changing the defaults

Every group defaults to autoload disabled. To autoload a specific group, declare it by name via `add_autoloaded_groups()` — a static, per-request registry `save()` consults live at write time, so it can be declared from more than one place (a module declaring its own group, a consumer's own `configure( Options::class, ... )` declaring further groups of its own) without either caller needing to know about the other's list.

```php
// bootstrap.php
return array(
    Options::class => static function ( Options $options ): void {
        $options->add_autoloaded_groups( array( 'my_frequently_read_group' ) );

        // Or, for the default (ungrouped) instance's own option:
        $options->autoload_default_group();
    },
);
```

## Constants

### `DEFAULT_GROUP_NAME`

```php
const DEFAULT_GROUP_NAME = '_options_';
```

Group name the default (ungrouped) instance uses.

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

### `set( $key, $value )`

Set a configuration value.

```php
public function set( string $key, $value ): void
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The configuration key<br>`$value` — The value to store |
| **Return** | — |
| **Throws** | — |

Marks the group dirty, so the change is written at shutdown.

<br>

### `get( $key, $fallback )`

Get a configuration value.

```php
public function get( string $key, $fallback = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The configuration key<br>`$fallback` — Returned when the key is not present |
| **Return** | The stored value, or `$fallback` |
| **Throws** | — |

<br>

### `has( $key )`

Check whether a key is present.

```php
public function has( string $key ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The configuration key |
| **Return** | True when the key exists, whatever its value |
| **Throws** | — |

Uses `array_key_exists()` rather than `isset()`, so a key stored as `null` reports `true` instead of being indistinguishable from one never set.

<br>

### `delete( $key )`

Remove a key.

```php
public function delete( string $key ): void
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The configuration key |
| **Return** | — |
| **Throws** | — |

Removing something that was never there is not an error. Like `set()`, this is written at shutdown rather than immediately.

<br>

### `set_group_name( $group_name )`

Set the group namespace before the instance boots.

```php
public function set_group_name( string $group_name ): void
```

|  | Details |
|---|---|
| **Parameters** | `$group_name` — The namespace identifier |
| **Return** | — |
| **Throws** | — |

Used by group() through the plugin's configurator so the correct option is loaded when boot() runs. Setting it after boot has no effect on the already-loaded values.

<br>

### `add_autoloaded_groups( $group_names )`

Declare additional group names that autoload, for the whole plugin.

```php
public function add_autoloaded_groups( array $group_names ): void
```

|  | Details |
|---|---|
| **Parameters** | `$group_names` — Group names that should autoload |
| **Return** | — |
| **Throws** | — |

Adds to the registry rather than replacing it, since more than one caller may need to declare a group autoloaded independently. A module can name a group of its own worth autoloading — `Migrations` documents `Migrations::OPTIONS_GROUP_NAME` for that, leaving the call to you — while your own `configure( Options::class, ... )` declares further groups, neither call needing to know about the other's list. `save()` checks the registry live at write time (see there), so call order relative to `group()` does not matter — only that a name is registered before the save it should apply to.

## Whether a group is worth autoloading

Autoloading trades memory on *every* request for a query on the requests that read the group, so the question is what fraction of requests read it:

- **Read on most requests** — a setting the front end consults on every
page — is worth autoloading. One row in the autoloaded bundle beats one query per request.
- **Read on a few** — a setting only a form submission or an admin screen
looks at — is not. It loads on every request including the ones that never touch it, and a query on the rare request that does is cheaper.
- **Large either way** is not, whatever reads it. The autoloaded bundle is
fetched and unserialized whole, so a big group taxes every request.

Not autoloading costs nothing but a query when the group is first read, so the default is the safe answer and this is the deliberate exception.

<br>

### `autoload_default_group()`

Declare the default (ungrouped) instance's own option autoloaded.

```php
public function autoload_default_group(): void
```

A thin convenience over `add_autoloaded_groups()` for the one group name that has no explicit `group()` call of its own — the plugin's default Options instance, storing under `{slug}__options_`. Equivalent to `add_autoloaded_groups( array( self::DEFAULT_GROUP_NAME ) )`.

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

Persist pending changes for the current group to the database.

```php
public function save(): void
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | — |
| **Throws** | `RuntimeException` — When the write fails for a value that is actually changing |

Called automatically on `shutdown`. Call it directly to force an early write at a safe point — before a redirect, a long-running WP-CLI task, or `fastcgi_finish_request()` — where waiting for shutdown risks losing the changes. A group autoloads only if `add_autoloaded_groups()` named it.

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

- [`Module`](../module.md) — what every module inherits
- [`wp zt add module options`](../../commands/add-module.md) — the command that copies it
