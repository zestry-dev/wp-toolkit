<!--
    Generated from src/Services/Transients.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Transients

Reads and writes transients, under keys namespaced to your plugin.

WordPress decides where a transient actually lives: an external object cache when the site has one, the options table when it does not. You get the same API either way.

Keys are prefixed with your plugin slug, which matters more here than it looks — every plugin's transients share one namespace, so an unprefixed `config` is a collision waiting to happen.

Values round-trip exactly as you stored them, `false` and `null` included, so storing "we asked and there was nothing" works — `has()` is what tells that apart from never having asked.

**Treat every value as optional.** A transient can disappear before its expiry: an object cache evicts under memory pressure, and a deploy may clear it entirely. Anything you cannot recompute belongs in `Options`, not here. Anything that only needs to survive the current request is cheaper in `Globals`.

[Adding it](#adding-it) &nbsp;·&nbsp; [Storing something expensive to work out](#storing-something-expensive-to-work-out) &nbsp;·&nbsp; [Reading and writing directly](#reading-and-writing-directly) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zestry add service transients
```

## Storing something expensive to work out

```php
public Transients $transients;

public function get_summary(): array {
    if ( ! $this->transients->has( 'summary' ) ) {
        $this->transients->set( 'summary', $this->build_summary(), HOUR_IN_SECONDS );
    }

    return $this->transients->get( 'summary' );
}
```

## Reading and writing directly

```php
$this->transients->set( 'rates', $rates, 15 * MINUTE_IN_SECONDS );

$rates = $this->transients->get( 'rates', array() );

$this->transients->delete( 'rates' );
```

## Changing the defaults

`Transients` takes no configuration, so it needs no `bootstrap.php` entry at all. It is built the first time something asks for it:

```php
$transients = $plugin->get( Transients::class );

// Or, from any service, module, command or action:
public Transients $transients;   // injected before your code runs
```

## Constants

### `MAX_KEY_LENGTH`

```php
const MAX_KEY_LENGTH = 172;
```

The longest a key may be once prefixed.

## Methods

### `get( $key, $fallback )`

Read a stored value.

```php
public function get( string $key, mixed $fallback = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$key` — Your own key, unprefixed<br>`$fallback` — Returned when nothing is stored. Defaults to null |
| **Return** | The stored value, or `$fallback` |
| **Throws** | `InvalidArgumentException` — When the key is empty or too long |

<br>

### `set( $key, $value, $ttl )`

Store a value.

```php
public function set( string $key, mixed $value, int $ttl = 0 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$key` — Your own key, unprefixed<br>`$value` — Anything serializable<br>`$ttl` — Seconds to keep it. 0 means no expiry, which still does not make it permanent |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the key is empty or too long |

Any serializable value, `false` and `null` included.

<br>

### `has( $key )`

Whether a value is stored under this key.

```php
public function has( string $key ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$key` — Your own key, unprefixed |
| **Return** | True when something is stored, whatever its value |
| **Throws** | `InvalidArgumentException` — When the key is empty or too long |

Distinct from `null !== get()`, which cannot tell a stored `null` from a missing key.

<br>

### `delete( $key )`

Delete a stored value.

```php
public function delete( string $key ): void
```

|  | Details |
|---|---|
| **Parameters** | `$key` — Your own key, unprefixed |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the key is empty or too long |

Deleting something that was never there is not an error; it just returns false.

There is deliberately no "delete everything" companion. Every plugin's transients share one object-cache group, and that cache offers no way to list keys — so such a method could only either miss everything on a site with an object cache, or delete other plugins' entries along with yours. Track the keys you need to clear, or give them a short enough `$ttl` that clearing is unnecessary.

<br>

### `is_stored( $stored )`

Whether what came back from WordPress is one of ours.

```php
protected function is_stored( mixed $stored ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$stored` — Whatever `get_transient()` returned |
| **Return** | True when it is a stored value rather than a miss |
| **Throws** | — |

<br>

### `get_prefixed_key( $key )`

Your key with the plugin's prefix, checked for length.

```php
protected function get_prefixed_key( string $key ): string
```

|  | Details |
|---|---|
| **Parameters** | `$key` — Your own key, unprefixed |
| **Return** | The prefixed key WordPress will store under |
| **Throws** | `InvalidArgumentException` — When the key is empty or too long |

Loud rather than silent: an over-long transient key is truncated by the database, so two different keys can quietly become one and start returning each other's values.

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
- [`wp zestry add service transients`](../../commands/add-service.md) — the command that copies it
