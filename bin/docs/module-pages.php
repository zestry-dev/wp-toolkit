<?php

/**
 * DevTools: module guide page generation
 */

declare( strict_types=1 );

/**
 * Record, or read back, base classes whose page could not be written.
 *
 * A generation run that drops a page has to exit non-zero, but not before
 * finishing -- reporting one missing class and stopping would hide the rest,
 * and the tree is already cleared either way. Called with a name to record one,
 * with none to collect them.
 *
 * @param string|null $base The base class's short name, or null to read the list.
 * @return string[] Every base class recorded so far.
 */
function zestry_record_missing_base( ?string $base = null ): array {
	static $missing = array();

	if ( null !== $base ) {
		$missing[] = $base;
	}

	return $missing;
}

/**
 * Locate the file declaring a class, by short name.
 *
 * Searches `src/Modules/` first, then `src/Kernel/`, so a kernel base class such
 * as `Module` resolves the same way a module's own base class does.
 *
 * @param string $root  Absolute path to the repository root.
 * @param string $class The class's short name, e.g. `AjaxAction` or `Module`.
 * @return string|null Absolute path, or null when nothing declares it.
 */
function zestry_find_class_file( string $root, string $class ): ?string {
	/*
	 * Two levels under the tree, not one: `RequestArgument` lives at
	 * `src/Modules/Request/Attributes/`, and a single-level glob could not see
	 * it -- so the attribute appeared in several code samples and had no page.
	 */
	$candidates = array(
		$root . '/src/Modules/*/' . $class . '.php',
		$root . '/src/Modules/*/*/' . $class . '.php',
		$root . '/src/Modules/' . $class . '.php',
		$root . '/src/Kernel/*/' . $class . '.php',
		$root . '/src/Kernel/' . $class . '.php',
	);

	foreach ( $candidates as $pattern ) {
		$matches = glob( $pattern ) ?: array();

		if ( array() !== $matches ) {
			return $matches[0];
		}
	}

	return null;
}

/**
 * Base classes a consumer may extend instead of the one discovery names.
 *
 * A module's `instanceof` guard names exactly one base per discovered file --
 * `AdminPages` tests for `AdminPage` -- but a subclass of that base shipped in
 * the same directory is just as much something a consumer writes against, and
 * satisfies the same guard. `ModernAdminPage` is one, and until this it
 * appeared in no generated page at all: it ships to every plugin that runs
 * `wp zt add admin-pages`, yet the only way to learn it existed was to list
 * the copied directory.
 *
 * Detected from the source rather than declared in `registry.php`, so a base
 * added later is documented by existing rather than by being remembered.
 *
 * @param string   $module_dir Absolute path to the module's own source directory.
 * @param string[] $returns    Base classes the module's discovery guard names.
 * @return array<string, string> Subclass short name => the base it extends.
 */
function zestry_find_alternate_bases( string $module_dir, array $returns ): array {
	$alternates = array();

	foreach ( glob( $module_dir . '/*.php' ) ?: array() as $file ) {
		$source = (string) file_get_contents( $file );

		if ( ! preg_match( '/abstract class (\w+) extends (\w+)/', $source, $match ) ) {
			continue;
		}

		if ( in_array( $match[2], $returns, true ) ) {
			$alternates[ $match[1] ] = $match[2];
		}
	}

	ksort( $alternates );

	return $alternates;
}

/**
 * A class's one-line docblock summary.
 *
 * @param string $root  Absolute path to the repository root.
 * @param string $class The class's short name.
 * @return string The summary, or an empty string when there is none.
 */
function zestry_class_summary( string $root, string $class ): string {
	$path = zestry_find_class_file( $root, $class );

	if ( null === $path ) {
		return '';
	}

	$source   = (string) file_get_contents( $path );
	$docblock = zestry_docblock_before( $source, zestry_type_declaration( $class ) );

	return null === $docblock ? '' : zestry_summary( $docblock );
}

/**
 * Read everything the docs need about one module, from its source alone.
 *
 * @param string $path Absolute path to a module class file.
 * @return array{class: string, summary: string, prose: string, example: string|null, roots: string[], returns: string[]}|null
 */
function zestry_read_module( string $path ): ?array {
	$source = (string) file_get_contents( $path );

	// Either base: a service and a module are documented the same way, and which
	// one it is comes from the registry section rather than from this pattern.
	if ( ! preg_match( '/(?:final )?class (\w+) extends (?:Module|Service)/', $source, $class_match ) ) {
		return null;
	}

	$class    = $class_match[1];
	$docblock = zestry_docblock_before( $source, '(?:final )?class ' . $class . '\b' );

	if ( null === $docblock ) {
		return null;
	}

	$tags  = zestry_extract_custom_tags( $docblock );
	$split = zestry_split_example( $tags['body'] );

	// The constant's name is kept beside its value: it is what pairs a root with
	// the base class discovered from it, for the one module that has two of each.
	preg_match_all( "/const (\w+)_ROOT\s*=\s*'([^']+)'/", $source, $roots );

	// A module acts on its own and so must be listed in bootstrap.php to be
	// built; a service is built on demand and needs no entry. `Views` resolves a
	// file you name under a directory, but only when called, which is what keeps
	// it a service.
	$is_module = (bool) preg_match( '/class \w+ extends Module\b/', $source );

	/*
	 * Only the discovery guard names a base class a *file* must return. Matching
	 * any `instanceof` would also pick up unrelated type checks -- AdminPages
	 * tests `$placement instanceof ParentMenu` when deciding menu placement,
	 * which has nothing to do with what a page file returns.
	 */
	preg_match_all(
		'/!\s*\$\w+ instanceof (\w+)\s*\)\s*\{(?:\s*\/\/[^\n]*\n)*\s*throw new DiscoveryException/',
		$source,
		$returns
	);

	$found = $returns[1];

	/*
	 * A module walking two roots with one method holds the expected class in a
	 * variable -- `! $instance instanceof $expected` -- which the pattern above
	 * cannot name, so SiteHealth came back with no base classes at all and the
	 * modules index told a reader its files return nothing. The `@discovers` tag
	 * is how such a module says so itself.
	 */
	if ( preg_match_all( '/^\s*\*?\s*@discovers\s+(\w+)/m', $docblock, $declared ) ) {
		$found = array_merge( $found, $declared[1] );
	}

	return array(
		'class'     => $class,
		'summary'   => zestry_summary( $docblock ),
		'prose'     => $split['prose'],
		'example'   => $split['example'],
		'blocks'    => $split['blocks'],
		'setup'     => $tags['setup'],
		'examples'  => $tags['examples'],
		'is_module' => $is_module,
		// Walking a directory and resolving a named file under one are different
		// promises, and the facts line said "Discovers" for both. `Views` takes
		// the template name you give it; it never enumerates `views/`.
		'walks'     => str_contains( $source, 'use WithFolderWalker;' ),
		'roots'     => $roots[2],
		'root_of'   => zestry_pair_roots_to_bases( $roots[1], $roots[2], array_values( array_unique( $found ) ) ),
		'returns'   => array_values( array_unique( $found ) ),
	);
}

/**
 * Which directory each discovered base class is found in.
 *
 * Only `PostTypes` has two of each, and pairing them by position quietly told
 * every reader that a `Taxonomy` goes in `post-types/` -- where the module
 * rejects it with a `DiscoveryException`. Matched by name instead: the constant
 * `TAXONOMIES_ROOT` belongs to `Taxonomy`, whatever order either was
 * declared in.
 *
 * @param string[] $names  The `<NAME>_ROOT` constant names.
 * @param string[] $roots  Their values, in the same order.
 * @param string[] $bases  The base classes a discovered file may return.
 * @return array<string, string> Base class name to the directory it is found in.
 */
function zestry_pair_roots_to_bases( array $names, array $roots, array $bases ): array {
	if ( array() === $roots || array() === $bases ) {
		return array();
	}

	$paired = array();

	foreach ( $bases as $index => $base ) {
		$paired[ $base ] = $roots[0];

		foreach ( $names as $position => $name ) {
			// `POST_TYPES` and `PostType`, `TAXONOMIES` and `Taxonomy`: both
			// sides reduced to one lowercase singular word, then matched on the
			// end, since a base often carries a prefix its root does not
			// (`ACTIONS` names the root an `AjaxAction` is found in).
			if ( str_ends_with( strtolower( $base ), zestry_singular( $name ) ) ) {
				$paired[ $base ] = $roots[ $position ];
				continue 2;
			}
		}

		// No name matched: fall back to position, which is right whenever the
		// two lists were declared in the same order.
		if ( isset( $roots[ $index ] ) ) {
			$paired[ $base ] = $roots[ $index ];
		}
	}

	return $paired;
}

/**
 * The directory `wp zt make <type>` writes into, read from the command itself.
 *
 * Only interesting when it differs from where the module then discovers the
 * file, which is true of exactly one type -- but reading it rather than listing
 * the exception means a second one cannot appear undocumented.
 *
 * @param string $root Absolute path to the repository root.
 * @param string $type The `wp zt make` subcommand, e.g. `block`.
 * @return string|null The plugin-relative directory, or null when it is computed.
 */
