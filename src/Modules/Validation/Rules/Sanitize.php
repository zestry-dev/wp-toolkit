<?php

/**
 * Validation API: Sanitize rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation\Rules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\Validation\Rule;

/**
 * Clean a value with a function, once it has passed every check.
 *
 * ```
 * #[Sanitize( 'sanitize_title' )]
 * public string $slug;
 *
 * #[Sanitize( 'sanitize_textarea_field' )]
 * public string $note = '';
 * ```
 *
 * It refuses nothing. Several run in the order they are written.
 *
 * Every check sees the value as it was sent, and cleaning comes after them.
 * So a sanitizer has to leave a valid value valid: with
 * `Length( min: 1 ), Sanitize( 'trim' )`, a name of three spaces passes and
 * then becomes empty. Say what you mean about what was sent instead:
 * {@see NotBlank} refuses a value that is only spaces.
 *
 * ```php
 * #[NotBlank, Sanitize( 'trim' )]
 * public string $name;
 * ```
 *
 * @rationale
 * Laravel, Symfony's forms, Zod and Pydantic all clean before checking. That
 * order is not available here: WordPress checks an ability's input against
 * its published schema before any of this runs, and the published schema is
 * what a caller checks before sending. Checking the cleaned value again was
 * also ruled out, since one value could then pass or fail depending on which
 * of two checks you were looking at.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE )]
final class Sanitize extends Rule {

	/**
	 * Set the function.
	 *
	 * @param callable $callback Called with the value; returns the cleaned value. A function name or `array( self::class, 'method' )`.
	 */
	public function __construct( mixed $callback ) {
		parent::__construct( sanitize: $callback );
	}
}
