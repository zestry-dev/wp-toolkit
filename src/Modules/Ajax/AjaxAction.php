<?php

/**
 * AJAX API: AjaxAction base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Ajax;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * Base class for file-based AJAX action handlers.
 *
 * Action files return a subclass instance. The Ajax module injects the shared
 * plugin, checks authorization via `capability_check()`, verifies the nonce when
 * required, and calls `handle()`. For example, an action named `save-profile.php`
 * may return `current_user_can( 'edit_user', $id )` from `capability_check()`,
 * override `get_nonce_context()` with the profile ID, and implement `handle()`.
 *
 * Authorization is deliberately not optional: `capability_check()` is abstract,
 * so every action must make an explicit allow/deny decision. A nonce proves the
 * request was intended (anti-CSRF); it does not prove the user is permitted, so
 * the two checks are separate and both run before `handle()`.
 *
 * A file at `actions/save-profile.php` registers as
 * `wp_ajax_{plugin}-save-profile` (see {@see Ajax::get_action_slug()}).
 * `wp zt make action <name>` generates a starting point.
 * The page that triggers this action gets its URL (with a nonce attached) from
 * the Ajax module: `$ajax->get_action_url( 'save-profile' )`. The request is
 * rejected before `handle()` runs if `capability_check()` returns false, or (since
 * `is_nonce_required()` defaults to true) if the request's nonce does not verify.
 *
 * ## What it takes
 *
 * Declare each input with
 * {@see \Zestry\WPToolkit\Services\Request\Attributes\RequestArgument} on a typed
 * property, the same way a route and an ability do, and read it as
 * `$this->post_id`:
 *
 * ```php
 * #[RequestArgument( 'Which post to act on.' )]
 * public int $post_id;
 * ```
 *
 * The value is checked against the property's type and bound before
 * `capability_check()` runs, so a capability decision can name what it is
 * deciding about: `current_user_can( 'edit_post', $this->post_id )`. An argument
 * that does not fit, or a required one left out, is answered with a 400 before
 * any of your code runs.
 *
 * It is also unslashed. WordPress hands an AJAX hook `$_REQUEST` exactly as it
 * arrived, slashes and all, where it unslashes a REST route's parameters first —
 * so an action reading the request itself has to remember `wp_unslash()`, and
 * one that declares its arguments does not.
 *
 * An action that declares none reads the request itself, and nothing here
 * touches it.
 *
 * @stub action.php.stub
 */
abstract class AjaxAction implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * Decide whether the current request is authorized to run this action.
	 *
	 * Runs before the nonce check and before `handle()`. Return false to reject
	 * the request with a 403. Typical implementations return a `current_user_can()`
	 * call, optionally with a resource id for meta capabilities. This is required
	 * so authorization is a conscious, per-action decision rather than an omission;
	 * an action open to any logged-in user should `return true;` explicitly.
	 *
	 * @return bool True when the current user may run the action.
	 */
	abstract public function capability_check(): bool;

	/**
	 * Determine whether logged-out visitors may invoke the action.
	 *
	 * Return true only for endpoints designed for unauthenticated requests; the
	 * default keeps the generated `wp_ajax_nopriv_` hook protected with a 403.
	 *
	 * Security note: a nonce does NOT protect an unauthenticated endpoint. For a
	 * logged-out visitor WordPress mints nonces under user id 0 with an empty
	 * session token, so a nonce for a given action is effectively shared by every
	 * anonymous visitor within the tick window and any attacker can generate one by
	 * loading a page that embeds get_action_url(). A public action must therefore
	 * treat every request as hostile: validate and sanitize all input, rate-limit,
	 * and use its own per-request token where genuine CSRF protection is needed.
	 *
	 * @return bool Whether public requests may execute this action.
	 */
	public function allow_not_privileged(): bool {
		return false;
	}

	/**
	 * Return the optional value used to scope the action nonce.
	 *
	 * Returning a resource identifier, such as a post ID, creates a nonce that
	 * cannot be reused for a different resource. A raw int is accepted as-is --
	 * `return (int) $_REQUEST['post_id'];` needs no cast, since the whole
	 * `string|int|null` range survives dispatch into
	 * {@see Ajax::verify_action_nonce()}. Whatever you return has to match the
	 * value the nonce was minted with, either the third argument to
	 * {@see Ajax::create_action_nonce()} or the argument
	 * {@see Ajax::get_action_url()}'s `$context_key` names. Return null to
	 * scope the nonce to the action name alone.
	 *
	 * @return string|int|null Nonce context, or null for the default scope.
	 */
	public function get_nonce_context(): string|int|null {
		return null;
	}

	/**
	 * Determine whether the dispatcher must validate a nonce.
	 *
	 * Nonce verification protects state-changing endpoints against CSRF for
	 * authenticated users. It provides no CSRF protection on the public
	 * (`wp_ajax_nopriv_`) path — see allow_not_privileged() — so requiring a nonce
	 * on an action that also allows unauthenticated access is a false sense of
	 * security. Override this only for deliberately nonce-free endpoints such as a
	 * public webhook.
	 *
	 * @return bool Whether a request nonce is required.
	 */
	public function is_nonce_required(): bool {
		return true;
	}

	/**
	 * The action name this is dispatched under.
	 *
	 * Your filename with the plugin slug prefixed, since `wp_ajax_*` is one
	 * namespace shared by every plugin: `actions/save-draft.php` answers to
	 * `{plugin-slug}-save-draft`. This is the value JavaScript sends as its
	 * `action` parameter.
	 *
	 * @return string
	 */
	final public function get_slug(): string {
		return $this->get_plugin()->get( Ajax::class )->get_slug_of( $this );
	}

	/**
	 * Handles the AJAX request after authorization and nonce validation.
	 *
	 * Implementations should send a response or use WordPress response helpers.
	 *
	 * @return void
	 */
	abstract public function handle(): void;
}