function zestry_make_destination( string $root, string $type ): ?string {
	$file = $root . '/resources/commands/make/' . $type . '.php';

	if ( ! is_file( $file ) ) {
		return null;
	}

	// A literal return only. `migration` computes its path from a timestamp,
	// and a guess there would be worse than saying nothing.
	if ( ! preg_match(
		"/function get_default_dir\([^)]*\)[^{]*\{[^}]*?return '([^']+)';/s",
		(string) file_get_contents( $file ),
		$match
	) ) {
		return null;
	}

	return $match[1];
}

/**
 * One lowercase singular word from a `<NAME>_ROOT` constant's name.
 *
 * @param string $name The constant's middle, e.g. `POST_TYPES`.
 * @return string e.g. `posttype`.
 */
function zestry_singular( string $name ): string {
	$word = strtolower( str_replace( '_', '', $name ) );

	if ( str_ends_with( $word, 'ies' ) ) {
		return substr( $word, 0, -3 ) . 'y';
	}

	return rtrim( $word, 's' );
}

/**
 * The parameter names of a declaration, without their types or defaults.
 *
 * A heading carries the short form -- `walk_folder( $root_dir, $extensions,
 * $depth )` -- while the code block below it carries the full types. The two
 * lines then say different things, rather than one restating the other, and
 * the heading stays short enough to make a usable anchor. This is how
 * pkg.go.dev presents a method.
 *
 * Names are extracted rather than split on commas, since a default value can
 * contain one of its own: `array $extensions = array( 'php' )`.
 *
 * @param string $parameters A declaration's parameter list, parentheses included.
 * @return string The names in `( $a, $b )` form, or `()` when there are none.
 */
function zestry_parameter_list( string $parameters ): string {
	preg_match_all( '/\$(\w+)/', $parameters, $names );

	if ( array() === $names[1] ) {
		return '()';
	}

	return '( $' . implode( ', $', $names[1] ) . ' )';
}

/**
 * List a class's callable surface, with each method's one-line summary.
 *
 * `public` and `protected` are both included: a protected method on a base
 * class is the DSL a subclass overrides -- `Command::read_line()` exists to be
 * called from a subclass's own handle() -- which is exactly what a reader of
 * that page needs. A protected method that is *not* an extension point marks
 * itself `@internal`.
 *
 * Constructors and `private` members are never listed, nor is anything marked
 * `@internal`.
 *
 * Each entry records the class it was **declared** on, so a page can print an
 * inherited member short and link to the page that owns it rather than
 * reprinting its body. Sources are read in most-derived-first order and the
 * first declaration of a name wins, which is also what makes an override
 * replace what it overrides instead of appearing beside it.
 *
 * @param string $root  Absolute path to the repository root.
 * @param string $class The class name, e.g. `Ajax` or `AjaxAction`.
 * @return array<int, array{name: string, label: string, signature: string, summary: string, description: string, params: array, return: string, throws: array, origin: string}>
 */
function zestry_public_api( string $root, string $class ): array {
	$path = zestry_find_class_file( $root, $class );

	if ( null === $path ) {
		return array();
	}

	$api = array();

	// class name => source, in the order a reader would meet them: the class
	// itself, then its own traits, then its parent and the traits that parent
	// pulls in. `Module` declares almost nothing -- get_plugin() comes from
	// WithPlugin and Service -- so without the last two its page lists nothing.
	$sources = array( $class => (string) file_get_contents( $path ) );

	$collect_traits = static function ( string $from ) use ( $root, $path, &$sources ): void {
		if ( ! preg_match_all( '/^\s*use\s+(\w+);/m', $from, $traits ) ) {
			return;
		}

		foreach ( $traits[1] as $trait ) {
			$trait_path = zestry_find_class_file( $root, $trait );

			if ( null !== $trait_path && $trait_path !== $path && ! isset( $sources[ $trait ] ) ) {
				$sources[ $trait ] = (string) file_get_contents( $trait_path );
			}
		}
	};

	$collect_traits( $sources[ $class ] );

	if ( preg_match( '/^(?:abstract |final )*class \w+ extends (\w+)/m', $sources[ $class ], $parent ) ) {
		$parent_path = zestry_find_class_file( $root, $parent[1] );

		if ( null !== $parent_path && $parent_path !== $path ) {
			$sources[ $parent[1] ] = (string) file_get_contents( $parent_path );
			$collect_traits( $sources[ $parent[1] ] );
		}
	}

	$methods = array();

	foreach ( $sources as $origin => $source ) {
		preg_match_all(
			'#/\*\*((?:(?!\*/).)*?)\*/\s*((?:final |abstract |static )*(?:public|protected)(?: static)? function (\w+)\s*(\((?:[^()]|(?4))*\))(?:\s*:\s*[^\s{;]+)?)#s',
			$source,
			$found,
			PREG_SET_ORDER
		);

		foreach ( $found as $match ) {
			$methods[] = array_merge( $match, array( 'origin' => (string) $origin ) );
		}
	}

	$declared = array();

	foreach ( $methods as $method ) {
		[ , $docblock, $signature, $name ] = $method;

		// An override replaces what it overrides. Without this a page listed
		// both -- ModernAdminPage::enqueue_assets() appeared twice, once with
		// its own body and once with AdminPage's.
		if ( isset( $declared[ $name ] ) ) {
			continue;
		}

		$declared[ $name ] = true;

		/*
		 * A constructor is listed only when it takes something. Service's is
		 * `final` and takes nothing, so this skips it on every service, module
		 * and base class -- while `Plugin::__construct( $entry, $slug )` is
		 * documented, which it was not: the slug it defaults from the entry
		 * file's directory name namespaces every hook, option, handle and
		 * command the modules go on to register, and no page said so.
		 */
		if ( '__construct' === $name && '()' === zestry_parameter_list( $method[4] ) ) {
			continue;
		}

		/*
		 * An abstract method is kept, and marked. It used to be skipped on the
		 * reasoning that a stub already shows it filled in -- but that left the
		 * fifteen methods a consumer is *required* to write documented nowhere,
		 * with their parameters, return types and contracts visible only in the
		 * source. It is the one part of a base class that is not optional.
		 */
		$abstract = str_contains( $signature, 'abstract ' );

		/*
		 * `@internal` marks a method that is public only because something else
		 * has to reach it -- a WordPress hook callback, most often. It is not
		 * part of the surface a consumer calls, so listing it invites calls that
		 * were never intended.
		 */
		if ( preg_match( '/^\s*\*\s*@internal\b/m', $docblock ) ) {
			continue;
		}

		$body = zestry_strip_docblock( $docblock );
		$tags = zestry_parse_tags( $body );

		// A method may carry `@example` blocks of its own, the same way a class
		// does. They were extracted and then dropped -- Command::get_assoc_args()
		// has a nine-line worked sample that reached no page.
		$custom = zestry_extract_custom_tags( $docblock );

		$api[] = array(
			'name'        => $name,
			'label'       => $name . zestry_parameter_list( $method[4] ),
			// Collapse a wrapped declaration onto one line.
			'signature'   => trim( (string) preg_replace( '/\s+/', ' ', $signature ) ),
			'summary'     => zestry_summary( $body ),
			'description' => zestry_description( $body ),
			'params'      => $tags['params'],
			'return'      => $tags['return'],
			'throws'      => $tags['throws'],
			'abstract'    => $abstract,
			'examples'    => $custom['examples'],
			'origin'      => $method['origin'],
			'inherited'   => $method['origin'] !== $class,
		);
	}

	return $api;
}

/**
 * The page that documents a class in full, as a docs-relative path.
 *
 * Only the classes something else inherits from are here: those are the ones a
 * page can hand off to instead of reprinting. Anything absent is treated as
 * having no page of its own, and is printed in full wherever it appears.
 *
 * @return array<string, string> Class name => path under `docs/`.
 */
function zestry_base_class_pages(): array {
	return array(
		'Module'            => 'modules/module.md',
		'ActivationHandler' => 'modules/activation-handler.md',
		'WithPlugin'        => 'kernel/with-plugin.md',
		'WithEnablement'    => 'kernel/with-enablement.md',
		'WithFolderWalker'  => 'kernel/with-folder-walker.md',
	);
}

/**
 * A written page's path relative to `docs/`.
 *
 * @param string $file Absolute path to the page being written.
 * @return string The path under `docs/`, or an empty string when it is not one.
 */
function zestry_docs_relative( string $file ): string {
	$at = strrpos( $file, '/docs/' );

	return false === $at ? '' : substr( $file, $at + strlen( '/docs/' ) );
}

/**
 * A link from one page under `docs/` to another, by their docs-relative paths.
 *
 * @param string $from The linking page, e.g. `modules/cron/README.md`.
 * @param string $to   The linked page, e.g. `modules/module.md`.
 * @return string The relative href.
 */
