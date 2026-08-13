<?php

/**
 * Core API: Module-not-found exception class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Exceptions;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a requested service or module class cannot be resolved.
 *
 * Raised when the class does not exist or is not a Service subclass -- which
 * includes every Module -- so no instance can be created for the requested
 * name. Checked before every instantiation, so it is raised as the class is
 * asked for rather than when something first calls it.
 */
class ModuleNotFoundException extends ModuleException {
}
