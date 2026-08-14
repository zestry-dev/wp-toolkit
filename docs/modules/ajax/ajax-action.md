<!--
    Generated from src/Modules/Ajax/AjaxAction.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# AjaxAction

[Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for file-based AJAX action handlers.

Action files return a subclass instance. The Ajax module injects the shared plugin, checks authorization via `capability_check()`, verifies the nonce when required, and calls `handle()`. For example, an action named `save-profile.php` may return `current_user_can( 'edit_user', $id )` from `capability_check()`, override `get_nonce_context()` with the profile ID, and implement `handle()`.

Authorization is deliberately not optional: `capability_check()` is abstract, so every action must make an explicit allow/deny decision. A nonce proves the request was intended (anti-CSRF); it does not prove the user is permitted, so the two checks are separate and both run before `handle()`.

A file at `actions/save-profile.php` registers as `wp_ajax_{plugin}-save-profile` (see `Ajax::get_action_slug()`). `wp zt make action <name>` generates a starting point. The page that triggers this action gets its URL (with a nonce attached) from the Ajax module: `$ajax->get_action_url( 'save-profile' )`. The request is rejected before `handle()` runs if `capability_check()` returns false, or (since `is_nonce_required()` defaults to true) if the request's nonce does not verify.

## What it takes

Declare each input with `RequestArgument` on a typed property, the same way a route and an ability do, and read it as `$this->post_id`:

```php
#[RequestArgument( 'Which post to act on.' )]
public int $post_id;
```

The value is checked against the property's type and bound before `capability_check()` runs, so a capability decision can name what it is deciding about: `current_user_can( 'edit_post', $this->post_id )`. An argument that does not fit, or a required one left out, is answered with a 400 before any of your code runs.

It is also unslashed. WordPress hands an AJAX hook `$_REQUEST` exactly as it arrived, slashes and all, where it unslashes a REST route's parameters first — so an action reading the request itself has to remember `wp_unslash()`, and one that declares its arguments does not.

An action that declares none reads the request itself, and nothing here touches it.

## Generated starting point

[`wp zt make action <name>`](../../commands/make-action.md) writes this file:

```php
<?php
/**
 * example AJAX action.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\Ajax\AjaxAction;
// use Acme\Plugin\Core\Modules\Request\Attributes\RequestArgument;

return new class() extends AjaxAction {

	// The action is this file's name -- {plugin-slug}-example. That is the
	// `action` your JavaScript posts, so renaming the file leaves every caller
	// still sending the old one answered with WordPress's bare `0`.

	// What this action takes, declared once. The value is checked against the
	// type, unslashed, and bound before the methods below run -- so read
	// $this->post_id rather than $_POST['post_id']. Uncomment to use:
	//
	// #[RequestArgument( 'Which post to act on.' )]
	// public int $post_id;


	// Authorization check, run before the nonce check and before handle().
	// Return false to reject the request with a 403 -- a nonce proves the
	// request was intended, not that the user is permitted, so both checks
	// run independently. Replace 'manage_options' with whatever capability
	// (optionally scoped to a specific resource, e.g. current_user_can(
	// 'edit_post', $post_id )) this action actually requires.
	public function capability_check(): bool {
		return \current_user_can( 'manage_options' );
	}

	// false (default): only logged-in users may call this action at all --
	// wp_ajax_nopriv_ requests are rejected with a 403 before capability_check()
	// even runs. Return true only for an endpoint meant for logged-out
	// visitors, and if you do: a nonce provides NO protection for it (WordPress
	// mints anonymous nonces under user id 0, effectively shared by every
	// logged-out visitor), so treat every request to it as hostile input.
	public function allow_not_privileged(): bool {
		return false;
	}

	// Optional value that scopes this action's nonce to a specific resource
	// (e.g. return a post ID) so the nonce cannot be reused for a different
	// one. null scopes the nonce to just the action name. Whatever is
	// returned here must also be passed when creating the nonce via
	// $ajax->create_action_nonce( $action_name, $context ).
	public function get_nonce_context(): string|int|null {
		return null;
	}

	// true (default): the request must carry a valid nonce (verified after
	// capability_check(), before handle()) or it is rejected with a 403.
	// Nonces protect logged-in users against CSRF; they do nothing for a
	// nopriv-allowed endpoint (see allow_not_privileged()) -- return false
	// only for a deliberately nonce-free endpoint such as a public webhook.
	public function is_nonce_required(): bool {
		return true;
	}

	// Runs only once both checks above pass. Send a response with
	// wp_send_json_success()/wp_send_json_error() -- both end the request.
	public function handle(): void {
		\wp_send_json_success();
	}
};
```

## You must implement

These 2 methods are abstract: a subclass that does not declare all of them will not load.

### `capability_check()`

Decide whether the current request is authorized to run this action.

```php
abstract public function capability_check(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | True when the current user may run the action |
| **Throws** | — |

Runs before the nonce check and before `handle()`. Return false to reject the request with a 403. Typical implementations return a `current_user_can()` call, optionally with a resource id for meta capabilities. This is required so authorization is a conscious, per-action decision rather than an omission; an action open to any logged-in user should `return true;` explicitly.

<br>

### `handle()`

Handles the AJAX request after authorization and nonce validation.

```php
abstract public function handle(): void
```

Implementations should send a response or use WordPress response helpers.

## Methods you can use

### `allow_not_privileged()`

Determine whether logged-out visitors may invoke the action.

```php
public function allow_not_privileged(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Whether public requests may execute this action |
| **Throws** | — |

Return true only for endpoints designed for unauthenticated requests; the default keeps the generated `wp_ajax_nopriv_` hook protected with a 403.

Security note: a nonce does NOT protect an unauthenticated endpoint. For a logged-out visitor WordPress mints nonces under user id 0 with an empty session token, so a nonce for a given action is effectively shared by every anonymous visitor within the tick window and any attacker can generate one by loading a page that embeds get_action_url(). A public action must therefore treat every request as hostile: validate and sanitize all input, rate-limit, and use its own per-request token where genuine CSRF protection is needed.

<br>

### `get_nonce_context()`

Return the optional value used to scope the action nonce.

```php
public function get_nonce_context(): string|int|null
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Nonce context, or null for the default scope |
| **Throws** | — |

Returning a resource identifier, such as a post ID, creates a nonce that cannot be reused for a different resource. A raw int is accepted as-is — `return (int) $_REQUEST['post_id'];` needs no cast, since the whole `string|int|null` range survives dispatch into `Ajax::verify_action_nonce()`. Whatever you return has to match the value the nonce was minted with, either the third argument to `Ajax::create_action_nonce()` or the argument `Ajax::get_action_url()`'s `$context_key` names. Return null to scope the nonce to the action name alone.

<br>

### `is_nonce_required()`

Determine whether the dispatcher must validate a nonce.

```php
public function is_nonce_required(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Whether a request nonce is required |
| **Throws** | — |

Nonce verification protects state-changing endpoints against CSRF for authenticated users. It provides no CSRF protection on the public (`wp_ajax_nopriv_`) path — see allow_not_privileged() — so requiring a nonce on an action that also allows unauthenticated access is a false sense of security. Override this only for deliberately nonce-free endpoints such as a public webhook.

<br>

### `get_slug()`

The action name this is dispatched under.

```php
final public function get_slug(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Your filename with the plugin slug prefixed, since `wp_ajax_*` is one namespace shared by every plugin: `actions/save-draft.php` answers to `{plugin-slug}-save-draft`. This is the value JavaScript sends as its `action` parameter.

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

A module that names a `boots_on` also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.

<br>

### `is_enabled()`

*Inherited from [`WithEnablement`](../../kernel/with-enablement.md).*

Whether this should be registered at all.

```php
public function is_enabled(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

Called once, after the instance is wired and before anything is registered. Return false and nothing happens: no hook is bound and no WordPress registration is made.

The default is true, so a file that says nothing registers — being on disk is the convention, and this is the exception to it.

It registers nothing either way.
