<?php

/**
 * Core API: Module-not-found exception class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Exceptions;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a requested module class cannot be built.
 *
 * Raised when the class does not exist or is not a {@see \Zestry\WPToolkit\Kernel\Abstracts\Module},
 * so no instance could be created for the name. Checked before the declaration
 * is looked up, so a name that could never be a module says that rather than
 * sending you to `bootstrap.php` to add it.
 */
class ModuleNotFoundException extends ModuleException {
}
