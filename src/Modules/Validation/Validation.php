<?php

/**
 * Validation API: Validation module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Validation;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Helpers\Arr;
use Zestry\WPToolkit\Modules\Request\UploadedFile;

/**
 * Describe data with a class, then check it, clean it and build it.
 *
 * A class whose properties are typed and declared is the whole description:
 * the schema a caller reads, the checks a value must pass, and the object you
 * get back. Nothing to extend and nothing to register:
 *
 * ```
 * final class Booking {
 *
 *     #[Property( 'The workshop to book.' )]
 *     public int $workshop_id;
 *
 *     #[Email]
 *     public string $email;
 *
 *     #[ListOf( Guest::class ), Count( max: 8 )]
 *     public array $guests = array();
 *
 *     #[Property]
 *     public ?\DateTimeImmutable $arrives = null;
 * }
 *
 * $booking = $validation->parse( Booking::class, $data );
 *
 * if ( is_wp_error( $booking ) ) {
 *     return $booking; // every problem, keyed by where it is: guests[2].email
 * }
 *
 * $booking->guests[0]->name;
 * ```
 *
 * A route, an ability and an AJAX action use the same declarations on their
 * own properties, and check what arrives before your code runs -- so most of
 * the time you declare and never call this.
 *
 * ## Declaring a property
 *
 * A property is part of the shape when it carries {@see Property}, any
 * {@see Rule}, {@see ListOf} or {@see MapOf}. Its PHP type says what it holds:
 *
 * | Type | Accepts | You get |
 * | --- | --- | --- |
 * | `string`, `int`, `float`, `bool` | that type, or a string that reads as it | the type |
 * | an enum | a case's backing value, or a pure enum's case name | the case |
 * | `\DateTimeImmutable`, `\DateTimeInterface` | a date-time string | the date |
 * | a class | an object matching its declarations | an instance, built without its constructor |
 * | `array` with {@see ListOf} | a list, or a comma-separated string | a list |
 * | `array` with {@see MapOf} | an object with keys of the caller's choosing | an array keyed by them |
 * | `object`, `\stdClass` | any object | a `\stdClass` |
 * | {@see UploadedFile} | an upload, on a REST route only | the file |
 *
 * **No default makes it required.** A default makes it optional, and is what a
 * caller is told leaving it out gets them; `?Type $x = null` is the way to say
 * "optional, with no value".
 *
 * **Types shape, rules narrow.** A rule never changes what kind of value a
 * property holds; it only limits which values of that kind pass. Something
 * with properties of its own -- an address, a guest -- is a class, not a rule.
 *
 * ## In what order
 *
 * For each value: its type and the schema of its rules are checked, then each
 * rule's own `check()`, then it is cast to its type, then each rule's
 * `sanitize()` runs, in the order written. A class, a list or a map is checked
 * from the inside out -- every property, item and entry first, then the rules
 * on the whole -- and a level whose contents failed is not checked as a whole.
 *
 * Every problem is reported, one per place, keyed by a path such as
 * `guests[2].email` or `styles['main.css']`.
 *
 * ## Translating
 *
 * An attribute takes only constant expressions, so `__()` cannot go in one.
 * State what needs working out at runtime as a partial schema over the derived
 * one -- `$overrides` here, `args()` on a route, `input_schema()` on an
 * ability:
 *
 * ```
 * array(
 *     'properties' => array(
 *         'email' => array( 'description' => __( 'Where the receipt goes.', 'acme' ) ),
 *     ),
 * )
 * ```
 *
 * A keyed map is merged into, so naming one description leaves the type, the
 * rules and the required-ness alone. A list -- `required`, an `enum` -- is
 * replaced whole. See [`Arr::replace_recursive()`](../../kernel/arr.md). An empty schema --
 * `properties` naming nothing, a property or `items` given as `array()` --
 * changes nothing.
 */
class Validation extends Module {

	/**
	 * How deep a class may nest before it is a cycle rather than a shape.
	 */
	private const MAX_DEPTH = 10;

	/**
	 * The JSON Schema type for each PHP type a value may be declared as.
	 */
	private const SCALAR_TYPES = array(
		'int'    => 'integer',
		'float'  => 'number',
		'string' => 'string',
		'bool'   => 'boolean',
	);

	/**
	 * The keywords WordPress checks on one kind of value only, by that kind.
	 *
	 * Any other rule keyword -- `enum`, `anyOf`, `oneOf`, `title`,
	 * `description` -- applies to every value.
	 */
	private const KEYWORD_KINDS = array(
		'format'           => 'string',
		'pattern'          => 'string',
		'minLength'        => 'string',
		'maxLength'        => 'string',
		'minimum'          => 'number',
		'maximum'          => 'number',
		'exclusiveMinimum' => 'number',
		'exclusiveMaximum' => 'number',
		'multipleOf'       => 'number',
		'minItems'         => 'array',
		'maxItems'         => 'array',
		'uniqueItems'      => 'array',
		'minProperties'    => 'object',
		'maxProperties'    => 'object',
	);

	/**
	 * Every JSON Schema type, which is what `'mixed'` accepts.
	 */
	private const ANY_TYPE = array( 'array', 'object', 'string', 'number', 'integer', 'boolean', 'null' );

	/**
	 * The schema keywords a rule may state: those WordPress enforces.
	 */
	private const RULE_KEYWORDS = array(
		'title',
		'description',
		'format',
		'enum',
		'minimum',
		'maximum',
		'exclusiveMinimum',
		'exclusiveMaximum',
		'multipleOf',
		'minLength',
		'maxLength',
		'pattern',
		'minItems',
		'maxItems',
		'uniqueItems',
		'minProperties',
		'maxProperties',
		'anyOf',
		'oneOf',
	);

	/**
	 * The formats WordPress refuses a value for, or cleans one with.
	 */
	private const CHECKED_FORMATS = array( 'email', 'date-time', 'uuid', 'ip', 'hex-color', 'text-field', 'textarea-field' );

	/**
	 * The keywords that describe a container's contents, rather than the
	 * container itself.
	 */
	private const CONTENT_KEYWORDS = array( 'items', 'properties', 'required', 'additionalProperties', 'patternProperties', 'default' );

	/**
	 * What each class declares, worked out once.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $slots = array();

	/**
	 * The rules each class carries on itself.
	 *
	 * @var array<string, Rule[]>
	 */
	private array $class_rules = array();

	/**
	 * The properties a class declares, keyed by name.
	 *
	 * Public and protected only: reflection cannot reliably reach a private
	 * property declared on an ancestor.
	 *
	 * @param object|string $target The object, or its class name.
	 * @return array<string, \ReflectionProperty>
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 */
	public function get_properties( object|string $target ): array {
		return \array_map(
			static function ( array $slot ): \ReflectionProperty {
				return $slot['property'];
			},
			$this->get_slots( \is_object( $target ) ? $target::class : $target )
		);
	}

	/**
	 * The uploads a class declares, and whether each must be present.
	 *
	 * Apart from every other property because no schema can describe a file:
	 * only a REST route can take one, read from the request's own file params.
	 *
	 * @param object|string $target The object, or its class name.
	 * @return array<string, bool> Whether each file must be present, keyed by name.
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 *
	 * @internal
	 */
	public function get_file_properties( object|string $target ): array {
		$files = array();

		foreach ( $this->get_slots( \is_object( $target ) ? $target::class : $target ) as $name => $slot ) {
			if ( \in_array( $slot['kind'], array( 'file', 'files' ), true ) ) {
				$files[ $name ] = ! $slot['has_default'];
			}
		}

		return $files;
	}

