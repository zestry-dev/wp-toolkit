<?php

/**
 * Request API: Request module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Request;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Helpers\Arr;
use Zestry\WPToolkit\Modules\Request\Attributes\RequestArgument;

/**
 * Turns declared arguments into schemas, and incoming values into properties.
 *
 * The machinery behind {@see Attributes\RequestArgument}, shared by every part
 * of a plugin that takes arguments from somewhere it does not control -- a
 * {@see \Zestry\WPToolkit\Modules\RestApi\RestRoute}, an
 * {@see \Zestry\WPToolkit\Modules\Abilities\Ability}, an
 * {@see \Zestry\WPToolkit\Modules\Ajax\AjaxAction} and an
 * {@see \Zestry\WPToolkit\Modules\AdminPages\AdminPage}. That is why the attribute is one
 * attribute: they ask the same question, and answering it four times would mean
 * four vocabularies for one idea.
 *
 * You rarely call this. Declare the properties, and the module that discovered
 * your route, ability, action or page does the rest.
 *
 * @example What it does for you
 * ```
 * #[RequestArgument( 'The order to cancel.' )]
 * public int $order_id;
 *
 * #[RequestArgument( 'Whether to email the customer.' )]
 * public bool $notify = true;
 * ```
 *
 * becomes this schema, published to whoever is calling:
 *
 * ```
 * array(
 *     'type'       => 'object',
 *     'properties' => array(
 *         'order_id' => array( 'type' => 'integer', 'description' => 'The order to cancel.' ),
 *         'notify'   => array( 'type' => 'boolean', 'description' => 'Whether to email the customer.', 'default' => true ),
 *     ),
 *     'required'   => array( 'order_id' ),
 * );
 * ```
 *
 * and the values arrive on `$this->order_id` and `$this->notify`.
 */
class Request extends Module {

	/**
	 * How deep a structure may nest before this is a cycle rather than a shape.
	 */
	private const MAX_DEPTH = 10;

	/**
	 * The JSON Schema type for each PHP type an argument may be declared as.
	 */
	private const SCALAR_TYPES = array(
		'int'    => 'integer',
		'float'  => 'number',
		'string' => 'string',
		'bool'   => 'boolean',
		'array'  => 'array',
	);

	/**
	 * The arguments an object declares, keyed by property name.
	 *
	 * Public and protected only: reflection
	 * cannot reliably reach a private property declared on an ancestor.
	 *
	 * @param object|string $target The object, or the class name of a structure.
	 * @return array<string, array{0: \ReflectionProperty, 1: RequestArgument}>
	 */
	public function get_arguments( object|string $target ): array {
		$properties = ( new \ReflectionClass( $target ) )->getProperties(
			\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED
		);

		$arguments = array();

		foreach ( $properties as $property ) {
			$attributes = $property->getAttributes( RequestArgument::class );

			if ( array() === $attributes ) {
				continue;
			}

			if ( $property->isStatic() ) {
				// One value shared by every instance of the class, so binding it
				// would carry one caller's argument into the next one's call.
				throw new \InvalidArgumentException(
					\sprintf(
						'The argument %s::$%s is static. An argument belongs to one call, and a static property belongs to every call at once.',
						$property->getDeclaringClass()->getName(),
						$property->getName()
					)
				);
			}

			$arguments[ $property->getName() ] = array( $property, $attributes[0]->newInstance() );
		}

		return $arguments;
	}

	/**
	 * The JSON Schema object describing everything an object accepts.
	 *
	 * `$overrides` is a partial schema stated on top of the derived one, for the
	 * parts an attribute cannot carry. PHP allows only constant expressions in an
	 * attribute argument, so `__()` -- and anything else worked out while the
	 * request runs -- has to be said here instead. Anything you state wins;
	 * everything you leave out keeps whatever the declarations gave it, including
	 * the binding and the validation they wired.
	 *
	 * A keyed map is merged into, so naming one property's `description` leaves
	 * the rest of that property alone:
	 *
	 * ```
	 * $request->get_schema(
	 *     $ability,
	 *     array(
	 *         'properties' => array(
	 *             'order_id' => array( 'description' => __( 'The order to cancel.', 'acme-plugin' ) ),
	 *         ),
	 *     )
	 * );
	 * ```
	 *
	 * A **list is replaced whole** — `required`, an `enum`, a nullable `type`.
	 * Stating `enum => array( 'web' )` gives you exactly that, rather than your
	 * entry laid over the first of the derived ones. That is
	 * {@see \Zestry\WPToolkit\Kernel\Helpers\Arr::replace_recursive()}, where the rule and its
	 * reason are written out.
	 *
	 * @param object|string        $target    The object, or the class name of a structure.
	 * @param array<string, mixed> $overrides A partial schema stated over the derived one.
	 * @return array<string, mixed> A schema, or an empty array when nothing is declared.
	 * @throws \InvalidArgumentException When an argument cannot be described.
	 */
	public function get_schema( object|string $target, array $overrides = array() ): array {
		return Arr::replace_recursive( $this->get_object_schema( $target, 0 ), $overrides );
	}

