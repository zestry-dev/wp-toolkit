<?php

/**
 * Validation API: MapOf attribute
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Says an `array` property is a map with keys of the caller's choosing, and
 * what each value is.
 *
 * ```
 * #[MapOf( StyleFile::class ), Keys( new Slug() )]
 * public array $styles = array();
 * ```
 *
 * For keys you know in advance, write a class instead: its properties can
 * carry rules, and you get a typed object back. A map is for keys you do not.
 *
 * A value takes the same types as a {@see ListOf} item. Rules for each value go
 * in {@see Each}, rules for each key in {@see Keys}.
 *
 * A list is refused where a map is declared, so `[ "a", "b" ]` is not read as
 * `{ "0": "a", "1": "b" }`. An empty one is accepted either way, since `{}`
 * and `[]` decode to the same empty array.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class MapOf {

	/**
	 * Declare the map.
	 *
	 * @param string $type What each value is: a class name, or `'string'`, `'int'`, `'float'`, `'bool'`, `'object'`, `'mixed'`.
	 */
	public function __construct(
		public readonly string $type
	) {}
}