function zestry_relative_page( string $from, string $to ): string {
	$keep = static function ( string $part ): bool {
		return '' !== $part && '.' !== $part;
	};

	$from_parts = array_values( array_filter( explode( '/', dirname( $from ) ), $keep ) );
	$to_parts   = array_values( array_filter( explode( '/', $to ), $keep ) );

	while ( array() !== $from_parts && count( $to_parts ) > 1 && $from_parts[0] === $to_parts[0] ) {
		array_shift( $from_parts );
		array_shift( $to_parts );
	}

	return str_repeat( '../', count( $from_parts ) ) . implode( '/', $to_parts );
}

/**
 * Drop leading and trailing blank lines from a block.
 *
 * Keeps the spacing between entries decided by the caller rather than by
 * whichever optional part a method's body happened to end on.
 *
 * @param string[] $lines The block to trim.
 * @return string[] The same lines without blank edges.
 */
function zestry_trim_blank_edges( array $lines ): array {
	while ( array() !== $lines && '' === trim( (string) reset( $lines ) ) ) {
		array_shift( $lines );
	}

	while ( array() !== $lines && '' === trim( (string) end( $lines ) ) ) {
		array_pop( $lines );
	}

	return $lines;
}

/**
 * Render a method's parameters, return and throws as one table.
 *
 * Three fixed rows, so every method reads the same way and a reader looking
 * for what a call throws finds the row in the same place whether or not it
 * throws anything. An em dash fills a row with nothing to say, which answers
 * the question rather than leaving it open by omitting the row.
 *
 * @param array<string, mixed> $method The method's extracted data.
 * @return string[] Markdown lines, or none when there is nothing to show.
 */
function zestry_render_signature_table( array $method ): array {
	// A raw pipe would end the cell early.
	$escape = static function ( string $text ): string {
		return str_replace( '|', '\|', trim( $text ) );
	};

	$parameters = array();

	foreach ( $method['params'] as $param ) {
		$parameters[] = '`$' . $param['name'] . '` — ' . $escape( rtrim( $param['description'], '.' ) );
	}

	$throws = array();

	foreach ( $method['throws'] as $throw ) {
		$throws[] = '`' . $throw['type'] . '` — ' . $escape( rtrim( $throw['description'], '.' ) );
	}

	$return = '' === $method['return'] ? '' : $escape( ucfirst( rtrim( $method['return'], '.' ) ) );

	/*
	 * An `@return` tag carrying a type but no description used to leave this
	 * empty, and an empty return with no params and no throws dropped the whole
	 * table -- so `get_charset_collate(): string` published a signature saying
	 * it returns a string above no Details table at all, while its neighbours
	 * had one. The declared type is the answer in that case, and it is already
	 * in the signature this is rendered beside.
	 */
	if ( '' === $return && preg_match( '/\)\s*:\s*([^\s{;]+)$/', $method['signature'], $declared ) ) {
		$return = 'void' === $declared[1] ? '' : '`' . $escape( $declared[1] ) . '`';
	}

	if ( array() === $parameters && array() === $throws && '' === $return ) {
		return array();
	}

	/*
	 * Three fixed rows, labelled down the left. A markdown table has no footer
	 * to put the return and throws in, so the label column separates them
	 * instead -- and every method reads the same way, with an em dash answering
	 * a row that has nothing to say rather than the row being dropped.
	 *
	 * `<br>` separates entries in a cell, since a markdown list cannot open
	 * inside one.
	 */
	/*
	 * "Details" rather than "Description": the column holds three different
	 * shapes -- a list of named parameters, a single return value, a list of
	 * exception types -- and only the first is a description of anything.
	 */
	return array(
		'|  | Details |',
		'|---|---|',
		'| **Parameters** | ' . ( array() === $parameters ? '—' : implode( '<br>', $parameters ) ) . ' |',
		'| **Return** | ' . ( '' === $return ? '—' : $return ) . ' |',
		'| **Throws** | ' . ( array() === $throws ? '—' : implode( '<br>', $throws ) ) . ' |',
		'',
	);
}

/**
 * Render one method as a markdown block.
 *
 * An inherited member is printed in full, like any other: a reader looking up
 * what a `Cron` can do should find `on_wp_init()` under `Cron`, not a
 * redirection to read it somewhere else. It carries a line naming where it came
 * from, so the page it is declared on is one click away.
 *
 * @param array{name: string, label: string, signature: string, summary: string, description: string, params: array, return: string, throws: array, origin?: string} $method The method to render.
 * @param string                                                                                                $level  The heading level, e.g. `###`.
 * @param string                                                                                                $page   The page being written, relative to `docs/`.
 * @return string[] Markdown lines.
 */
function zestry_render_method( array $method, string $level, string $page = '' ): array {
	$body  = array();
	$owner = zestry_base_class_pages()[ $method['origin'] ?? '' ] ?? null;

	if ( ( $method['inherited'] ?? false ) && null !== $owner && '' !== $page ) {
		$body[] = sprintf(
			'*Inherited from [`%s`](%s).*',
			$method['origin'],
			zestry_relative_page( $page, $owner )
		);
		$body[] = '';
	}

	// The summary reads straight off the heading, before the declaration it
	// describes: it says what the method is for, in the words the name abbreviates.
	if ( '' !== $method['summary'] ) {
		$body[] = $method['summary'];
		$body[] = '';
	}

	$body[] = '```php';
	$body[] = $method['signature'];
	$body[] = '```';
	$body[] = '';

	/*
	 * The table sits above the description: what a method takes and returns is
	 * what most readers came for, and the description is the paragraph they read
	 * only once the signature has not answered the question.
	 */
	$body = array_merge( $body, zestry_render_signature_table( $method ) );

	if ( '' !== $method['description'] ) {
		$body[] = $method['description'];
		$body[] = '';
	}

	// A method's own worked examples, after the prose that introduces them.
	foreach ( $method['examples'] ?? array() as $example ) {
		if ( '' !== $example['caption'] ) {
			$body[] = '**' . $example['caption'] . '**';
			$body[] = '';
		}

		if ( '' !== $example['description'] ) {
			$body[] = $example['description'];
			$body[] = '';
		}

		if ( '' !== $example['code'] ) {
			$body[] = '```' . $example['language'];
			$body[] = $example['code'];
			$body[] = '```';
			$body[] = '';
		}

		if ( '' !== $example['after'] ) {
			$body[] = $example['after'];
			$body[] = '';
		}
	}

	$lines = array(
		sprintf( '%s `%s`', $level, $method['label'] ),
		'',
	);

	foreach ( zestry_trim_blank_edges( $body ) as $line ) {
		$lines[] = $line;
	}

	$lines[] = '';

	return $lines;
}

/**
 * The sample values one `wp zt make` type's stubs render with.
 *
 * The values every type shares live here. Anything only one type asks for
 * lives under `bin/docs/stub-values/`, which returns its own
 * `placeholder => value` array and overrides these -- so `taxonomy` can say
 * `Genre` where the shared default is `Book`, without every other page
 * inheriting it.
 *
 * One file per type, named for the `make` word: `block.php` covers every stub
 * under `stubs/block/`, since the answers that pick a variant are the type's,
 * not each file's. A type with nothing of its own needs no file.
 *
 * @param string $root Absolute path to the repository root.
 * @param string $type The `wp zt make <type>` word, e.g. `block` or `route`.
 * @return array<string, string> Replacement values, keyed by `{{placeholder}}`.
 */
function zestry_stub_values( string $root, string $type ): array {
	/*
	 * `copied_namespace` mirrors what MakeCommand derives through
	 * Copier::get_target_namespace(): the plugin's own namespace plus the
	 * `Core` segment copied source lands under. Spelled out rather than
	 * required from Copier, since this file is read before any autoloader.
	 */
	$shared = array(
		'{{namespace}}'        => 'Acme\\Plugin',
		'{{copied_namespace}}' => 'Acme\\Plugin\\Core',
		'{{name}}'             => 'example',
		'{{title}}'            => 'Example',
		'{{slug}}'             => 'acme-plugin',
		'{{slug_camel}}'       => 'acmePlugin',
		'{{text_domain}}'      => 'acme-plugin',
		// The class-file types -- `module`, `service`, `activation` -- and where
		// each lands. Two of the three write into `Modules`, so `service`
		// overrides this and the others need no file of their own.
		'{{class_name}}'       => 'Example',
		'{{class_namespace}}'  => 'Acme\\Plugin\\Modules',
	);

	$file = $root . '/bin/docs/stub-values/' . $type . '.php';

	if ( ! is_file( $file ) ) {
		return $shared;
	}

	$own = require $file;

	if ( ! is_array( $own ) ) {
		fwrite( STDERR, sprintf( "%s must return an array.\n", substr( $file, strlen( $root ) + 1 ) ) );
		exit( 1 );
	}

	return array_merge( $shared, $own );
}