	/**
	 * The per-parameter `args` array `register_rest_route()` takes.
	 *
	 * Flat rather than nested: WordPress reads each entry as the schema for one
	 * parameter, so a structure becomes one entry of `type => object`. An
	 * argument's `validate`/`sanitize` are wired into WordPress's own slots here,
	 * so a route's failures are reported the way every other route reports them.
	 *
	 * `$overrides` is keyed by argument name rather than nested under
	 * `properties`, matching the flat shape it merges into — see
	 * {@see get_schema()} for what merging does and does not replace. A name with
	 * no declaration behind it is added rather than dropped: that is still a
	 * parameter WordPress validates, read off the request rather than off a
	 * property.
	 *
	 * @param object                              $target    The wired route.
	 * @param string[]                            $required  Names required regardless of their default — a route's URL tokens.
	 * @param array<string, array<string, mixed>> $overrides Partial schemas stated over the derived ones, keyed by argument name.
	 * @return array<string, array<string, mixed>>
	 * @throws \InvalidArgumentException When an argument cannot be described.
	 *
	 * @internal
	 */
	public function get_rest_args( object $target, array $required = array(), array $overrides = array() ): array {
		$args = array();

		foreach ( $this->get_arguments( $target ) as $name => [ $property, $argument ] ) {
			if ( $this->is_file( $property, $argument ) ) {
				// Deliberately absent: WordPress never puts an upload among a
				// request's parameters, so an arg declared for one would be a
				// parameter that is always missing -- and `required` would reject
				// every request that carried the file correctly.
				continue;
			}

			$arg                       = \array_merge( $this->get_property_schema( $property, $argument, 0 ), $argument->schema );
			[ $has_default, $default ] = $this->get_declared_default( $property );

			if ( $has_default ) {
				$arg['default'] = $default;
			}

			if ( ! $has_default || \in_array( $name, $required, true ) ) {
				$arg['required'] = true;
			}

			/*
			 * WordPress checks an argument against its schema on its own, but only
			 * while nothing has claimed a callback: a validate_callback runs
			 * *instead of* schema validation, and the schema check it falls back on
			 * lives inside the default sanitize_callback. So each callback below is
			 * set to restore exactly what declaring the other one displaced --
			 * never to check something WordPress has already checked.
			 */
			if ( null === $argument->validate && null !== $argument->sanitize ) {
				/*
				 * Validating alone. The sanitize_callback below displaces the
				 * default that would have checked the schema, so core's own
				 * checker is named here to put it back -- unwrapped, so it runs
				 * once and reports exactly as it always does.
				 */
				$arg['validate_callback'] = 'rest_validate_request_arg';
			}

			if ( null !== $argument->validate ) {
				$arg['validate_callback'] = function ( $value, $request, $param ) use ( $argument, $name ) {
					$is_valid = \rest_validate_request_arg( $value, $request, $param );

					if ( true !== $is_valid ) {
						return $is_valid;
					}

					// Then the rule the schema could not express.
					return $this->get_callback_value( $argument->validate, $value, $name, 'validate' )
						? true
						: $this->get_refusal( array( $name ), 'rest_invalid_param', array( 'status' => 400 ) );
				};
			}

			if ( null !== $argument->sanitize ) {
				$arg['sanitize_callback'] = function ( $value, $request, $param ) use ( $argument, $name ) {
					// Schema first, so a rule of your own is handed the value as its
					// declared type rather than as the string a query carries.
					$value = \rest_sanitize_request_arg( $value, $request, $param );

					return \is_wp_error( $value )
						? $value
						: $this->get_callback_value( $argument->sanitize, $value, $name, 'sanitize' );
				};
			} elseif ( null !== $argument->validate ) {
				/*
				 * Sanitising alone. The default here is rest_parse_request_arg,
				 * which validates before it sanitises -- and the validate_callback
				 * above has already done that.
				 */
				$arg['sanitize_callback'] = 'rest_sanitize_request_arg';
			}

			$args[ $name ] = $arg;
		}

		/*
		 * Stated last, so one rule covers every key: what you write here wins.
		 * That includes the callbacks above, which is the only way to replace what
		 * a declared `validate:` wired -- and the reason naming one is worth
		 * meaning.
		 */
		foreach ( $overrides as $name => $override ) {
			$args[ $name ] = isset( $args[ $name ] )
				? Arr::replace_recursive( $args[ $name ], $override )
				: $override;
		}

		return $args;
	}

	/**
	 * The file arguments an object declares, keyed by property name.
	 *
	 * Separate from every other argument because WordPress carries uploads
	 * separately: they are absent from a request's parameters, so they are read
	 * from `WP_REST_Request::get_file_params()` and never validated against a
	 * schema.
	 *
	 * @param object $target The wired route.
	 * @return array<string, bool> Whether each file argument must be present, keyed by name.
	 *
	 * @internal
	 */
	public function get_file_arguments( object $target ): array {
		$files = array();

		foreach ( $this->get_arguments( $target ) as $name => [ $property, $argument ] ) {
			if ( ! $this->is_file( $property, $argument ) ) {
				continue;
			}

			[ $has_default ] = $this->get_declared_default( $property );

			$files[ $name ] = ! $has_default;
		}

		return $files;
	}