	/**
	 * The JSON Schema describing everything a class accepts.
	 *
	 * `$overrides` is a partial schema stated over the derived one, for what an
	 * attribute cannot carry: a translated description, an `enum` worked out at
	 * runtime. Anything you state wins; everything you leave out keeps what the
	 * declarations gave it.
	 *
	 * A file is left out: no schema can describe one.
	 *
	 * @param object|string        $target    The object, or its class name.
	 * @param array<string, mixed> $overrides A partial schema stated over the derived one.
	 * @return array<string, mixed> A schema, or an empty array when nothing is declared.
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 */
	public function get_schema( object|string $target, array $overrides = array() ): array {
		$class_name = \is_object( $target ) ? $target::class : $target;
		$schema     = array() === $this->get_slots( $class_name ) ? array() : $this->get_object_schema( $class_name, 0 );

		return Arr::replace_recursive( $schema, self::get_stated_overrides( $overrides ) );
	}

	/**
	 * Check data against a class, and name every problem.
	 *
	 * @param string               $class_name     The class describing the data.
	 * @param mixed                $data      The data, as it arrived.
	 * @param array<string, mixed> $overrides A partial schema stated over the derived one.
	 * @return array<string, string> Each problem's message, keyed by its path. Empty when the data passed.
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 */
	public function validate( string $class_name, mixed $data, array $overrides = array() ): array {
		$errors = array();

		$this->walk( $this->get_root_node( $class_name ), $data, $this->get_schema( $class_name, $overrides ), '', true, $errors );

		return $errors;
	}

	/**
	 * Check data against a class, and build an instance of it.
	 *
	 * ```
	 * $booking = $validation->parse( Booking::class, json_decode( $body, true ) );
	 *
	 * if ( is_wp_error( $booking ) ) {
	 *     wp_send_json_error( $booking->get_error_data()['errors'], 400 );
	 * }
	 * ```
	 *
	 * The refusal's message reads every problem as one sentence each, and its
	 * data carries them under `errors`, keyed by path, and the top-level names
	 * involved under `params`.
	 *
	 * @template T of object
	 * @param class-string<T>      $class_name     The class describing the data.
	 * @param mixed                $data      The data, as it arrived.
	 * @param array<string, mixed> $overrides A partial schema stated over the derived one.
	 * @return T|\WP_Error The instance, checked and cleaned, or the refusal.
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 */
	public function parse( string $class_name, mixed $data, array $overrides = array() ): object {
		$errors = array();

		[ $passed, $built ] = $this->walk( $this->get_root_node( $class_name ), $data, $this->get_schema( $class_name, $overrides ), '', true, $errors );

		return $passed ? $built : $this->get_refusal( $errors, 'validation_failed' );
	}

	/**
	 * Check and build the values for an object's own declared properties.
	 *
	 * The step before {@see bind()}, for an object that is built once and
	 * answers many calls -- a route, an ability, an action. The values are
	 * returned rather than assigned, so the object is untouched when they fail.
	 *
	 * Pass `$check` false when the platform has already checked them, and only
	 * the casting, cleaning and building is left.
	 *
	 * @param object               $target     The object declaring the properties.
	 * @param array<string, mixed> $values     The values, as they arrived.
	 * @param string               $error_code The code a refusal carries.
	 * @param array<string, mixed> $overrides  A partial schema stated over the derived one.
	 * @param bool                 $check      Whether to check the values, or only build them.
	 * @return array<string, mixed>|\WP_Error The values, ready to bind, or the refusal.
	 * @throws \InvalidArgumentException When a declaration cannot be described, or the object carries a rule of its own.
	 *
	 * @internal
	 */
	public function resolve( object $target, array $values, string $error_code = 'validation_failed', array $overrides = array(), bool $check = true ) {
		$class_name = $target::class;

		$this->get_slots( $class_name );

		if ( array() !== $this->class_rules[ $class_name ] ) {
			// A rule on the class checks the finished object, and this one is
			// never built here -- it is the handler, filled in by bind().
			throw new \InvalidArgumentException(
				\sprintf(
					'%s carries a rule of its own, and it answers calls rather than being built from one. Move the properties into a class, and the rule onto that.',
					$class_name
				)
			);
		}

		$errors = array();

		[ $passed, $built ] = $this->walk_properties( $class_name, $values, $this->get_schema( $class_name, $overrides ), '', $check, $errors );

		return $passed ? $built : $this->get_refusal( $errors, $error_code, array( 'status' => 400 ) );
	}

	/**
	 * Check one property's value, and name every problem in it.
	 *
	 * @param object|string        $target The object, or its class name.
	 * @param string               $name   The property's name.
	 * @param mixed                $value  The value, as it arrived.
	 * @param array<string, mixed> $schema The property's schema, as the platform holds it.
	 * @return array<string, string> Each problem's message, keyed by its path.
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 *
	 * @internal
	 */
	public function get_property_errors( object|string $target, string $name, mixed $value, array $schema ): array {
		$slots = $this->get_slots( \is_object( $target ) ? $target::class : $target );

		if ( ! isset( $slots[ $name ] ) ) {
			return array();
		}

		$errors = array();

		/*
		 * The arg as the platform registered it: a schema plus how to process
		 * it. Only the processing half goes -- `items`, `properties` and a
		 * class's `required` list are what the walk checks the contents against.
		 */
		$schema = \array_diff_key( $schema, \array_flip( array( 'validate_callback', 'sanitize_callback', 'arg_options' ) ) );

		$this->walk( $slots[ $name ], $value, $schema, $name, true, $errors );

		return $errors;
	}

	/**
	 * Return an object's declared properties to what a first call would see.
	 *
	 * A route, an ability and an action are each built once and answer many
	 * calls. Without this, a property left out of the second call still
	 * holds what the first one sent.
	 *
	 * A property with a default goes back to it. One without goes back to
	 * uninitialized, so a required one that is missing fails as it would on a
	 * first call rather than reusing a stale value. {@see bind()} calls this
	 * first, so binding is enough on its own.
	 *
	 * @internal
	 * @param object $target The object whose properties to clear.
	 * @return void
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 */
	public function reset( object $target ): void {
		foreach ( $this->get_slots( $target::class ) as $name => $slot ) {
			if ( $slot['property']->isReadOnly() ) {
				// Left alone. PHP refuses to unset an initialized readonly
				// property, and assign() refuses one in a message that says what
				// to do about it -- clearing it here would replace that with a fatal.
				continue;
			}

			if ( $slot['has_default'] ) {
				$this->assign( $target, $slot['property'], $name, $slot['default'] );

				continue;
			}

			$this->unset_property( $target, $name );
		}
	}

	/**
	 * Assign built values onto an object's declared properties.
	 *
	 * The values are taken as they are: {@see resolve()} is what builds them. A
	 * property the values leave out goes back to its default, or to
	 * uninitialized; {@see reset()} runs first.
	 *
	 * @internal
	 * @param object              $target The object to fill.
	 * @param array<string,mixed> $values The built values, keyed by property name.
	 * @return void
	 * @throws \InvalidArgumentException When a declaration cannot be described, or a property will not take its value.
	 */
	public function bind( object $target, array $values ): void {
		$this->reset( $target );

		foreach ( $this->get_slots( $target::class ) as $name => $slot ) {
			if ( \array_key_exists( $name, $values ) ) {
				$this->assign( $target, $slot['property'], $name, $values[ $name ] );
			} elseif ( $slot['has_default'] && ! $slot['property']->isInitialized( $target ) ) {
				$this->assign( $target, $slot['property'], $name, $slot['default'] );
			}
		}
	}

