<?php

/**
 * AJAX API: Ajax module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Ajax;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Modules\Path;
use Zestry\WPToolkit\Modules\Request\Request;

/**
 * Discovers plugin AJAX actions and registers their WordPress hooks.
 *
 * An action directory contains PHP files named after the action, such as
 * `actions/save-profile.php`. Each file returns an AjaxAction instance. During
 * an AJAX request the module maps that file to `wp_ajax_{plugin}-{action}` and
 * conditionally maps the equivalent `wp_ajax_nopriv_` hook.
 *
 * ## Reach for a REST route first
 *
 * For something new, a {@see \Zestry\WPToolkit\Modules\RestApi\Route} is usually the
 * better answer, and the difference is bigger than the transport:
 *
 * - **A route publishes what it accepts.** Both take `#[RequestArgument]` on a
 *   typed property, validated and bound before your code runs -- but only a
 *   route turns that declaration into a schema a client can read before calling
 *   it. An action's contract is visible to whoever wrote both ends, and to
 *   nobody else.
 * - **A route is callable by anything.** `admin-ajax.php` answers one URL with
 *   an `action` parameter and is a WordPress-shaped convention; a route is an
 *   ordinary HTTP endpoint with a versioned namespace.
 * - **A route composes with the rest of this toolkit.** An operation worth
 *   exposing more than once belongs in an {@see \Zestry\WPToolkit\Modules\Abilities\Ability},
 *   which is reachable over REST, from an AI agent, and from your own PHP at
 *   once. An AJAX action is the one shape that cannot join in.
 *
 * What is left for this module is real, though: an admin screen whose
 * JavaScript already posts to `admin-ajax.php`, an existing plugin's action you
 * are extending, or WordPress's own heartbeat. Choose it because something
 * already speaks it, rather than because a form has to submit somewhere.
 *
 *
 * @setup-hook init
 */
class Ajax extends Module implements Bootable {

	use WithFolderWalker;

	/**
	 * WP_Error code used for every rejected AJAX request -- a failed
	 * capability check, a failed nonce check, and an unprivileged request to
	 * an action that does not allow it all reject with this same code, only
	 * the message differs.
	 */
	const FORBIDDEN_ERROR_CODE = 'forbidden';

	/**
	 * Default plugin-relative directory of action files.
	 */
	const ACTIONS_ROOT = 'actions';

	/**
	 * Priority for the generated `wp_ajax_*`/`wp_ajax_nopriv_*` handlers.
	 *
	 * `wp_ajax_{action}` is a shared dispatch point: any plugin can attach to
	 * the same action name. Running ahead of third-party listeners means this
	 * module's capability and nonce checks decide the request before another
	 * callback can `wp_send_json_*()` and `die()` on it -- which would skip
	 * those checks entirely. The only explicit priority in this module, hence
	 * a named constant rather than a bare literal at the two call sites.
	 */
	private const HANDLER_PRIORITY = 1;

	/**
	 * Discovered actions by local name, once the directory has been walked.
	 *
	 * @var array<string, AjaxAction>|null
	 */
	private ?array $discovered = null;

	/**
	 * Determine whether the current request is handled by admin-ajax.php.
	 *
	 * Rather than reading the `DOING_AJAX` constant directly, so the result
	 * passes through the `wp_doing_ajax` filter that tests and alternative AJAX
	 * endpoints rely on.
	 *
	 * @return bool True for an AJAX request.
	 */
	public function is_ajax_request(): bool {
		return \wp_doing_ajax();
	}

	/**
	 * Build the globally namespaced WordPress action name.
	 *
	 * @param string $name The local action name.
	 * @return string The namespaced action name.
	 */
	public function get_action_slug( string $name ): string {
		return $this->get_plugin()->get_namespaced_name( $name );
	}

