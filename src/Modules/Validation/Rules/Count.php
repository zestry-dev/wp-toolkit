<?php

/**
 * Validation API: Count rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * How many items a list, or entries a map, may have.
 *
 * ```
 * #[ListOf( Guest::class ), Count( min: 1, max: 8 )]
 * public array $guests;
 * ```
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Count extends Rule {

	/**
	 * Set the limits. Leave one out for no limit on that side.
	 *
	 * @param int|null $min The fewest allowed.
	 * @param int|null $max The most allowed.
	 */
	public function __construct(
		private readonly ?int $min = null,
		private readonly ?int $max = null
	) {}

	/**
	 * The schema keywords the value must meet.
	 *
	 * On a map these are published as `minProperties` and `maxProperties`.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return \array_filter(
			array(
				'minItems' => $this->min,
				'maxItems' => $this->max,
			),
			'is_int'
		);
	}
}
