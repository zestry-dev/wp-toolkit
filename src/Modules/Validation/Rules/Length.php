<?php

/**
 * Validation API: Length rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * How many characters a string may have.
 *
 * ```
 * #[Length( min: 1, max: 80 )]
 * public string $title;
 * ```
 *
 * Characters, not bytes: `é` is one.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Length extends Rule {

	/**
	 * Set the limits. Leave one out for no limit on that side.
	 *
	 * @param int|null $min The fewest characters allowed.
	 * @param int|null $max The most characters allowed.
	 */
	public function __construct(
		private readonly ?int $min = null,
		private readonly ?int $max = null
	) {}

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return \array_filter(
			array(
				'minLength' => $this->min,
				'maxLength' => $this->max,
			),
			'is_int'
		);
	}
}
