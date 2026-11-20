<?php

/**
 * Validation API: OneOf rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * The only values allowed.
 *
 * ```
 * #[OneOf( 'web', 'phone', 'in-person' )]
 * public string $channel = 'web';
 * ```
 *
 * For a set your code also switches on, type the property as an enum instead:
 * you get the case back rather than a string.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class OneOf extends Rule {

	/**
	 * The values allowed.
	 *
	 * @var array<int, mixed>
	 */
	private readonly array $values;

	/**
	 * Set the values.
	 *
	 * @param mixed ...$values The values allowed.
	 */
	public function __construct( mixed ...$values ) {
		$this->values = \array_values( $values );
	}

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return array( 'enum' => $this->values );
	}
}
