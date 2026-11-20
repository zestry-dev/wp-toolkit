<?php

/**
 * Validation API: ListOf attribute
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Says an `array` property is a list, and what each item is.
 *
 * PHP has no typed arrays, so this says what `list<Guest>` would:
 *
 * ```
 * #[ListOf( Guest::class )]
 * public array $guests = array();
 *
 * #[ListOf( 'string' ), Each( new Slug() )]
 * public array $tags = array();
 * ```
 *
 * An item is a class, an enum, a date, or one of `'string'`, `'int'`,
 * `'float'`, `'bool'`, `'object'` (keys of the caller's choosing) and `'mixed'`
 * (anything at all). Rules for each item go in {@see Each}; rules on the
 * property itself, such as {@see Rules\Count}, apply to the list.
 *
 * You always get a list back, indexed from 0, even when the caller sent gaps
 * or `?tags=a,b` in a query string.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class ListOf {

	/**
	 * Declare the list.
	 *
	 * @param string $type What each item is: a class name, or `'string'`, `'int'`, `'float'`, `'bool'`, `'object'`, `'mixed'`.
	 */
	public function __construct(
		public readonly string $type
	) {}
}