/**
 * Read the example of a discovered file, from the stub that generates it.
 *
 * The stub is the better source than a base-class docblock: it is the file a
 * reader actually receives from `wp zt make`, it is already exercised by the
 * DevTools tests, and keeping the example only here means there is one copy
 * rather than a docblock and a stub that can disagree.
 *
 * The base class names its own stub with `@stub`, relative to
 * `src/DevTools/stubs/`, optionally followed by a caption and, on the lines
 * beneath it, as much explanation as the file warrants:
 *
 *     @stub route.php.stub
 *
 *     @stub block/block.json.stub  The metadata
 *     Every other file is wired to this one: the editor script, the styles and
 *     the render all reach it through a `file:` path relative to this file.
 *
 * The tag repeats, because a type can generate more than one file and a reader
 * of `block` wants the metadata and the PHP side by side. The caption titles
 * each one; without it the filename does.
 *
 * This used to be inferred instead -- scan every `resources/commands/make/*.php` for a
 * quoted `*.php.stub`, then match the stub's `extends` against the class name.
 * Both halves guessed wrong. `make block` names a stub *directory*, so no
 * quoted filename matched and Block got no example at all; and `route.php.stub`
 * declares `extends RestRoute` while the page is Route's, so that one failed
 * the second test. A tag on the class states the link rather than deducing it.
 *
 * `{{placeholder}}` tokens are substituted with what `make` would produce, so
 * the page shows real code rather than a template.
 *
 * @param string $root      Absolute path to the repository root.
 * @param string $base      The base class name, e.g. `AjaxAction`.
 * @param string $base_file Absolute path to the base class's own file.
 * @return array{type: string, files: array<int, array{caption: string, description: string, example: string, source: string, language: string}>}|null
 */
function zestry_read_stub_example( string $root, string $base, string $base_file ): ?array {
	$source   = (string) file_get_contents( $base_file );
	$docblock = zestry_docblock_before( $source, '(?:final )?abstract class ' . $base . '\b' )
		?? zestry_docblock_before( $source, '(?:final )?class ' . $base . '\b' );

	/*
	 * A tag's body runs to the next tag or the end: the first line is the path
	 * and its caption, anything after is the description.
	 */
	if ( null === $docblock || ! preg_match_all( '/^@stub\s+(\S+)[ \t]*([^\n]*)\n?((?:(?!@\w)[^\n]*\n?)*)/m', $docblock, $tags, PREG_SET_ORDER ) ) {
		return null;
	}

	// The `wp zt make <type>` word: a multi-file type's stubs sit in a
	// directory named after it, a single-file type's stub is named for it.
	$type = str_contains( $tags[0][1], '/' )
		? dirname( $tags[0][1] )
		: basename( $tags[0][1], '.php.stub' );

	$values = zestry_stub_values( $root, $type );
	$files  = array();

	foreach ( $tags as $tag ) {
		$relative = $tag[1];
		$stub     = $root . '/src/DevTools/stubs/' . $relative;

		if ( ! is_file( $stub ) ) {
			fwrite( STDERR, sprintf( "%s declares @stub %s, which does not exist.\n", $base, $relative ) );
			exit( 1 );
		}

		$name     = basename( $relative, '.stub' );
		$rendered = rtrim( strtr( (string) file_get_contents( $stub ), $values ) );

		/*
		 * strtr() leaves a placeholder it has no value for exactly as it found
		 * it, so a stub gaining one nothing supplies would otherwise publish a
		 * raw `{{token}}` into the docs. Fail the build instead: the fix is to
		 * add it to bin/docs/stub-values/{type}.php, or to zestry_stub_values()
		 * once a second type wants it too.
		 */
		if ( preg_match_all( '/\{\{(\w+)\}\}/', $rendered, $unresolved ) ) {
			fwrite(
				STDERR,
				sprintf(
					"%s renders %s with no value for: %s\n",
					$base,
					$relative,
					implode( ', ', array_unique( $unresolved[0] ) )
				)
			);
			exit( 1 );
		}

		$files[] = array(
			'caption'     => '' !== trim( $tag[2] ) ? trim( $tag[2] ) : $name,
			'description' => zestry_render_inline_tags( zestry_unwrap_prose( trim( $tag[3] ?? '' ) ) ),
			'example'     => $rendered,
			'source'      => 'src/DevTools/stubs/' . $relative,
			'language'    => zestry_stub_language( $name ),
		);
	}

	return array(
		'type'  => $type,
		'files' => $files,
	);
}

/**
 * The fenced-code language for a generated filename.
 *
 * @param string $name The generated file's name, e.g. `block.json` or `edit.tsx`.
 * @return string A markdown info string, or an empty string when none fits.
 */
function zestry_stub_language( string $name ): string {
	$languages = array(
		'php'  => 'php',
		'json' => 'json',
		'ts'   => 'ts',
		'tsx'  => 'tsx',
		'js'   => 'js',
		'jsx'  => 'jsx',
		'css'  => 'css',
	);

	return $languages[ pathinfo( $name, PATHINFO_EXTENSION ) ] ?? '';
}

/**
 * Write one guide page per module, plus the index table.
 *
 * Every fact on these pages comes from the module's own source or from
 * `registry.php`; nothing here is hand-maintained.
 *
 * @param string $root Absolute path to the repository root.
 * @return int The number of module pages written.
 */
