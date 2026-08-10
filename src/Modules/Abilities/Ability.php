<?php

/**
 * Abilities API: Ability base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Abilities;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;
use Zestry\WPToolkit\Services\Request\Request;

/**
 * One thing your plugin can do, described well enough for something else to call it.
 *
 * A file in `abilities/` returns one of these, and its filename is the name it
 * registers under: `create-order.php` becomes `{plugin-slug}/create-order`.
 *
 * The audience is not a person reading your code. WordPress puts every ability
 * on a REST endpoint, and an MCP adapter turns the same registration into a tool
 * an AI agent can call — from your `description()` and your schemas alone,
 * without a line of protocol code on your side. That is what makes this
 * different from a {@see \Zestry\WPToolkit\Modules\RestApi\Route}: a route is a URL you
 * document for developers you can talk to, an ability is a contract something
 * reads on its own.
 *
 * Write for that reader. `description()` is the entire brief an agent gets for
 * deciding whether to call this at all; `input_schema()` is how it knows what to
 * send. Both are worth more care than they look.
 *
 * @stub ability.php.stub
 *
 * @example An ability
 * ```
 * use Acme\Plugin\Core\Modules\Abilities\Ability;
 * use Acme\Plugin\Core\Services\Request\Attributes\RequestArgument;
 * use Acme\Plugin\Core\Modules\Abilities\Effect;
 *
 * return new class extends Ability {
 *
 *     public function label(): string {
 *         return __( 'Cancel an order', 'acme-plugin' );
 *     }
 *
 *     public function description(): string {
 *         return __(
 *             'Cancels an order that has not shipped yet and restocks its items. Refunds are not issued.',
 *             'acme-plugin'
 *         );
 *     }
 *
 *     public function effect(): Effect {
 *         return Effect::Delete;
 *     }
 *
 *     #[RequestArgument( 'The order to cancel.' )]
 *     public int $order_id;
 *
 *     public function permission_check( mixed $input ): bool {
 *         return current_user_can( 'edit_shop_orders', $this->order_id );
 *     }
 *
 *     public function handle( mixed $input ): mixed {
 *         return array( 'cancelled' => acme_cancel_order( $this->order_id ) );
 *     }
 * };
 * ```
 */
