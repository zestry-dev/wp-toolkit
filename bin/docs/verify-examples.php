<?php

/**
 * DevTools: docblock example verification
 */

declare( strict_types=1 );

/**
 * Check that every method a docblock example calls actually exists.
 *
 * An example is documentation a reader copies, so a call to a method that was
 * renamed or never existed is worse than no example at all. This caught
 * `Path::get_plugin_dir()` in a hand-written example when the real method is
 * `get_plugin_uploads_dir()`.
 *
 * Only `$variable->method()` calls on a variable named after a known module are
 * checked; WordPress functions and locals are ignored, since this has no way to
 * resolve them.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Human-readable problems, empty when everything resolves.
 */
function zestry_verify_examples( string $root ): array {
	$problems = array();
	$classes  = array();

	// Index every class and trait in src/ by short name, with its
	// public/protected methods and whatever it inherits them from.
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );

	foreach ( $files as $file ) {
		if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}

		$source = (string) file_get_contents( $file->getPathname() );

		/*
		 * Anchored to the start of a line, since a declaration always is and
		 * prose is not: unanchored, "Base class for lifecycle callbacks" in a
		 * docblock indexed a class named "for" and the real one never appeared.
		 * Traits are indexed alongside classes so a `use` can be followed.
		 */
		if ( ! preg_match( '/^(abstract |final )*(?:class|trait) (\w+)(?: extends (\w+))?/m', $source, $class_match ) ) {
			continue;
		}

		preg_match_all( '/(?:public|protected)(?: static)? function (\w+)\(/', $source, $methods );

		// Trait methods belong to the using class as surely as its own, and
		// `boot()` reaches examples that way.
		preg_match_all( '/^\tuse (\w+)/m', $source, $traits );

		$classes[ $class_match[2] ] = array(
			'methods'  => $methods[1],
			'parents'  => array_merge( array_filter( array( $class_match[3] ?? '' ) ), $traits[1] ),
			'abstract' => 'abstract ' === $class_match[1],
			'path'     => substr( $file->getPathname(), strlen( $root ) + 1 ),
		);
	}

	// A method a class inherits is one an example may legitimately call, so
	// each class absorbs what its parent and traits declare. Resolved after the
	// scan, since a parent may be indexed later than the class extending it.
	foreach ( array_keys( $classes ) as $class ) {
		$classes[ $class ]['methods'] = zestry_inherited_methods( $classes, $class );
	}

	/*
	 * A variable named $ajax/$path/$globals is assumed to hold that module.
	 *
	 * An abstract base is left out: `$module` in an example inside Module means
	 * "whichever module this is", and the methods it calls are the subclass's
	 * own, so mapping the name to the base class only ever reports a method the
	 * base was never going to declare.
	 */
	$by_variable = array();

	foreach ( $classes as $class => $details ) {
		if ( $details['abstract'] ) {
			continue;
		}

		$by_variable[ strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $class ) ) ] = $class;
	}

	foreach ( $files as $file ) {
		if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}

		$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		$source   = (string) file_get_contents( $file->getPathname() );

		$fenced = false;

		foreach ( explode( "\n", $source ) as $number => $line ) {
			if ( preg_match( '/^\s*\*\s*```/', $line ) ) {
				$fenced = ! $fenced;

				continue;
			}

			/*
			 * Code in a docblock, in either of the two shapes this codebase
			 * writes it: fenced, or indented four spaces past the asterisk.
			 *
			 * The gate used to be the indented form alone, which silently
			 * excluded every fenced example -- where a line at the top level of
			 * the block carries exactly one space. Most examples here are
			 * fenced, so most were never checked, which is how a published page
			 * came to call a method that has never existed.
			 *
			 * Prose is still excluded, and has to be: a sentence saying "read
			 * `$this->order_id`, not `$request->get_param()`" names a method on
			 * purpose, and on a class this cannot resolve.
			 */
			if ( ! $fenced && ! preg_match( '/^\s*\*\s{4,}/', $line ) ) {
				continue;
			}

			/*
			 * Both shapes an example uses, and the second is the one the toolkit
			 * actually teaches: `$views->render()` when something resolved the
			 * service, and `$this->views->render()` when a typed property was
			 * injected. Only the first was checked, so every example written the
			 * recommended way went unverified -- which is how
			 * `$this->transients->forget()` reached a published page naming a
			 * method that has never existed.
			 */
			if ( ! preg_match_all( '/\$(?:this->)?(\w+)->(\w+)\(/', $line, $calls, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $calls as $call ) {
				$problems = array_merge(
					$problems,
					zestry_check_example_call( $classes, $by_variable, $relative, $number + 1, $call[1], $call[2] )
				);
			}
		}
	}

	return $problems;
}