function zestry_generate_module_pages( string $root ): int {
	// One tree, mirroring src/ and bootstrap.php: there is one kind of thing, so
	// there is one place to document it.
	$output_dir = $root . '/docs/modules';
	$registry   = require $root . '/src/DevTools/registry.php';

	if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0755, true ) && ! is_dir( $output_dir ) ) {
		fwrite( STDERR, "Could not create {$output_dir}\n" );
		exit( 1 );
	}

	// Clear the whole tree: pages moved into per-entry folders, so a stale
	// file from an earlier layout would otherwise survive forever.
	foreach ( glob( $output_dir . '/*.md' ) ?: array() as $stale ) {
		unlink( $stale );
	}

	foreach ( glob( $output_dir . '/*', GLOB_ONLYDIR ) ?: array() as $stale_dir ) {
		foreach ( glob( $stale_dir . '/*.md' ) ?: array() as $stale ) {
			unlink( $stale );
		}

		rmdir( $stale_dir );
	}

	$modules = array();

	foreach ( $registry as $name => $entry ) {
		// PSR-4: `Zestry\WPToolkit\Modules\Path` is src/Modules/Path.php. Everything below
		// is derived from that one path, so nothing restates the layout.
		$source     = $entry['source'];
		$class_path = str_replace( '\\', '/', zestry_without_root_namespace( $source ) );
		$candidate  = $root . '/src/' . $class_path . '.php';

		// A module with a directory of its own puts the class inside it under
		// the same name, and that directory is where a second base class would
		// live. Anything else is a lone file with no directory to scan.
		$segments   = explode( '/', $class_path );
		$short_name = (string) array_pop( $segments );
		$module_dir = array() !== $segments && end( $segments ) === $short_name
			? $root . '/src/' . implode( '/', $segments )
			: null;

		if ( ! is_file( $candidate ) ) {
			fwrite( STDERR, "No class file for registry entry '{$name}' at {$candidate}\n" );
			continue;
		}

		$module = zestry_read_module( $candidate );

		if ( null === $module ) {
			fwrite( STDERR, "Could not read module '{$name}' from {$candidate}\n" );
			continue;
		}

		$module['name']    = $name;
		$module['depends'] = $entry['depends'] ?? array();
		// A single-file module has no directory of its own to hold a second
		// base class, and scanning src/Core/Modules/ itself would sweep up every
		// other module's.
		$module['alternates'] = null === $module_dir
			? array()
			: zestry_find_alternate_bases( $module_dir, $module['returns'] );
		$module['relative']   = 'src/' . $class_path . '.php';
		$modules[ $name ]     = $module;
	}

	// Which section a name is filed under, so a dependency can be linked across
	// to the other tree rather than assumed to be a sibling.
	foreach ( $modules as $name => $module ) {
		$module_dir = $output_dir . '/' . $name;

		if ( ! is_dir( $module_dir ) && ! mkdir( $module_dir, 0755, true ) && ! is_dir( $module_dir ) ) {
			fwrite( STDERR, "Could not create {$module_dir}\n" );
			exit( 1 );
		}

		$page = zestry_generated_banner( $module['relative'] );

		$page[] = '# ' . $module['class'];
		$page[] = '';

		// Directly under the title, so the facts read before the contents list.
		$page = array_merge( $page, zestry_render_facts_table( $module ) );

		/*
		 * What it is, before what to type. The install snippet and the
		 * bootstrap.php callout used to sit here, which put two commands and a
		 * five-line warning between the title and the module's own first
		 * sentence -- so a page opened by answering a question the reader could
		 * not have had yet.
		 */
		$page = array_merge( $page, zestry_render_blocks( $module['blocks'] ) );

		// The contents list follows the opening, not the title.
		$page[] = ZESTRY_TOC_MARKER;

		$page[] = '## Adding it';
		$page[] = '';
		$page[] = '```bash';
		// The subcommand names the kind, so the snippet has to as well.
		$page[] = 'wp zt add ' . $name;
		$page[] = '```';
		$page[] = '';

		/*
		 * Copying a module is half of installing it: it acts on its own, so it
		 * has to be built for any of that to happen, and being listed in
		 * `bootstrap.php` is what builds it. `wp zt add` writes that entry, so
		 * this is reassurance for a reader wondering what else is needed -- and
		 * the one thing to check when a module appears to do nothing. Said only
		 * on module pages, since a service is never listed at all.
		 */
		if ( $module['is_module'] ) {
			$page[] = '> [!IMPORTANT]';
			$page[] = '> **A module is built because `bootstrap.php` lists it.**'
				. ' `' . $module['class'] . '` binds its hooks when the plugin builds it,'
				. ' so it has to be listed there — which `wp zt add` writes for you.'
				. ' Left out, nothing is discovered and nothing reports why;'
				. ' [`wp zt doctor`](../../commands/doctor.md) is what catches it.';
			$page[] = '';
			$page[] = '```php';
			$page[] = '// bootstrap.php';
			$page[] = 'return array(';
			$page[] = '    ' . $module['class'] . '::class,';
			$page[] = ');';
			$page[] = '```';
			$page[] = '';
		}

		/*
		 * Every usable example first, before anything optional. A reader is here
		 * to do the thing the module does, and configuration is by definition
		 * the part they can skip -- it used to come first, so an Options page
		 * opened on the autoload registry rather than on `$options->get()`.
		 */
		foreach ( $module['examples'] as $example ) {
			$page[] = '## ' . ( '' !== $example['caption'] ? $example['caption'] : 'Usage' );
			$page[] = '';

			if ( '' !== $example['description'] ) {
				$page[] = $example['description'];
				$page[] = '';
			}

			$page[] = '```' . $example['language'];
			$page[] = $example['code'];
			$page[] = '```';
			$page[] = '';

			if ( '' !== $example['after'] ) {
				$page[] = $example['after'];
				$page[] = '';
			}
		}

		/*
		 * One heading for both kinds. This used to read "Configuring the
		 * module", which puts the word for optional work in the imperative. It
		 * is now what it is: the defaults are fine, and this is where to change
		 * them.
		 */
		$page[] = '## Changing the defaults';
		$page[] = '';

		if ( array() === $module['setup'] ) {
			$page[] = $module['is_module']
				? sprintf(
					'`%s` takes no configuration. The bare `modules` entry above is all it needs — reach it with `$plugin->get( %1$s::class )`, or declare a property of its type and have it injected.',
					$module['class']
				)
				: sprintf(
					'`%s` takes no configuration, so it needs no `bootstrap.php` entry at all. It is built the first time something asks for it:',
					$module['class']
				);
			$page[] = '';

			if ( ! $module['is_module'] ) {
				$page[] = '```php';
				$page[] = sprintf( '$%s = $plugin->get( %s::class );', strtolower( $module['class'] ), $module['class'] );
				$page[] = '';
				$page[] = '// Or, from any service, module, command or action:';
				$page[] = sprintf( 'public %s $%s;   // injected before your code runs', $module['class'], strtolower( $module['class'] ) );
				$page[] = '```';
				$page[] = '';
			}
		}

		foreach ( $module['setup'] as $setup ) {
			if ( '' !== $setup['caption'] ) {
				$page[] = $setup['caption'];
				$page[] = '';
			}

			if ( '' !== $setup['description'] ) {
				$page[] = $setup['description'];
				$page[] = '';
			}

			$page[] = '```' . $setup['language'];
			$page[] = $setup['code'];
			$page[] = '```';
			$page[] = '';

			if ( '' !== $setup['after'] ) {
				$page[] = $setup['after'];
				$page[] = '';
			}
		}

		/*
		 * A discovered file's base class gets its own page: the stub, its methods
		 * and its constants are a separate subject from the module that finds it,
		 * and inlining both made a single page cover two things at once.
		 */
		foreach ( $module['returns'] as $base ) {
			$base_file = zestry_find_class_file( $root, $base );
			$stub      = null === $base_file ? null : zestry_read_stub_example( $root, $base, $base_file );

			$page[] = sprintf( '## Writing %s %s', zestry_article( $base ), $base );
			$page[] = '';
			$page[] = sprintf(
				'A file in `%s` returns %s [`%s`](%s.md) instance%s.',
				( $module['root_of'][ $base ] ?? $module['roots'][0] ) . '/',
				zestry_article( $base ),
				$base,
				zestry_base_slug( $base ),
				null !== $stub ? sprintf( ', which `wp zt make %s <name>` generates', $stub['type'] ) : ''
			);

			/*
			 * Blocks is the one module whose files are not authored where they
			 * are discovered: `wp zt make block` writes to the source tree and
			 * `wp-scripts` compiles it into the discovered one. Saying only
			 * where the module looks sends a reader to edit build output.
			 */
			$authored_in = null === $stub ? null : zestry_make_destination( $root, $stub['type'] );

			if ( null !== $authored_in && $authored_in !== ( $module['root_of'][ $base ] ?? $module['roots'][0] ) ) {
				$page[] = '';
				$page[] = sprintf(
					'You write it in `%s/` — that is what `wp zt make %s <name>` creates and what you edit. `%s/` holds the compiled output `npm run build` produces, and is the directory this module reads.',
					$authored_in,
					$stub['type'],
					$module['root_of'][ $base ] ?? $module['roots'][0]
				);
			}
			$page[] = '';

			zestry_write_base_class_page( $root, $output_dir . '/' . $name, $base, $stub );

			/*
			 * A subclass of that base is an equally valid thing to extend, and
			 * satisfies the same discovery guard. Named here because the section
			 * above has just told the reader to extend $base, which is the last
			 * moment an alternative is still useful to hear about.
			 */
			$alternates = array_keys(
				array_filter(
					$module['alternates'],
					static function ( string $parent_class ) use ( $base ): bool {
						return $parent_class === $base;
					}
				)
			);

			if ( array() !== $alternates ) {
				$page[] = 1 === count( $alternates )
					? sprintf( 'The toolkit also ships a specialised base to extend in place of `%s`, satisfying the same guard:', $base )
					: sprintf( 'The toolkit also ships specialised bases to extend in place of `%s`, each satisfying the same guard:', $base );
				$page[] = '';

				foreach ( $alternates as $alternate ) {
					$summary = zestry_class_summary( $root, $alternate );

					$page[] = sprintf(
						'- [`%s`](%s.md)%s',
						$alternate,
						zestry_base_slug( $alternate ),
						'' === $summary ? '' : ' — ' . lcfirst( rtrim( $summary, '.' ) )
					);

					zestry_write_base_class_page( $root, $output_dir . '/' . $name, $alternate, null );
				}

				$page[] = '';
			}
		}

		/*
		 * The other types the module ships that a consumer writes against: a
		 * base class reached only through a factory, an enum whose cases they
		 * return, an attribute they annotate a property with. Each gets the same
		 * page a discovered base does, and is listed here so it is reachable.
		 */
		/*
		 * Only a module with a directory of its own has companions. `Log` and
		 * `Options` are single files directly under `src/Modules/`, so taking
		 * their parent directory here meant scanning every module in the tree
		 * and giving each of them a copy of everyone else's base classes.
		 */
		$module_source_dir = dirname( $root . '/' . $module['relative'] );
		$companions        = basename( $module_source_dir ) === $module['class']
			? zestry_find_companion_types(
				$module_source_dir,
				array_merge( array( $module['class'] ), $module['returns'], array_keys( $module['alternates'] ) )
			)
			: array();

		if ( array() !== $companions ) {
			$page[] = '## Related classes';
			$page[] = '';
			$page[] = 'Shipped with this module, and written against directly:';
			$page[] = '';

			foreach ( $companions as $companion => $kind ) {
				$summary = zestry_class_summary( $root, $companion );

				$page[] = sprintf(
					'- [`%s`](%s.md) — %s%s',
					$companion,
					zestry_base_slug( $companion ),
					$kind,
					'' === $summary ? '' : ', ' . lcfirst( rtrim( $summary, '.' ) )
				);

				zestry_write_base_class_page( $root, $module_dir, $companion, null );
			}

			$page[] = '';
		}

		$page = array_merge( $page, zestry_render_api_section( $root, $module['class'], '##', $module_dir . '/README.md' ) );

		/*
		 * Every page used to end on a `| **Throws** | — |` table row, which is
		 * a dead end: nothing said where to go next, and the reader had to know
		 * a filename to get anywhere. Built from what the page already holds,
		 * so a module gains its links by having dependencies and a base class
		 * rather than by anyone remembering to add them.
		 */
		$page = array_merge( $page, zestry_render_see_also( $module, $name ) );

		zestry_write_page( $module_dir . '/README.md', zestry_insert_toc( $page ) );
	}

	/*
	 * Each base class sits beside the index of the things that extend it, rather
	 * than inside any one of their folders -- it belongs to all of them.
	 */
	zestry_write_base_class_page( $root, $output_dir, 'Module', null );

	/*
	 * ActivationHandler is a Module subclass shipped in Core rather than a registry
	 * entry, so nothing above reaches it -- and two module pages send a reader
	 * to it (`Migrations` for run_pending(), `Cron` for unscheduling) without
	 * anywhere to land. It sits beside Module, which is what it extends.
	 */
	zestry_write_base_class_page( $root, $output_dir, 'ActivationHandler', null );

	/*
	 * The kernel types every plugin meets and no module owns. The four
	 * exceptions are the ones a consumer actually catches -- their docblocks
	 * carry the clearest prose in the repository, including the fact that one
	 * `catch ( ModuleException $e )` covers every way a module can fail to come
	 * up -- and until now that reached docs/ only as one-line "Throws" cells.
	 * `PluginAware` and `WithPlugin` are named across eleven pages with nothing
	 * to link to; `Bootable` is what marks a module that acts on its own.
	 */
	$kernel_dir = dirname( $output_dir ) . '/kernel';

	if ( ! is_dir( $kernel_dir ) && ! mkdir( $kernel_dir, 0755, true ) && ! is_dir( $kernel_dir ) ) {
		fwrite( STDERR, "Could not create {$kernel_dir}\n" );
		exit( 1 );
	}

	$kernel_types = array(
		'ModuleException'             => 'Every module failure, and the one class that catches them all',
		'DiscoveryException'          => 'A discovered file returned the wrong thing, or a named directory is missing',
		'ModuleNotFoundException'     => 'A class was asked for that cannot be built',
		'CircularDependencyException' => 'Two classes depend on each other',
		'PluginAware'                 => 'The contract that makes an object wireable',
		'WithPlugin'                  => 'The trait that satisfies it',
		'Bootable'                    => 'What marks a module that acts on its own',
		'WithFolderWalker'            => 'How every discovery module reads its directory',
		'WithEnablement'              => 'Let a discovered file say it should not register',
		'Arr'                         => 'Nested array paths, and the operations you reach for on a list of rows',
		'Str'                         => 'Spelling a name the way the thing you hand it to spells names',
	);

	$index = zestry_generated_banner( 'src/Kernel/ and the classes it declares' );

	$index[] = '# Kernel reference';
	$index[] = '';
	$index[] = 'The classes underneath every plugin: what you catch, what you implement, and';
	$index[] = 'what the discovery modules share. You rarely name these directly — but when';
	$index[] = 'something fails, this is what it fails with.';
	$index[] = '';

	foreach ( $kernel_types as $type => $blurb ) {
		zestry_write_base_class_page( $root, $kernel_dir, $type, null );

		$index[] = sprintf( '- [`%s`](%s.md) — %s', $type, zestry_base_slug( $type ), $blurb );
	}

	$index[] = '';
	$index[] = 'See also [`Plugin`](../plugin.md) and [`Module`](../modules/module.md).';
	$index[] = '';

	zestry_write_page( $kernel_dir . '/README.md', $index );

	/*
	 * Plugin is Core, not a module, so its page sits beside the modules index
	 * rather than inside it -- a consumer builds one in its entry file and
	 * reaches every module through it.
	 *
	 * Written outside $output_dir, so the stale-file sweep above never sees it:
	 * renaming this page leaves the old one behind to be deleted by hand.
	 */
	zestry_write_base_class_page( $root, $root . '/docs', 'Plugin', null );

	// One index, generated -- the depends column is registry.php itself.
	$entries = $modules;

	$index = zestry_generated_banner( 'src/DevTools/registry.php and each class it names' );

		$index[] = '# Modules';
		$index[] = '';
		$index[] = 'A module is anything a plugin is made of, and `bootstrap.php` lists every'
			. ' one. Some act on their own -- binding a hook, registering a post type,'
			. ' walking a directory -- and some only work when you call them. Listing one is'
			. ' what builds it, and nothing outside that file is ever built.';
		$index[] = '';
		$index[] = '## What your files are named';
		$index[] = '';
		$index[] = 'A discovered file\'s name is the thing it registers as: `resources/commands/greet.php`'
			. ' is `wp your-plugin greet`, `post-types/book.php` is the `book` post type,'
			. ' `fields/acme_rating.php` is the `acme_rating` meta key. You never repeat that'
			. ' name inside the file.';
		$index[] = '';
		$index[] = 'Whether your plugin slug is prefixed onto that name depends on where the'
			. ' name lands:';
		$index[] = '';
		$index[] = '- **Prefixed**, when the name goes into something every plugin on the site'
			. ' shares — admin page slugs, cron hooks, AJAX actions, Site Health checks,'
			. ' REST namespaces, WP-CLI commands. Two plugins with a `sync` schedule must'
			. ' not collide, so yours is `your-plugin-sync`. A hyphen joins the two halves'
			. ' wherever the destination takes one; the few that take something else say'
			. ' so — an option name joins with `_`, a REST namespace with `/`, a WP-CLI'
			. ' command with a space.';
		$index[] = '- **Not prefixed**, when the name is your own public API and something else'
			. ' constrains it — post types and taxonomies, which WordPress caps at 20 and 32'
			. ' characters; meta keys, which appear in your REST responses; block names,'
			. ' which `block.json` already qualifies. Prefix these yourself in the filename'
			. ' when you need to.';
		$index[] = '';
		$index[] = 'All of them extend [`Module`](module.md). One that acts on its own also'
			. ' implements [`Bootable`](../kernel/bootable.md), whose `on_boot()` runs when'
			. ' the plugin builds it; a module without it works only when you call it.';

	$index[] = '';
	$index[] = 'Everything here is optional. `wp zt add <name>` copies one into your'
		. ' plugin, along with anything it depends on.';
	$index[] = '';

	/*
	 * What each module is *for* is a column rather than a second table: it is
	 * the thing being scanned for, so it comes first and is said once.
	 */
	$index[]  = '## Every module';
	$index[]  = '';
	$index[]  = 'Add nothing up front. Reach for one when you hit what it solves:';
	$index[]  = '';
	$headings = array( 'Module', 'Reach for it to…', 'Discovers', 'A file returns', 'Also copies' );

	$rows = array();

	/*
	 * Alphabetical, not the registry's own order. The registry is ordered by
	 * what depends on what, which put `assets`, `log` and `options` at the
	 * top of a table people read to find a name -- and it means adding an
	 * entry reshuffles rows that did not change.
	 */
	$listed = $entries;
	ksort( $listed );

	foreach ( $listed as $name => $module ) {
		$dirs = array() === $module['roots']
			? '—'
			: implode(
				', ',
				array_map(
					static function ( string $dir ): string {
						return '`' . $dir . '/`';
					},
					$module['roots']
				)
			);

		/*
		 * The column is headed "Discovers", and one module does not: `assets`
		 * resolves URLs from one root and reads a manifest out of the other,
		 * walking neither. Said in the cell rather than left to the heading,
		 * because a reader who takes it at its word drops a file in and waits
		 * for something to register. Derived from the same `use
		 * WithFolderWalker;` test the per-module facts line uses, so a module
		 * that stops walking cannot keep the claim.
		 */
		if ( '—' !== $dirs && ! ( $module['walks'] ?? true ) ) {
			$dirs .= ' (read, not walked)';
		}

		// Alternates listed alongside the guard's own base: this column is
		// where a reader decides what to extend, so a base missing from it
		// is a base they never learn exists.
		$bases = array_merge( $module['returns'], array_keys( $module['alternates'] ) );

		$returns = array() === $bases
			? '—'
			: implode(
				', ',
				array_map(
					static function ( string $base ) use ( $name ): string {
						return sprintf( '[`%s`](%s/%s.md)', $base, $name, zestry_base_slug( $base ) );
					},
					$bases
				)
			);

		$depends = array() === $module['depends']
			? '—'
			: implode(
				', ',
				array_map(
					static function ( string $dependency ): string {
						return '`' . $dependency . '`';
					},
					$module['depends']
				)
			);

		$rows[] = array(
			sprintf( '[`%s`](%s/)', $name, $name ),
			zestry_entry_purpose( $name ),
			$dirs,
			$returns,
			$depends,
		);
	}

	$index = array_merge( $index, zestry_render_table( $headings, $rows ) );

	$index[] = '';

	/*
	 * "Also copies" lists modules and services, which is what a reader
	 * needs for every row but two: `blocks` and `assets` each also write
	 * build tooling into files they do not own -- package.json scripts and
	 * devDependencies, a tsconfig, a webpack config, .gitignore entries.
	 * Naming that here because this table is where a reader decides what
	 * to add, and "copies a class" and "reconfigures my toolchain" are
	 * different enough to be worth the sentence.
	 */
	$index[] = '**`blocks` and `assets` also write build tooling outside their own'
		. ' tree** -- npm scripts and devDependencies, a `tsconfig.json`, a'
		. ' `webpack.config.js`, `.gitignore` entries. Everything either writes is'
		. ' additive, and [`wp zt add`](../commands/add.md) lists it.';
	$index[] = '';
	$index[] = 'One worth calling out: **`ajax` serves `admin-ajax.php`**, not the REST'
		. ' API. Reach for it when something already speaks that protocol -- an'
		. ' existing script, a third-party integration -- and `rest-api` otherwise.';
	$index[] = '';
	$index[] = '**`path` arrives on its own** with almost every other entry, so it is'
		. ' rarely worth naming.';

	$index[] = '';
	$index[] = '> [!NOTE]';
	$index[] = '> **A module whose directory does not exist yet discovers nothing, and'
		. ' says nothing.** The directory each one reads is fixed, so adding a'
		. ' module before writing its first file is fine.';
	$index[] = '';

	zestry_write_page( $output_dir . '/README.md', $index );

	return count( $modules );
}