	/**
	 * Reduce an object to the data that would build it again.
	 *
	 * An enum becomes its value, a date an RFC 3339 string, and an object of a
	 * declared class an array of its declared properties: what a caller would
	 * send, and what JSON can carry.
	 *
	 * @param mixed $value The object, or any value.
	 * @return mixed
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 */
	public function to_array( mixed $value ): mixed {
		if ( $value instanceof \BackedEnum ) {
			return $value->value;
		}

		if ( $value instanceof \UnitEnum ) {
			return $value->name;
		}

		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( \DateTimeInterface::RFC3339 );
		}

		if ( \is_array( $value ) ) {
			return \array_map( array( $this, 'to_array' ), $value );
		}

		if ( $value instanceof \stdClass ) {
			return \array_map( array( $this, 'to_array' ), (array) $value );
		}

		if ( ! \is_object( $value ) || $value instanceof UploadedFile ) {
			return $value instanceof UploadedFile ? $value->to_array() : $value;
		}

		$data = array();

		foreach ( $this->get_slots( $value::class ) as $name => $slot ) {
			if ( $slot['property']->isInitialized( $value ) ) {
				$data[ $name ] = $this->to_array( $slot['property']->getValue( $value ) );
			}
		}

		return $data;
	}

	/**
	 * The error that names every problem.
	 *
	 * @param array<string, string> $errors Each problem's message, keyed by its path.
	 * @param string                $code   The error code to carry.
	 * @param array<string, mixed>  $data   Anything to attach, such as a status.
	 * @return \WP_Error
	 *
	 * @internal
	 */
	public function get_refusal( array $errors, string $code, array $data = array() ): \WP_Error {
		$params = array();

		foreach ( \array_keys( $errors ) as $path ) {
			$name = (string) \preg_replace( '/[.\[].*$/', '', (string) $path );

			if ( '' !== $name ) {
				$params[ $name ] = $name;
			}
		}

		$messages = array();

		foreach ( $errors as $path => $message ) {
			// A rule's own message is about the value, not where it is.
			$messages[] = '' === (string) $path || \str_starts_with( $message, (string) $path . ' ' )
				? $message
				: \sprintf(
					/* translators: 1: where the value is, such as guests[0].email; 2: what is wrong with it. */
					\__( '%1$s: %2$s', 'zestry-toolkit' ),
					$path,
					$message
				);
		}

		return new \WP_Error(
			$code,
			\implode( ' ', $messages ),
			\array_merge(
				$data,
				array(
					'errors' => $errors,
					'params' => \array_values( $params ),
				)
			)
		);
	}

	/**
	 * What one class declares, worked out once.
	 *
	 * @param string $class_name The class name.
	 * @return array<string, array<string, mixed>> Each declared property, keyed by name.
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 */
	private function get_slots( string $class_name ): array {
		if ( isset( $this->slots[ $class_name ] ) ) {
			return $this->slots[ $class_name ];
		}

		$reflection = new \ReflectionClass( $class_name );
		$slots      = array();

		foreach ( $reflection->getProperties( \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED ) as $property ) {
			$slot = $this->get_slot( $property );

			if ( null !== $slot ) {
				$slots[ $property->getName() ] = $slot;
			}
		}

		$class_rules = array();

		foreach ( $reflection->getAttributes( Rule::class, \ReflectionAttribute::IS_INSTANCEOF ) as $attribute ) {
			$class_rules[] = $this->get_checked_rule( $attribute->newInstance(), $class_name );
		}

		$this->class_rules[ $class_name ] = $class_rules;
		$this->slots[ $class_name ]       = $slots;

		return $slots;
	}

	/**
	 * What one property declares, or null when it is not part of the shape.
	 *
	 * @param \ReflectionProperty $property The property.
	 * @return array<string, mixed>|null
	 * @throws \InvalidArgumentException When the declaration cannot be described.
	 */
	private function get_slot( \ReflectionProperty $property ): ?array {
		$label = $property->getDeclaringClass()->getName() . '::$' . $property->getName();
		$first = static function ( string $attribute ) use ( $property ): ?object {
			$found = $property->getAttributes( $attribute );

			return array() === $found ? null : $found[0]->newInstance();
		};

		$declared = $first( Property::class );
		$list     = $first( ListOf::class );
		$map      = $first( MapOf::class );
		$each     = $first( Each::class );
		$keys     = $first( Keys::class );
		$rules    = \array_map(
			function ( \ReflectionAttribute $attribute ) use ( $label ): Rule {
				return $this->get_checked_rule( $attribute->newInstance(), $label );
			},
			$property->getAttributes( Rule::class, \ReflectionAttribute::IS_INSTANCEOF )
		);

		$this->assert_one_rule_per_keyword( $rules, $label );

		if ( null === $declared && null === $list && null === $map && null === $each && null === $keys && array() === $rules ) {
			return null;
		}

		if ( $property->isStatic() ) {
			// One value shared by every instance, so filling it would carry one
			// caller's value into the next one's call.
			throw new \InvalidArgumentException(
				\sprintf( '%s is static. A declared property belongs to one value, and a static one belongs to every value at once.', $label )
			);
		}

		$type = $property->getType();

		if ( ! $type instanceof \ReflectionNamedType || 'mixed' === $type->getName() ) {
			throw new \InvalidArgumentException(
				\sprintf( '%s needs a single declared type. A union, `mixed` or an untyped property cannot be described to a caller.', $label )
			);
		}

		$name = $type->getName();

		if ( null !== $list && null !== $map ) {
			throw new \InvalidArgumentException( \sprintf( '%s says it is both a list and a map. Keep one of ListOf and MapOf.', $label ) );
		}

		if ( 'array' !== $name && ( null !== $list || null !== $map ) ) {
			throw new \InvalidArgumentException( \sprintf( '%s is typed %s, so ListOf or MapOf describes nothing. Type it as an array, or remove them.', $label, $name ) );
		}

		if ( null !== $each && null === $list && null === $map ) {
			throw new \InvalidArgumentException( \sprintf( '%s has Each without ListOf or MapOf, so there are no items for it to apply to.', $label ) );
		}

		if ( null !== $keys && null === $map ) {
			throw new \InvalidArgumentException( \sprintf( '%s has Keys without MapOf. Only a map has keys of the caller\'s choosing.', $label ) );
		}

		if ( 'array' === $name ) {
			if ( null === $list && null === $map ) {
				throw new \InvalidArgumentException(
					\sprintf(
						'%s is an array that does not say what it holds. Add #[ListOf( Thing::class )] for a list, or #[MapOf( Thing::class )] for keys of the caller\'s choosing. For fixed keys, write a class.',
						$label
					)
				);
			}

			$item = $this->get_item_node( ( $list ?? $map )->type, null !== $each ? $each->rules : array(), $label );

			if ( null !== $map && \in_array( $item['kind'], array( 'file', 'files' ), true ) ) {
				throw new \InvalidArgumentException( \sprintf( '%s is a map of files. Uploads arrive as a list: use ListOf.', $label ) );
			}

			$node = array(
				'kind' => null !== $list ? ( 'file' === $item['kind'] ? 'files' : 'list' ) : 'map',
				'type' => 'array',
				'item' => $item,
				'keys' => null !== $keys ? $this->get_checked_rules( $keys->rules, $label ) : array(),
			);
		} else {
			$node = $this->get_type_node( $name, $label );
		}

		$this->assert_rules_fit( $node, $rules, $label );

		if ( array() !== $node['keys'] ) {
			// A key is always a string, whatever the map holds.
			$this->assert_rules_fit(
				array(
					'kind' => 'scalar',
					'type' => 'string',
				),
				$node['keys'],
				$label
			);
		}

		[ $has_default, $default ] = $this->get_declared_default( $property );

		return \array_merge(
			$node,
			array(
				'property'    => $property,
				'nullable'    => $type->allowsNull(),
				'rules'       => $rules,
				'description' => null !== $declared ? $declared->description : '',
				'has_default' => $has_default,
				'default'     => $default,
			)
		);
	}

	/**
	 * The node for each item of a list, or each value of a map.
	 *
	 * @param string $type  What ListOf or MapOf named.
	 * @param Rule[] $rules The rules in Each.
	 * @param string $label The property, for a message.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When the type cannot be described.
	 */
	private function get_item_node( string $type, array $rules, string $label ): array {
		if ( 'mixed' === $type ) {
			$node = array( 'kind' => 'mixed' );
		} else {
			$node = $this->get_type_node( $type, $label );
		}

		$this->assert_rules_fit( $node, $this->get_checked_rules( $rules, $label ), $label );

		return \array_merge(
			array(
				'type'     => $type,
				'item'     => null,
				'keys'     => array(),
				'nullable' => false,
				'rules'    => $rules,
			),
			$node
		);
	}

	/**
	 * The node for a value of one type.
	 *
	 * @param string $type  A scalar type name, `object`, or a class name.
	 * @param string $label The property, for a message.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When the type cannot be described.
	 */
	private function get_type_node( string $type, string $label ): array {
		$kind = match ( true ) {
			isset( self::SCALAR_TYPES[ $type ] )                         => 'scalar',
			'object' === $type || \stdClass::class === $type             => 'object',
			\enum_exists( $type )                                        => 'enum',
			\is_a( $type, \DateTimeInterface::class, true )              => 'date',
			\is_a( $type, UploadedFile::class, true )                    => 'file',
			\class_exists( $type ) && ! ( new \ReflectionClass( $type ) )->isAbstract() => 'class',
			default                                                      => null,
		};

		if ( null === $kind ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'%s is typed %s, which is neither string, int, float, bool, an enum, a date, nor a class that can be built.',
					$label,
					$type
				)
			);
		}

		return array(
			'kind' => $kind,
			'type' => $type,
			'item' => null,
			'keys' => array(),
		);
	}

	/**
	 * The node a whole class is walked from.
	 *
	 * @param string $class_name The class name.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When the class declares no properties.
	 */
	private function get_root_node( string $class_name ): array {
		if ( array() === $this->get_slots( $class_name ) ) {
			// Nothing to check against, so every value would pass.
			throw new \InvalidArgumentException(
				\sprintf( '%s declares no properties, so nothing describes it. Give its properties #[Property] or a rule.', $class_name )
			);
		}

		return array(
			'kind'     => 'class',
			'type'     => $class_name,
			'item'     => null,
			'keys'     => array(),
			'nullable' => false,
			'rules'    => array(),
		);
	}

	/**
	 * Refuse a rule whose schema says something WordPress never checks.
	 *
	 * @param Rule   $rule  The rule.
	 * @param string $label Where it was declared, for a message.
	 * @return Rule The same rule.
	 * @throws \InvalidArgumentException When the schema has a keyword or format that would not be checked.
	 */
	private function get_checked_rule( Rule $rule, string $label ): Rule {
		$schema  = $rule->get_schema();
		$unknown = \array_diff( \array_keys( $schema ), self::RULE_KEYWORDS );

		if ( array() !== $unknown ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The rule %1$s on %2$s states "%3$s", which a rule cannot say. The shape -- type, items, properties, required -- comes from the property\'s type, ListOf and MapOf; a rule says only what WordPress checks: %4$s.',
					$rule::class,
					$label,
					\implode( '", "', $unknown ),
					\implode( ', ', self::RULE_KEYWORDS )
				)
			);
		}

		$format = $schema['format'] ?? null;

		if ( null !== $format && ! \in_array( $format, self::CHECKED_FORMATS, true ) && ! ( 'uri' === $format && $rule->has_check() ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The rule %1$s on %2$s states the format "%3$s", which WordPress does not check. Checked formats are %4$s; "uri" is allowed in a rule with a check() of its own.',
					$rule::class,
					$label,
					\is_scalar( $format ) ? (string) $format : \gettype( $format ),
					\implode( ', ', self::CHECKED_FORMATS )
				)
			);
		}

		return $rule;
	}

	/**
	 * Refuse any of several rules whose schema says something WordPress never checks.
	 *
	 * @param Rule[] $rules The rules.
	 * @param string $label Where they were declared, for a message.
	 * @return Rule[] The same rules.
	 * @throws \InvalidArgumentException When a schema has a keyword or format that would not be checked.
	 */
	private function get_checked_rules( array $rules, string $label ): array {
		foreach ( $rules as $rule ) {
			$this->get_checked_rule( $rule, $label );
		}

		$this->assert_one_rule_per_keyword( $rules, $label );

		return $rules;
	}

	/**
	 * Refuse two rules on one value that state the same keyword.
	 *
	 * @param Rule[] $rules The rules on one value.
	 * @param string $label Where they were declared, for a message.
	 * @return void
	 * @throws \InvalidArgumentException When two rules state one keyword.
	 *
	 * @rationale
	 * A schema has one slot per keyword, so merging the two kept whichever came
	 * last: `#[Slug, Length( max: 100 )]` published 100 over Slug's 64, and the
	 * tighter limit was lost without a word. Keeping the stricter value needs an
	 * answer per keyword, and two patterns have none; WordPress does not check
	 * `allOf`, which would have kept both. So the declaration is refused.
	 */
	private function assert_one_rule_per_keyword( array $rules, string $label ): void {
		$stated_by = array();

		foreach ( $rules as $rule ) {
			foreach ( \array_keys( $rule->get_schema() ) as $keyword ) {
				if ( isset( $stated_by[ $keyword ] ) ) {
					throw new \InvalidArgumentException(
						\sprintf(
							'The rules %1$s and %2$s on %3$s both state "%4$s", and a schema holds one. Keep one of them, or write the combined limit as one rule.',
							$stated_by[ $keyword ]::class,
							$rule::class,
							$label,
							$keyword
						)
					);
				}

				$stated_by[ $keyword ] = $rule;
			}
		}
	}

	/**
	 * Refuse a rule whose keywords WordPress would never check on this value.
	 *
	 * @param array<string, mixed> $node  What the value was declared as.
	 * @param Rule[]               $rules The rules on it.
	 * @param string               $label Where they were declared, for a message.
	 * @return void
	 * @throws \InvalidArgumentException When a keyword does not apply to the value, or restates what its type says.
	 *
	 * @rationale
	 * WordPress checks `maxLength` on strings, `minimum` on numbers and
	 * `minItems` on arrays, and skips each everywhere else -- so
	 * `#[Length( max: 3 )] public int $code` was published and never enforced.
	 * Zod cannot express that (`.min()` lives on each type), and Pydantic
	 * refuses it when the model is built; this does the latter. A rule also may
	 * not restate what the type already says: `#[Email]` on a date replaced its
	 * `date-time` format, and the value then failed to become a date.
	 */
	private function assert_rules_fit( array $node, array $rules, string $label ): void {
		$kind = $this->get_keyword_kind( $node );

		foreach ( $rules as $rule ) {
			foreach ( $rule->get_schema() as $keyword => $stated ) {
				if ( 'date' === $node['kind'] && 'format' === $keyword ) {
					throw new \InvalidArgumentException(
						\sprintf(
							'The rule %1$s on %2$s states a format, and the property\'s type already makes it "date-time". Remove the rule, or type the property as a string.',
							$rule::class,
							$label
						)
					);
				}

				if ( 'enum' === $node['kind'] && 'enum' === $keyword ) {
					$cases   = $this->get_enum_schema( $node['type'] )['enum'];
					$foreign = \array_diff( (array) $stated, $cases );

					if ( array() !== $foreign ) {
						throw new \InvalidArgumentException(
							\sprintf(
								'The rule %1$s on %2$s allows "%3$s", which is not a case of %4$s. A rule can narrow an enum\'s cases, never add to them.',
								$rule::class,
								$label,
								\implode( '", "', \array_map( 'strval', $foreign ) ),
								$node['type']
							)
						);
					}

					continue;
				}

				$applies_to = self::KEYWORD_KINDS[ $keyword ] ?? null;

				if ( null === $applies_to || 'any' === $kind || $applies_to === $kind ) {
					continue;
				}

				// A count on a map is a count of its entries, and is published as one.
				if ( 'map' === $node['kind'] && \in_array( $keyword, array( 'minItems', 'maxItems' ), true ) ) {
					continue;
				}

				throw new \InvalidArgumentException(
					\sprintf(
						'The rule %1$s on %2$s states "%3$s", which WordPress checks only on %4$s values, so on %5$s it would be published and never enforced. Remove the rule, or change the property\'s type.',
						$rule::class,
						$label,
						$keyword,
						$applies_to,
						$this->get_kind_label( $node )
					)
				);
			}
		}
	}

	/**
	 * Which of the keyword families in KEYWORD_KINDS a value answers to.
	 *
	 * @param array<string, mixed> $node What the value was declared as.
	 * @return string `string`, `number`, `array`, `object`, `any`, or `none`.
	 */
	private function get_keyword_kind( array $node ): string {
		return match ( $node['kind'] ) {
			'scalar' => match ( $node['type'] ) {
				'string'       => 'string',
				'int', 'float' => 'number',
				default        => 'none',
			},
			'date'   => 'string',
			'list'   => 'array',
			'map', 'object', 'class' => 'object',
			'mixed'  => 'any',
			default  => 'none',
		};
	}

	/**
	 * A value's kind, as a message names it.
	 *
	 * @param array<string, mixed> $node What the value was declared as.
	 * @return string
	 */
	private function get_kind_label( array $node ): string {
		return match ( $node['kind'] ) {
			'scalar'         => ( 'int' === $node['type'] ? 'an ' : 'a ' ) . $node['type'],
			'list'           => 'a list',
			'map'            => 'a map',
			'date'           => 'a date',
			'enum'           => 'an enum',
			'file', 'files'  => 'an upload',
			default          => 'an object',
		};
	}

	/**
	 * The schema for one class.
	 *
	 * @param string $class_name The class name.
	 * @param int    $depth How deep this call already is.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When a declaration cannot be described, or the nesting does not end.
	 */
	private function get_object_schema( string $class_name, int $depth ): array {
		if ( $depth > self::MAX_DEPTH ) {
			throw new \InvalidArgumentException(
				\sprintf( '%s nests more than %d deep. A class that contains itself has no schema.', $class_name, self::MAX_DEPTH )
			);
		}

		$properties = array();
		$required   = array();

		foreach ( $this->get_slots( $class_name ) as $name => $slot ) {
			if ( \in_array( $slot['kind'], array( 'file', 'files' ), true ) ) {
				// No schema can describe an upload, and WordPress keeps them out
				// of a request's parameters: only a route reads one, apart.
				continue;
			}

			$properties[ $name ] = $this->get_slot_schema( $slot, $depth );

			if ( '' !== $slot['description'] ) {
				$properties[ $name ] = array( 'description' => $slot['description'] ) + $properties[ $name ];
			}

			if ( $slot['has_default'] ) {
				$default = $this->to_array( $slot['default'] );

				// An empty array is `[]` in JSON, which no object schema accepts.
				$properties[ $name ]['default'] = array() === $default && \in_array( 'object', (array) ( $properties[ $name ]['type'] ?? array() ), true ) ? (object) array() : $default;
			} else {
				$required[] = $name;
			}
		}

		$schema = array( 'type' => 'object' );

		foreach ( $this->class_rules[ $class_name ] as $rule ) {
			$schema = \array_merge( $schema, $rule->get_schema() );
		}

		$schema['properties'] = $properties;

		if ( array() !== $required ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * The schema for one node: a property, or an item of one.
	 *
	 * @param array<string, mixed> $node  The node.
	 * @param int                  $depth How deep this call already is.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 */
	private function get_slot_schema( array $node, int $depth ): array {
		$schema = match ( $node['kind'] ) {
			'scalar' => array( 'type' => self::SCALAR_TYPES[ $node['type'] ] ),
			'mixed'  => array( 'type' => self::ANY_TYPE ),
			'object' => array( 'type' => 'object' ),
			'enum'   => $this->get_enum_schema( $node['type'] ),
			'date'   => array(
				'type'   => 'string',
				'format' => 'date-time',
			),
			'list'   => array(
				'type'  => 'array',
				'items' => $this->get_slot_schema( $node['item'], $depth ),
			),
			'map'    => $this->get_map_schema( $node, $depth ),
			default  => $this->get_class_schema( $node['type'], $depth ),
		};

		foreach ( $node['rules'] as $rule ) {
			$stated = $rule->get_schema();

			if ( 'map' === $node['kind'] ) {
				// A count on a map is a count of its entries, which JSON Schema
				// spells differently.
				foreach ( array(
					'minItems' => 'minProperties',
					'maxItems' => 'maxProperties',
				) as $from => $to ) {
					if ( isset( $stated[ $from ] ) ) {
						$stated[ $to ] = $stated[ $from ];
						unset( $stated[ $from ] );
					}
				}
			}

			$schema = \array_merge( $schema, $stated );
		}

		if ( $node['nullable'] ) {
			$schema['type'] = array( $schema['type'], 'null' );

			if ( isset( $schema['enum'] ) ) {
				$schema['enum'][] = null;
			}
		}

		return $schema;
	}

	/**
	 * The schema for a property typed as a class.
	 *
	 * @param string $class_name The class name.
	 * @param int    $depth How deep this call already is.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When the class declares nothing.
	 */
	private function get_class_schema( string $class_name, int $depth ): array {
		if ( array() === $this->get_slots( $class_name ) ) {
			// A schema saying "an object" and nothing more would accept anything
			// and build an empty instance -- worse than refusing to publish one.
			throw new \InvalidArgumentException(
				\sprintf(
					'%s declares no properties, so nothing describes it. Give its properties #[Property] or a rule, or type the value as object.',
					$class_name
				)
			);
		}

		return $this->get_object_schema( $class_name, $depth + 1 );
	}

	/**
	 * The schema for a map.
	 *
	 * @param array<string, mixed> $node  The map's node.
	 * @param int                  $depth How deep this call already is.
	 * @return array<string, mixed>
	 */
	private function get_map_schema( array $node, int $depth ): array {
		$values  = $this->get_slot_schema( $node['item'], $depth );
		$pattern = null;

		foreach ( $node['keys'] as $rule ) {
			$pattern = $rule->get_schema()['pattern'] ?? $pattern;
		}

		if ( null === $pattern ) {
			return array(
				'type'                 => 'object',
				'additionalProperties' => $values,
			);
		}

		return array(
			'type'                 => 'object',
			'patternProperties'    => array( $pattern => $values ),
			'additionalProperties' => false,
		);
	}

	/**
	 * The schema for an enum: the values it accepts, and nothing else.
	 *
	 * A backed enum sends its backing value; a pure enum its case names.
	 *
	 * @param string $enum_name The enum's class name.
	 * @return array<string, mixed>
	 */
	private function get_enum_schema( string $enum_name ): array {
		$backing = ( new \ReflectionEnum( $enum_name ) )->getBackingType();

		return array(
			'type' => $backing instanceof \ReflectionNamedType ? self::SCALAR_TYPES[ $backing->getName() ] : 'string',
			'enum' => \array_map(
				static function ( \UnitEnum $member ) {
					return $member instanceof \BackedEnum ? $member->value : $member->name;
				},
				$enum_name::cases()
			),
		);
	}

	/**
	 * Check, clean and build one value.
	 *
	 * @param array<string, mixed>  $node   What the value was declared as.
	 * @param mixed                 $value  The value, as it arrived.
	 * @param array<string, mixed>  $schema The value's schema.
	 * @param string                $path   Where the value is, for a message.
	 * @param bool                  $check  Whether to check the value, or only build it.
	 * @param array<string, string> $errors Collects every problem, keyed by path.
	 * @return array{0: bool, 1: mixed} Whether it passed, and the value built.
	 */
	private function walk( array $node, mixed $value, array $schema, string $path, bool $check, array &$errors ): array {
		if ( null === $value && $node['nullable'] ) {
			return array( true, null );
		}

		$label = '' === $path ? \__( 'The value', 'zestry-toolkit' ) : $path;

		$container = \in_array( $node['kind'], array( 'list', 'map', 'class' ), true );

		if ( $check && 'mixed' !== $node['kind'] ) {
			// A container's type only, for now: its own limits wait until
			// everything in it has been checked, so a list one item too long
			// still reports what is wrong inside it.
			$valid = \rest_validate_value_from_schema( $value, $container ? \array_intersect_key( $schema, array( 'type' => true ) ) : $schema, $label );

			if ( \is_wp_error( $valid ) ) {
				return $this->fail( $errors, $path, $valid->get_error_message() );
			}
		}

		if ( 'mixed' === $node['kind'] ) {
			// Anything at all, so nothing to check -- and nothing to cast:
			// WordPress picks the first of several types a value could be, and
			// `1` could be a list.
			return array( true, $value );
		}

		switch ( $node['kind'] ) {
			case 'list':
				[ $passed, $built ] = $this->walk_list( $node, $value, $schema, $path, $check, $errors );
				break;

			case 'map':
				[ $passed, $built ] = $this->walk_map( $node, $value, $schema, $path, $check, $errors );
				break;

			case 'class':
				[ $passed, $built ] = $this->walk_object( $node['type'], $value, $schema, $path, $check, $errors );
				break;

			default:
				// A value with no contents: checking it as a whole is all there is,
				// and the rules see it as it was sent -- the order a route runs in.
				if ( $check ) {
					foreach ( $node['rules'] as $rule ) {
						$message = $this->get_rule_message( $rule, $value, $path );

						if ( null !== $message ) {
							return $this->fail( $errors, $path, $message );
						}
					}
				}

				$cast = \rest_sanitize_value_from_schema( $value, $schema, $label );

				if ( \is_wp_error( $cast ) ) {
					return $this->fail( $errors, $path, $cast->get_error_message() );
				}

				foreach ( $node['rules'] as $rule ) {
					$cast = $this->get_sanitized_value( $rule, $cast, $path );
				}

				return array( true, $this->get_converted_value( $node, $cast ) );
		}

		if ( ! $passed ) {
			return array( false, null );
		}

		// A container is checked as a whole only once everything in it passed,
		// and is handed to its rules built.
		if ( $check ) {
			$valid = \rest_validate_value_from_schema( $value, $this->get_node_schema( $schema ), $label );

			if ( \is_wp_error( $valid ) ) {
				return $this->fail( $errors, $path, $valid->get_error_message() );
			}

			foreach ( $node['rules'] as $rule ) {
				$message = $this->get_rule_message( $rule, $built, $path );

				if ( null !== $message ) {
					return $this->fail( $errors, $path, $message );
				}
			}
		}

		foreach ( $node['rules'] as $rule ) {
			$built = $this->get_sanitized_value( $rule, $built, $path );
		}

		return array( true, $built );
	}

	/**
	 * Check, clean and build each item of a list.
	 *
	 * @param array<string, mixed>  $node   The list's node.
	 * @param mixed                 $value  The list, as it arrived.
	 * @param array<string, mixed>  $schema The list's schema.
	 * @param string                $path   Where the list is.
	 * @param bool                  $check  Whether to check, or only build.
	 * @param array<string, string> $errors Collects every problem.
	 * @return array{0: bool, 1: mixed}
	 */
	private function walk_list( array $node, mixed $value, array $schema, string $path, bool $check, array &$errors ): array {
		$passed = true;
		$built  = array();

		// A list in a query string arrives as `a,b`, and one with gaps in its
		// keys is still a list: both come back indexed from 0.
		foreach ( \rest_sanitize_array( $value ) as $index => $item ) {
			[ $item_passed, $item_built ] = $this->walk( $node['item'], $item, $schema['items'] ?? array(), $path . '[' . $index . ']', $check, $errors );

			$passed  = $passed && $item_passed;
			$built[] = $item_built;
		}

		return array( $passed, $built );
	}

	/**
	 * Check, clean and build each entry of a map.
	 *
	 * @param array<string, mixed>  $node   The map's node.
	 * @param mixed                 $value  The map, as it arrived.
	 * @param array<string, mixed>  $schema The map's schema.
	 * @param string                $path   Where the map is.
	 * @param bool                  $check  Whether to check, or only build.
	 * @param array<string, string> $errors Collects every problem.
	 * @return array{0: bool, 1: mixed}
	 */
	private function walk_map( array $node, mixed $value, array $schema, string $path, bool $check, array &$errors ): array {
		/*
		 * PHP decodes `{ "0": "a", "1": "b" }` and `[ "a", "b" ]` to the same
		 * array, so nothing here can tell the two apart -- and refusing both
		 * would refuse a map whose keys happen to be numbers.
		 */
		$entries = \rest_sanitize_object( $value );

		$values = \is_array( $schema['additionalProperties'] ?? null ) ? $schema['additionalProperties'] : array();

		foreach ( $schema['patternProperties'] ?? array() as $matches ) {
			$values = $matches;
		}

		$passed = true;
		$built  = array();

		foreach ( $entries as $key => $entry ) {
			$key        = (string) $key;
			$entry_path = $path . ( \preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $key ) ? '.' . $key : "['" . $key . "']" );

			if ( $check ) {
				$message = $this->get_key_problem( $node['keys'], $key, $entry_path );

				if ( null !== $message ) {
					$this->fail( $errors, $entry_path, $message );
					$passed = false;

					continue;
				}
			}

			foreach ( $node['keys'] as $rule ) {
				$key = (string) $this->get_sanitized_value( $rule, $key, $entry_path );
			}

			if ( \array_key_exists( $key, $built ) ) {
				// Two keys that clean to one would keep only the last entry.
				$this->fail(
					$errors,
					$entry_path,
					/* translators: 1: where the value is, such as styles['Main.css']; 2: the key once cleaned. */
					\sprintf( \__( '%1$s is the same key as another once cleaned: %2$s.', 'zestry-toolkit' ), $entry_path, $key )
				);
				$passed = false;

				continue;
			}

			[ $entry_passed, $entry_built ] = $this->walk( $node['item'], $entry, $values, $entry_path, $check, $errors );

			$passed        = $passed && $entry_passed;
			$built[ $key ] = $entry_built;
		}

		return array( $passed, $built );
	}

	/**
	 * Check a value against a class, and build an instance of it.
	 *
	 * @param string                $class_name  The class name.
	 * @param mixed                 $value  The value, as it arrived.
	 * @param array<string, mixed>  $schema The class's schema.
	 * @param string                $path   Where the value is.
	 * @param bool                  $check  Whether to check, or only build.
	 * @param array<string, string> $errors Collects every problem.
	 * @return array{0: bool, 1: mixed}
	 */
	private function walk_object( string $class_name, mixed $value, array $schema, string $path, bool $check, array &$errors ): array {
		if ( \is_object( $value ) && $value instanceof $class_name ) {
			return array( true, $value );
		}

		[ $passed, $values ] = $this->walk_properties( $class_name, \rest_sanitize_object( $value ), $schema, $path, $check, $errors );

		if ( ! $passed ) {
			return array( false, null );
		}

		$instance = ( new \ReflectionClass( $class_name ) )->newInstanceWithoutConstructor();

		foreach ( $this->get_slots( $class_name ) as $name => $slot ) {
			if ( \array_key_exists( $name, $values ) ) {
				$this->assign( $instance, $slot['property'], $name, $values[ $name ] );
			} elseif ( $slot['has_default'] ) {
				// Built without its constructor, so a promoted parameter's
				// default was never applied.
				$this->assign( $instance, $slot['property'], $name, $slot['default'] );
			}
		}

		foreach ( $this->class_rules[ $class_name ] as $rule ) {
			$message = $check ? $this->get_rule_message( $rule, $instance, $path ) : null;

			if ( null !== $message ) {
				return $this->fail( $errors, $path, $message );
			}

			$instance = $this->get_sanitized_value( $rule, $instance, $path );
		}

		return array( true, $instance );
	}

	/**
	 * Check, clean and build each declared property of a class.
	 *
	 * @param string                $class_name  The class name.
	 * @param array<string, mixed>  $values The values, as they arrived.
	 * @param array<string, mixed>  $schema The class's schema.
	 * @param string                $path   Where the values are.
	 * @param bool                  $check  Whether to check, or only build.
	 * @param array<string, string> $errors Collects every problem.
	 * @return array{0: bool, 1: array<string, mixed>}
	 */
	private function walk_properties( string $class_name, array $values, array $schema, string $path, bool $check, array &$errors ): array {
		$slots      = $this->get_slots( $class_name );
		$properties = \is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
		$required   = \is_array( $schema['required'] ?? null ) ? $schema['required'] : array();
		$prefix     = '' === $path ? '' : $path . '.';

		// Also said per property, as `required: true`: the only form a REST arg
		// has room for, since the arg's own `required` is whether it was sent.
		foreach ( $properties as $name => $property ) {
			if ( true === ( $property['required'] ?? null ) ) {
				$required[] = $name;
			}
		}
		$passed = true;
		$built  = array();

		foreach ( $slots as $name => $slot ) {
			$child = $prefix . $name;

			if ( ! \array_key_exists( $name, $values ) ) {
				$is_file = \in_array( $slot['kind'], array( 'file', 'files' ), true );

				if ( $check && ( \in_array( $name, $required, true ) || ( $is_file && ! $slot['has_default'] ) ) ) {
					/* translators: %s: where the value is, such as guests[0].name. */
					$this->fail( $errors, $child, \sprintf( \__( '%s is required.', 'zestry-toolkit' ), $child ) );
					$passed = false;
				}

				continue;
			}

			if ( 'file' === $slot['kind'] ) {
				$built[ $name ] = \is_array( $values[ $name ] ) ? UploadedFile::from_array( $values[ $name ] ) : $values[ $name ];

				continue;
			}

			if ( 'files' === $slot['kind'] ) {
				// PHP transposes a multi-file field, so this is one entry rather
				// than a list of them.
				$built[ $name ] = \is_array( $values[ $name ] ) ? UploadedFile::from_multiple( $values[ $name ] ) : $values[ $name ];

				continue;
			}

			[ $slot_passed, $slot_built ] = $this->walk( $slot, $values[ $name ], $properties[ $name ] ?? $this->get_slot_schema( $slot, 0 ), $child, $check, $errors );

			if ( $slot_passed ) {
				$built[ $name ] = $slot_built;
			}

			$passed = $passed && $slot_passed;
		}

		if ( ! $check ) {
			return array( $passed, $built );
		}

		// What an override describes but no property declares is still checked:
		// it was published, so a caller was told it would be.
		foreach ( \array_diff_key( $properties, $slots ) as $name => $property_schema ) {
			$child = $prefix . $name;

			if ( ! \array_key_exists( $name, $values ) ) {
				if ( \in_array( $name, $required, true ) ) {
					/* translators: %s: where the value is, such as guests[0].name. */
					$this->fail( $errors, $child, \sprintf( \__( '%s is required.', 'zestry-toolkit' ), $child ) );
					$passed = false;
				}

				continue;
			}

			$valid = \rest_validate_value_from_schema( $values[ $name ], $property_schema, $child );

			if ( \is_wp_error( $valid ) ) {
				$this->fail( $errors, $child, $valid->get_error_message() );
				$passed = false;
			}
		}

		if ( false === ( $schema['additionalProperties'] ?? null ) ) {
			foreach ( \array_diff_key( $values, $properties, $slots ) as $name => $unused ) {
				$child = $prefix . $name;

				/* translators: %s: where the value is, such as guests[0].nickname. */
				$this->fail( $errors, $child, \sprintf( \__( '%s is not a valid property.', 'zestry-toolkit' ), $child ) );
				$passed = false;
			}
		}

		return array( $passed, $built );
	}

	/**
	 * What is wrong with one key of a map, if anything.
	 *
	 * @param Rule[] $rules The rules in Keys.
	 * @param string $key   The key.
	 * @param string $path  Where the entry is, for a message.
	 * @return string|null
	 */
	private function get_key_problem( array $rules, string $key, string $path ): ?string {
		$schema = array( 'type' => 'string' );

		foreach ( $rules as $rule ) {
			$schema = \array_merge( $schema, $rule->get_schema() );
		}

		$valid = \rest_validate_value_from_schema( $key, $schema, $path );

		if ( \is_wp_error( $valid ) ) {
			return $valid->get_error_message();
		}

		foreach ( $rules as $rule ) {
			$message = $this->get_rule_message( $rule, $key, $path );

			if ( null !== $message ) {
				return $message;
			}
		}

		return null;
	}

	/**
	 * A node's schema, without the keywords that describe its contents.
	 *
	 * A container's contents are walked one by one, so each problem in them is
	 * reported where it is rather than as the first one WordPress stops at.
	 *
	 * @param array<string, mixed> $schema The schema.
	 * @return array<string, mixed>
	 */
	private function get_node_schema( array $schema ): array {
		return \array_diff_key(
			$schema,
			\array_flip( self::CONTENT_KEYWORDS ),
			\array_flip( array( 'validate_callback', 'sanitize_callback', 'arg_options' ) )
		);
	}

	/**
	 * Turn a cast value into what its node was declared as.
	 *
	 * @param array<string, mixed> $node  The node.
	 * @param mixed                $value The cast value.
	 * @return mixed
	 */
	private function get_converted_value( array $node, mixed $value ): mixed {
		if ( null === $value ) {
			return null;
		}

		switch ( $node['kind'] ) {
			case 'enum':
				foreach ( $node['type']::cases() as $case ) {
					if ( ( $case instanceof \BackedEnum ? $case->value : $case->name ) === $value ) {
						return $case;
					}
				}

				return $value;

			case 'date':
				if ( $value instanceof \DateTimeInterface ) {
					return $value;
				}

				$concrete = \DateTimeInterface::class === $node['type'] ? \DateTimeImmutable::class : $node['type'];

				return new $concrete( (string) $value );

			case 'object':
				return $this->get_object_value( $value, true );

			default:
				return $value;
		}
	}

	/**
	 * Turn a decoded JSON object back into an object.
	 *
	 * `{}` and `[]` both decode to an empty array, so `$declared` carries what
	 * the declaration knows: where it says object, the value is cast whatever its
	 * shape. A value nested inside, which nothing declared, stays a list when it
	 * is one.
	 *
	 * @param mixed $value    The value.
	 * @param bool  $declared Whether a declared type says this is an object.
	 * @return mixed
	 */
	private function get_object_value( mixed $value, bool $declared = false ): mixed {
		if ( ! \is_array( $value ) ) {
			return $value;
		}

		$converted = \array_map(
			function ( $item ) {
				return \is_array( $item ) ? $this->get_object_value( $item ) : $item;
			},
			$value
		);

		return ! $declared && \array_is_list( $converted ) ? $converted : (object) $converted;
	}

	/**
	 * Run a rule's check, saying which property if it cannot take the value.
	 *
	 * PHP's own message names the callback's parameter and a line of library
	 * code -- true, and no help in finding the declaration that paired them.
	 *
	 * @param Rule   $rule  The rule.
	 * @param mixed  $value The value.
	 * @param string $path  Where the value is.
	 * @return string|null
	 * @throws \InvalidArgumentException When the rule cannot take the value.
	 */
	private function get_rule_message( Rule $rule, mixed $value, string $path ): ?string {
		try {
			return $rule->check( $value );
		} catch ( \TypeError $e ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The rule %1$s on "%2$s" cannot check the value it was given: %3$s', $rule::class, $path, $e->getMessage() ),
				0,
				$e
			);
		}
	}

	/**
	 * Run a rule's sanitize, saying which property if it cannot take the value.
	 *
	 * @param Rule   $rule  The rule.
	 * @param mixed  $value The value.
	 * @param string $path  Where the value is.
	 * @return mixed
	 * @throws \InvalidArgumentException When the rule cannot take the value.
	 */
	private function get_sanitized_value( Rule $rule, mixed $value, string $path ): mixed {
		try {
			return $rule->sanitize( $value );
		} catch ( \TypeError $e ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The rule %1$s on "%2$s" cannot clean the value it was given: %3$s', $rule::class, $path, $e->getMessage() ),
				0,
				$e
			);
		}
	}

	/**
	 * Record a problem, keeping the first one found at each place.
	 *
	 * @param array<string, string> $errors  Collects every problem.
	 * @param string                $path    Where the problem is.
	 * @param string                $message What it is.
	 * @return array{0: false, 1: null}
	 */
	private function fail( array &$errors, string $path, string $message ): array {
		$errors[ $path ] ??= $message;

		return array( false, null );
	}

	/**
	 * Put one value onto its property, saying which one if it will not go.
	 *
	 * Assigned from the declaring class's own scope, so a readonly property
	 * on a freshly built object can be filled as its constructor would have.
	 *
	 * @param object              $target   The object.
	 * @param \ReflectionProperty $property The property.
	 * @param string              $name     The property's name.
	 * @param mixed               $value    The value.
	 * @return void
	 * @throws \InvalidArgumentException When the property will not take the value.
	 */
	private function assign( object $target, \ReflectionProperty $property, string $name, mixed $value ): void {
		if ( $property->isReadOnly() && $property->isInitialized( $target ) ) {
			// A route or an ability is built once and answers many calls, so a
			// readonly property would be filled for the first caller and refuse
			// every one after.
			throw new \InvalidArgumentException(
				\sprintf(
					'"%s" is readonly on an object that answers more than one call, so it can only ever be set once. Drop readonly, or move it into a class of its own.',
					$name
				)
			);
		}

		try {
			\Closure::bind(
				static function ( object $target, string $name, mixed $value ): void {
					$target->$name = $value;
				},
				null,
				$property->getDeclaringClass()->getName()
			)( $target, $name, $value );
		} catch ( \TypeError $e ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The value given for "%s" is not what it was declared as: %s', $name, $e->getMessage() )
			);
		}
	}

	/**
	 * Return a typed property to uninitialized.
	 *
	 * `unset()` obeys the visibility of the scope it runs in, so a protected
	 * property is out of reach from here; the closure runs in the object's own.
	 *
	 * @param object $target The object.
	 * @param string $name   The property's name.
	 * @return void
	 */
	private function unset_property( object $target, string $name ): void {
		( function ( string $property ): void {
			unset( $this->$property );
		} )->call( $target, $name );
	}

	/**
	 * The default a property declares, if it declares one.
	 *
	 * A promoted constructor parameter's default belongs to the parameter, so
	 * `ReflectionProperty::hasDefaultValue()` answers false for it.
	 *
	 * @param \ReflectionProperty $property The property.
	 * @return array{0: bool, 1: mixed} Whether there is one, and what it is.
	 */
	private function get_declared_default( \ReflectionProperty $property ): array {
		if ( $property->hasDefaultValue() ) {
			return array( true, $property->getDefaultValue() );
		}

		$constructor = $property->isPromoted() ? $property->getDeclaringClass()->getConstructor() : null;

		foreach ( null !== $constructor ? $constructor->getParameters() : array() as $parameter ) {
			if ( $parameter->getName() === $property->getName() && $parameter->isDefaultValueAvailable() ) {
				return array( true, $parameter->getDefaultValue() );
			}
		}

		return array( false, null );
	}

	/**
	 * Overrides, without the maps that name nothing.
	 *
	 * An empty array replaces whole, which is what lets `'enum' => array()`
	 * empty an enum. An empty schema or map of names would do the same --
	 * `array( 'properties' => array() )` dropped every property -- while what
	 * it says is "nothing to change": the shape `array( 'properties' =>
	 * $route->args() )` takes for a route with no args. So an empty schema,
	 * written or left by this, is removed rather than merged.
	 *
	 * @param array<string, mixed> $overrides A partial schema.
	 * @return array<string, mixed>
	 */
	private static function get_stated_overrides( array $overrides ): array {
		foreach ( array( 'items', 'additionalProperties' ) as $key ) {
			if ( \is_array( $overrides[ $key ] ?? null ) ) {
				$overrides[ $key ] = self::get_stated_overrides( $overrides[ $key ] );

				if ( array() === $overrides[ $key ] ) {
					unset( $overrides[ $key ] );
				}
			}
		}

		foreach ( array( 'properties', 'patternProperties' ) as $key ) {
			if ( ! \is_array( $overrides[ $key ] ?? null ) ) {
				continue;
			}

			foreach ( $overrides[ $key ] as $name => $property ) {
				if ( \is_array( $property ) ) {
					$overrides[ $key ][ $name ] = self::get_stated_overrides( $property );

					if ( array() === $overrides[ $key ][ $name ] ) {
						unset( $overrides[ $key ][ $name ] );
					}
				}
			}

			if ( array() === $overrides[ $key ] ) {
				unset( $overrides[ $key ] );
			}
		}

		return $overrides;
	}
}
