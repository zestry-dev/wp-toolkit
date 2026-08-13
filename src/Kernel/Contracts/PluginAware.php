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
 * An object implementing this interface can receive the shared plugin and have
 * its declared service and module dependencies injected. The WithPlugin trait
 * provides a conforming implementation, so classes that use the trait only need
 * to declare that they implement this interface. Modules, AJAX actions, and CLI
 * commands are all plugin-aware and can be passed to Plugin::wire().
 *
 * "Wiring" an object means performing both steps this interface exposes, in
 * order: assign the shared plugin with set_plugin(), then populate the object's
 * declared service-typed properties with _inject_services(), which needs the
 * plugin already assigned to resolve them. Plugin::wire() and the module
 * repository perform exactly this sequence for every plugin-aware object they
 * construct, whether it is a registered module, a CLI command, or an AJAX
 * action loaded from a file — so an object never needs to call these methods
 * itself, only declare the typed properties it wants populated.
 */
interface PluginAware {

	/**
	 * Assign the shared plugin instance.
	 *
	 * The first half of wiring. Must run before _inject_services(), which reads
	 * the plugin assigned here to resolve the object's declared dependencies.
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

	/**
	 * Populate declared Service-typed properties from the plugin.
	 *
	 * The second half of wiring. Resolves the plugin assigned by set_plugin(),
	 * so it must run after that method, not before.
	 *
	 * @return void
	 */
	public function _inject_services(): void;
}
