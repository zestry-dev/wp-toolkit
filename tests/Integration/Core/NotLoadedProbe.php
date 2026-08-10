<?php

/**
 * A service that exists only to be named and not loaded.
 *
 * `Plugin::bootstrap()` must be able to read a declaration without compiling
 * the class it names -- so this class is autoloadable, declared by one test,
 * and that test asserts it never became loaded. Anything that reintroduces an
 * `is_a( $name, ..., true )` per entry fails there.
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Abstracts\Service;

final class NotLoadedProbe extends Service {
}
