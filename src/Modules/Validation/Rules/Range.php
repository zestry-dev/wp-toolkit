<?php

/**
 * Validation API: Range rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * The values a number may take.
 *
 * ```
 * #[Range( 1, 10 )]
 * public int $seats = 1;
 *
 * #[Range( 100, 900, step: 100 )]
 * public int $weight = 400;
 * ```
 *
 * Both ends are included. `step` asks for a multiple of it, counted from zero
 * rather than from `min`.
 *
 * Prefer whole numbers for the limits. A fraction is enforced exactly, but
 * WordPress writes the limit into its message as a whole number, so
 * `Range( min: 0.5 )` refuses `0.2` with "must be greater than or equal to 0".
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Range extends Rule {

	/**
	 * Set the limits. Leave one out for no limit on that side.
	 *
	 * @param int|float|null $min  The smallest value allowed.
	 * @param int|float|null $max  The largest value allowed.
	 * @param int|float|null $step A number the value must be a multiple of.
	 */
	public function __construct(
		private readonly int|float|null $min = null,
		private readonly int|float|null $max = null,
		private readonly int|float|null $step = null
	) {}

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return \array_filter(
			array(
				'minimum'    => $this->min,
				'maximum'    => $this->max,
				'multipleOf' => $this->step,
			),
			static function ( $limit ): bool {
				return null !== $limit;
			}
		);
	}
}
