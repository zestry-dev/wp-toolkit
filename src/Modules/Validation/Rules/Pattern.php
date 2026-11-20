<?php

/**
 * Validation API: Pattern rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * A regular expression a string must match.
 *
 * ```
 * #[Pattern( '^[A-Z]{2}$' )]
 * public string $country;
 * ```
 *
 * Written without delimiters, the way JSON Schema writes one, since it is
 * published as it is. It matches anywhere in the string unless you anchor it
 * with `^` and `$`: `Pattern( '[a-z]+' )` accepts `ABCa`, since `a` is in
 * there. Nothing anchors it for you, because a caller reads the same pattern
 * and has to reach the same answer.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Pattern extends Rule {

	/**
	 * Set the expression.
	 *
	 * @param string $pattern The expression, without delimiters.
	 */
	public function __construct(
		private readonly string $pattern
	) {}

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return array( 'pattern' => $this->pattern );
	}
}