	/**
	 * Read a target's declared arguments out of the current request.
	 *
	 * For a caller whose platform hands it no parameters of its own. A route gets
	 * them from {@see \WP_REST_Request::get_param()} and an ability is passed them
	 * outright; an admin page and an AJAX action are plain hooks, so this is the
	 * equivalent for them, resolving each name the same way a route does:
	 *
	 * 1. the **JSON body**, when the `Content-Type` says the body is one
	 * 2. the **form body** -- `$_POST`, on a method that carries one
	 * 3. the **query string** -- `$_GET`
	 *
	 * First source holding the name wins, and a name no source holds is left out
	 * rather than nulled, so the property keeps its default. A **cookie and a
	 * header are never parameters**: a header is a separate accessor and a cookie
	 * is not on the request at all.
	 *
	 * @rationale
	 * That list is not reimplemented here -- the values are loaded into a
	 * {@see \WP_REST_Request} exactly the way {@see \WP_REST_Server::serve_request()}
	 * loads them, and {@see \WP_REST_Request::get_param()} is what resolves each
	 * name. So the order *is* `get_parameter_order()` rather than a copy of it
	 * that can fall behind, and the JSON body is parsed by the parser core
	 * already ships.
	 *
	 * `$_REQUEST` was the obvious way to write this, and is why none of it reads
	 * one. What that superglobal merges is set by PHP's `request_order`, empty on
	 * a stock build and falling back to `variables_order` -- `EGPCS`, cookies
	 * included and merged last, so a cookie beats the posted value of the same
	 * name, silently, on a configuration the plugin does not control.
	 *
	 * @param object $target The object declaring the arguments.
	 * @return array<string,mixed> The values present, unslashed and otherwise raw.
	 * @throws \InvalidArgumentException When an argument cannot be described.
	 */
	public function get_submitted_values( object $target ): array {
		$arguments = $this->get_arguments( $target );

		if ( array() === $arguments ) {
			return array();
		}

		$request = $this->get_current_request();
		$values  = array();

		foreach ( \array_keys( $arguments ) as $name ) {
			if ( $request->has_param( $name ) ) {
				$values[ $name ] = $request->get_param( $name );
			}
		}

		return $values;
	}

	/**
	 * Check raw values against the schema, then apply each argument's own rules.
	 *
	 * For a caller whose platform checks nothing: an AJAX action is a hook, and
	 * WordPress hands it the superglobals exactly as they arrived. A route's
	 * parameters are checked by WordPress against the args it was registered
	 * with, and an ability's input against its schema, so neither needs this.
	 *
	 * An argument the caller left out is left out, unless it has no default —
	 * that one is missing rather than absent, and is refused the way WordPress
	 * refuses a missing required parameter.
	 *
	 * @param object              $target     The object declaring the arguments.
	 * @param array<string,mixed> $values     The values as they arrived.
	 * @param string              $error_code The code a refusal carries.
	 * @return array<string,mixed>|\WP_Error The values checked, cast and sanitized, or the refusal.
	 * @throws \InvalidArgumentException When an argument cannot be described.
	 */
	public function get_validated_values( object $target, array $values, string $error_code ) {
		$schema  = $this->get_schema( $target );
		$refused = array();
		$missing = array();

		foreach ( $this->get_arguments( $target ) as $name => [ $property ] ) {
			if ( ! \array_key_exists( $name, $values ) ) {
				[ $has_default ] = $this->get_declared_default( $property );

				if ( ! $has_default ) {
					$missing[] = $name;
				}

				continue;
			}

			if ( \is_wp_error( \rest_validate_value_from_schema( $values[ $name ], $schema['properties'][ $name ], $name ) ) ) {
				$refused[] = $name;
			}
		}

		if ( array() !== $missing ) {
			return new \WP_Error(
				'rest_missing_callback_param',
				\sprintf(
					/* translators: %s: comma-separated list of argument names. */
					\__( 'Missing parameter(s): %s', 'zestry-toolkit' ),
					\implode( ', ', $missing )
				),
				array(
					'status' => 400,
					'params' => $missing,
				)
			);
		}

		if ( array() !== $refused ) {
			return $this->get_refusal( $refused, $error_code, array( 'status' => 400 ) );
		}

		return $this->get_prepared_values( $target, $values, $error_code );
	}

