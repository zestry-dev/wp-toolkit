<?php

/**
 * Validation API: Email rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * An email address, cleaned with `sanitize_email()`.
 *
 * ```
 * #[Email]
 * public string $email;
 * ```
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Email extends Rule {

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return array( 'format' => 'email' );
	}

	/**
	 * Clean the value once it has passed.
	 *
	 * @param mixed $value The value to clean.
	 * @return mixed The cleaned value.
	 */
	public function sanitize( mixed $value ): mixed {
		return \is_string( $value ) ? \sanitize_email( $value ) : $value;
	}
}
