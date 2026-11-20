<?php

/**
 * Validation API: Structure base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * A value with properties of its own -- a booking, an address, a guest -- built
 * from an array by {@see Validation}.
 *
 * ```
 * final class Booking extends Structure {
 *
 *     #[Property( 'Guest email.' ), Email]
 *     public string $email;
 *
 *     #[Property( 'Anything we should know.' )]
 *     public ?string $note = null;
 * }
 * ```
 *
 * Type a property as one and a route, an ability or an AJAX action builds it
 * from what was sent; `$validation->parse( Booking::class, $data )` builds one
 * anywhere else. Either way you get an instance whose every property has been
 * checked.
 *
 * **That is the only way to get one.** The constructor is private, so `new
 * Booking()` is an error, and a subclass cannot declare a constructor of its
 * own. A check belongs in a rule on the class, a cleanup in a rule's
 * `sanitize()`, and a value worked out from others in a method.
 *
 * @rationale
 * Built without a constructor, a class's own constructor would be skipped
 * silently -- a breakpoint there never fires, a check there never happens.
 * Calling it instead means mapping its parameters onto properties. A final,
 * private, empty one removes the question: there is nothing to skip.
 */
abstract class Structure {

	/**
	 * Built by {@see Validation} only.
	 */
	final private function __construct() {}
}