	/**
	 * Run each argument's own validate and sanitize callbacks.
	 *
	 * For a caller with nowhere to hang them — an ability, whose input WordPress
	 * validates against the schema and no further. A route wires the same
	 * callbacks into WordPress's own slots instead, through
	 * {@see get_rest_args()}, so neither runs them twice.
	 *
	 * The schema's cast happens here too, and only here: an ability's input is
	 * validated and never sanitised, so this is where a value becomes the type
	 * its property was declared as.
	 *
	 * @param object              $target     The object declaring the arguments.
	 * @param array<string,mixed> $values     The values, already checked against the schema.
	 * @param string              $error_code The code a refusal carries. Each platform has its own for
	 *                                        input it rejected — `ability_invalid_input` is what
	 *                                        WordPress itself returns when an ability's schema says no —
	 *                                        and a caller should not have to handle two for one idea.
	 * @return array<string,mixed>|\WP_Error The values with each sanitized in place, or the refusal.
	 */
	public function get_prepared_values( object $target, array $values, string $error_code ) {
		$schema  = $this->get_schema( $target );
		$refused = array();

		foreach ( $this->get_arguments( $target ) as $name => [ , $argument ] ) {
			if ( ! \array_key_exists( $name, $values ) ) {
				continue;
			}

			// The rule sees the value as it was sent, the same way a route's does.
			if ( null !== $argument->validate && ! $this->get_callback_value( $argument->validate, $values[ $name ], $name, 'validate' ) ) {
				// Collected rather than returned: WordPress answers a route with
				// every parameter it refused at once, and a caller fixing its call
				// should not have to discover them one round trip at a time.
				$refused[] = $name;
				continue;
			}

			/*
			 * Then the schema's own cast, which nothing else here does: WordPress
			 * validates an ability's input and never sanitises it, and its
			 * validation passes a numeric string for an integer -- so `"42"` would
			 * arrive as a string for an `int` argument and fail on the way onto
			 * the property.
			 */
			$cast = \rest_sanitize_value_from_schema( $values[ $name ], $schema['properties'][ $name ], $name );

			if ( \is_wp_error( $cast ) ) {
				return $cast;
			}

			$values[ $name ] = null !== $argument->sanitize
				? $this->get_callback_value( $argument->sanitize, $cast, $name, 'sanitize' )
				: $cast;
		}

		return array() === $refused ? $values : $this->get_refusal( $refused, $error_code );
	}

	/**
	 * Return an object's declared arguments to what a first call would see.
	 *
	 * A route, an ability, an action and a page are each built once and answer
	 * many calls. Without this, an argument left out of the second call still
	 * holds what the first one sent -- so a nullable argument meaning "not
	 * supplied", which is how one is made optional, quietly reports the previous
	 * caller's value instead.
	 *
	 * An argument with a default goes back to it. One without goes back to
	 * uninitialized, so a required argument that is missing fails as it would on
	 * a first call rather than reusing a stale value.
	 *
	 * {@see bind()} calls this before it assigns anything, so binding is enough
	 * on its own; this is public for a caller that assigns arguments by hand.
	 *
	 * @param object $target The object whose arguments to clear.
	 * @return void
	 * @throws \InvalidArgumentException When an argument cannot be described.
	 */
	public function reset( object $target ): void {
		foreach ( $this->get_arguments( $target ) as $name => [ $property ] ) {
			if ( $property->isReadOnly() ) {
				// Left alone. PHP refuses to unset an initialized readonly
				// property, and assign() already refuses one on an object that
				// answers more than one call, in a message that says what to do
				// about it -- clearing it here would replace that with a fatal.
				continue;
			}

			[ $has_default, $default ] = $this->get_declared_default( $property );

			if ( $has_default ) {
				$this->assign( $target, $property, $name, $default );

				continue;
			}

			$this->unset_property( $target, $property->getName() );
		}
	}

	/**
	 * Assign incoming values onto an object's declared arguments.
	 *
	 * A value for a structure is built into an instance of it first, so the
	 * handler reads `$this->address->city` rather than an array. An argument the
	 * caller left out goes back to its declared default, and one with no default
	 * goes back to uninitialized -- see {@see reset()}, which runs first.
	 *
	 * @param object              $target The object to populate.
	 * @param array<string,mixed> $values The validated values.
	 * @return void
	 * @throws \InvalidArgumentException When an argument cannot be described.
	 */
	public function bind( object $target, array $values ): void {
		// This call's arguments start from the declaration rather than from
		// whatever the last call through the same object left behind.
		$this->reset( $target );

		foreach ( $this->get_arguments( $target ) as $name => [ $property, $argument ] ) {
			if ( ! \array_key_exists( $name, $values ) ) {
				/*
				 * A structure is built without calling its constructor, so a
				 * promoted parameter's default was never applied -- and reading an
				 * uninitialized typed property throws. An ordinary property
				 * default is already in place, and assigning over it would fail
				 * outright if it were readonly, so only the uninitialized case is
				 * filled in.
				 */
				[ $has_default, $default ] = $this->get_declared_default( $property );

				if ( $has_default && ! $property->isInitialized( $target ) ) {
					$this->assign( $target, $property, $name, $default );
				}

				continue;
			}

			$this->assign( $target, $property, $name, $this->get_bound_value( $property, $argument, $values[ $name ] ) );
		}
	}

	/**
	 * Build an instance of a structure from an array of values.
	 *
	 * Built without calling a constructor, so a structure needs no particular
	 * shape and its property defaults still apply.
	 *
	 * @param string              $structure The structure's class name.
	 * @param array<string,mixed> $values    The values for it.
	 * @return object
	 * @throws \InvalidArgumentException When one of its arguments cannot be described.
	 */
	public function hydrate( string $structure, array $values ): object {
		$instance = ( new \ReflectionClass( $structure ) )->newInstanceWithoutConstructor();

		$this->bind( $instance, $values );

		return $instance;
	}

