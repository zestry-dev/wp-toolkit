<?php

/**
 * Validation API: Keys attribute
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Rules for every key of a {@see MapOf}.
 *
 * ```
 * #[MapOf( 'string' ), Keys( new Slug() )]
 * public array $remap = array();
 * ```
 *
 * A key is always a string by the time a rule sees it, even one PHP stored as
 * a number. Its `pattern` is published through `patternProperties`, which
 * WordPress enforces; anything else a key rule says is checked here.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class Keys {

	/**
	 * The rules each key must meet.
	 *
	 * @var Rule[]
	 */
	public readonly array $rules;

	/**
	 * Declare the rules.
	 *
	 * @param Rule ...$rules The rules each key must meet.
	 */
	public function __construct( Rule ...$rules ) {
		$this->rules = $rules;
	}
}
