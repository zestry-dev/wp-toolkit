<?php

/**
 * Validation API: Distinct rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * No item of a list may appear twice.
 *
 * ```
 * #[ListOf( 'int' ), Distinct]
 * public array $post_ids = array();
 * ```
 *
 * It compares the items with one another, never with anything stored. "Not
 * already registered" needs a lookup, which is a rule of your own with a
 * `check()`:
 *
 * ```
 * #[Rule( check: array( self::class, 'is_free' ) )]
 * public string $email;
 *
 * public static function is_free( string $value ): ?string {
 *     return email_exists( $value ) ? __( 'That email is already registered.', 'acme' ) : null;
 * }
 * ```
 *
 * @rationale
 * Not `Unique`: that name reads as "nobody else has this value", which is
 * Laravel's `unique` and the database rule a plugin is most likely to write
 * itself. `Distinct` is Laravel's word for this check.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Distinct extends Rule {

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return array( 'uniqueItems' => true );
	}
}
