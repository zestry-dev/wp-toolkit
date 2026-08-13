<!--
    Generated from src/Services/Cookie.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Cookie

Dependencies [`transients`](../transients/)

Reads and writes this plugin's cookies, encrypted when you want them to be.

Every name is prefixed with your plugin slug, so `set( 'seen_tour', '1' )` writes `acme_plugin_seen_tour` and cannot collide with WordPress's own cookies or another plugin's. `get_cookie_name()` hands back the full name for the places you need it verbatim — JavaScript, or a caching plugin's exclusion list.

The defaults are the safe ones: `HttpOnly`, `Secure` whenever the site is on HTTPS, and `SameSite=Lax`, which is what lets a cookie survive the redirect that follows a form post.

Three pairs, in order of how much they do:

| Write | Read | Holds |
|---|---|---|
| `set()` | `get()` | a string, as-is |
| `set_encrypted()` | `get_encrypted()` | any value, unreadable to the browser |
| `set_flash()` | `get_flash()` | the same, for exactly one more request |

[Adding it](#adding-it) &nbsp;·&nbsp; [Reading and writing](#reading-and-writing) &nbsp;·&nbsp; [Storing something structured](#storing-something-structured) &nbsp;·&nbsp; [Carrying a notice across a redirect](#carrying-a-notice-across-a-redirect) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add service cookie
```

## Reading and writing

A lifetime of `0` is a session cookie, gone when the browser closes.

```php
$cookies = $plugin->get( Cookie::class );

$cookies->set( 'seen_tour', '1', WEEK_IN_SECONDS );

if ( '1' === $cookies->get( 'seen_tour' ) ) {
    return;
}

$cookies->forget( 'seen_tour' );
```

## Storing something structured

Serialized and encrypted on the way out, decrypted and restored on the way back, so an array survives the round trip and the browser sees ciphertext.

```php
$cookies->set_encrypted( 'cart', array( 'items' => array( 12, 40 ) ), DAY_IN_SECONDS );

$cart = $cookies->get_encrypted( 'cart', array( 'items' => array() ) );
```

## Carrying a notice across a redirect

A form handler redirects because the browser's current request is still the POST — a refresh resubmits it. But the redirect throws away everything the handler knew, which is why a saved page so often comes back with `?updated=1` in the URL: a query argument is the crude way to say one thing survived. It is also bookmarkable, so the notice reappears on every refresh, for a save that happened once.

`set_flash()` is the less crude way. The value survives exactly one request, is read once, and never reaches the URL.

```php
public function handle_submit(): void {
    $this->options->set( 'threshold', $this->threshold );

    $this->cookies->set_flash( array( 'saved' => __( 'Settings saved.', 'acme-plugin' ) ) );

    wp_safe_redirect( $this->get_page_url() );
    exit;
}

public function render(): void {
    $this->view( 'admin-pages/settings', array(
        'notice' => $this->cookies->get_flash( array() )['saved'] ?? '',
    ) );
}
```

`public Cookie $cookies;` on the page is all the wiring it needs — a typed property is injected when the page is wired.

> [!IMPORTANT]
> Encryption stops the browser reading or forging the contents, and does nothing about size: browsers cap a cookie near 4 KB and drop a longer one without saying so. `set_flash()` handles that for you by moving a large payload into a transient. `set_encrypted()` cannot — a cookie's lifetime is yours to choose and a transient could not honour it — so it refuses and says so, past `MAX_COOKIE_BYTES`.

## Changing the defaults

`Cookie` takes no configuration, so it needs no `bootstrap.php` entry at all. It is built the first time something asks for it:

```php
$cookie = $plugin->get( Cookie::class );

// Or, from any service, module, command or action:
public Cookie $cookie;   // injected before your code runs
```

## Constants

### `FLASH_TTL`

```php
const FLASH_TTL = 30;
```

How long a flashed value waits to be read, in seconds.

### `FLASH_COOKIE`

```php
const FLASH_COOKIE = 'flash';
```

The cookie a flashed value travels in.

### `MAX_COOKIE_BYTES`

```php
const MAX_COOKIE_BYTES = 3072;
```

The largest value this will ask a browser to hold, in bytes.

## Methods

### `get( $name, $fallback )`

Read one of this plugin's cookies.

```php
public function get( string $name, ?string $fallback = null ): ?string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local name, without the plugin prefix<br>`$fallback` — Returned when the browser sent no such cookie |
| **Return** | The value, or the fallback |
| **Throws** | — |

<br>

### `has( $name )`

Whether the browser sent one of this plugin's cookies.

```php
public function has( string $name ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local name, without the plugin prefix |
| **Return** | `bool` |
| **Throws** | — |

The way to tell an empty string apart from an absent cookie, which `get()` reports the same when its fallback is null.

<br>

### `set( $name, $value, $lifetime )`

Write one of this plugin's cookies.

```php
public function set( string $name, string $value, int $lifetime = 0 ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local name, without the plugin prefix<br>`$value` — What to store<br>`$lifetime` — Seconds from now; 0 for a session cookie |
| **Return** | Whether the header was sent |
| **Throws** | — |

The value is also put into `$_COOKIE`, so the rest of *this* request reads it back — PHP's own `setcookie()` does not, which is a reliable half hour of debugging for anyone who has not met it before.

<br>

### `forget( $name )`

Delete one of this plugin's cookies.

```php
public function forget( string $name ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local name, without the plugin prefix |
| **Return** | Whether the header was sent |
| **Throws** | — |

<br>

### `set_encrypted( $name, $value, $lifetime )`

Store any value, serialized and encrypted.

```php
public function set_encrypted( string $name, mixed $value, int $lifetime = 0 ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$value` — Anything `maybe_serialize()` can represent<br>`$name` — The local name, without the plugin prefix<br>`$lifetime` — Seconds from now; 0 for a session cookie |
| **Return** | Whether the header was sent |
| **Throws** | — |

The browser holds ciphertext: it cannot read the value and cannot change it without the change being detected on the way back. An array or an object arrives as itself rather than as a string.

<br>

### `get_encrypted( $name, $fallback )`

Read a value written by `set_encrypted()`.

```php
public function get_encrypted( string $name, mixed $fallback = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local name, without the plugin prefix<br>`$fallback` — Returned when there is nothing to read |
| **Return** | The stored value, or the fallback |
| **Throws** | — |

A value that does not authenticate is discarded exactly as an absent one is, and silently: a cookie the browser truncated, a cookie left over from before the salts were rotated, and a cookie somebody edited are one event from here, and none of them is a developer's mistake to warn about.

<br>

### `set_flash( $value, $name )`

Store a value for the next request only.

```php
public function set_flash( mixed $value, string $name = self::FLASH_COOKIE ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$value` — Anything `maybe_serialize()` can represent<br>`$name` — The cookie to travel in, when one flash is not enough |
| **Return** | Whether the header was sent |
| **Throws** | — |

Survives exactly one redirect, encrypted like `set_encrypted()`, and nothing of it reaches the URL.

<br>

### `get_flash( $fallback, $name )`

Take a flashed value, which can be read only once.

```php
public function get_flash( mixed $fallback = null, string $name = self::FLASH_COOKIE ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$fallback` — Returned when nothing was flashed<br>`$name` — The cookie it travelled in |
| **Return** | The flashed value, or the fallback |
| **Throws** | — |

The second call returns the fallback: reading deletes the cookie, so a refresh does not show a notice again for something that already happened. WordPress's own `get_settings_errors()` consumes its transient the same way.

<br>

### `get_cookie_name( $name )`

The full name a cookie is stored under.

```php
public function get_cookie_name( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local name |
| **Return** | The full cookie name |
| **Throws** | — |

Your slug joined to the local name with `_`, the separator WordPress uses for its own cookies. Reach for this wherever the name is needed outside PHP — reading it in JavaScript, or naming it in a caching plugin's exclusion list.

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

Use it to reach something you did not declare a property for — a module you need in one method only, or one you look up by a name computed at runtime. For anything you use throughout the class, declare a typed property instead and let it be injected.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```

## See also

- [`transients`](../transients/) — copied in alongside this one
- [`Service`](../service.md) — what every service inherits
- [`wp zt add service cookie`](../../commands/add-service.md) — the command that copies it