/**
 * Report one example call naming a method its class does not have.
 *
 * @param array<string, array{methods: string[], abstract: bool}> $classes     Every class, by short name.
 * @param array<string, string>                                   $by_variable Variable name => class.
 * @param string                                                  $relative    The file's repo-relative path.
 * @param int                                                     $line        The line number, 1-indexed.
 * @param string                                                  $variable    The variable or property called on.
 * @param string                                                  $method      The method called.
 * @return string[] Problems found.
 */
function zestry_check_example_call( array $classes, array $by_variable, string $relative, int $line, string $variable, string $method ): array {
	if ( ! isset( $by_variable[ $variable ] ) ) {
		return array();
	}

	$class = $by_variable[ $variable ];

	if ( in_array( $method, $classes[ $class ]['methods'], true ) ) {
		return array();
	}

	return array(
		sprintf(
			'%s:%d — example calls $%s->%s(), which does not exist on %s',
			$relative,
			$line,
			$variable,
			$method,
			$class
		),
	);
}

/**
 * Check that every toolkit class an example or a stub names actually exists.
 *
 * The companion to {@see zestry_verify_examples()}, which resolves method calls but
 * never reads a `use` statement -- so fifteen imports naming classes that had
 * been renamed passed this build green. A wrong import is the worse failure of
 * the two: a missing method is a fatal on the line that calls it, a missing
 * class is a fatal the moment the file loads.
 *
 * Three spellings reach a reader and all three are checked together, because the
 * `Core` segment is exactly what a rename gets wrong:
 *
 *     Zestry\WPToolkit\Modules\Ajax\Ajax                 this package's own tree
 *     Acme\Plugin\Core\Modules\Ajax\Ajax    a consumer's copy, in a docs example
 *     {{copied_namespace}}\Modules\Ajax     the same, unrendered, in a .stub
 *
 * A name outside them is the consumer's own class (`Acme\Plugin\Modules\
 * Shortcode`) and is skipped -- nothing here can know whether it exists.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Human-readable problems, empty when every import resolves.
 */
function zestry_verify_imports( string $root ): array {
	$problems = array();

	/*
	 * PSR-4 both ways: a class exists if its file does. Cheaper and stricter
	 * than class_exists(), which would need this package autoloaded and would
	 * answer yes for anything Composer could reach, including a consumer's.
	 *
	 * A path landing on a directory is a namespace prose mentions rather than a
	 * class it names -- `Zestry\WPToolkit\Modules\...` written mid-sentence reaches here as
	 * `Zestry\WPToolkit\Modules`, and src/Modules is real. Only a class can be wrong.
	 */
	$exists = static function ( string $class ) use ( $root ): bool {
		$path = $root . '/src/' . str_replace( '\\', '/', zestry_without_root_namespace( $class ) );

		return is_file( $path . '.php' ) || is_dir( $path );
	};

	foreach ( zestry_import_sources( $root ) as $relative => $lines ) {
		foreach ( $lines as $number => $line ) {
			if ( ! preg_match_all( '/\\\\?(?:\{\{copied_namespace\}\}|\{\{namespace\}\}|Acme\\\\Plugin|Zestry\\\\WPToolkit)(\\\\\w+)+/', $line, $matches ) ) {
				continue;
			}

			foreach ( $matches[0] as $reference ) {
				$reference = ltrim( $reference, '\\' );

				$copied = str_replace(
					array( '{{copied_namespace}}', 'Acme\\Plugin\\Core' ),
					'Zestry\\WPToolkit',
					$reference
				);

				// A reference through the copied root: it has to name something.
				if ( str_starts_with( $copied, 'Zestry\\WPToolkit\\' ) ) {
					if ( ! $exists( $copied ) ) {
						$problems[] = sprintf(
							'%s:%d — names %s, which does not exist (no src/%s.php)',
							$relative,
							$number + 1,
							$reference,
							str_replace( '\\', '/', zestry_without_root_namespace( $copied ) )
						);
					}

					continue;
				}

				/*
				 * A reference through the plain root is the consumer's own code
				 * and unknowable here -- unless the same name exists in this
				 * package, which means it is toolkit source written without the
				 * `Core` segment. That is the failure that shipped fifteen dead
				 * imports: `Acme\Plugin\Modules\Ajax\AjaxAction` reads fine and
				 * names nothing, because the copy lands a segment deeper.
				 */
				$plain = str_replace( array( '{{namespace}}', 'Acme\\Plugin' ), 'Zestry\\WPToolkit', $reference );
				if ( str_starts_with( $plain, ZESTRY_ROOT_NAMESPACE . '\\' ) && is_file( $root . '/src/' . str_replace( '\\', '/', zestry_without_root_namespace( $plain ) ) . '.php' ) ) {
					$problems[] = sprintf(
						'%s:%d — names %s, but that is toolkit source: it is copied under the `%s` segment, so this needs %s',
						$relative,
						$number + 1,
						$reference,
						'Core',
						preg_replace( '/^(\{\{namespace\}\}|Acme\\\\Plugin)/', '$1\\\\Core', $reference )
					);
				}
			}
		}
	}

	return $problems;
}