	/**
	 * Create a nonce for an action and optional context.
	 *
	 * `$context` accepts an int as well as a string so a bare resource
	 * identifier -- a post ID, most often -- can be passed straight through
	 * from {@see AjaxAction::get_nonce_context()} or from a request argument
	 * without being cast first. It is concatenated onto the action name either
	 * way, so `123` and `'123'` produce the same nonce.
	 *
	 * Only `null` and `''` mean "no context". A context of `0` scopes the nonce
	 * like any other value.
	 *
	 * @rationale
	 * This guarded on `if ( $context )`, so a post ID of `0` was silently
	 * treated as no context at all. Minting and verifying agreed with each
	 * other, so nothing visibly broke -- an action returning `0` just got an
	 * unscoped nonce while believing it was scoped. Both guards have to test
	 * the same way; changing one alone lets a nonce verify against a different
	 * action name than it was minted for.
	 *
	 * @param string          $name The local action name.
	 * @param string|int|null $context The optional nonce context.
	 * @return string The generated nonce.
	 */
	public function create_action_nonce( string $name, string|int|null $context = null ): string {
		$action = $this->get_action_slug( $name );

		if ( null !== $context && '' !== $context ) {
			$action .= '_' . $context;
		}

		return \wp_create_nonce( $action );
	}

	/**
	 * Build the admin-ajax.php URL for an action.
	 *
	 * Pass `$context_key` to scope the nonce to one of the request arguments
	 * you are already sending: `get_action_url( 'edit', array( 'post_id' => 123
	 * ), 'post_id' )` mints the nonce against `123`, which is the value the
	 * action's {@see AjaxAction::get_nonce_context()} then has to return for
	 * verification to pass. A string or an int both work.
	 *
	 * @param string      $name The local action name.
	 * @param array       $args Request arguments.
	 * @param string|null $context_key Argument key whose value scopes the nonce.
	 * @return string The action URL.
	 * @throws \InvalidArgumentException When the named argument is not a string or an int.
	 */
	public function get_action_url( string $name, array $args = array(), ?string $context_key = null ): string {
		// The optional context argument must match the verifier's context value.
		$context = null !== $context_key && isset( $args[ $context_key ] ) ? $args[ $context_key ] : null;

		/*
		 * Named rather than let through: the value goes straight into a
		 * `string|int|null` parameter, so an array or a float raised a
		 * TypeError from inside the toolkit naming neither the key nor the
		 * call. Reported the way Path, Cron and RestApi report bad arguments.
		 */
		if ( null !== $context && ! \is_string( $context ) && ! \is_int( $context ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'Nonce context "%s" must be a string or an int, %s given.',
					$context_key,
					\gettype( $context )
				)
			);
		}

		$nonce = $this->create_action_nonce( $name, $context );

