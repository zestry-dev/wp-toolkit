<?php

/**
 * REST API: RestRoute base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\RestApi;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Base class for a file-based WordPress REST API route handling one HTTP method.
 *
 * A route file returns a {@see Route} -- built with the static constructor for
 * its HTTP method, wrapping a RestRoute instance, and declaring the namespace
 * version and URL pattern as plain strings (see {@see RestApi} for the full
 * convention).
 *
 * The module wires the instance, binds its `#[RequestArgument]` properties
 * (see {@see \Zestry\WPToolkit\Services\Request\Attributes\RequestArgument}) from the
 * request, and registers it with `register_rest_route()`. A response
 * `schema()` is published only if you override it.
 *
 * So a route file describes itself -- permission, bound parameters -- and
 * implements one `handle()`, for the one method its `Route` declares.
 *
 * schema() is a method, not an attribute, unlike RequestArgument:
 * PHP attributes cannot be attached to an anonymous class at all (only a
 * named class declaration), and every route file returns an anonymous class
 * to match AdminPage/AjaxAction/Command's own convention, so a class-level
 * concern like the response schema has to stay a regular overridable method.
 *
 * One file handles exactly one HTTP method, mirroring AjaxAction and AdminPage:
 * each of those is one file for one action or one page, not one file branching
 * on multiple concerns. `get.php` and `post.php` for the same resource are two
 * independent route files, so GET and POST can each declare their own
 * permission_check() and RequestArgument-bound properties without one class
 * accumulating a method per HTTP method per concern. Logic genuinely shared
 * between them — loading the same resource, a repeated validation rule —
 * belongs in a small dependency (a Module, or any typed property) injected
 * into both files, the same way AdminPages and Ajax share Path rather than
 * duplicating path resolution.
 *
 * Authorization is deliberately not optional: permission_check() is abstract,
 * so every route makes an explicit allow/deny decision before handle() runs,
 * the same rule AjaxAction enforces for AJAX actions and for the same reason —
 * an endpoint with no author-visible authorization decision is a bug waiting
 * to ship, not a convenience.
 */
