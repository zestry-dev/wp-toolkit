<?php

/**
 * Validation API: Url rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * An absolute `http` or `https` address.
 *
 * ```
 * #[Url]
 * public string $website;
 * ```
 *
 * It is not fetched, so an address that is well formed but does not answer
 * still passes.
 *
 * @rationale
 * WordPress publishes `format: uri` and only cleans it with `sanitize_url()`,
 * which returns an empty string for something it cannot read rather than
 * refusing it -- so the check is done here. `wp_http_validate_url()` was not
 * used: it resolves the host, which makes a request's validity depend on DNS.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Url extends Rule {

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return array( 'format' => 'uri' );
	}

	/**
	 * Whether the value is an absolute http or https address.
	 *
	 * @param mixed $value The value to check.
	 * @return string|null Null when the value is fine, or the message saying what is wrong.
	 */
	public function check( mixed $value ): ?string {
		$scheme = \is_string( $value ) ? \wp_parse_url( $value, PHP_URL_SCHEME ) : null;

		if ( false === \filter_var( $value, FILTER_VALIDATE_URL ) || ! \in_array( \strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
			return \__( 'This is not a web address.', 'zestry-toolkit' );
		}

		return null;
	}
}
