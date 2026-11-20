<?php

/**
 * Validation API: NotBlank rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * A string with at least one character that is not a space.
 *
 * ```
 * #[Property( 'Your name.' ), NotBlank, Length( max: 80 ), Sanitize( 'trim' )]
 * public string $name;
 * ```
 *
 * It judges the value as it was sent, like every other rule, so it is what
 * keeps `Sanitize( 'trim' )` from turning three spaces into an empty name.
 * Any kind of space counts as blank, the non-breaking one pasted from a
 * document included.
 *
 * It states no schema keyword, so it combines with `Length` and `Pattern`
 * freely, and a caller learns of it from the refusal rather than the schema.
 * `null` passes where the property's type allows it, and a value that is not
 * a string is left to its type.
 *
 * @rationale
 * Not `Pattern( '\S' )`: it reads like a riddle, takes the one `pattern`
 * slot a property has, and PCRE's `\S` does not count a non-breaking space as
 * a space.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class NotBlank extends Rule {

	/**
	 * Refuse an empty string, or one made only of spaces.
	 *
	 * @param mixed $value The value, as it was sent.
	 * @return string|null Null when the value is fine, or the message saying what is wrong.
	 */
	public function check( mixed $value ): ?string {
		if ( \is_string( $value ) && 1 !== \preg_match( '/[^\s\p{Z}]/u', $value ) ) {
			return \__( 'This must not be blank.', 'zestry-toolkit' );
		}

		return null;
	}
}
