<?php

/**
 * Validation API: Property attribute
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Declares a property as part of its class's shape, and describes it.
 *
 * A property carrying any rule, {@see ListOf} or {@see MapOf} is already
 * declared, so this is needed only where nothing else is -- or to describe one:
 *
 * ```
 * #[Property( 'The order to cancel.' )]
 * public int $order_id;
 *
 * #[Property]
 * public Status $status;
 *
 * #[Email]                     // declared by the rule
 * public string $email;
 * ```
 *
 * The description is published with the schema. Whatever is calling reads it
 * to decide what to send, and cannot ask you, so write one wherever the name
 * alone would leave it guessing.
 *
 * A description cannot be translated here: an attribute takes only constant
 * expressions, so `__()` inside one fails when the file is compiled. State a
 * translated one in `args()` on a route or `input_schema()` on an ability.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Property {

	/**
	 * Declare the property.
	 *
	 * @param string $description What it is, in a sentence a stranger can act on.
	 */
	public function __construct(
		public readonly string $description = ''
	) {}
}
