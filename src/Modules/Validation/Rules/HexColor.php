<?php

/**
 * Validation API: HexColor rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * A color written `#rgb` or `#rrggbb`.
 *
 * ```
 * #[HexColor]
 * public string $accent = '#3858e9';
 * ```
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class HexColor extends Rule {

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return array( 'format' => 'hex-color' );
	}
}
