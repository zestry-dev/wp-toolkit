<?php

/**
 * Core API: Module exception base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Exceptions;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Base exception for declaration, resolution, and boot failures.
 *
 * Catch this to handle any error raised while declaring, resolving, or booting
 * a service or a module, without also catching unrelated runtime exceptions.
 * More specific failures extend this class: Plugin throws this directly for a
 * malformed `bootstrap.php` -- one that returns something other than an array,
 * or holds an entry naming no class. Resolving one raises the
 * ModuleNotFoundException and CircularDependencyException subclasses, and every file-discovery module throws
 * DiscoveryException for a layout it cannot read.
 */
class ModuleException extends \RuntimeException {

	/**
	 * The message raised when a property asks for a module by type.
	 *
	 * Injection is for services. A service is built when something asks for it
	 * and does nothing else, so a typed property is an honest way to ask. A
	 * module *boots* when it is built -- it binds hooks, walks a directory,
	 * registers things with WordPress -- and a property declaration hides all of
	 * that behind a type name.
	 *
	 * Asking through `get()` puts the cost back where a reader can see it, and
	 * keeps two modules from reaching for each other by accident: a property is
	 * declared once and forgotten, while a call sits in the method that needs it.
	 *
	 * @param string $owner    The class declaring the property.
	 * @param string $property Its name.
	 * @param string $module   The module class it asked for.
	 * @return self
	 *
	 * @internal
	 */
	public static function module_property( string $owner, string $property, string $module ): self {
		return new self(
			\sprintf(
				'%1$s declares `%3$s $%2$s`, and a module is not injected: building one boots it. Drop the'
					. ' property and ask where you need it -- `$this->get_plugin()->get( %3$s::class )`.',
				$owner,
				$property,
				\substr( (string) \strrchr( '\\' . $module, '\\' ), 1 )
			)
		);
	}

	/**
	 * The message raised when a module is asked for before its hook fires.
	 *
	 * A module that names a boot hook has said it cannot do its work before one
	 * -- registering into a WordPress registry that does not exist yet, or
	 * following other plugins onto the same hook. Building it early would bind
	 * it on the wrong side of whatever it was waiting for.
	 *
	 * Refused rather than built early, because early is exactly what the
	 * declaration ruled out, and a module that boots at the wrong moment reports
	 * nothing: it registers into a registry nobody has filled, and the feature is
	 * simply absent.
	 *
	 * @param string $module The module class asked for.
	 * @param string $hook   The hook it boots on.
	 * @return self
	 *
	 * @internal
	 */
	public static function not_booted_yet( string $module, string $hook ): self {
		return new self(
			\sprintf(
				'%1$s boots on `%2$s`, which has not fired yet. Ask for it from `%2$s` or later, or give its'
					. ' `bootstrap.php` entry a `boots_on` this plugin can live with.',
				$module,
				$hook
			)
		);
	}
}