/**
 * The lines a reader could copy a class name out of, keyed by relative path.
 *
 * Docblock lines only for PHP source -- real code is the compiler's problem, and
 * checking it here would report every `use` in the package. Stubs and the two
 * hand-written pages are read whole, since every line of both is sample code.
 *
 * @param string $root Absolute path to the repository root.
 * @return array<string, string[]>
 */
function zestry_import_sources( string $root ): array {
	$sources = array();

	foreach ( array( 'src', 'resources' ) as $directory ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory ) );

		foreach ( $files as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
			$contents = (string) file_get_contents( $file->getPathname() );

			if ( 'stub' === $file->getExtension() ) {
				$sources[ $relative ] = explode( "\n", $contents );
				continue;
			}

			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$sources[ $relative ] = array_map(
				static function ( string $line ): string {
					return preg_match( '/^\s*\*/', $line ) ? $line : '';
				},
				explode( "\n", $contents )
			);
		}
	}

	foreach ( array( 'docs/README.md', 'docs/getting-started.md' ) as $page ) {
		if ( is_file( $root . '/' . $page ) ) {
			$sources[ $page ] = explode( "\n", (string) file_get_contents( $root . '/' . $page ) );
		}
	}

	return $sources;
}

/**
 * Every method a class declares plus everything it inherits.
 *
 * @param array<string, array{methods: string[], parents: string[]}> $classes Indexed classes.
 * @param string                                                    $class   Short class name to resolve.
 * @param string[]                                                  $seen    Names already walked, guarding a cycle.
 * @return string[]
 */
function zestry_inherited_methods( array $classes, string $class, array $seen = array() ): array {
	if ( ! isset( $classes[ $class ] ) || in_array( $class, $seen, true ) ) {
		return array();
	}

	$seen[]  = $class;
	$methods = $classes[ $class ]['methods'];

	foreach ( $classes[ $class ]['parents'] as $parent ) {
		$methods = array_merge( $methods, zestry_inherited_methods( $classes, $parent, $seen ) );
	}

	return array_unique( $methods );
}

/**
 * Report an `{@see method()}` naming a method its own file does not declare.
 *
 * The prose gate above has to exclude sentences, because a sentence may name a
 * method on a class this cannot resolve. `{@see}` carries no such ambiguity:
 * written bare, it means "on this class", so the file that contains it is the
 * file that has to declare it. That is narrow enough to check, and it is a
 * reference readers follow -- the extractor renders it as the method's name, so
 * a published page recommended `enqueue_script()` for a year after the method
 * was deleted.
 *
 * A qualified `{@see Foo::bar()}` is left alone: the class it names may live
 * anywhere, which is the ambiguity this deliberately does not take on.
 *
 * @param string $root Absolute path to the repository root.
 * @return string[] Problems found.
 */
function zestry_verify_see_tags( string $root ): array {
	$problems = array();

	foreach ( array( '/src', '/resources' ) as $directory ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $directory ) );

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );

			foreach ( explode( "\n", $source ) as $number => $line ) {
				if ( ! preg_match_all( '/\{@see\s+([a-z_][a-z0-9_]*)\(\)\}/i', $line, $seen ) ) {
					continue;
				}

				foreach ( $seen[1] as $method ) {
					if ( preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\(/', $source ) ) {
						continue;
					}

					$problems[] = sprintf(
						'%s:%d: {@see %s()} names a method this file does not declare -- the page renders it as a recommendation a reader cannot follow.',
						substr( $file->getPathname(), strlen( $root ) + 1 ),
						$number + 1,
						$method
					);
				}
			}
		}
	}

	return $problems;
}