abstract class Ability implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * A short name for this ability.
	 *
	 * Shown wherever abilities are listed. A few words, translated.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * What this ability does, in prose.
	 *
	 * The most important method here. An agent reads only this to decide whether
	 * your ability is the right one to call, so write it as an instruction to a
	 * capable stranger: what it does, what it does *not* do, and anything that
	 * would surprise someone who guessed from the label. "Cancels an order that
	 * has not shipped yet and restocks its items. Refunds are not issued."
	 *
	 * @return string
	 */
	abstract public function description(): string;

	/**
	 * What running this does to the site.
	 *
	 * Required rather than defaulted, because WordPress turns it into the HTTP
	 * method the REST endpoint demands — and every other method gets `405`. An
	 * unstated effect is not "unknown" to WordPress, it is "no", which would make
	 * a read-only ability `POST`-only. {@see Effect}.
	 *
	 * @return Effect
	 */
	abstract public function effect(): Effect;

	/**
	 * Whether the current user may run this.
	 *
	 * Checked before {@see handle()}, on every way into the ability — REST, MCP,
	 * and your own PHP alike. This is the gate, so a capability check belongs
	 * here rather than in `handle()`.
	 *
	 * Any {@see \Zestry\WPToolkit\Services\Request\Attributes\RequestArgument} properties are already bound, so a
	 * check can name the thing being acted on:
	 * `current_user_can( 'edit_post', $this->id )`.
	 *
	 * Unlike {@see \Zestry\WPToolkit\Modules\RestApi\RestRoute::permission_check()} this
	 * returns a plain `bool`. WordPress replaces a refusal with a message of its
	 * own before the caller sees it — deliberately, so a check cannot leak why it
	 * said no to someone who is not allowed — and treats a returned `WP_Error` as
	 * a mistake worth reporting with `_doing_it_wrong()`.
	 *
	 * @param mixed $input The validated input, in the shape input_schema() describes.
	 * @return bool
	 */
	abstract public function permission_check( mixed $input ): bool;

	/**
	 * Do the thing.
	 *
	 * Reached only once the input has been validated against
	 * {@see input_schema()} and {@see permission_check()} has passed. Whatever you
	 * return is validated against {@see output_schema()} in turn, so a shape
	 * that disagrees with the schema fails loudly rather than reaching the
	 * caller.
	 *
	 * Return a `WP_Error` for a failure the caller should see; its message is
	 * read by whatever called you, so make it a sentence rather than a code.
	 *
	 * Any {@see \Zestry\WPToolkit\Services\Request\Attributes\RequestArgument} properties are bound by the time this
	 * runs, so read `$this->order_id` rather than `$input['order_id']`.
	 *
	 * @param mixed $input The validated input.
	 * @return mixed The result, or a `WP_Error`.
	 */
	abstract public function handle( mixed $input ): mixed;

	/**
	 * The name this ability is registered under.
	 *
	 * Your filename under your plugin's namespace, since abilities share one
	 * registry with every other plugin: `create-order.php` gives
	 * `{plugin-slug}/create-order`. This is the name a client calls, and the one
	 * that appears in `wp-json/wp-abilities/v1/abilities`.
	 *
	 * The filename is used exactly as written. WordPress accepts only lowercase
	 * letters, digits and dashes in either half of the name, and a file it would
	 * refuse is refused here first -- `create_order.php` throws a
	 * `DiscoveryException` naming the file, at boot, rather than registering under
	 * a name you did not type. Spell it with dashes.
	 *
	 * @return string
	 */
	final public function get_name(): string {
		return $this->abilities()->get_name_of( $this );
	}

	/**
	 * JSON Schema for what this ability accepts.
	 *
	 * WordPress validates against it before your code runs, so `handle()` never
	 * sees input that does not fit.
	 *
	 * By default this is built from your
	 * {@see \Zestry\WPToolkit\Services\Request\Attributes\RequestArgument} properties, which
	 * is the shorter way to say the same thing and binds the values onto the
	 * object as well. Override to write the schema by hand
	 * instead — it *replaces* the derived one rather than adding to it, and
	 * nothing is bound:
	 *
	 *     return array(
	 *         'type'       => 'object',
	 *         'properties' => array(
	 *             'order_id' => array(
	 *                 'type'        => 'integer',
	 *                 'description' => __( 'The order to cancel.', 'acme-plugin' ),
	 *             ),
	 *         ),
	 *         'required'   => array( 'order_id' ),
	 *     );
	 *
	 * An empty array means the ability takes no input at all.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return $this->get_plugin()->get( Request::class )->get_schema( $this );
	}

	/**
	 * JSON Schema for what this ability returns.
	 *
	 * Validated after `handle()`, so a result that does not match is an error
	 * rather than something the caller has to guess at. Worth writing even when
	 * the shape feels obvious to you: it is not obvious to the thing calling.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return array();
	}

	/**
	 * The category this ability is filed under.
	 *
	 * Defaults to one named after your plugin, registered for you. Return
	 * `'site'` or `'user'` for WordPress's own, or another slug you registered
	 * yourself — the category has to exist by the time abilities register, or
	 * WordPress refuses this one.
	 *
	 * @return string
	 */
	public function category(): string {
		return $this->abilities()->get_category_slug();
	}

	/**
	 * Whether anything outside your own PHP may call this.
	 *
	 * False by default, matching WordPress: an ability is a registry entry first
	 * and an endpoint second, and plenty of them exist only to be composed by
	 * code you wrote. Return true to put this one on the REST API and offer it to
	 * any MCP adapter installed on the site.
	 *
	 * Being public is not the same as being unguarded — {@see permission_check()}
	 * still runs. But it is the *only* thing that runs: WordPress's run endpoint
	 * checks that the ability is public, validates the input against the schema,
	 * and calls your check, with no authentication of its own anywhere in it. An
	 * anonymous request reaches `permission_check()` directly, and whatever it
	 * returns is the answer. Listing abilities does require a logged-in user;
	 * running one does not.
	 *
	 * So read `permission_check()` again with a stranger in mind before returning
	 * true here.
	 *
	 * @return bool
	 */
	public function is_public(): bool {
		return false;
	}

	/**
	 * Anything else WordPress or an adapter should record about this ability.
	 *
	 * An escape hatch for the parts of `meta` that have no method of their own,
	 * merged underneath the ones that do — `annotations` always comes from
	 * {@see effect()} and `public` from {@see is_public()}.
	 *
	 * The useful one is `show_in_rest`, which otherwise follows `public`.
	 * Returning `array( 'show_in_rest' => false )` from a public ability offers
	 * it to MCP adapters while keeping it off the REST API.
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return array();
	}

	/**
	 * The module that discovered this ability.
	 *
	 * @return Abilities
	 */
	final protected function abilities(): Abilities {
		return $this->get_plugin()->get( Abilities::class );
	}
}
