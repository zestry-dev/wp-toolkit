<?php

/**
 * Validation API: Ip rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * An IPv4 or IPv6 address.
 *
 * ```
 * #[Ip]
 * public string $address;
 * ```
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Ip extends Rule {

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return array( 'format' => 'ip' );
	}
}