/**
 * "an AdminPage", not "a AdminPage".
 *
 * @param string $word The word the article precedes.
 * @return string Either `a` or `an`.
 */
function zestry_article( string $word ): string {
	return in_array( strtoupper( $word[0] ), array( 'A', 'E', 'I', 'O', 'U' ), true ) ? 'an' : 'a';
}

/**
 * The page filename for a base class, without its extension.
 *
 * @param string $base The base class name, e.g. `AjaxAction`.
 * @return string A kebab-case slug, e.g. `ajax-action`.
 */
function zestry_base_slug( string $base ): string {
	return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $base ) );
}

/**
 * Render a module's facts as one inline line of labelled values.
 *
 * A line rather than a table: there are only ever two or three of these, and a
 * full table for that much puts a bordered block between the title and the page
 * it introduces. Reading them inline also lets the line sit above the contents
 * list, where it says what the module is before the reader picks a section.
 *
 * @param array<string, mixed> $module The module's extracted data.
 * @return string[] Markdown lines.
 */
function zestry_render_facts_table( array $module ): array {
	$facts = array();

	if ( array() !== $module['roots'] ) {
		$facts[] = ( ( $module['walks'] ?? true ) ? 'Discovers ' : 'Reads from ' ) . implode(
			', ',
			array_map(
				static function ( string $dir ): string {
					return '`' . $dir . '/`';
				},
				$module['roots']
			)
		);
	}

	if ( array() !== $module['returns'] ) {
		$facts[] = 'Each file returns ' . implode(
			', ',
			array_map(
				static function ( string $base ): string {
					return sprintf( '[`%s`](%s.md)', $base, zestry_base_slug( $base ) );
				},
				$module['returns']
			)
		);
	}

	if ( array() !== $module['depends'] ) {
		$facts[] = 'Dependencies ' . implode(
			', ',
			array_map(
				/*
				 * A dependency's page lives under its own section, which is
				 * usually the other one: nine of the ten modules depend on
				 * `path`, a service. Linking every one as a sibling pointed all
				 * nine at docs/modules/path/, which does not exist.
				 */
				static function ( string $dependency ): string {
					$prefix = '../';

					return sprintf( '[`%s`](%s%s/)', $dependency, $prefix, $dependency );
				},
				$module['depends']
			)
		);
	}

	if ( array() === $facts ) {
		return array();
	}

	return array(
		implode( ' &nbsp;·&nbsp; ', $facts ),
		'',
	);
}

