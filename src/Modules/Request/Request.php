<?php

/**
 * Request API: Request module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Request;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Modules\Validation\Validation;

/**
 * Reads a request into the properties a route or an action declares.
 *
 * The declarations themselves are {@see Validation}'s: a typed property with
 * {@see \Zestry\WPToolkit\Modules\Validation\Property} or a rule. This module is
 * the part only an HTTP request has -- the parameters WordPress registers for a
 * route, the order a value is looked up in, and uploads.
 *
 * You rarely call this. Declare the properties, and the module that discovered
 * your route or action does the rest.
 */
class Request extends Module {

	/**
	 * The keys of a REST arg that say how to process it, rather than describe it.
	 */
	private const ARG_KEYS = array( 'validate_callback', 'sanitize_callback', 'arg_options' );

	/**
	 * The per-parameter `args` array `register_rest_route()` takes.
	 *
	 * Flat rather than nested: WordPress reads each entry as the schema for one
	 * parameter, so a class becomes one entry of `type => object`. Each one is
	 * checked by {@see Validation} in WordPress's own `validate_callback` slot,
	 * so a route's failures are reported the way every other route reports
	 * them, keyed by where they are.
	 *
	 * `$overrides` is keyed by parameter name, matching the flat shape it is
	 * stated over. A name no property declares is added rather than dropped:
	 * still a parameter WordPress checks, read off the request.
	 *
	 * @param object                              $target    The wired route.
	 * @param string[]                            $required  Names required regardless of their default -- a route's URL tokens.
	 * @param array<string, array<string, mixed>> $overrides Partial schemas stated over the derived ones, keyed by name.
	 * @return array<string, array<string, mixed>>
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 *
	 * @internal
	 */
	public function get_rest_args( object $target, array $required = array(), array $overrides = array() ): array {
		$validation = $this->with( Validation::class );
		$declared   = $validation->get_properties( $target );

		/*
		 * Stated before anything is derived from it, so the check and what is
		 * published both use the final schema -- a translated description
		 * changes nothing, an `enum` worked out at runtime is enforced.
		 */
		$schema   = $validation->get_schema( $target, array( 'properties' => $overrides ) );
		$required = \array_merge( $schema['required'] ?? array(), $required );
		$args     = array();

		foreach ( $schema['properties'] ?? array() as $name => $property ) {
			if ( ! isset( $declared[ $name ] ) ) {
				// Nothing declares it, so WordPress checks it as it was stated.
				$args[ $name ] = $property;

				continue;
			}

			// `required: true` on the parameter itself is how a REST arg says it.
			if ( true === ( $property['required'] ?? null ) ) {
				$required[] = $name;
				unset( $property['required'] );
			}

			$handlers = \array_intersect_key( $property, \array_flip( self::ARG_KEYS ) );
			$property = \array_diff_key( $property, $handlers );

			$args[ $name ] = self::get_wordpress_schema( $property );

			if ( \in_array( $name, $required, true ) ) {
				$args[ $name ]['required'] = true;
			}

			/*
			 * A validate_callback replaces WordPress's own schema check, and
			 * Validation's walk runs that same check at every level before the
			 * rules -- so every problem is reported, not just the first. It walks
			 * the schema as Validation states it, not as reshaped for WordPress.
			 */
			$args[ $name ]['validate_callback'] = $handlers['validate_callback'] ?? function ( $value ) use ( $validation, $target, $name, $property ) {
				$errors = $validation->get_property_errors( $target, $name, $value, $property );

				return array() === $errors ? true : $validation->get_refusal( $errors, 'rest_invalid_param', array( 'status' => 400 ) );
			};

			/*
			 * Left as sent: the route's own build step casts every value. Core's
			 * cast would also turn each item of a `mixed` list into an array, by
			 * picking the first type that fits.
			 */
			$args[ $name ]['sanitize_callback'] = $handlers['sanitize_callback'] ?? static function ( $value ) {
				return $value;
			};

			if ( isset( $handlers['arg_options'] ) ) {
				$args[ $name ]['arg_options'] = $handlers['arg_options'];
			}
		}

		return $args;
	}

	/**
	 * Read an object's declared properties out of the current request.
	 *
	 * For a caller whose platform hands it no parameters of its own. A route gets
	 * them from {@see \WP_REST_Request::get_param()} and an ability is passed them
	 * outright; an AJAX action is a plain hook, so this is the equivalent for it,
	 * resolving each name the same way a route does:
	 *
	 * 1. the **JSON body**, when the `Content-Type` says the body is one
	 * 2. the **form body** -- `$_POST`, on a method that carries one
	 * 3. the **query string** -- `$_GET`
	 *
	 * An upload is read from `$_FILES`. First source holding the name wins, and a
	 * name no source holds is left out, so the property keeps its default. A
	 * **cookie and a header are never parameters**.
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
	 * @param object $target The object declaring the properties.
	 * @return array<string,mixed> The values present, unslashed and otherwise raw.
	 * @throws \InvalidArgumentException When a declaration cannot be described.
	 */
	public function get_submitted_values( object $target ): array {
		$validation = $this->with( Validation::class );
		$names      = \array_keys( $validation->get_properties( $target ) );

		if ( array() === $names ) {
			return array();
		}

		$request = $this->get_current_request();
		$files   = $request->get_file_params();
		$values  = array();

		foreach ( $names as $name ) {
			if ( $request->has_param( $name ) ) {
				$values[ $name ] = $request->get_param( $name );
			}
		}

		foreach ( \array_keys( $validation->get_file_properties( $target ) ) as $name ) {
			if ( isset( $files[ $name ] ) ) {
				$values[ $name ] = $files[ $name ];
			}
		}

		return $values;
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
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validation is the sanitising, against the declared schema.
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
	 * A schema, with each list of required properties moved onto the properties.
	 *
	 * A REST arg's own `required` says whether the parameter must be sent, so it
	 * has no room for a class's list. Each property says so itself instead --
	 * `required: true`, which WordPress reads at any depth -- all the way down,
	 * since a property marked that way has no room for its own list either.
	 *
	 * @param array<string, mixed> $schema The schema, as Validation states it.
	 * @return array<string, mixed>
	 */
	private static function get_wordpress_schema( array $schema ): array {
		foreach ( array( 'items', 'additionalProperties' ) as $key ) {
			if ( \is_array( $schema[ $key ] ?? null ) ) {
				$schema[ $key ] = self::get_wordpress_schema( $schema[ $key ] );
			}
		}

		foreach ( \is_array( $schema['patternProperties'] ?? null ) ? $schema['patternProperties'] : array() as $pattern => $values ) {
			$schema['patternProperties'][ $pattern ] = self::get_wordpress_schema( $values );
		}

		if ( ! \is_array( $schema['properties'] ?? null ) ) {
			return $schema;
		}

		$list = array();

		if ( \is_array( $schema['required'] ?? null ) ) {
			$list = $schema['required'];
			unset( $schema['required'] );
		}

		foreach ( $schema['properties'] as $name => $property ) {
			$property = self::get_wordpress_schema( $property );

			if ( \in_array( $name, $list, true ) ) {
				$property['required'] = true;
			}

			$schema['properties'][ $name ] = $property;
		}

		return $schema;
	}
}
