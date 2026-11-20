<?php

/**
 * Validation API: Uuid rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * A UUID, in its usual hyphenated form.
 *
 * ```
 * #[Uuid]
 * public string $token;
 *
 * #[Uuid( version: 7 )]
 * public string $event_id;
 * ```
 *
 * Any version by default; name one to accept only that version. Upper and
 * lower case are both accepted, and the value reaches your code in lower case,
 * so two spellings of one UUID compare equal.
 *
 * @rationale
 * Not `format: uuid`: WordPress checks it with `wp_is_uuid()`, whose pattern
 * is lowercase-only, and which can pin no version but 4. A published
 * `pattern` says both things to a caller and is enforced on every platform.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Uuid extends Rule {

	/**
	 * Set the version.
	 *
	 * @param int|null $version The only version accepted, 1 to 8. Leave it out for any.
	 * @throws \InvalidArgumentException When the version is not one a UUID can have.
	 */
	public function __construct(
		private readonly ?int $version = null
	) {
		if ( null !== $version && ( $version < 1 || $version > 8 ) ) {
			throw new \InvalidArgumentException(
				\sprintf( 'A UUID has a version from 1 to 8, not %d.', $version )
			);
		}
	}

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		$hex = '[0-9a-fA-F]';

		// The version is the first digit of the third group, and the variant
		// the first digit of the fourth; a versioned UUID always has both.
		$middle = null === $this->version
			? $hex . '{4}-' . $hex . '{4}'
			: $this->version . $hex . '{3}-[89abAB]' . $hex . '{3}';

		return array( 'pattern' => '^' . $hex . '{8}-' . $hex . '{4}-' . $middle . '-' . $hex . '{12}$' );
	}

	/**
	 * Lower-case the value, so one UUID has one spelling.
	 *
	 * @param mixed $value The value, already checked.
	 * @return mixed
	 */
	public function sanitize( mixed $value ): mixed {
		return \is_string( $value ) ? \strtolower( $value ) : $value;
	}
}
