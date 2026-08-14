<!--
    Generated from src/Modules/Globals.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Globals

Provides an in-memory registry for values shared during one request.

Unlike Options, values are never written to the database. This is useful for request-scoped coordination: one module stores something another needs later in the same request, without a global or a database round trip.

[Adding it](#adding-it) &nbsp;·&nbsp; [Sharing a value across one request](#sharing-a-value-across-one-request) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add globals
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Globals` does nothing until something asks, so it goes at the top level — which `wp zt add` writes for you. Left out, the first `with( Globals::class )` throws rather than building it, which [`wp zt doctor`](../../commands/doctor.md) catches before a request does.

```php
// bootstrap.php
return array(
    Globals::class,
);
```

## Sharing a value across one request

`has()` is the way to tell a stored `null` from an absent key — `get()` returns `null` for both.

```php
$globals = $plugin->get( Globals::class );

$globals->set( 'current_job', $job );

// Elsewhere in the same request:
if ( $globals->has( 'current_job' ) ) {
    $job = $globals->get( 'current_job' );
}

// get() takes a fallback for a key that was never set:
$mode = $globals->get( 'render_mode', 'default' );
```

## Changing the defaults

`Globals` takes no configuration. The entry above is all it needs — reach it with `$this->with( Globals::class )` from any module or discovered file, or `$plugin->get( Globals::class )` from your entry file.

## Methods

### `set( $key, $value )`

Set a global value.

```php
public function set( string $key, $value ): void
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The registry key<br>`$value` — The value to store |
| **Return** | — |
| **Throws** | — |

Returns void, like every other setter here, so calls do not chain. Only `Plugin`'s builder methods are fluent.

<br>

### `get( $key, $fallback )`

Get a global value.

```php
public function get( string $key, $fallback = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The registry key<br>`$fallback` — The default value if key does not exist |
| **Return** | The stored value or default |
| **Throws** | — |

Existence is checked with has() rather than a null comparison, so a value that was explicitly stored as null is returned as null and is not confused with a key that was never set.

<br>

### `has( $key )`

Check if a global value exists.

```php
public function has( string $key ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The registry key |
| **Return** | True if the key exists, false otherwise |
| **Throws** | — |

<br>

### `delete( $key )`

Remove a value.

```php
public function delete( string $key ): void
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The key to remove |
| **Return** | — |
| **Throws** | — |

Removing something that was never there is not an error.

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

A module listed under a heading also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.

## See also

- [`Module`](../module.md) — what every module inherits
- [`wp zt add globals`](../../commands/add.md) — the command that copies it
