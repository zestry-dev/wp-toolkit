<?php

/**
 * Core API: Circular-dependency exception class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Exceptions;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when two modules reach for each other while building.
 *
 * Only `make()` can get here. `get()` publishes the shared instance before the
 * module boots, so anything reaching back for it during that boot receives the
 * in-flight one -- `make()` never publishes, so two modules making each other
 * would recurse until the stack gave out. The plugin tracks the classes it is
 * part-way through building and raises this the moment one is asked for again.
 */
class CircularDependencyException extends ModuleException {
}
