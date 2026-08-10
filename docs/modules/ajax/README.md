<!--
    Generated from src/Modules/Ajax/Ajax.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Ajax

Discovers `actions/` &nbsp;·&nbsp; Each file returns [`AjaxAction`](ajax-action.md) &nbsp;·&nbsp; Dependencies [`path`](../../services/path/), [`request`](../../services/request/)

Discovers plugin AJAX actions and registers their WordPress hooks.

An action directory contains PHP files named after the action, such as `actions/save-profile.php`. Each file returns an AjaxAction instance. During an AJAX request the module maps that file to `wp_ajax_{plugin}-{action}` and conditionally maps the equivalent `wp_ajax_nopriv_` hook.

## Reach for a REST route first

For something new, a `Route` is usually the better answer, and the difference is bigger than the transport:

- **A route publishes what it accepts.** Both take `#[RequestArgument]` on a
typed property, validated and bound before your code runs — but only a route turns that declaration into a schema a client can read before calling it. An action's contract is visible to whoever wrote both ends, and to nobody else.
- **A route is callable by anything.** `admin-ajax.php` answers one URL with
an `action` parameter and is a WordPress-shaped convention; a route is an ordinary HTTP endpoint with a versioned namespace.
- **A route composes with the rest of this toolkit.** An operation worth
exposing more than once belongs in an `Ability`, which is reachable over REST, from an AI agent, and from your own PHP at once. An AJAX action is the one shape that cannot join in.

What is left for this module is real, though: an admin screen whose JavaScript already posts to `admin-ajax.php`, an existing plugin's action you are extending, or WordPress's own heartbeat. Choose it because something already speaks it, rather than because a form has to submit somewhere.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing an AjaxAction](#writing-an-ajaxaction) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zestry add module ajax
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Ajax` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zestry add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zestry doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    Ajax::class,
);
```

## Changing the defaults

Register an initializer only to point the module at a non-default directory.

```php
// bootstrap.php
return array(
    Ajax::class => static function ( Ajax $ajax ): void {
        $ajax->set_actions_root( 'ajax/actions' );
    },
);
```

## Writing an AjaxAction

A file in `actions/` returns an [`AjaxAction`](ajax-action.md) instance, which `wp zestry make action <name>` generates.

## Constants

### `FORBIDDEN_ERROR_CODE`

```php
const FORBIDDEN_ERROR_CODE = 'forbidden';
```

WP_Error code used for every rejected AJAX request — a failed capability check, a failed nonce check, and an unprivileged request to an action that does not allow it all reject with this same code, only the message differs.

### `DEFAULT_ACTIONS_ROOT`

```php
const DEFAULT_ACTIONS_ROOT = 'actions';
```

Default plugin-relative directory of action files.

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

### `set_actions_root( $actions_root )`

Set the plugin-relative directory that contains action files.

```php
public function set_actions_root( string $actions_root ): void
```

|  | Details |
|---|---|
| **Parameters** | `$actions_root` — Plugin-relative directory of action files |
| **Return** | — |
| **Throws** | `DiscoveryException` — When the directory named here does not exist at boot, or a file beneath it returns something other than an AjaxAction instance |

Call this from the module initializer before the plugin boots the module to override the default `actions` directory.

Naming a directory is what makes its absence fatal. Discovery runs at `init`, and if the directory you name here is not there it throws a `DiscoveryException` then — so a typo in your initializer takes the request down rather than registering nothing and leaving you to wonder why your actions never fire. The *default* `actions` directory being absent is deliberately not an error: a plugin that has not written its first action yet should still boot.

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
| **Throws** | `DiscoveryException` — When a directory named by set_actions_root() does not exist, or a file returns the wrong value |

Kept rather than rebuilt, so `get_slug_of()` compares against the same instances a caller is holding.

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

- [`AjaxAction`](ajax-action.md) — what a file in `actions/` returns
- [`path`](../../services/path/) — copied in alongside this one
- [`request`](../../services/request/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zestry add module ajax`](../../commands/add-module.md) — the command that copies it
