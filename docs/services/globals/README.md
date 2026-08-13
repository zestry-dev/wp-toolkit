<!--
    Generated from src/Services/Globals.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Globals

Provides an in-memory registry for values shared during one request.

Unlike Options, values are never written to the database. This is useful for request-scoped coordination: one module stores something another needs later in the same request, without a global or a database round trip.

[Adding it](#adding-it) &nbsp;·&nbsp; [Sharing a value across one request](#sharing-a-value-across-one-request) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add service globals
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

`Globals` takes no configuration, so it needs no `bootstrap.php` entry at all. It is built the first time something asks for it:

```php
$globals = $plugin->get( Globals::class );

// Or, from any service, module, command or action:
public Globals $globals;   // injected before your code runs
```

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

How you reach a module, always: building one boots it, so the cost belongs at the call rather than hidden in a property declaration. Also how you reach a service you look up by a name computed at runtime.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```

## See also

- [`Service`](../service.md) — what every service inherits
- [`wp zt add service globals`](../../commands/add-service.md) — the command that copies it