		$args['_wpnonce'] = $nonce;
		$args['action']   = $this->get_action_slug( $name );
		return \add_query_arg( $args, \admin_url( 'admin-ajax.php' ) );
	}

	/**
	 * Verify the nonce supplied with the current request.
	 *
	 * `$context` must be the same value the nonce was minted with, and accepts
	 * the same `string|int|null` an action's
	 * {@see AjaxAction::get_nonce_context()} returns. As when minting, only
	 * `null` and `''` mean "no context" -- see {@see create_action_nonce()}.
	 *
	 * @param string          $name The local action name.
	 * @param string|int|null $context The optional nonce context.
	 * @return int|false The nonce age or false when invalid.
	 */
	public function verify_action_nonce( string $name, string|int|null $context = null ): int|false {
		$action = $this->get_action_slug( $name );

		if ( null !== $context && '' !== $context ) {
			$action .= '_' . $context;
		}

		$request_value = isset( $_REQUEST['_wpnonce'] ) ? \sanitize_text_field( \wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		return \wp_verify_nonce( $request_value, $action );
	}

	/**
	 * Load action files and register authenticated and public WordPress hooks.
	 *
	 * Files immediately below the configured root are considered actions. Their
	 * basename becomes the action suffix, so `delete-item.php` maps to the plugin
	 * slug followed by `-delete-item`.
	 *
	 * Security ordering: the generated `wp_ajax_*` handler always runs the action's
	 * `capability_check()` before it verifies the nonce. A nonce proves the request
	 * was intended (anti-CSRF); it does not prove the requester is permitted to make
	 * it. Deciding authorization first means an unauthorized caller is rejected
	 * regardless of nonce validity, instead of nonce verification standing in as a
	 * de facto — and insufficient — permission check.
	 *
	 * Logged-out access is opt-in per action: the `wp_ajax_nopriv_` hook is always
	 * registered, but it only reaches the real handler when the action's
	 * `allow_not_privileged()` returns true; otherwise it is wired to a handler that
	 * unconditionally rejects the request with a 403.
	 *
	 * @return void
	 * @throws DiscoveryException When a file returns the wrong value.
	 *
	 * @internal
	 */
	public function register_actions(): void {
		foreach ( $this->get_discovered_actions() as $name => $instance ) {
			$this->register_action( $name, $instance );
		}
	}

	/**
	 * This action's slug, from the file it was discovered in.
	 *
	 * @param AjaxAction $action The instance to look up.
	 * @return string The `{plugin-slug}-{name}` slug it is registered under.
	 * @throws \InvalidArgumentException When the instance was not discovered by this module.
	 */
	public function get_slug_of( AjaxAction $action ): string {
		$name = \array_search( $action, $this->get_discovered_actions(), true );

		if ( false === $name ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The given %s instance was not discovered by this Ajax module.', AjaxAction::class )
			);
		}

		return $this->get_action_slug( $name );
	}

	/**
	 * Every discovered action, keyed by its local name.
	 *
	 * Kept rather than rebuilt, so {@see get_slug_of()} compares against the
	 * same instances a caller is holding.
	 *
	 * @return array<string, AjaxAction> Wired instances keyed by local name.
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	public function get_discovered_actions(): array {
		if ( null !== $this->discovered ) {
			return $this->discovered;
		}

		$root_dir = $this->with( Path::class )->get_plugin_path( self::ACTIONS_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			$this->discovered = array();

			return $this->discovered;
		}

		$instances = array();

		foreach ( $this->walk_folder( $root_dir, array( 'php' ), 1 ) as $file ) {
			$name        = \basename( $file, '.php' );
			$action_file = $root_dir . '/' . $file;
			/** @var AjaxAction $instance */
			$instance = require $action_file;

			if ( ! $instance instanceof AjaxAction ) {
				// Loading arbitrary files would hide configuration errors until dispatch.
				throw new DiscoveryException(
					\sprintf(
						'The file "%s" must return an instance of %s. Got: %s',
						$action_file,
						AjaxAction::class,
						\is_object( $instance ) ? $instance::class : \gettype( $instance )
					)
				);
			}

			// Wire the action so it behaves like a module: plugin assigned and
			// declared module properties injected before handle() runs.
			$this->get_plugin()->wire( $instance );

			// Discovered but switched off: wired first, so is_enabled() can read an
			// injected service, then nothing about it is registered.
			if ( ! $instance->is_enabled() ) {
				continue;
			}

			$instances[ $name ] = $instance;
		}

		$this->discovered = $instances;

		return $this->discovered;
	}

	/**
	 * Resolve the action directory and schedule handler registration.
	 *
	 * Handlers are only needed on AJAX requests, so discovery is skipped on other
	 * requests. Registration is scheduled on `init` — after plugins_loaded and
	 * well before any wp_ajax_* dispatch — so it runs at a stable point regardless
	 * of when the plugin is run(). If `init` has already fired (for example when
	 * the plugin is run late), registration happens immediately.
	 *
	 * The root is resolved inside register_actions() at hook time, not here,
	 * matching Cron, PostTypes, AdminPages and RestApi. `$actions_root` stays
	 * the single source of truth, and the hooked closure captures no state.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function on_boot(): void {
		if ( ! $this->is_ajax_request() ) {
			return;
		}

		$this->register_actions();
	}

	/**
	 * Read this action's declared arguments out of the request and onto it.
	 *
	 * WordPress hands an AJAX hook the superglobals as they arrived, slashed and
	 * unchecked -- unlike a REST route, whose parameters it unslashes and
	 * validates before the route ever sees them.
	 * {@see \Zestry\WPToolkit\Modules\Request\Request::get_submitted_values()} does both here,
	 * reading the body then the query string in the order a route does: an action
	 * that declares its arguments never touches a superglobal.
	 *
	 * An action that declares none reads the request itself, and nothing here
	 * touches it.
	 *
	 * @param AjaxAction $instance The wired action.
	 * @return true|\WP_Error True once bound, or why the request was refused.
	 */
	private function bind_arguments( AjaxAction $instance ) {
		if ( array() === $this->with( Request::class )->get_arguments( $instance ) ) {
			return true;
		}

		$values = $this->with( Request::class )->get_submitted_values( $instance );

		$checked = $this->with( Request::class )->get_validated_values( $instance, $values, 'rest_invalid_param' );

		if ( \is_wp_error( $checked ) ) {
			return $checked;
		}

		$this->with( Request::class )->bind( $instance, $checked );

		return true;
	}

	/**
	 * Bind one action's handlers to WordPress.
	 *
	 * @param string     $name     The action's local name.
	 * @param AjaxAction $instance The wired action.
	 * @return void
	 */
	private function register_action( string $name, AjaxAction $instance ): void {
		$slug = $this->get_action_slug( $name );

		$handler = function () use ( $instance, $name ) {
			/*
			 * Arguments first, so a capability decision can name the thing it is
			 * deciding about -- current_user_can( 'edit_post', $this->post_id ).
			 * Nothing has happened yet at this point: the values are checked
			 * against what the action declared, and reading them causes no side
			 * effect that a caller who is then refused could have provoked.
			 */
			$bound = $this->bind_arguments( $instance );

			if ( \is_wp_error( $bound ) ) {
				\wp_send_json_error(
					array(
						'code'    => $bound->get_error_code(),
						'message' => $bound->get_error_message(),
						'params'  => $bound->get_error_data()['params'] ?? array(),
					),
					400
				);
			}

			// Authorization next: a nonce proves intent, not permission, so the
			// action's explicit capability decision gates the request before any
			// nonce work or application code runs.
			if ( ! $instance->capability_check() ) {
				$this->send_forbidden_json_error( \__( 'You are not allowed to access this action.', 'zestry-toolkit' ) );
			}

			// Enforce the action's nonce policy before invoking application code.
			if ( $instance->is_nonce_required() ) {
				$context = $instance->get_nonce_context();
				if ( ! $this->verify_action_nonce( $name, $context ) ) {
					$this->send_forbidden_json_error( \__( 'Invalid nonce.', 'zestry-toolkit' ) );
				}
			}

			$instance->handle();
		};

		$nopriv_forbidden_handler = function () {
			// Reject unauthenticated requests unless the action explicitly allows them.
			$this->send_forbidden_json_error( \__( 'You are not allowed to access this action.', 'zestry-toolkit' ) );
		};

		\add_action( 'wp_ajax_' . $slug, $handler, self::HANDLER_PRIORITY );

		\add_action(
			'wp_ajax_nopriv_' . $slug,
			// Logged-out access is opt-in on individual action classes.
			$instance->allow_not_privileged() ? $handler : $nopriv_forbidden_handler,
			self::HANDLER_PRIORITY
		);
	}

	/**
	 * Send a rejected-request JSON response with the shared forbidden error code.
	 *
	 * @param string $message Human-readable rejection reason.
	 * @return void
	 */
	private function send_forbidden_json_error( string $message ): void {
		\wp_send_json_error( new \WP_Error( self::FORBIDDEN_ERROR_CODE, $message ), 403 );
	}
}
