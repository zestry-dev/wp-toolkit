<?php

/**
 * Core API: WithPlugin trait
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Traits;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Service;
use Zestry\WPToolkit\Kernel\Attributes\NoInject;
use Zestry\WPToolkit\Kernel\Plugin;

/**
 * Provides plugin access and automatic dependency injection.
 *
 * Satisfies the PluginAware contract. A class using the trait requests a
 * service or a module by declaring a public or protected property typed as
 * that class -- for example `public Path $path;` -- which the plugin populates
 * via inject_modules() after set_plugin() runs. The type only has to be a
 * Service subclass, which every Module is, so both kinds are injected the same
 * way. Private properties are never injected (reflection cannot reach a private
 * property declared on an ancestor class). Mark a property with #[NoInject] to
 * exclude it from injection.
 *
 * Declare injected dependencies `public` by convention: every module and
 * DevTools command in this toolkit does, which keeps a module's dependencies
 * uniformly inspectable. `protected` is equally supported by the mechanism and
 * is the right choice only when a subclass hierarchy genuinely needs the
 * dependency hidden from callers.
 *
 * Typical usage, matching how Command, AdminPage, and AjaxAction consume
 * this trait directly (a Service or Module gets the same behavior by
 * extending the Service base class, which already uses this trait):
 *
 *     class MyAction {
 *         use WithPlugin;
 *
 *         public Path $path;
 *
 *         public function handle(): void {
 *             $absolute = $this->path->get_plugin_path( 'some/file.php' );
 *         }
 *     }
 */
trait WithPlugin {

	/**
	 * Plugin instance.
	 *
	 * Left uninitialized until set_plugin() runs; it is the caller's
	 * responsibility to call set_plugin() before get_plugin() or
	 * inject_modules() are used, since PHP will throw on read of an
	 * uninitialized typed property rather than returning a default.
	 *
	 * @var Plugin
	 */
	private Plugin $_plugin;

	/**
	 * Set the plugin instance.
	 *
	 * Loads the plugin and triggers automatic dependency injection.
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
	 * Use it to reach something you did not declare a property for -- a module
	 * you need in one method only, or one you look up by a name computed at
	 * runtime. For anything you use throughout the class, declare a typed
	 * property instead and let it be injected.
	 *
	 *     $this->get_plugin()->get( Options::class )->get( 'api_key' );
	 *
	 * @return Plugin The plugin instance.
	 */
	final public function get_plugin(): Plugin {
		return $this->_plugin;
	}

	/**
	 * Populate declared properties typed as a service or module class.
	 *
	 * This supports declarative dependencies. For example, declaring
	 * `public Path $path;` causes the plugin to resolve Path and assign it
	 * before the initializer, command, or action handler runs.
	 *
	 * A property is injected when it is public or protected and typed as a
	 * Service subclass, which includes every Module. Private ones never are, and
	 * `#[NoInject]` opts one out. Scalars, unions, untyped properties and any
	 * other class type are left alone as caller-owned state.
	 *
	 * @internal
	 */
	final public function inject_modules(): void {
		// Public and protected only: reflection cannot reach a private property
		// declared on an ancestor class, so injecting private would work on the
		// declaring class and silently fail in every subclass.
		//
		// setValue() on a non-public property needs no setAccessible() call as
		// of PHP 8.1, which composer.json already floors at.
		$reflection = new \ReflectionObject( $this );
		$properties = $reflection->getProperties(
			\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED
		);

		foreach ( $properties as $property ) {
			$type = $property->getType();

			if ( ! $type ) {
				continue;
			}

			if ( ! $type instanceof \ReflectionNamedType || $type->isBuiltin() ) {
				continue;
			}

			$type_name = $type->getName();

			// Service, so both kinds inject: a Module is a Service that also
			// acts on its own, and a property typed as either is wired the same.
			if ( ! \is_subclass_of( $type_name, Service::class ) ) {
				continue;
			}

			if ( $property->getAttributes( NoInject::class ) ) {
				continue;
			}

			// No setAccessible() call: relies on PHP 8.1+ implicit accessibility.
			$module_instance = $this->get_plugin()->get( $type_name );
			$property->setValue( $this, $module_instance );
		}
	}
}
