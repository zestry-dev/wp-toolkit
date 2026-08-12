<?php

/**
 * DevTools: the consuming plugin's own running instance
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Service;

/**
 * Finds the `Plugin` the consuming plugin built for itself, if it built one.
 *
 * Every other devtool reader answers a question from files: `ZestryConfig` from
 * `zestry.json`, `BootstrapFile` from `bootstrap.php`, `Copier` from what is on
 * disk. Between them they describe what a plugin is *declared* to be, which is
 * all a file can say.
 *
 * What no file says is what the plugin *became*. A slug can be passed to the
 * constructor and appear in no configuration at all; a discovery root can be
 * moved by a `set_*_root()` call inside an initializer. Both are decided at run
 * time, on an instance -- and by the time a `wp zt` command runs, WordPress
 * has already loaded the plugin and built it. There is nothing to construct
 * here, only something to find.
 *
 * > [!IMPORTANT]
 * > **This is the only devtool reader that can return nothing.** A plugin that
 * > has not run, is not active, or builds no `Plugin` at all is an ordinary
 * > state rather than an error, and every caller has to have an answer without
 * > one. Treat it as detail a command can add, never as detail it needs.
 *
 * The two-Plugin-instance separation still holds. This does not build the
 * consumer's plugin, resolve any of its modules, or run any initializer of
 * theirs -- WordPress did all of that before the command dispatched, and this
 * reads the result.
 *
 * @example Adding what the files cannot say
 * ```
 * public RuntimePlugin $runtime;
 *
 * $slug = $this->runtime->get_slug( $plugin_root ) ?? basename( $plugin_root );
 * ```
 */
class RuntimePlugin extends Service {

	/**
	 * The global every copied `Plugin` publishes itself into.
	 *
	 * A plain global, because a static would be per-copy: each plugin owns a
	 * rewritten `Plugin` class of its own, so two plugins on a site have two
	 * unrelated classes and two unrelated statics. The name is not rewritten
	 * when the kernel is copied -- `Copier` rewrites namespaces and text-domain
	 * literals, and this is neither -- so every plugin on a site publishes into
	 * the same array.
	 *
	 * @var string
	 */
	public const REGISTRY = 'zestry_runtime_plugins';

	/**
	 * What the devtool calls on a running plugin, and therefore what identifies one.
	 *
	 * @var string[]
	 */
	private const REQUIRED_METHODS = array( 'get_slug', 'get_bootstrap_file' );

	/**
	 * The running plugin rooted at a directory, or null when there is none.
	 *
	 * Keyed by the plugin's own directory rather than by slug, since the slug is
	 * one of the things being asked about -- and because that is what
	 * {@see ConsumerPlugin::get_plugin_root()} resolves from the working
	 * directory, so the lookup is exact rather than "whichever ran last".
	 *
	 * **Identified by what it can do, never by `instanceof`.** `Copier` rewrites
	 * the namespace of every file it copies, so a consuming plugin publishes its
	 * own `Acme\Plugin\Core\Kernel\Plugin` -- a class unrelated to this one, for
	 * the same reason {@see REGISTRY} is a plain global rather than a static. A
	 * nominal check here is false for every plugin this class exists to read, and
	 * it fails silently: the key is present and correct, and the type test
	 * discards it.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return object|null The running plugin, or null when there is none.
	 */
	public function get( string $plugin_root ): ?object {
		$running = $GLOBALS[ self::REGISTRY ] ?? null;

		if ( ! \is_array( $running ) ) {
			return null;
		}

		$instance = $running[ \rtrim( $plugin_root, '/\\' ) ] ?? null;

		if ( ! \is_object( $instance ) ) {
			return null;
		}

		foreach ( self::REQUIRED_METHODS as $method ) {
			if ( ! \method_exists( $instance, $method ) ) {
				return null;
			}
		}

		return $instance;
	}

	/**
	 * The slug that plugin actually registers everything under.
	 *
	 * Worth asking for on its own because nothing on disk holds it: it is the
	 * entry file's second constructor argument, and defaults to the directory
	 * name only when that argument is omitted. Every namespaced name a module
	 * registers carries it -- `wp {slug} greet`, `?page={slug}-settings`,
	 * `{slug}-sync` -- so reporting the directory name as though it were the
	 * slug is wrong exactly when it matters.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return string|null The slug, or null when the plugin is not running.
	 */
	public function get_slug( string $plugin_root ): ?string {
		return $this->get( $plugin_root )?->get_slug();
	}

	/**
	 * The slug, or the same default `Plugin` would apply if it were running.
	 *
	 * For a caller that has to name something either way -- an npm scope, a
	 * webpack output name -- rather than report what it found. A plugin that has
	 * not written its entry file yet cannot be asked, and the directory name is
	 * what it will answer once it can.
	 *
	 * Not the text domain, which names a translation catalogue and is free to
	 * differ from the slug. Anything built from it would not match what `Assets`
	 * registers at runtime.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return string The slug, or the directory name.
	 */
	public function get_slug_or_default( string $plugin_root ): string {
		return $this->get_slug( $plugin_root ) ?? \basename( \rtrim( $plugin_root, '/\\' ) );
	}
}
