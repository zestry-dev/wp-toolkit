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
 * other than an array, holds an entry naming no class, or gives a class entry a
 * value that is not a configurator -- and for a module asked for that the file
 * never declared, or that has not reached the hook it is listed under. Building one
 * raises the ModuleNotFoundException and CircularDependencyException subclasses,
 * and every file-discovery module throws DiscoveryException for a layout it
 * cannot read.
 */
class ModuleException extends \RuntimeException {

	/**
	 * The message raised when a class entry's value is not a configurator.
	 *
	 * A class name keys one thing: the callback that configures it. Timing is a
	 * group heading over the modules that share it, not a key inside the entry,
	 * so there is nothing else an entry's value could be.
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
				'The `bootstrap.php` entry for %1$s is %2$s. A class entry\'s value is the callback that'
					. ' configures it: `%1$s::class => static function ( $module ) { ... }`. A module needing'
					. ' no configuration is written bare, and when it boots is a group heading above it:'
					. ' `\'init\' => array( %1$s::class )`.',
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
	 * A module listed under a heading has said it cannot do its work before that
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
				'%1$s boots on `%2$s`, which has not fired yet. Ask for it from `%2$s` or later, or list'
					. ' it under a heading this plugin can live with.',
				$module,
				$hook
			)
		);
	}

	/**
	 * A Bootable module whose entry never says when it boots.
	 *
	 * Names the two headings that cover almost every module: the plugin's own
	 * loaded action for anything that only needs the rest of the plugin, and
	 * `init` for anything WordPress will not accept before then.
	 *
	 * @param string      $module      The module class declared without timing.
	 * @param string      $loaded_hook The plugin's own loaded action.
	 * @param string|null $file        The bootstrap file, when there is one.
	 * @return self
	 *
	 * @internal
	 */
	public static function boot_timing_undeclared( string $module, string $loaded_hook, ?string $file = null ): self {
		return new self(
			\sprintf(
				'%1$s acts when it is built, so it has to be listed under the hook it acts on. In %2$s move'
					. ' it into a group: `\'%3$s\' => array( %1$s::class )` boots it once the whole plugin'
					. ' is up, and `\'init\' => array( %1$s::class )` waits for WordPress. The top level is'
					. ' for modules that do nothing until something asks.',
				$module,
				null === $file ? 'the entry declaring it' : $file,
				$loaded_hook
			)
		);
	}
}
