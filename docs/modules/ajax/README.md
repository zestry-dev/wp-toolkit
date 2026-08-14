<!--
    Generated from src/Modules/Ajax/Ajax.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Ajax

Discovers `resources/actions/` &nbsp;·&nbsp; Each file returns [`AjaxAction`](ajax-action.md) &nbsp;·&nbsp; Dependencies [`path`](../path/), [`request`](../request/)

Discovers plugin AJAX actions and registers their WordPress hooks.

An action directory contains PHP files named after the action, such as `resources/actions/save-profile.php`. Each file returns an AjaxAction instance. During an AJAX request the module maps that file to `wp_ajax_{plugin}-{action}` and conditionally maps the equivalent `wp_ajax_nopriv_` hook.

## Reach for a REST route first

For something new, a `Route` is usually the better answer, and the difference is bigger than the transport:

- **A route publishes what it accepts.** Both take `#[RequestArgument]` on a
typed property, validated and bound before your code runs — but only a route turns that declaration into a schema a client can read before calling it. An action's contract is visible to whoever wrote both ends, and to nobody else.
- **A route is callable by anything.** `admin-ajax.php` answers one URL with
an `action` parameter and is a WordPress-shaped convention; a route is an ordinary HTTP endpoint with a versioned namespace.
- **A route composes with the rest of this toolkit.** An operation worth
exposing more than once belongs in an `Ability`, which is reachable over REST, from an AI agent, and from your own PHP at once. An AJAX action is the one shape that cannot join in.

What is left for this module is real, though: an admin screen whose JavaScript already posts to `admin-ajax.php`, an existing plugin's action you are extending, or WordPress's own heartbeat. Choose it because something already speaks it, rather than because a form has to submit somewhere.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing an AjaxAction](#writing-an-ajaxaction) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add ajax
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `Ajax` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'init' => array(
        Ajax::class,
    ),
);
```

## Changing the defaults

`Ajax` takes no configuration. The entry above is all it needs — reach it with `$this->with( Ajax::class )` from any module or discovered file, or `$plugin->get( Ajax::class )` from your entry file.

## Writing an AjaxAction

A file in `resources/actions/` returns an [`AjaxAction`](ajax-action.md) instance, which `wp zt make action <name>` generates.

## Constants

### `FORBIDDEN_ERROR_CODE`

```php
const FORBIDDEN_ERROR_CODE = 'forbidden';
```

WP_Error code used for every rejected AJAX request — a failed capability check, a failed nonce check, and an unprivileged request to an action that does not allow it all reject with this same code, only the message differs.

### `ACTIONS_ROOT`

```php
const ACTIONS_ROOT = 'resources/actions';
```

Default plugin-relative directory of action files.

## Methods

### `is_ajax_request()`

Determine whether the current request is handled by admin-ajax.php.

```php
public function is_ajax_request(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | True for an AJAX request |
| **Throws** | — |

Rather than reading the `DOING_AJAX` constant directly, so the result passes through the `wp_doing_ajax` filter that tests and alternative AJAX endpoints rely on.

<br>

### `get_action_slug( $name )`

Build the globally namespaced WordPress action name.

```php
public function get_action_slug( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local action name |
| **Return** | The namespaced action name |
| **Throws** | — |

<br>

### `create_action_nonce( $name, $context )`

Create a nonce for an action and optional context.

```php
public function create_action_nonce( string $name, string|int|null $context = null ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local action name<br>`$context` — The optional nonce context |
| **Return** | The generated nonce |
| **Throws** | — |

`$context` accepts an int as well as a string so a bare resource identifier — a post ID, most often — can be passed straight through from `AjaxAction::get_nonce_context()` or from a request argument without being cast first. It is concatenated onto the action name either way, so `123` and `'123'` produce the same nonce.

Only `null` and `''` mean "no context". A context of `0` scopes the nonce like any other value.

<br>

### `get_action_url( $name, $args, $context_key )`

Build the admin-ajax.php URL for an action.

```php
public function get_action_url( string $name, array $args = array(), ?string $context_key = null ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local action name<br>`$args` — Request arguments<br>`$context_key` — Argument key whose value scopes the nonce |
| **Return** | The action URL |
| **Throws** | `InvalidArgumentException` — When the named argument is not a string or an int |

Pass `$context_key` to scope the nonce to one of the request arguments you are already sending: `get_action_url( 'edit', array( 'post_id' => 123 ), 'post_id' )` mints the nonce against `123`, which is the value the action's `AjaxAction::get_nonce_context()` then has to return for verification to pass. A string or an int both work.

<br>

### `verify_action_nonce( $name, $context )`

Verify the nonce supplied with the current request.

```php
public function verify_action_nonce( string $name, string|int|null $context = null ): int|false
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local action name<br>`$context` — The optional nonce context |
| **Return** | The nonce age or false when invalid |
| **Throws** | — |

`$context` must be the same value the nonce was minted with, and accepts the same `string|int|null` an action's `AjaxAction::get_nonce_context()` returns. As when minting, only `null` and `''` mean "no context" — see `create_action_nonce()`.

<br>

### `get_slug_of( $action )`

This action's slug, from the file it was discovered in.

```php
public function get_slug_of( AjaxAction $action ): string
```

|  | Details |
|---|---|
| **Parameters** | `$action` — The instance to look up |
| **Return** | The `{plugin-slug}-{name}` slug it is registered under |
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module |

<br>

### `get_discovered_actions()`

Every discovered action, keyed by its local name.

```php
public function get_discovered_actions(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Wired instances keyed by local name |
| **Throws** | `DiscoveryException` — When a file returns the wrong value |

Kept rather than rebuilt, so `get_slug_of()` compares against the same instances a caller is holding.

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

- [`AjaxAction`](ajax-action.md) — what a file in `resources/actions/` returns
- [`path`](../path/) — copied in alongside this one
- [`request`](../request/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add ajax`](../../commands/add.md) — the command that copies it