	/**
	 * The current request, loaded into the object a route would have been given.
	 *
	 * The same four `set_*()` calls {@see \WP_REST_Server::serve_request()} makes,
	 * so `get_param()` resolves a name here exactly as it would on a route.
	 *
	 * Only `Content-Type` is set, where the server sets every header, because it
	 * is the one header parameter resolution consults -- and reaching the server's
	 * own `get_headers()` means `rest_get_server()`, which fires `rest_api_init`
	 * and registers every route on the site to read one string.
	 *
	 * @return \WP_REST_Request The request, ready to be asked for a parameter.
	 */
	private function get_current_request(): \WP_REST_Request {
		/*
		 * phpcs:disable WordPress.Security.NonceVerification -- whoever reads these enforces its own nonce policy; collecting them decides nothing.
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- get_validated_values() is the sanitising, against the declared schema.
		 */
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? \strtoupper( \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$request = new \WP_REST_Request( $method );

		$request->set_query_params( \wp_unslash( $_GET ) );
		$request->set_body_params( \wp_unslash( $_POST ) );
		$request->set_file_params( $_FILES );

		if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
			$request->set_header( 'Content-Type', \sanitize_text_field( \wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) );
		}
		// phpcs:enable

		$request->set_body( \WP_REST_Server::get_raw_data() );

		return $request;
	}

	/**
	 * Put one value onto its property, saying which argument if it will not go.
	 *
	 * Reachable only when a hand-written schema no longer agrees with the
	 * property it fills -- but PHP's own message names the anonymous class and
	 * the type, never the argument, which is the one thing worth knowing.
	 *
	 * @param object              $target   The object being populated.
	 * @param \ReflectionProperty $property The declared property.
	 * @param string              $name     The argument's name.
	 * @param mixed               $value    The value to assign.
	 * @return void
	 * @throws \InvalidArgumentException When the property will not take the value.
	 */
	private function assign( object $target, \ReflectionProperty $property, string $name, $value ): void {
		if ( $property->isReadOnly() && $property->isInitialized( $target ) ) {
			// A route or an ability is built once and answers many calls, so a
			// readonly argument could be filled for the first caller and then
			// refuse every one after. A structure is built per call and never
			// reaches this.
			throw new \InvalidArgumentException(
				\sprintf(
					'The argument "%s" is readonly on an object that answers more than one call, so it can only ever be set once. Drop readonly, or move the argument into a structure of its own.',
					$name
				)
			);
		}

		try {
			// No setAccessible() call: relies on PHP 8.1+ implicit accessibility.
			$property->setValue( $target, $value );
		} catch ( \TypeError $e ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The value given for "%s" is not what it was declared as: %s',
					$name,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Return a typed property to uninitialized.
	 *
	 * `unset()` is a language construct and obeys the visibility of the scope it
	 * runs in, so a protected argument is out of reach from here -- and
	 * reflection has no unset of its own to use instead. Calling the closure on
	 * the target gives it that object's own scope, which is where the construct
	 * works for either visibility.
	 *
	 * @param object $target The object holding the property.
	 * @param string $name   The property's name.
	 * @return void
	 */
	private function unset_property( object $target, string $name ): void {
		( function ( string $property ): void {
			unset( $this->$property );
		} )->call( $target, $name );
	}

	/**
	 * Reduce a declared default to the value a caller would send.
	 *
	 * A schema is read as JSON, and a default is part of it -- so an enum case,
	 * a date or a structure has to be published as what it looks like on the
	 * wire rather than as the object it is in PHP.
	 *
	 * @param mixed $value The declared default.
	 * @return mixed
	 * @throws \InvalidArgumentException When one of its arguments cannot be described.
	 */
	private function get_publishable_value( $value ) {
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
			return \array_map(
				function ( $item ) {
					return $this->get_publishable_value( $item );
				},
				$value
			);
		}

		if ( ! \is_object( $value ) ) {
			return $value;
		}

		$published = array();

		foreach ( $this->get_arguments( $value ) as $name => [ $property ] ) {
			if ( $property->isInitialized( $value ) ) {
				$published[ $name ] = $this->get_publishable_value( $property->getValue( $value ) );
			}
		}

		return $published;
	}

	/**
	 * Build the schema for one object, guarding against a structure that nests
	 * into itself.
	 *
	 * @param object|string $target The object, or the class name of a structure.
	 * @param int           $depth  How deep this call already is.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When an argument cannot be described, or the nesting does not end.
	 */
	private function get_object_schema( object|string $target, int $depth ): array {
		if ( $depth > self::MAX_DEPTH ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The arguments of %s nest more than %d deep. A structure that contains itself has no schema.',
					\is_object( $target ) ? $target::class : $target,
					self::MAX_DEPTH
				)
			);
		}

		$properties = array();
		$required   = array();

		foreach ( $this->get_arguments( $target ) as $name => [ $property, $argument ] ) {
			$properties[ $name ] = $this->get_property_schema( $property, $argument, $depth );

			[ $has_default, $default ] = $this->get_declared_default( $property );

			if ( $has_default ) {
				// Optional, and the caller is told what leaving it out gets them --
				// as the value they would send, since a schema is read as JSON and
				// an enum case or a structure is neither.
				$properties[ $name ]['default'] = $this->get_publishable_value( $default );
			} else {
				// A typed property with no default is uninitialized until something
				// assigns it, so "optional" would mean a handler reading a property
				// that throws. The declaration has already answered this.
				$required[] = $name;
			}

			// Stated last so an explicit key always wins, including `type`.
			$properties[ $name ] = \array_merge( $properties[ $name ], $argument->schema );
		}

