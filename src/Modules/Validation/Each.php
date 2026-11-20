<?php

/**
 * Validation API: Each attribute
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Rules for every item of a {@see ListOf}, or every value of a {@see MapOf}.
 *
 * ```
 * #[ListOf( 'int' ), Count( min: 1 ), Each( new Range( 100, 900, step: 100 ) )]
 * public array $weights;
 * ```
 *
 * `Count` applies to the list; `Range` to each number in it. A problem is
 * reported at the item, `$.weights[2]`.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Each {

	/**
	 * The rules each item must meet.
	 *
	 * @var Rule[]
	 */
	public readonly array $rules;

	/**
	 * Declare the rules.
	 *
	 * @param Rule ...$rules The rules each item must meet.
	 */
	public function __construct( Rule ...$rules ) {
		$this->rules = $rules;
	}
}
