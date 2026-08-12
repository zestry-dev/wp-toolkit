<?php

/**
 * DevTools: check that a page's subclass matches the base class it extends
 */

declare( strict_types=1 );

/**
 * Report a documented method whose signature its base class would reject.
 *
 * A page showing a subclass is showing code a reader will copy, and PHP checks
 * an override's signature when the class loads — before any of this toolkit's
 * own guards can run, and fatally, on every request. So a page that drops a
 * parameter does not mislead a reader, it takes their site down, and there is no
 * admin screen left to deactivate the plugin from.
 *
 * `composer docs:check` already catches a page naming a method that does not
 * exist. This catches the narrower and more expensive case: the method exists,
 * the page spells it differently, and nothing notices until the copy is loaded.
 *
 * Parameter names are ignored, since an override may rename them freely. Types
 * and count are what PHP enforces, and an override may add optional parameters
 * of its own — so only the base's own positions are compared.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Problems found.
 */
function zestry_verify_signatures( string $root ): array {
	$abstracts = zestry_abstract_signatures( $root );
	$problems  = array();

	$pages = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/docs' ) );

	foreach ( $pages as $page ) {
		if ( ! $page->isFile() || 'md' !== $page->getExtension() ) {
			continue;
		}

		$relative = substr( $page->getPathname(), strlen( $root ) + 1 );
		$markdown = (string) file_get_contents( $page->getPathname() );

		// Every fenced block, whatever it is labelled: a snippet showing a
		// subclass is worth checking whether or not it says ```php.
		preg_match_all( '/^```[a-z]*\n(.*?)^```/ms', $markdown, $blocks );

		foreach ( $blocks[1] as $block ) {
			$problems = array_merge( $problems, zestry_check_subclass( $abstracts, $relative, $block, 'the page declares' ) );
		}
	}

	/*
	 * A stub is the same risk with a wider blast radius. A page showing a bad
	 * override misleads whoever copies it; a stub *is* what every generated file
	 * starts as, so the same mistake reaches everyone who runs `wp zt make`
	 * from that point on. It has happened: route.php.stub once narrowed
	 * permission_check() to `bool` while its own comment told the author to
	 * return a WP_Error, which fatals under strict_types.
	 *
	 * The whole stub is the block, since a stub is nothing but the subclass.
	 * Placeholders are left unrendered on purpose -- none of them appears in a
	 * method signature, and reading the file raw keeps this independent of the
	 * sample values.
	 */
	foreach ( glob( $root . '/src/DevTools/stubs/{*.php.stub,*/*.php.stub}', GLOB_BRACE ) ?: array() as $stub ) {
		$problems = array_merge(
			$problems,
			zestry_check_subclass(
				$abstracts,
				substr( $stub, strlen( $root ) + 1 ),
				(string) file_get_contents( $stub ),
				'the stub declares'
			)
		);
	}

	return $problems;
}

/**
 * Report every override in one block of code that its base class would reject.
 *
 * PHP checks an override's signature when the class loads -- before any of this
 * toolkit's own guards can run, fatally, on every request -- so this is not a
 * matter of taste. It is also not catchable: an incompatible declaration is a
 * compile error, which is why this reads the source rather than loading it.
 *
 * @param array<string, array<string, string[]>> $abstracts Parameter types, by class then method.
 * @param string                                 $relative  The file's repo-relative path, for the message.
 * @param string                                 $block     The code to check.
 * @param string                                 $says      How to describe the source in the message.
 * @return string[] Problems found.
 */
function zestry_check_subclass( array $abstracts, string $relative, string $block, string $says ): array {
	// `class X extends Base`, and the anonymous form every discovered
	// file returns: `new class extends Base` / `new class() extends Base`.
	if ( ! preg_match( '/(?:class\s+\w+|new\s+class(?:\(\))?)\s+extends\s+\\\\?([\w\\\\]+)/', $block, $extends ) ) {
		return array();
	}

	$base = substr( strrchr( '\\' . $extends[1], '\\' ), 1 );

	if ( ! isset( $abstracts[ $base ] ) ) {
		return array();
	}

	$problems = array();

	foreach ( $abstracts[ $base ] as $method => $expected ) {
		if ( ! preg_match( '/(?:public|protected)\s+function\s+' . $method . '\s*\(([^)]*)\)/', $block, $found ) ) {
			continue;
		}

		$actual = zestry_parameter_types( $found[1] );

		if ( $actual === array_slice( $actual, 0, count( $expected ) ) && $expected === array_slice( $actual, 0, count( $expected ) ) ) {
			continue;
		}

		$problems[] = sprintf(
			'%1$s — %2$s::%3$s() takes (%4$s), and %5$s (%6$s). Anything built from this fatals on every request.',
			$relative,
			$base,
			$method,
			implode( ', ', $expected ),
			$says,
			implode( ', ', $actual )
		);
	}

	return $problems;
}

/**
 * Every abstract method in `src/`, by declaring class and method name.
 *
 * @param string $root Absolute path to the repository root.
 * @return array<string, array<string, string[]>> Parameter types, keyed by class then method.
 */
function zestry_abstract_signatures( string $root ): array {
	$signatures = array();

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );

	foreach ( $files as $file ) {
		if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}

		$source = (string) file_get_contents( $file->getPathname() );

		if ( ! preg_match( '/^abstract class (\w+)/m', $source, $class ) ) {
			continue;
		}

		preg_match_all( '/abstract\s+(?:public|protected)\s+function\s+(\w+)\s*\(([^)]*)\)/', $source, $methods, PREG_SET_ORDER );

		foreach ( $methods as $method ) {
			$signatures[ $class[1] ][ $method[1] ] = zestry_parameter_types( $method[2] );
		}
	}

	return $signatures;
}

/**
 * The declared types of a parameter list, in order, with names dropped.
 *
 * A leading `?` and any namespace are stripped, so a page writing
 * `WP_REST_Request` matches a source file writing `\WP_REST_Request`, and an
 * untyped parameter counts as one position rather than none.
 *
 * @param string $parameters The text between the parentheses.
 * @return string[]
 */
function zestry_parameter_types( string $parameters ): array {
	$parameters = trim( $parameters );

	if ( '' === $parameters ) {
		return array();
	}

	$types = array();

	foreach ( explode( ',', $parameters ) as $parameter ) {
		$parameter = trim( $parameter );

		// Everything up to the variable is the type, if anything is.
		$type = trim( (string) preg_replace( '/\$\w+.*$/', '', $parameter ) );
		$type = ltrim( $type, '?' );
		$type = str_replace( ' ', '', $type );

		if ( str_contains( $type, '\\' ) ) {
			$type = substr( strrchr( $type, '\\' ), 1 );
		}

		$types[] = '' === $type ? 'mixed' : $type;
	}

	return $types;
}
