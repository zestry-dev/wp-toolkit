<?php

/**
 * Validation API: Rule
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * A limit on a value, written once and used on any property.
 *
 * A rule narrows a value that already has a shape: how long a string may be,
 * which values a number may take, whether a post exists. The shape itself comes
 * from the property's type, never from a rule.
 *
 * A rule has three halves, and each is optional:
 *
 * - **{@see get_schema()}** -- what JSON Schema can say: `pattern`, `maxLength`,
 *   `enum`. It is published, so a caller reads the limit before sending
 *   anything, and WordPress checks it.
 * - **{@see check()}** -- what a schema cannot say: that a post exists, that an
 *   email is not yet registered. Return `null` when the value is fine, or the
 *   message saying what is wrong.
 * - **{@see sanitize()}** -- clean the value once it has passed. It never
 *   refuses anything: refusing is the job of `check()`. Nothing checks the
 *   cleaned value again, so leave a valid value valid.
 *
 * Write your own by extending this class and overriding the halves you need.
 * `wp zt make rule Slug` writes one into `lib/Rules/`:
 *
 * ```
 * #[\Attribute( \Attribute::TARGET_PROPERTY )]
 * final class Slug extends Rule {
 *
 *     public function get_schema(): array {
 *         return array( 'pattern' => '^[a-z][a-z0-9]*(-[a-z0-9]+)*$', 'maxLength' => 64 );
 *     }
 *
 *     public function sanitize( mixed $value ): mixed {
 *         return strtolower( $value );
 *     }
 * }
 * ```
 *
 * Or state one inline, for a limit only one property has:
 *
 * ```
 * #[Rule( check: array( self::class, 'is_open_workshop' ), sanitize: 'sanitize_title' )]
 * public string $workshop;
 *
 * public static function is_open_workshop( string $value ): ?string {
 *     return get_page_by_path( $value, OBJECT, 'acme_workshop' ) ? null : __( 'There is no open workshop by that name.', 'acme' );
 * }
 * ```
 *
 * Once an inline rule appears on a second property, move it into a class.
 *
 * **On a {@see Structure}, a rule checks the finished object.** Declare it with
 * `\Attribute::TARGET_CLASS` and `check()` receives the object, built and with
 * every property already passed -- the place for a limit that spans several
 * properties, such as an end date after a start date. A route, an ability
 * or an AJAX action cannot carry one -- it answers calls rather than being
 * built from one, and throws on its first call. Give it a property of the
 * Structure instead.
 *
 * ## What `get_schema()` may say
 *
 * Only what WordPress enforces, so nothing is published that is never checked:
 * `format`, `enum`, `pattern`, `minLength`, `maxLength`, `minimum`, `maximum`,
 * `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf`, `minItems`, `maxItems`,
 * `uniqueItems`, `minProperties`, `maxProperties`, `anyOf`, `oneOf`, plus
 * `title` and `description`. Anything else is refused with a message naming
 * the rule.
 *
 * `format` is checked for `email`, `date-time`, `uuid`, `ip` and `hex-color`.
 * WordPress only cleans `uri`, so a rule stating it must also have a `check()`
 * -- {@see Rules\Url} is that rule.
 *
 * Shape keywords -- `type`, `items`, `properties`, `required`,
 * `additionalProperties` -- are refused: those come from the property's type,
 * {@see ListOf} and {@see MapOf}.
 *
 * Every rule on a property must pass. Two of them may not state the same
 * keyword, though, since a schema holds one of each: `#[Slug, Length( max: 100 )]`
 * is refused when `Slug` already states a `maxLength`. Keep one, or write the
 * combined limit as one rule. For "either this or that", state `anyOf` in a
 * single rule.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE )]
class Rule {

	/**
	 * The inline schema, for a rule stated with `#[Rule( schema: ... )]`.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema = array();

	/**
	 * The inline check, for a rule stated with `#[Rule( check: ... )]`.
	 *
	 * @var callable|null
	 */
	private mixed $check = null;

	/**
	 * The inline sanitizer, for a rule stated with `#[Rule( sanitize: ... )]`.
	 *
	 * @var callable|null
	 */
	private mixed $sanitize = null;

	/**
	 * State a rule inline.
	 *
	 * A rule class of your own does not need to call this: every half defaults
	 * to doing nothing.
	 *
	 * @param array<string, mixed> $schema   Schema keywords the value must meet.
	 * @param callable|null        $check    Called with the value. Returns `null` when it is fine, or the message.
	 *                                       A function name or `array( self::class, 'method' )`.
	 * @param callable|null        $sanitize Called with the value once it passed. Returns the cleaned value.
	 */
	public function __construct( array $schema = array(), mixed $check = null, mixed $sanitize = null ) {
		$this->schema   = $schema;
		$this->check    = $check;
		$this->sanitize = $sanitize;
	}

	/**
	 * The schema keywords the value must meet.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return $this->schema;
	}

	/**
	 * Whether the value is fine.
	 *
	 * For a value on a property, `$value` is what was sent, once it has passed
	 * the schema. For an object, a list or a map, it is what was built from it.
	 *
	 * @param mixed $value The value to check.
	 * @return string|null Null when the value is fine, or the message saying what is wrong.
	 */
	public function check( mixed $value ): ?string {
		if ( null === $this->check ) {
			return null;
		}

		$result = \call_user_func( $this->check, $value );

		if ( \is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		// A check that answers in a bool still refuses, rather than passing
		// everything because it never returned a string.
		if ( false === $result ) {
			return \__( 'This value is not valid.', 'zestry-toolkit' );
		}

		return \is_string( $result ) ? $result : null;
	}

	/**
	 * Clean the value once it has passed.
	 *
	 * @param mixed $value The value to clean.
	 * @return mixed The cleaned value.
	 */
	public function sanitize( mixed $value ): mixed {
		return null === $this->sanitize ? $value : \call_user_func( $this->sanitize, $value );
	}

	/**
	 * Whether this rule checks anything in code.
	 *
	 * @return bool
	 *
	 * @internal
	 */
	public function has_check(): bool {
		return null !== $this->check
			|| self::class !== ( new \ReflectionMethod( $this, 'check' ) )->getDeclaringClass()->getName();
	}
}
