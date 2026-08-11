<?php

/**
 * Core API: WithEnablement trait
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Traits;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Lets a discovered file decide whether it registers at all.
 *
 * Every file-based thing this toolkit finds -- an action, a route, a post type, a
 * field, a schedule, a page, a block -- registers because it is on disk. That is
 * the whole convention, and it has no answer for the thing you want to ship but
 * not switch on: a post type behind a feature flag, a route that needs a plugin
 * that may not be installed, a page that only makes sense on multisite.
 *
 * Overriding {@see is_enabled()} answers it, and deleting the override is how you
 * turn the thing back on.
 *
 * @example Registering only when something else is present
 * Checked once, before anything is registered, so a false costs nothing
 * afterwards -- no hook is bound and WordPress never hears of it.
 *
 * ```
 * return new class() extends PostType {
 *
 *     public function is_enabled(): bool {
 *         return class_exists( 'WooCommerce' );
 *     }
 *
 *     // ...
 * };
 * ```
 *
 * @example Behind one of your own settings
 * The instance is wired before it is asked, so an injected service is available
 * here -- which is what makes a stored setting usable as the switch.
 *
 * ```
 * return new class() extends RestRoute {
 *
 *     public Options $options;
 *
 *     public function is_enabled(): bool {
 *         return (bool) $this->options->get( 'expose_public_api' );
 *     }
 *
 *     // ...
 * };
 * ```
 *
 * @rationale
 * A trait rather than a method on each base class, because there are twelve of
 * them and one default; and a trait rather than something on `WithPlugin`, which
 * they all already use, because a `Service` and a `Module` use it too and would
 * come out with an `is_enabled()` that nothing reads -- a method that looks like
 * it works. Listing a module in `bootstrap.php` is what builds it, and a second
 * way to say the same thing would drift from it.
 *
 * No interface accompanies it. Every caller reaches an instance through its own
 * discovery guard, which has already established the base class, so an interface
 * would be a second name for a fact the `instanceof` above it just proved.
 *
 * `Migration` deliberately does not use this. Migrations run at most once ever,
 * in filename order, and a skipped one leaves a permanent gap -- enabling it
 * later runs it after migrations that already assumed it had run. A migration
 * that should not always do its work checks that inside `up()`, where the
 * decision is recorded as having been made.
 */
trait WithEnablement {

	/**
	 * Whether this should be registered at all.
	 *
	 * Called once, after the instance is wired and before anything is registered.
	 * Return false and nothing happens: no hook is bound and no WordPress
	 * registration is made.
	 *
	 * The default is true, so a file that says nothing registers -- being on disk
	 * is the convention, and this is the exception to it.
	 *
	 * Most modules ask at discovery and drop the file there. `post-types` and
	 * `fields` ask at registration instead, so that a switched-off file still
	 * appears in what they list -- a screen offering to switch a feature on can
	 * only offer what it can see. It registers nothing either way.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return true;
	}
}
