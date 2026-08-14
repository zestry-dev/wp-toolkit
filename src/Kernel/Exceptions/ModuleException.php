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
 * Catch this to handle any error raised while declaring, building, or booting a
 * module, without also catching unrelated runtime exceptions. Plugin throws it
 * directly for a `bootstrap.php` it cannot read -- one that returns something
 * other than an array, holds an entry naming no class, or configures an entry
 * with something other than an array -- and for a module asked for that the file
 * never declared, or that has not reached its `boots_on` hook yet. Building one
 * raises the ModuleNotFoundException and CircularDependencyException subclasses,
 * and every file-discovery module throws DiscoveryException for a layout it
 * cannot read.
 */
class ModuleException extends \RuntimeException {

	/**
	 * The message raised when a bootstrap entry is configured with anything but an array.
	 *
	 * A configured entry is an array, so `configure`, `boots_on` and `priority`
	 * are all written the same way and adding the second one to an entry never
	 * means rewriting the first.
	 *
	 * @param string $module The module class the entry names.
	 * @param string $given  What the entry's value is instead, as a type name.
	 * @return self
	 *
	 * @internal
	 */
	public static function bootstrap_entry_shape( string $module, string $given ): self {
		return new self(
			\sprintf(
				'The `bootstrap.php` entry for %1$s is %2$s. Configuration is an array:'
					. ' `%1$s::class => array( \'configure\' => $callback )`, which is also where'
					. ' `boots_on` and `priority` go. A module needing none is written bare.',
				$module,
				$given
			)
		);
	}

	/**
	 * The message raised when something asks for a module nothing declared.
	 *
	 * `bootstrap.php` is the whole inventory of what a plugin is made of, which
	 * only holds while nothing is built without being listed there. So an
	 * undeclared class is refused rather than constructed on the spot: reading
	 * that file tells you what the plugin has, and it stays true.
	 *
	 * @param string      $module The module class asked for.
	 * @param string|null $file   The bootstrap file the plugin reads, when it has one.
	 * @return self
	 *
	 * @internal
	 */
	public static function not_declared( string $module, ?string $file = null ): self {
		return new self(
			\sprintf(
				'%s is not declared, so nothing built it. Add it to %s -- that file is'
					. ' everything this plugin is made of, and nothing outside it is ever built.',
				$module,
				null === $file ? '`bootstrap.php`' : $file
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
