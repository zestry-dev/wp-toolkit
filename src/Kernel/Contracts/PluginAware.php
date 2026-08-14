<?php

/**
 * Core API: PluginAware contract
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Contracts;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Plugin;

/**
 * Contract for objects the plugin can wire.
 *
 * An object implementing this interface can be given the shared plugin, which
 * is what lets it reach every module through `with()`. The
 * {@see \Zestry\WPToolkit\Kernel\Traits\WithPlugin} trait provides a conforming
 * implementation, so a class using the trait only has to declare that it
 * implements this.
 *
 * {@see \Zestry\WPToolkit\Kernel\Abstracts\Module} already does both. This is for
 * everything else the plugin wires but does not build -- a CLI command, an AJAX
 * action, an admin page, anything a discovered file returns -- which reaches its
 * dependencies exactly the way a module does.
 */
interface PluginAware {

	/**
	 * Assign the shared plugin instance.
	 *
	 * @param Plugin $plugin The plugin instance.
	 * @return void
	 */
	public function set_plugin( Plugin $plugin ): void;

	/**
	 * Get the shared plugin instance.
	 *
	 * @return Plugin The plugin instance.
	 */
	public function get_plugin(): Plugin;
}