		if ( array() === $properties ) {
			return array();
		}

		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);

		if ( array() !== $required ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * The schema for one argument, from the property's own type.
	 *
	 * @param \ReflectionProperty $property The declared property.
	 * @param RequestArgument     $argument Its attribute.
	 * @param int                 $depth    How deep this call already is.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When the property has no type this can describe.
	 */
	private function get_property_schema( \ReflectionProperty $property, RequestArgument $argument, int $depth ): array {
		$type = $property->getType();
		$name = $type instanceof \ReflectionNamedType ? $type->getName() : null;

		if ( null === $name ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The argument %s::$%s needs a single declared type. A union or an untyped property cannot be described to a caller.',
					$property->getDeclaringClass()->getName(),
					$property->getName()
				)
			);
		}

		// Omitted rather than empty: a description nobody wrote is not a
		// description of nothing.
		$schema = '' !== $argument->description ? array( 'description' => $argument->description ) : array();

		if ( ! isset( self::SCALAR_TYPES[ $name ] ) ) {
			// `object` and `stdClass` say the same thing: an object whose keys are
			// the caller's business. JSON Schema has a word for exactly that, and
			// `schema:` can still narrow it.
			if ( 'object' === $name || \stdClass::class === $name ) {
				$schema['type'] = $type->allowsNull() ? array( 'object', 'null' ) : 'object';

				return $schema;
			}

			// An interface counts: DateTimeInterface is the one worth typing an
			// argument as, and anything else reaching this fails a step later with
			// a message about describing nothing.
			if ( ! \class_exists( $name ) && ! \enum_exists( $name ) && ! \interface_exists( $name ) ) {
				throw new \InvalidArgumentException(
					\sprintf(
						'The argument %s::$%s is typed %s, which is neither int, float, string, bool, array, a date, an enum, nor a class whose properties describe it.',
						$property->getDeclaringClass()->getName(),
						$property->getName(),
						$name
					)
				);
			}

			$described = $this->get_class_schema( $name, $depth );

			return \array_merge( $schema, $type->allowsNull() ? $this->get_nullable_schema( $described ) : $described );
		}

		$schema['type'] = $type->allowsNull() ? array( self::SCALAR_TYPES[ $name ], 'null' ) : self::SCALAR_TYPES[ $name ];

		/*
		 * PHP has no array-of-type syntax -- `LineItem[]` is docblock notation,
		 * not a type -- so `of` says it instead. Something has to: an array whose
		 * contents go undescribed is the one hole a caller cannot read its way
		 * out of, and this module exists to hand it a complete contract.
		 */
		if ( 'array' !== $name && null !== $argument->of ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The argument %s::$%s is not a list, so `of:` describes nothing. Remove it, or type the property as an array.',
					$property->getDeclaringClass()->getName(),
					$property->getName()
				)
			);
		}

		if ( 'array' === $name ) {
			if ( null !== $argument->of ) {
				$schema['items'] = $this->get_class_schema( $argument->of, $depth );
			} elseif ( ! isset( $argument->schema['items'] ) && ! isset( $argument->schema['type'] ) ) {
				// Unless the schema said it is not a list after all: PHP's `array`
				// is a JSON object as readily as a JSON array, and only the author
				// knows which this one is.
				throw new \InvalidArgumentException(
					\sprintf(
						'The argument %s::$%s is an array that does not say what it holds. Name a class with `of: Thing::class`, or describe the items with `schema: array( \'items\' => ... )`.',
						$property->getDeclaringClass()->getName(),
						$property->getName()
					)
				);
			}
		}

		return $schema;
	}

	/**
	 * The schema for a property typed as a class.
	 *
	 * An enum is a closed set rather than a shape: it has no properties to
	 * recurse into, and its cases *are* the contract. Anything else is a
	 * structure, described by its own arguments.
	 *
	 * @param string $declared The declared class or enum name.
	 * @param int    $depth    How deep this call already is.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When one of its arguments cannot be described.
	 */
	private function get_class_schema( string $declared, int $depth ): array {
		if ( 'object' === $declared || \stdClass::class === $declared ) {
			return array( 'type' => 'object' );
		}

		if ( ! \class_exists( $declared ) && ! \interface_exists( $declared ) && ! \enum_exists( $declared ) ) {
			throw new \InvalidArgumentException(
				\sprintf( 'Nothing declares %s, so it cannot describe an argument.', $declared )
			);
		}

		if ( \is_a( $declared, UploadedFile::class, true ) ) {
			// An upload arrives as multipart/form-data, which JSON Schema has no
			// type for -- and WordPress keeps uploads out of a request's
			// parameters entirely. Only a route can take one, through
			// get_rest_args(), which skips it before reaching here.
			throw new \InvalidArgumentException(
				\sprintf(
					'A file cannot be described in a schema, so only a REST route can take one. %s is not something an ability can accept.',
					UploadedFile::class
				)
			);
		}

		if ( \is_a( $declared, \UnitEnum::class, true ) ) {
			return $this->get_enum_schema( $declared );
		}

		// A moment in time is a string on the wire, and WordPress already knows
		// how to check one: `format: date-time` is enforced by rest_parse_date().
		if ( \is_a( $declared, \DateTimeInterface::class, true ) ) {
			return array(
				'type'   => 'string',
				'format' => 'date-time',
			);
		}

		$schema = $this->get_object_schema( $declared, $depth + 1 );

		if ( array() === $schema ) {
			// Nothing about the class says what it holds, so the schema would
			// carry a description and no type at all -- a published contract that
			// says nothing, which is worse than refusing to publish one.
			throw new \InvalidArgumentException(
				\sprintf(
					'%s declares no arguments, so nothing describes it. Give its properties #[RequestArgument], or describe the value with `schema:` instead of typing it as a class.',
					$declared
				)
			);
		}

		return $schema;
	}

	/**
	 * The schema for an enum: the values it accepts, and nothing else.
	 *
	 * A backed enum sends its backing value, which is the case for backing one --
	 * the value is then written down rather than inferred, and renaming a case
	 * does not change what callers must send. A pure enum has no such value, so
	 * its case names stand in; the schema names them either way, so a caller
	 * never has to guess which it is looking at.
	 *
	 * @param string $declared The enum's class name.
	 * @return array<string, mixed>
	 */
	private function get_enum_schema( string $declared ): array {
		$backing = ( new \ReflectionEnum( $declared ) )->getBackingType();

		$values = \array_map(
			static function ( \UnitEnum $member ) {
				return $member instanceof \BackedEnum ? $member->value : $member->name;
			},
			$declared::cases()
		);

		return array(
			'type' => $backing instanceof \ReflectionNamedType ? self::SCALAR_TYPES[ $backing->getName() ] : 'string',
			'enum' => $values,
		);
	}

	/**
	 * Widen a schema to accept null as well.
	 *
	 * A closed set has to admit null on both counts: the type says it is allowed
	 * and the enumerated values say which ones are, so leaving null out of either
	 * would reject it.
	 *
	 * @param array<string, mixed> $schema The schema to widen.
	 * @return array<string, mixed>
	 */
	private function get_nullable_schema( array $schema ): array {
		$schema['type'] = array( $schema['type'] ?? 'object', 'null' );

		if ( isset( $schema['enum'] ) ) {
			$schema['enum'][] = null;
		}

		return $schema;
	}

	/**
	 * Build the value for a class-typed argument: an enum case, or a structure.
	 *
	 * @param string $declared The declared class or enum name.
	 * @param mixed  $value    The validated value.
	 * @return mixed
	 * @throws \InvalidArgumentException When the value names no case of that enum.
	 */
	private function get_class_value( string $declared, $value ) {
		if ( \is_a( $declared, \UnitEnum::class, true ) ) {
			return $this->get_enum_case( $declared, $value );
		}

		if ( \is_a( $declared, \DateTimeInterface::class, true ) ) {
			return $this->get_date_value( $declared, $value );
		}

		if ( null === $value || $value instanceof $declared ) {
			return $value;
		}

		if ( ! \is_array( $value ) ) {
			/*
			 * Caught here rather than at the assignment, because a list of
			 * structures given scalars assigns perfectly well -- an array of
			 * strings is still an array -- and the wrong contents would only
			 * surface wherever the handler happened to read one.
			 */
			throw new \InvalidArgumentException(
				\sprintf( '%s is built from an object, and %s was given.', $declared, \gettype( $value ) )
			);
		}

		return $this->hydrate( $declared, $value );
	}

	/**
	 * Turn a decoded JSON object back into an object.
	 *
	 * WordPress decodes a request body to associative arrays, so an argument
	 * declared as an object has to be given one back. A list stays a list --
	 * casting one would turn its positions into property names.
	 *
	 * @param mixed $value The validated value.
	 * @return mixed
	 */
	private function get_object_value( $value ) {
		if ( ! \is_array( $value ) ) {
			return $value;
		}

		$converted = \array_map(
			function ( $item ) {
				return \is_array( $item ) ? $this->get_object_value( $item ) : $item;
			},
			$value
		);

		return \array_is_list( $converted ) ? $converted : (object) $converted;
	}

	/**
	 * Build the moment an incoming string names.
	 *
	 * The interface itself resolves to an immutable date, since something has to
	 * be instantiated and a value that cannot be changed underneath its holder is
	 * the safer of the two.
	 *
	 * @param string $declared The declared class or interface name.
	 * @param mixed  $value    The validated value.
	 * @return mixed
	 * @throws \InvalidArgumentException When the value is not a date WordPress can read.
	 */
	private function get_date_value( string $declared, $value ) {
		if ( null === $value || $value instanceof \DateTimeInterface ) {
			return $value;
		}

		$concrete = \DateTimeInterface::class === $declared ? \DateTimeImmutable::class : $declared;

		try {
			return new $concrete( (string) $value );
		} catch ( \Exception $e ) {
			// Unreachable through a schema this module built: `format: date-time`
			// is checked before anything binds.
			throw new \InvalidArgumentException(
				\sprintf( '"%s" is not a date.', \is_scalar( $value ) ? (string) $value : \gettype( $value ) )
			);
		}
	}

	/**
	 * Find the case an incoming value names.
	 *
	 * @param string $declared The enum's class name.
	 * @param mixed  $value    The validated value.
	 * @return \UnitEnum|null
	 * @throws \InvalidArgumentException When the value names no case of that enum.
	 */
	private function get_enum_case( string $declared, $value ): ?\UnitEnum {
		if ( null === $value || $value instanceof $declared ) {
			return $value;
		}

		foreach ( $declared::cases() as $case ) {
			if ( ( $case instanceof \BackedEnum ? $case->value : $case->name ) === $value ) {
				return $case;
			}
		}

		// Unreachable through a schema this module built, which lists exactly
		// these -- so this means the schema was replaced by hand and no longer
		// agrees with the property it fills.
		throw new \InvalidArgumentException(
			\sprintf( '"%s" is not a case of %s.', \is_scalar( $value ) ? (string) $value : \gettype( $value ), $declared )
		);
	}

	/**
	 * Convert one incoming value into what its property was declared as.
	 *
	 * @param \ReflectionProperty $property The declared property.
	 * @param RequestArgument     $argument Its attribute.
	 * @param mixed               $value    The validated value.
	 * @return mixed
	 * @throws \InvalidArgumentException When the structure or enum cannot take the value.
	 */
	private function get_bound_value( \ReflectionProperty $property, RequestArgument $argument, $value ) {
		$type = $property->getType();
		$name = $type instanceof \ReflectionNamedType ? $type->getName() : null;

		if ( \is_a( (string) $name, UploadedFile::class, true ) ) {
			return \is_array( $value ) ? UploadedFile::from_array( $value ) : $value;
		}

		if ( 'array' === $name && UploadedFile::class === $argument->of && \is_array( $value ) ) {
			// PHP transposes a multi-file field, so this is one entry rather than
			// a list of them.
			return UploadedFile::from_multiple( $value );
		}

		if ( 'object' === $name || \stdClass::class === $name ) {
			return $this->get_object_value( $value );
		}

		if ( 'array' === $name && ( 'object' === $argument->of || \stdClass::class === $argument->of ) && \is_array( $value ) ) {
			return \array_map(
				function ( $item ) {
					return $this->get_object_value( $item );
				},
				$value
			);
		}

		if ( null !== $name && ! isset( self::SCALAR_TYPES[ $name ] ) ) {
			return $this->get_class_value( $name, $value );
		}

		if ( 'array' === $name && null !== $argument->of && \is_array( $value ) ) {
			return \array_map(
				function ( $item ) use ( $argument ) {
					return $this->get_class_value( $argument->of, $item );
				},
				$value
			);
		}

		return $value;
	}

	/**
	 * Whether a property is declared as an upload.
	 *
	 * @param \ReflectionProperty $property The declared property.
	 * @param RequestArgument     $argument Its attribute.
	 * @return bool
	 */
	private function is_file( \ReflectionProperty $property, RequestArgument $argument ): bool {
		$type = $property->getType();
		$name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

		return \is_a( $name, UploadedFile::class, true )
			|| ( 'array' === $name && UploadedFile::class === $argument->of );
	}

	/**
	 * The default an argument declares, if it declares one.
	 *
	 * A promoted constructor parameter is the case worth knowing about: its
	 * default belongs to the parameter, so `ReflectionProperty::hasDefaultValue()`
	 * answers false for `public function __construct( public string $country = \'US\' )`
	 * and the argument would be published as required.
	 *
	 * @param \ReflectionProperty $property The declared property.
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
	 * Call one of an argument's own callbacks, saying which if it will not run.
	 *
	 * A callback that cannot take what it is given fails inside this module,
	 * where PHP's own message names the callback's parameter and a line of
	 * library code -- true, and no help at all in finding the declaration that
	 * paired them.
	 *
	 * @param callable $callback The argument's validate or sanitize.
	 * @param mixed    $value    The value to pass it.
	 * @param string   $name     The argument's name.
	 * @param string   $kind     Which callback this is, for the message.
	 * @return mixed
	 * @throws \InvalidArgumentException When the callback refuses the value it was given.
	 */
	private function get_callback_value( callable $callback, $value, string $name, string $kind ) {
		try {
			return $callback( $value );
		} catch ( \TypeError $e ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The %1$s callback for "%2$s" cannot take the value it was given: %3$s',
					$kind,
					$name,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * The error a failed validate callback produces.
	 *
	 * The code is the caller's, because the two platforms already have their
	 * own: WordPress answers a route's invalid parameter with
	 * `rest_invalid_param`, and an ability's invalid input with
	 * `ability_invalid_input`. Matching whichever applies means a client handles
	 * one code per platform rather than two.
	 *
	 * Every refused argument is named, in the message and in the `params` data,
	 * the way WordPress names every parameter a route refused.
	 *
	 * @param string[]             $names The arguments that were refused.
	 * @param string               $code  The error code to carry.
	 * @param array<string, mixed> $data  Anything the platform attaches, such as a status.
	 * @return \WP_Error
	 */
	private function get_refusal( array $names, string $code, array $data = array() ): \WP_Error {
		return new \WP_Error(
			$code,
			\sprintf(
				/* translators: %s: comma-separated list of argument names. */
				\_n(
					'The value given for %s is not valid.',
					'The values given for %s are not valid.',
					\count( $names ),
					'zestry-toolkit'
				),
				'"' . \implode( '", "', $names ) . '"'
			),
			\array_merge( $data, array( 'params' => $names ) )
		);
	}
}