/**
 * Render a class's methods and constants as a reference section.
 *
 * @param string $root  Absolute path to the repository root.
 * @param string $class The class name.
 * @param string $level The heading level for the section, e.g. `##`.
 * @return string[] Markdown lines.
 */
function zestry_render_api_section( string $root, string $class, string $level, string $page_file = '' ): array {
	$page      = zestry_docs_relative( $page_file );
	$lines     = array();
	$constants = zestry_class_constants( $root, $class );
	$methods   = zestry_public_api( $root, $class );

	if ( array() !== $constants ) {
		$lines[] = $level . ' Constants';
		$lines[] = '';

		foreach ( $constants as $constant ) {
			$lines[] = sprintf( '%s# `%s`', $level, $constant['name'] );
			$lines[] = '';
			$lines[] = '```php';
			$lines[] = sprintf( 'const %s = %s;', $constant['name'], $constant['value'] );
			$lines[] = '```';
			$lines[] = '';

			if ( '' !== $constant['deprecated'] ) {
				$lines[] = '> **Deprecated.** ' . $constant['deprecated'];
				$lines[] = '';
			} elseif ( '' !== $constant['summary'] ) {
				$lines[] = $constant['summary'];
				$lines[] = '';
			}
		}
	}

	/*
	 * Two sections, because the two answer different questions. An abstract
	 * method is work the reader has to do before their file will load at all; a
	 * concrete one is something they may call if they want to. Listed together
	 * under "Methods" they read as one undifferentiated list, and the required
	 * half is the half a reader most needs to find.
	 */
	$required = array();
	$optional = array();

	foreach ( $methods as $method ) {
		if ( $method['abstract'] ?? false ) {
			$required[] = $method;
			continue;
		}

		$optional[] = $method;
	}

	if ( array() !== $required ) {
		$lines[] = $level . ' You must implement';
		$lines[] = '';
		$lines[] = 1 === count( $required )
			? 'This one method is abstract: a subclass that does not declare it will not load.'
			: sprintf(
				'These %d methods are abstract: a subclass that does not declare all of them will not load.',
				count( $required )
			);
		$lines[] = '';
		$lines   = array_merge( $lines, zestry_render_method_list( $required, $level, $page ) );
	}

	if ( array() !== $optional ) {
		$lines[] = array() === $required ? $level . ' Methods' : $level . ' Methods you can use';
		$lines[] = '';
		$lines   = array_merge( $lines, zestry_render_method_list( $optional, $level, $page ) );
	}

	return $lines;
}

/**
 * Render a run of methods, separated the way the page separates them.
 *
 * @param array<int, array<string, mixed>> $methods The methods to render.
 * @param string                           $level   The section's heading level, e.g. `##`.
 * @param string                           $page    The page being written, relative to `docs/`.
 * @return string[] Markdown lines.
 */
function zestry_render_method_list( array $methods, string $level, string $page = '' ): array {
	$lines = array();

	foreach ( $methods as $index => $method ) {
		if ( 0 !== $index ) {
			$lines[] = '<br>';
			$lines[] = '';
		}

		$lines = array_merge( $lines, zestry_render_method( $method, $level . '#', $page ) );
	}

	return $lines;
}

/**
 * Read the public constants declared by a class.
 *
 * @param string $root  Absolute path to the repository root.
 * @param string $class The class name.
 * @return array<int, array{name: string, value: string, summary: string, deprecated: string}>
 */
function zestry_class_constants( string $root, string $class ): array {
	$path = zestry_find_class_file( $root, $class );

	if ( null === $path ) {
		return array();
	}

	return zestry_public_constants( (string) file_get_contents( $path ) );
}

/**
 * Write the page for a discovered file's base class.
 *
 * @param string                                                     $root       Absolute path to the repository root.
 * @param string                                                     $output_dir Directory the module pages are written to.
 * @param string                                                     $base       The base class name.
 * @param array{example: string, source: string, type: string}|null  $stub       The stub that generates one, when there is one.
 * @return void
 */
