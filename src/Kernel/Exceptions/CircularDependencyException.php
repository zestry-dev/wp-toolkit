<?php

/**
 * Core API: Circular-dependency exception class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Exceptions;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when services or modules depend on each other in a cycle.
 *
 * Raised while resolving a class whose dependency graph re-enters that same
 * class before it finishes resolving, which cannot be satisfied. ServicesRepository
 * throws this from its instantiation guard, which tracks classes currently being
 * resolved and detects the re-entry.
 */
class CircularDependencyException extends ModuleException {
}
