<?php

/**
 * Core API: WithPlugin trait
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Traits;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Exceptions\ModuleException;
use Zestry\WPToolkit\Kernel\Plugin;

/**
 * Gives a class the plugin, and `with()` to reach every module through it.
 *
 * Satisfies the PluginAware contract. {@see \Zestry\WPToolkit\Kernel\Abstracts\Module} already
 * uses it, so a module has this for free; a class the plugin did not build --
 * a `Command`, an `AjaxAction`, an `AdminPage`, anything a discovered file
 * returns -- uses it directly and is passed through `$plugin->wire( $object )`.
 *
 * ```
 * class MyAction {
 *     use WithPlugin;
 *
 *     public function handle(): void {
 *         $absolute = $this->with( Path::class )->get_plugin_path( 'some/file.php' );
 *     }
 * }
 * ```
 *
 * One way to reach a dependency, and it reads the same in a module, a command
 * and a template helper.
 */
trait WithPlugin {

	/**
	 * Plugin instance.
	 *
	 * Left uninitialized until set_plugin() runs; it is the caller's
	 * responsibility to call set_plugin() before get_plugin() or with() are
	 * used, since PHP will throw on read of an uninitialized typed property
	 * rather than returning a default.
	 *
	 * @var Plugin
	 */
	private Plugin $_plugin;

	/**
	 * Set the plugin instance.
	 *
	 * @param Plugin $plugin The plugin instance.
	 * @return void
	 *
	 * @internal
	 */
	final public function set_plugin( Plugin $plugin ): void {
		$this->_plugin = $plugin;
	}

	/**
	 * Get the plugin this class belongs to.
	 *
	 * For the plugin's own answers -- its slug, its entry file, the headers it
	 * declares. To reach another module, {@see with()} is shorter and says what
	 * it is doing.
	 *
	 * @return Plugin The plugin instance.
	 */
	final public function get_plugin(): Plugin {
		return $this->_plugin;
	}

	/**
	 * Reach another module.
	 *
	 * The one way anything in a plugin reaches anything else. Returns the same
	 * instance every time, so two callers asking for `Options` share its state:
	 *
	 * ```
	 * $this->with( Options::class )->get( 'api_key' );
	 * ```
	 *
	 * **The module has to be listed in `bootstrap.php`.** Asking for one that is
	 * not throws, naming the class and the file to add it to -- nothing is built
	 * because something asked for it, so that file stays the whole inventory of
	 * what the plugin is made of.
	 *
	 * A module that names a `boots_on` also throws when asked for before that
	 * hook has fired, since building it early would bind it on the wrong side of
	 * whatever it was declared to follow.
	 *
	 * @template T of object
	 * @param class-string<T> $name The module class to reach.
	 * @return T The shared instance.
	 * @throws ModuleException If it is not declared, or has not booted yet.
	 */
	final public function with( string $name ): object {
		return $this->_plugin->get( $name );
	}
}