function zestry_write_base_class_page( string $root, string $output_dir, string $base, ?array $stub ): void {
	$path = zestry_find_class_file( $root, $base );

	/*
	 * Loud, because generation clears docs/ first: a base class this cannot
	 * locate does not produce a stale page, it produces no page at all, and a
	 * module page then links to a file that is simply gone. That has happened
	 * twice, both times because the source tree moved and the search paths in
	 * zestry_find_class_file() did not -- silently, since nothing failed.
	 */
	if ( null === $path ) {
		fwrite( STDERR, sprintf( "No class file for base class '%s'; its page was not written.\n", $base ) );
		zestry_record_missing_base( $base );
		return;
	}

	$relative = substr( $path, strlen( $root ) + 1 );
	$source   = (string) file_get_contents( $path );
	$docblock = zestry_docblock_before( $source, zestry_type_declaration( $base ) );
	$page     = zestry_generated_banner( $relative );

	$page[] = '# ' . $base;
	$page[] = '';

	if ( null !== $docblock ) {
		$tags  = zestry_extract_custom_tags( $docblock );
		$split = zestry_split_example( $tags['body'] );
		$page  = array_merge( $page, zestry_render_blocks( $split['blocks'] ) );

		foreach ( $tags['examples'] as $example ) {
			$page[] = '## ' . ( '' !== $example['caption'] ? $example['caption'] : 'Usage' );
			$page[] = '';

			if ( '' !== $example['description'] ) {
				$page[] = $example['description'];
				$page[] = '';
			}

			$page[] = '```' . $example['language'];
			$page[] = $example['code'];
			$page[] = '```';
			$page[] = '';

			if ( '' !== $example['after'] ) {
				$page[] = $example['after'];
				$page[] = '';
			}
		}
	}

	if ( null !== $stub ) {
		$page[] = '## Generated starting point';
		$page[] = '';
		// Linked to the command's own page, which documents its flags -- a base
		// class page is written from `../..`, so the path climbs out of
		// `modules/{module}/` to reach `commands/`.
		$page[] = sprintf(
			'[`wp zt make %1$s <name>`](../../commands/make-%1$s.md) writes %2$s:',
			$stub['type'],
			count( $stub['files'] ) > 1 ? 'these files' : 'this file'
		);
		$page[] = '';

		foreach ( $stub['files'] as $file ) {
			// A caption only earns a heading when there is more than one file to
			// tell apart; a lone stub is already introduced by the line above.
			if ( count( $stub['files'] ) > 1 ) {
				$page[] = '### ' . $file['caption'];
				$page[] = '';
			}

			if ( '' !== $file['description'] ) {
				$page[] = $file['description'];
				$page[] = '';
			}

			$page[] = '```' . $file['language'];
			$page[] = $file['example'];
			$page[] = '```';
			$page[] = '';
		}
	}

	$page_file = $output_dir . '/' . zestry_base_slug( $base ) . '.md';

	$page = array_merge( $page, zestry_render_api_section( $root, $base, '##', $page_file ) );

	zestry_write_page( $page_file, zestry_insert_toc( $page ) );
}

/**
 * Render a markdown table, dropping any column that is empty in every row.
 *
 * The first column is always kept: it is the row's own name, and a table
 * without it would have nothing to read across from.
 *
 * @param string[]              $headings One per column.
 * @param array<int, string[]>  $rows     Cells, in the same order as the headings.
 * @return string[] Markdown lines.
 */
function zestry_render_table( array $headings, array $rows ): array {
	$keep = array( 0 );

	foreach ( array_keys( $headings ) as $column ) {
		if ( 0 === $column ) {
			continue;
		}

		foreach ( $rows as $row ) {
			if ( '—' !== ( $row[ $column ] ?? '—' ) ) {
				$keep[] = $column;
				continue 2;
			}
		}
	}

	$pick = static function ( array $cells ) use ( $keep ): string {
		$kept = array();

		foreach ( $keep as $column ) {
			$kept[] = $cells[ $column ] ?? '';
		}

		return '| ' . implode( ' | ', $kept ) . ' |';
	};

	$lines = array( $pick( $headings ), '|' . str_repeat( '---|', count( $keep ) ) );

	foreach ( $rows as $row ) {
		$lines[] = $pick( $row );
	}

	return $lines;
}

/**
 * The links a reader is most likely to want after this page.
 *
 * @param array<string, mixed> $module The module's extracted data.
 * @param string               $name   Its installable name, e.g. `ajax`.
 * @return string[] Markdown lines.
 */
function zestry_render_see_also( array $module, string $name ): array {
	$links = array();

	foreach ( $module['returns'] as $base ) {
		$links[] = sprintf(
			'[`%s`](%s.md) — what a file in `%s/` returns',
			$base,
			zestry_base_slug( $base ),
			$module['root_of'][ $base ] ?? $module['roots'][0]
		);
	}

	foreach ( $module['depends'] as $dependency ) {
		$prefix = '../';

		$links[] = sprintf( '[`%s`](%s%s/) — copied in alongside this one', $dependency, $prefix, $dependency );
	}

	$links[] = '[`Module`](../module.md) — what every module inherits';

	$links[] = sprintf(
		'[`wp zt add %s`](../../commands/add.md) — the command that copies it',
		$name
	);

	$lines = array( '## See also', '' );

	foreach ( $links as $link ) {
		$lines[] = '- ' . $link;
	}

	$lines[] = '';

	return $lines;
}

/**
 * Types a module ships that a consumer writes against, beside its base class.
 *
 * A module directory holds more than the module and the class its files return.
 * `RestApi` also ships `RestRoute` -- the class every route file subclasses,
 * with three abstract methods a reader must implement -- plus the `RestArgument`
 * and `RestRequired` attributes; `AdminPages` ships the `ParentMenu` enum whose
 * ten cases are the only way to nest a page under a core menu. None of them had
 * a page, because the search looked only for a class extending the guard's base
 * and its declaration patterns were `class`-only.
 *
 * Found by what a consumer can act on rather than by a list kept here: an
 * abstract class they subclass, an enum whose cases they return, an attribute
 * they annotate with. The module, the guard's own base and its subclasses are
 * excluded -- each already has a page of its own.
 *
 * @param string   $module_dir Absolute path to the module's own source directory.
 * @param string[] $documented Names already given a page elsewhere.
 * @return array<string, string> Type short name => the kind of declaration it is.
 */
function zestry_find_companion_types( string $module_dir, array $documented ): array {
	$found = array();

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $module_dir ) );

	foreach ( $files as $file ) {
		if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}

		$source = (string) file_get_contents( $file->getPathname() );

		if ( ! preg_match( '/^(?:(abstract|final|readonly) )*(class|interface|trait|enum) (\w+)/m', $source, $match ) ) {
			continue;
		}

		[ , $modifier, $kind, $name ] = $match;

		if ( in_array( $name, $documented, true ) ) {
			continue;
		}

		// An attribute is a class like any other, and is told apart by the
		// `#[Attribute]` on its own declaration -- written fully qualified as
		// `#[\Attribute]` in this codebase, since these files import nothing.
		if ( preg_match( '/^#\[\\\\?Attribute\b/m', $source ) ) {
			$found[ $name ] = 'attribute';
			continue;
		}

		if ( 'enum' === $kind || 'interface' === $kind ) {
			$found[ $name ] = $kind;
			continue;
		}

		if ( 'abstract' === $modifier ) {
			$found[ $name ] = 'abstract class';
			continue;
		}

		// A plain class in a module directory is usually the module's own
		// internals. One a reader is handed and calls methods on says so with
		// `@api`, the same tag phpDocumentor reads it as.
		if ( preg_match( '/^\s*\*\s*@api\b/m', $source ) ) {
			$found[ $name ] = 'class';
		}
	}

	ksort( $found );

	return $found;
}

/**
 * What a module or service is reached for, in one clause.
 *
 * The one column on the index that no other source holds: `registry.php` says
 * what depends on what, and a class docblock opens with what the class *is*.
 * Neither answers "which of these fifteen do I want", which is the question
 * someone arrives at that page with.
 *
 * Every entry needs one. The table this replaced covered ten of fifteen
 * modules, and the five it left out -- `ajax`, `fields`, `meta-boxes`,
 * `site-health`, `abilities` -- were the ones a reader had no way to discover
 * they wanted. A missing entry here fails the docs build rather than quietly
 * printing an em dash.
 *
 * @param string $name The registry name.
 * @return string
 * @throws RuntimeException When the entry has no purpose written for it.
 */
function zestry_entry_purpose( string $name ): string {
	$purposes = array(
		// Modules.
		'abilities'     => 'give an AI agent a tool it can call (WordPress 6.9+)',
		'admin-pages'   => 'add a screen to the admin menu',
		'ajax'          => 'answer `admin-ajax.php`, for callers that already speak it',
		'assets'        => 'enqueue a script or stylesheet, and share code between them',
		'blocks'        => 'build a block for the editor',
		'cli'           => 'add a `wp` command',
		'cron'          => 'run something on a schedule',
		'fields'        => 'register post meta, and render it on the editor',
		'icons-library' => 'publish an SVG icon, for the editor and your own markup (WordPress 7.1+)',
		'log'           => 'record what went wrong',
		'meta-boxes'    => 'put a panel on the post or comment editor',
		'migrations'    => 'create or change a database table',
		'options'       => 'store settings',
		'post-types'    => 'register a custom post type or taxonomy',
		'rest-api'      => 'expose an HTTP endpoint',
		'site-health'   => 'report a verdict on Site Health, or list values on Info',

		// The ones that only work when you call them.
		'cookie'        => 'read and write a cookie, and carry a value across a redirect',
		'db'            => 'name a database table, yours or WordPress\'s',
		'globals'       => 'pass a value between classes within one request',
		'path'          => 'resolve a path or URL inside the plugin',
		'request'       => 'declare and validate what a route, ability, action or page accepts',
		'transients'    => 'keep a value past the request, with an expiry',
		'views'         => 'render a PHP template',
	);

	if ( ! isset( $purposes[ $name ] ) ) {
		throw new RuntimeException(
			sprintf(
				'No purpose written for "%s". Add one to zestry_entry_purpose() -- the index cannot say what it is for without it.',
				$name
			)
		);
	}

	return $purposes[ $name ];
}
