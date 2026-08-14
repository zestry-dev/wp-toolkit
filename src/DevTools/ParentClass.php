<?php

/**
 * DevTools: resolving the class a generated file extends
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;

/**
 * Finds the class `--extends` names, refuses one that cannot work, and writes
 * out the methods it leaves abstract.
 *
 * A plugin with more than a handful of post types or fields grows an
 * intermediate abstract — one class extending the toolkit's base and holding
 * what every file of that kind shares. Discovery already accepts it, because it
 * checks `instanceof` and a subclass of a `Field` is a `Field`. What was missing
 * is a generator that knows about it.
 *
 * The reason this is a feature rather than a one-word edit to the stub: a
 * generator pointed at your abstract can write out the methods *that* class
 * leaves abstract, which is knowledge no stub shipped here could contain.
 *
 * @internal
 */
class ParentClass extends Module {

	/**
	 * Where a bare name is looked for, under the plugin's own namespace.
	 *
	 * `Abstracts\` first because that is where an intermediate belongs and where
	 * a plugin that has one tends to keep it. The empty segment is the namespace
	 * root, so one kept loose is still found.
	 */
	private const SEARCH_SEGMENTS = array( 'Abstracts\\', 'Modules\\', '' );

	/**
	 * The value a stubbed method returns, by declared return type.
	 *
	 * A type absent from this list has no value that is both valid and obviously
	 * unfinished — an enum case or an object would be a guess — so a method
	 * returning one is written to throw until it is filled in.
	 */
	private const EMPTY_RETURNS = array(
		'array'  => 'array()',
		'string' => "''",
		'bool'   => 'false',
		'int'    => '0',
		'float'  => '0.0',
		'mixed'  => 'null',
	);

	/**
	 * The class a `--extends` value names.
	 *
	 * A name carrying a separator is taken verbatim, so a class outside the
	 * plugin's own namespace can still be named. A bare one is looked for under
	 * {@see SEARCH_SEGMENTS} — `--extends=EntityField` finds
	 * `Acme\Plugin\Abstracts\EntityField` without anyone typing it.
	 *
	 * @param string $given The value given on the command line.
	 * @param string $root  The plugin's namespace root, without a trailing separator.
	 * @return string The resolved class name.
	 * @throws \InvalidArgumentException When nothing of that name can be loaded.
	 */
	public function resolve( string $given, string $root ): string {
		$given = \ltrim( \trim( $given ), '\\' );

		if ( '' === $given ) {
			throw new \InvalidArgumentException( '--extends needs a class name.' );
		}

		if ( \str_contains( $given, '\\' ) ) {
			if ( ! \class_exists( $given ) ) {
				throw new \InvalidArgumentException(
					\sprintf(
						'No class "%s" could be loaded. Check the spelling, and that Composer knows about it -- a class added since the last `composer dump-autoload` will not be found.',
						$given
					)
				);
			}

			return $given;
		}

		$tried = array();

		foreach ( self::SEARCH_SEGMENTS as $segment ) {
			$candidate = $root . '\\' . $segment . $given;
			$tried[]   = $candidate;

			if ( \class_exists( $candidate ) ) {
				return $candidate;
			}
		}

		throw new \InvalidArgumentException(
			\sprintf(
				'No class "%s" could be loaded. Looked for %s. Give the full name if it lives somewhere else.',
				$given,
				\implode( ', ', $tried )
			)
		);
	}