abstract class RestRoute implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * Decide whether the current request is authorized to reach this route.
	 *
	 * Return false (or a WP_Error, which WordPress also accepts here) to reject
	 * the request with a 401/403. A route open to the public should
	 * `return true;` explicitly, so that every route makes a conscious decision
	 * rather than an omission.
	 *
	 * ## Authentication is already done — this is authorization
	 *
	 * WordPress has identified the caller before this runs, so you can rely on
	 * two things and write less:
	 *
	 * - **`current_user_can()` is accurate whichever way the caller signed in.**
	 *   `determine_current_user` has already run, so a login cookie and an
	 *   application password both arrive here as an identified user.
	 *   Application passwords need nothing from you.
	 * - **A cookie-authenticated request without a valid `wp_rest` nonce never
	 *   gets here.** `rest_cookie_check_errors()` has already rejected it with a
	 *   403, so there is no `X-WP-Nonce` left for you to check.
	 *
	 * What remains is the question this method exists to answer: what is this
	 * user allowed to do? `current_user_can()` is usually the whole answer, and
	 * returning its `false` gets you the right status without further work.
	 * {@see deny()} is for when you want to say why, and
	 * {@see is_application_password()} for a route that needs to turn a
	 * long-lived credential away.
	 *
	 * ## Your arguments are not bound yet
	 *
	 * Every `#[RequestArgument]` property is still uninitialized here. WordPress
	 * runs this before it validates arguments, so binding first would hand a
	 * permission check values nothing had checked. Read what you need from
	 * `$request` in this method, and `$this->id` from `handle()` onwards:
	 *
	 *     public function permission_check( WP_REST_Request $request ): bool|\WP_Error {
	 *         return current_user_can( 'edit_post', (int) $request['id'] );
	 *     }
	 *
	 * Touching `$this->id` here is a fatal, not a wrong answer. An ability is
	 * the other way round -- there the input is bound first -- and the table on
	 * the Arguments page compares the three surfaces.
	 *
	 * ## A public route is public to the internet
	 *
	 * `return true;` means anyone can call this, signed in or not. WordPress
	 * mints a nonce for a logged-out visitor under user id 0, so it is shared by
	 * every logged-out visitor and proves nothing: a nonce is not what makes an
	 * anonymous endpoint safe.
	 *
	 * What does: validate and sanitize every input rather than trusting the
	 * shape, rate-limit by IP or by some other key you control, and carry your
	 * own per-request token if you need to know a submission came from a page
	 * you rendered. Treat everything reaching such a route as hostile.
	 *
	 * @param WP_REST_Request $request The current REST request.
	 * @return bool|\WP_Error True to allow the request, false or a WP_Error to reject it.
	 */
	abstract public function permission_check( WP_REST_Request $request ): bool|\WP_Error;

	/**
	 * Handle the request.
	 *
	 * Runs only after permission_check() has allowed the request and, if this
	 * route has any `#[RequestArgument]`-attributed properties (see
	 * {@see \Zestry\WPToolkit\Services\Request\Attributes\RequestArgument}), after WordPress has
	 * validated and sanitized their values and RestApi has bound them onto
	 * those properties — so this method can read `$this->id` for a bound
	 * parameter instead of reaching into `$request` for it. Implementations
	 * return a WP_REST_Response (or a WP_Error, which WordPress converts to an
	 * error response using its `status` data).
	 *
	 * @param WP_REST_Request $request The current REST request.
	 * @return WP_REST_Response|\WP_Error The response to send.
	 */
	abstract public function handle( WP_REST_Request $request ): WP_REST_Response|\WP_Error;

	/**
	 * Describe the shape of this route's response, conforming to JSON Schema.
	 *
	 * Abstract, like permission_check(), so a route makes a conscious decision
	 * rather than silently inheriting a default — return null explicitly to
	 * publish no schema. This documents the RESPONSE (what handle() returns),
	 * unrelated to a RequestArgument-bound property's own validation (what a
	 * REQUEST accepts). WordPress exposes a non-null return through REST
	 * discovery: a client can retrieve it with an OPTIONS request or
	 * `?context=help`, the same mechanism
	 * {@see \WP_REST_Controller::get_item_schema()} provides for core routes.
	 *
	 * @return array<string, mixed>|null A JSON Schema description of the response, or null to publish none.
	 */
	abstract public function schema(): ?array;

	/**
	 * Refuse the request, at the status that tells a client what to do next.
	 *
	 * The status comes from `rest_authorization_required_code()`: **401 when
	 * nobody is logged in, 403 when someone is.** That difference is not
	 * cosmetic — 401 tells a client to authenticate and retry, 403 tells it not
	 * to bother.
	 *
	 * Returning bare `false` gives the same status with WordPress's generic
	 * message; use this when naming the reason is more helpful.
	 *
	 * Give a `$code` when a client has to tell your refusals apart. **Messages
	 * are translated, so a client cannot branch on them** — the code is the only
	 * stable thing in the response:
	 *
	 *     return $this->deny( __( 'Your trial has ended.', 'my-plugin' ), 'trial_expired' );
	 *     // {"code":"trial_expired","message":"Your trial has ended.", ...}
	 *
	 * Codes are **not** prefixed with your plugin slug, unlike the hooks and
	 * options this toolkit registers. Those share one global namespace and
	 * genuinely collide; an error code appears only in your own route's
	 * response, and that route already sits under your slug. The default is
	 * core's own `rest_forbidden` for the same reason — a client written to
	 * handle it generically should keep working against your API.
	 *
	 * Pick something specific to your API for a custom one, so it does not
	 * shadow a core code that means something else.
	 *
	 * @param string $message Why the request is refused, already translated.
	 * @param string $code    Distinguishes this refusal from your others.
	 * @return \WP_Error An error carrying that message and code at the right status.
	 */
	protected function deny( string $message, string $code = 'rest_forbidden' ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array( 'status' => \rest_authorization_required_code() )
		);
	}

	/**
	 * Whether an application password authenticated this request.
	 *
	 * Use it to *refuse* one — supporting them needs nothing from you. An
	 * application password is long-lived and often sits in a script, so a route
	 * that changes credentials or billing may want a present human:
	 *
	 *     if ( $this->is_application_password() ) {
	 *         return $this->deny( __( 'This action requires an interactive session.', 'my-plugin' ) );
	 *     }
	 *
	 * @return bool True when the request authenticated with an application password.
	 */
	protected function is_application_password(): bool {
		return null !== $this->get_application_password_uuid();
	}

	/**
	 * The UUID of the application password that authenticated this request.
	 *
	 * Identifies *which* credential was used, so you can log or revoke it. Null
	 * when the request did not use one.
	 *
	 * @return string|null The password's UUID, or null.
	 */
	protected function get_application_password_uuid(): ?string {
		if ( ! \function_exists( 'rest_get_authenticated_app_password' ) ) {
			return null;
		}

		$uuid = \rest_get_authenticated_app_password();

		return \is_string( $uuid ) ? $uuid : null;
	}
}
