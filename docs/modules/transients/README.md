<!--
    Generated from src/Modules/Transients.php.
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
wp zt add transients
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Transients` does nothing until something asks, so it goes at the top level — which `wp zt add` writes for you. Left out, the first `with( Transients::class )` throws rather than building it, which [`wp zt doctor`](../../commands/doctor.md) catches before a request does.

```php
// bootstrap.php
return array(
    Transients::class,
);
```

## Storing something expensive to work out

```php
public Transients $transients;

public function get_summary(): array {
    if ( ! $this->with( Transients::class )->has( 'summary' ) ) {
        $this->with( Transients::class )->set( 'summary', $this->build_summary(), HOUR_IN_SECONDS );
    }

    return $this->with( Transients::class )->get( 'summary' );
}
```

## Reading and writing directly

```php
$this->with( Transients::class )->set( 'rates', $rates, 15 * MINUTE_IN_SECONDS );

$rates = $this->with( Transients::class )->get( 'rates', array() );

$this->with( Transients::class )->delete( 'rates' );
```

## Changing the defaults

`Transients` takes no configuration. The entry above is all it needs — reach it with `$this->with( Transients::class )` from any module or discovered file, or `$plugin->get( Transients::class )` from your entry file.

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
- [`wp zt add transients`](../../commands/add.md) — the command that copies it