	/**
	 * Refuse a class that could not work, saying which way it could not.
	 *
	 * Both failures are cheap to catch here and expensive to meet later: the
	 * wrong base throws a `DiscoveryException` at boot, on every request, and a
	 * final class is a fatal as soon as the generated file is read.
	 *
	 * @param string $ancestor The resolved class.
	 * @param string $base   The class a file of this type has to be.
	 * @return void
	 * @throws \InvalidArgumentException When a file of this type cannot extend it.
	 */
	public function assert_usable( string $ancestor, string $base ): void {
		if ( ! \class_exists( $base ) ) {
			/*
			 * Said as its own failure rather than through the subclass check
			 * below, which would answer "does not extend" -- true, and no help
			 * at all when the class it does not extend is one this plugin has
			 * never had.
			 */
			throw new \InvalidArgumentException(
				\sprintf(
					'This plugin has no %s, so nothing here can extend it. Add the module that owns it with `wp zt add <name>`, then try again.',
					$base
				)
			);
		}

		if ( ( new \ReflectionClass( $ancestor ) )->isFinal() ) {
			throw new \InvalidArgumentException(
				\sprintf( '"%s" is final, so nothing can extend it.', $ancestor )
			);
		}

		if ( $ancestor !== $base && ! \is_subclass_of( $ancestor, $base ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'"%s" does not extend %s, so a file returning one would be refused at boot. Did you mean a different `make` type?',
					$ancestor,
					$base
				)
			);
		}
	}

	/**
	 * The methods a class leaves abstract, written out ready to fill in.
	 *
	 * Reflection reports exactly the set a subclass must implement: an abstract
	 * method that something in the chain has already implemented is not abstract
	 * any more, so what comes back is what is genuinely still owed.
	 *
	 * @param string $ancestor The resolved parent class.
	 * @return string The rendered methods, or an empty string when it leaves none.
	 */
	public function get_abstract_methods( string $ancestor ): string {
		$methods = array();

		foreach ( ( new \ReflectionClass( $ancestor ) )->getMethods() as $method ) {
			if ( ! $method->isAbstract() ) {
				continue;
			}

			$methods[] = $this->get_method_stub( $method );
		}

		return \implode( "\n\n", $methods );
	}

	/**
	 * One abstract method, rendered as something that compiles.
	 *
	 * @param \ReflectionMethod $method The abstract method.
	 * @return string
	 */
	private function get_method_stub( \ReflectionMethod $method ): string {
		$declared = $method->hasReturnType() ? $this->get_type_name( $method->getReturnType() ) : '';

		$lines = array();
		$doc   = $this->get_doc_comment( $method );

		if ( '' !== $doc ) {
			$lines[] = $doc;
		}

		// Spaces inside the parentheses only when something is between them,
		// which is what the coding standard the generated file is linted to says.
		$parameters = $this->get_parameters( $method );

		$lines[] = \sprintf(
			"\t%s function %s(%s)%s {",
			$method->isProtected() ? 'protected' : 'public',
			$method->getName(),
			'' === $parameters ? '' : ' ' . $parameters . ' ',
			'' === $declared ? '' : ': ' . $declared
		);

		$body = $this->get_body( $declared );

		if ( '' !== $body ) {
			$lines[] = "\t\t" . $body;
		}

		$lines[] = "\t}";

		return \implode( "\n", $lines );
	}

	/**
	 * A method's parameters, spelled as the declaration spells them.
	 *
	 * @param \ReflectionMethod $method The method.
	 * @return string
	 */
	private function get_parameters( \ReflectionMethod $method ): string {
		$parameters = array();

		foreach ( $method->getParameters() as $parameter ) {
			$declaration = ( $parameter->hasType() ? $this->get_type_name( $parameter->getType() ) . ' ' : '' )
				. ( $parameter->isPassedByReference() ? '&' : '' )
				. ( $parameter->isVariadic() ? '...' : '' )
				. '$' . $parameter->getName();

			if ( $parameter->isDefaultValueAvailable() ) {
				$declaration .= ' = ' . $this->get_default_literal( $parameter );
			}

			$parameters[] = $declaration;
		}

		return \implode( ', ', $parameters );
	}

	/**
	 * A parameter's default, written back as source rather than as a value.
	 *
	 * @param \ReflectionParameter $parameter The parameter.
	 * @return string
	 */
	private function get_default_literal( \ReflectionParameter $parameter ): string {
		if ( $parameter->isDefaultValueConstant() ) {
			// Written by name: the value would be a copy that stops tracking the
			// declaration it came from.
			return '\\' . \ltrim( (string) $parameter->getDefaultValueConstantName(), '\\' );
		}

		$default = $parameter->getDefaultValue();

		if ( null === $default ) {
			return 'null';
		}

		if ( \is_bool( $default ) ) {
			return $default ? 'true' : 'false';
		}

		if ( \is_string( $default ) ) {
			return "'" . \addcslashes( $default, "'\\" ) . "'";
		}

		if ( \is_array( $default ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- turning a value back into source is exactly what this is for.
			return array() === $default ? 'array()' : \var_export( $default, true );
		}

		return (string) $default;
	}

	/**
	 * A type, written so it resolves in a file that imports nothing.
	 *
	 * A class name comes out fully qualified for that reason. A union or an
	 * intersection keeps its declared order, so the generated signature is the
	 * one PHP compares against the parent's.
	 *
	 * @param \ReflectionType|null $type The declared type.
	 * @return string The type as source, or an empty string when there is none.
	 */
	private function get_type_name( ?\ReflectionType $type ): string {
		if ( $type instanceof \ReflectionNamedType ) {
			$name = $type->isBuiltin() ? $type->getName() : '\\' . $type->getName();

			// `mixed` and `null` are nullable already, and PHP rejects `?mixed`.
			return $type->allowsNull() && ! \in_array( $type->getName(), array( 'mixed', 'null' ), true )
				? '?' . $name
				: $name;
		}

		if ( $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ) {
			$parts = array();

			foreach ( $type->getTypes() as $member ) {
				$parts[] = $this->get_type_name( $member );
			}

			return \implode( $type instanceof \ReflectionUnionType ? '|' : '&', $parts );
		}

		return '';
	}

	/**
	 * The body for a method returning a given type.
	 *
	 * @param string $declared The rendered return type.
	 * @return string The body, or an empty string for a method returning nothing.
	 */
	private function get_body( string $declared ): string {
		if ( '' === $declared || 'void' === $declared || 'never' === $declared ) {
			return '';
		}

		if ( \str_starts_with( $declared, '?' ) ) {
			return 'return null;';
		}

		foreach ( \explode( '|', $declared ) as $member ) {
			if ( isset( self::EMPTY_RETURNS[ $member ] ) ) {
				return 'return ' . self::EMPTY_RETURNS[ $member ] . ';';
			}
		}

		/*
		 * An object, or an enum case. No value here would be both valid and
		 * obviously unfinished, and a method quietly returning something
		 * plausible is worse than one that stops -- this says which method, on
		 * the first request that reaches it.
		 */
		return \sprintf( "throw new \\RuntimeException( 'Return a %s here.' );", $declared );
	}

	/**
	 * A method's docblock, whole, re-indented for where it is going.
	 *
	 * What an abstract is for is written on the abstract, so carrying it across
	 * saves opening the parent to find out what the method owes. Copied entire
	 * rather than summarised: `@param` and `@return` describe the signature this
	 * generated method has verbatim, so they are as true here as there -- and
	 * whatever the parent says beyond its first line is usually the part worth
	 * having.
	 *
	 * Reflection hands back the block carrying the indentation it had in the
	 * parent, which is nothing on the opening line and the parent's own on every
	 * line after. Both are replaced, so the block sits where this file puts it
	 * rather than where it came from.
	 *
	 * @param \ReflectionMethod $method The method.
	 * @return string The docblock, or an empty string when it has none.
	 */
	private function get_doc_comment( \ReflectionMethod $method ): string {
		$doc = $method->getDocComment();

		if ( false === $doc ) {
			return '';
		}

		$lines = array();

		foreach ( \explode( "\n", $doc ) as $index => $line ) {
			// The opening `/**` sits flush against the tab; every line after it
			// is a continuation and takes the extra space its `*` aligns on.
			$lines[] = "\t" . ( 0 === $index ? '' : ' ' ) . \ltrim( $line );
		}

		return \implode( "\n", $lines );
	}
}
