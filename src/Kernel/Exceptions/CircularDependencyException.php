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
 * class before it finishes resolving, which cannot be satisfied. The plugin
 * tracks the classes it is part-way through building, and raises this the
 * moment one of them is asked for again.
 */
class CircularDependencyException extends ModuleException {
}
