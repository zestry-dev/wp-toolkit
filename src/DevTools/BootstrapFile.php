<?php

/**
 * DevTools: bootstrap.php reader/writer
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;

/**
 * Declares modules in a consuming plugin's `bootstrap.php`.
 *
 * That file lists the modules a plugin uses, and `Plugin::bootstrap()` reads
 * it. Both `wp zt add` and `wp zt make module` write to it, so a module is
 * active as soon as it is created rather than requiring a manual edit.
 *
 * The file is real PHP holding real closures, so it is appended to rather than
 * rewritten: a new entry goes in immediately before the closing `);`, leaving
 * everything already there untouched. A module already declared is skipped
 * rather than duplicated, keeping whatever configuration is on it.
 */
class BootstrapFile extends Module {

	/**
	 * Declare a module, unless it is already in the file.
	 *
	 * @param string      $plugin_root Absolute path to the consuming plugin's root.
	 * @param string      $class_name  Fully qualified module class name, without a leading separator.
	 * @param string|null $config      A commented lead-in written above the entry, or null for none.
	 * @param string|null $hook        The hook heading it goes under, or null for the top level.
	 * @param int         $priority    The priority that heading binds at.
	 * @return DeclarationResult What was written, or why nothing was.
	 */
	public function declare_module( string $plugin_root, string $class_name, ?string $config = null, ?string $hook = null, int $priority = 10 ): DeclarationResult {
		return $this->declare_modules(
			$plugin_root,
			array(
				$class_name => array(
					'config'   => $config,
					'hook'     => $hook,
					'priority' => $priority,
				),
			)
		);
	}

	/**
	 * Declare several modules in one pass.
	 *
	 * Entries are grouped by the heading they go under and written one group at
	 * a time, since each insertion moves every offset after it -- the file is
	 * re-read between groups rather than the offsets adjusted, which is the
	 * version that cannot drift.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param array<class-string, array{config: string|null, hook: string|null, priority: int}> $classes How to write each entry, keyed by class name.
	 * @return DeclarationResult What was written, or why nothing was.
	 */
	public function declare_modules( string $plugin_root, array $classes ): DeclarationResult {
		$path = $this->get_path( $plugin_root );

		if ( ! \is_file( $path ) ) {
			return DeclarationResult::NoFile;
		}

		$contents = (string) \file_get_contents( $path );
		$imports  = $this->get_imports( $contents );
		$new_uses = array();
		$groups   = array();

		foreach ( $classes as $class_name => $entry ) {
			if ( $this->has_module( $contents, $imports, $class_name ) ) {
				continue;
			}

			$reference = $this->resolve_reference( $class_name, $imports, $new_uses );
			$hook      = $entry['hook'] ?? null;
			$heading   = null === $hook ? '' : $this->get_heading( $hook, $entry['priority'] ?? 10 );
			$indent    = '' === $heading ? "\t" : "\t\t";
			$config    = $entry['config'] ?? null;

			$groups[ $heading ] ??= '';
			$groups[ $heading ]  .= ( null === $config || '' === $config ? '' : $config )
				. $indent . $reference . "::class,\n";
		}

		if ( array() === $groups ) {
			return DeclarationResult::AlreadyDeclared;
		}

		foreach ( $groups as $heading => $body ) {
			$updated = '' === $heading
				? $this->insert_at( $contents, $this->find_array_end( $contents ), $body )
				: $this->insert_into_group( $contents, $heading, $body );

			if ( null === $updated ) {
				return DeclarationResult::Unrecognized;
			}

			$contents = $updated;
		}

		$updated = $this->add_imports( $contents, $new_uses );

		if ( false === \file_put_contents( $path, $updated ) ) {
			return DeclarationResult::NotWritable;
		}

		return DeclarationResult::Declared;
	}

	/**
	 * Whether a plugin has a `bootstrap.php` at all.
	 *
	 * Absent is a choice rather than a mistake: a plugin may declare its modules
	 * in its entry file by hand instead.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return bool
	 */
	public function exists( string $plugin_root ): bool {
		return \is_file( $this->get_path( $plugin_root ) );
	}

	/**
	 * Every class the file declares, and whether it carries an initializer.
	 *
	 * Evaluated by `require`ing the file rather than tokenizing it, because
	 * that is what {@see \Zestry\WPToolkit\Kernel\Plugin::bootstrap()} itself does: a value
	 * built from a constant or a ternary is then reported as the plugin will
	 * actually see it, not as it is spelled. `Foo::class` resolves at compile
	 * time without autoloading, so this needs none of the consumer's classes to
	 * be loadable.
	 *
	 * Reports what is *written*, and nothing more. A heading is flattened away,
	 * since a class under one is declared as surely as one above it: what happens
	 * to a class is decided by
	 * whether it is a {@see \Zestry\WPToolkit\Kernel\Abstracts\Module}, which the caller asks
	 * the class rather than the file. Deciding whether a declaration is
	 * *correct* is the caller's job.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return array<string, array{initialize: bool}> Keyed by class name, without a leading separator.
	 * @throws \RuntimeException When the file does not parse, or does not return an array.
	 */
	public function read_declarations( string $plugin_root ): array {
		$path = $this->get_path( $plugin_root );

		if ( ! \is_file( $path ) ) {
			return array();
		}

		/*
		 * A parse error in a `require`d file is a fatal PHP cannot recover
		 * from, and this command's whole job is to be run when something is
		 * wrong. TOKEN_PARSE raises a catchable ParseError for the same input,
		 * so the file is checked before it is ever executed.
		 */
		try {
			\token_get_all( (string) \file_get_contents( $path ), TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			throw new \RuntimeException(
				\sprintf( '%s does not parse: %s', $path, $error->getMessage() ),
				0,
				$error
			);
		}

		$declared = require $path;

		if ( ! \is_array( $declared ) ) {
			throw new \RuntimeException( 'Bootstrap file must return an array: ' . $path );
		}

		return $this->read_entries( $declared );
	}

	/**
	 * The lines a module would be declared with.
	 *
	 * Printed for a consumer to paste when there is no file to append to, so the
	 * work is one paste rather than a trip to the docs. A module under a heading
	 * is printed with the heading around it, since the heading is what says when
	 * it boots and a bare line would declare something else.
	 *
	 * Fully qualified rather than imported, unlike what gets written into the
	 * file itself: this is pasted somewhere this class cannot see, so a short
	 * name would depend on an import that may not be there.
	 *
	 * @param string      $class_name Fully qualified module class name.
	 * @param string|null $config     A commented lead-in written above the entry, or null for none.
	 * @param string|null $hook       The hook heading it goes under, or null for the top level.
	 * @param int         $priority   The priority that heading binds at.
	 * @return string
	 */
	public function get_entry_line( string $class_name, ?string $config = null, ?string $hook = null, int $priority = 10 ): string {
		$lead = null === $config || '' === $config ? '' : $config;

		if ( null === $hook ) {
			return $lead . "\t\\" . $class_name . '::class,';
		}

		return $lead . \implode(
			"\n",
			array(
				"\t'" . $this->get_heading( $hook, $priority ) . "' => array(",
				"\t\t\\" . $class_name . '::class,',
				"\t),",
			)
		);
	}

	/**
	 * That file's path, written the way a reader would recognise it.
	 *
	 * Relative to the plugin when it is inside it, which is the usual case and
	 * the readable one; absolute otherwise, since a path outside the plugin
	 * shortened against it would be nonsense.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return string
	 */
	public function get_display_path( string $plugin_root ): string {
		$path = $this->get_path( $plugin_root );
		$root = \rtrim( $plugin_root, '/\\' ) . '/';

		return \str_starts_with( $path, $root ) ? \substr( $path, \strlen( $root ) ) : $path;
	}

	/**
	 * Every class one entry list declares, reading through its headings.
	 *
	 * The same shapes `declare_modules()` takes: a bare class name, a class name
	 * keyed to its configurator, and a heading keyed to a list of either. A
	 * heading is flattened away here -- callers ask what the plugin declares,
	 * and every class under a heading is declared as surely as one above it.
	 *
	 * @param array<array-key, mixed> $entries The entries as the file returned them.
	 * @return array<string, array{initialize: bool}> Keyed by class name, without a leading separator.
	 */
	private function read_entries( array $entries ): array {
		$declarations = array();

		foreach ( $entries as $key => $value ) {
			// A heading, by the same rule `declare_modules()` reads it: a hook
			// name never contains a backslash and `Foo::class` always does.
			if ( \is_string( $key ) && \is_array( $value ) && ! \str_contains( $key, '\\' ) ) {
				$declarations += $this->read_entries( $value );

				continue;
			}

			$class_name = \is_string( $key ) ? $key : $value;

			if ( ! \is_string( $class_name ) || '' === $class_name ) {
				continue;
			}

			$declarations[ \ltrim( $class_name, '\\' ) ] = array(
				'initialize' => \is_string( $key ) && \is_callable( $value ),
			);
		}

		return $declarations;
	}

	/**
	 * The heading a hook and priority are written as.
	 *
	 * `init` at WordPress's own default, `init:20` anywhere else -- 10 written
	 * out would be one more thing to read saying what leaving it out says.
	 *
	 * @param string $hook     The hook the group boots on.
	 * @param int    $priority The priority it binds at.
	 * @return string
	 */
	private function get_heading( string $hook, int $priority ): string {
		return 10 === $priority ? $hook : $hook . ':' . $priority;
	}

	/**
	 * Put a block of entries at a byte offset, opening a line for it if needed.
	 *
	 * @param string   $contents The file's contents.
	 * @param int|null $offset   Where the entries go, or null when there is nowhere.
	 * @param string   $body     The entry lines to insert.
	 * @return string|null The updated contents, or null when there was nowhere to put them.
	 */
	private function insert_at( string $contents, ?int $offset, string $body ): ?string {
		if ( null === $offset ) {
			return null;
		}

		$before = \substr( $contents, 0, $offset );

		// An empty `array()` closes on the line it opened on, so the first entry
		// needs a line of its own; one that already has entries ends the last of
		// them with a newline already.
		if ( ! \str_ends_with( $before, "\n" ) ) {
			$before .= "\n";
		}

		return $before . $body . \substr( $contents, $offset );
	}

	/**
	 * Put entries inside a heading, creating the heading when the file has none.
	 *
	 * @param string $contents The file's contents.
	 * @param string $heading  The heading to write under, `hook` or `hook:priority`.
	 * @param string $body     The entry lines to insert.
	 * @return string|null The updated contents, or null when the file has no returned array.
	 */
	private function insert_into_group( string $contents, string $heading, string $body ): ?string {
		$offset = $this->find_group_end( $contents, $heading );

		if ( null !== $offset ) {
			return $this->insert_at( $contents, $offset, $body );
		}

		// No such heading yet, so this writes one. Blank line above it, since a
		// heading is a section rather than another entry.
		$group = "\n\t'" . $heading . "' => array(\n" . $body . "\t),\n";

		return $this->insert_at( $contents, $this->find_array_end( $contents ), $group );
	}

	/**
	 * The byte offset of the closing bracket of one heading's array.
	 *
	 * Tokenized rather than matched, for the reason {@see find_array_end()} is:
	 * a heading's own name may appear in a comment or a string, and its closing
	 * bracket is only recognisable by counting from where it opened.
	 *
	 * @param string $contents The file's contents.
	 * @param string $heading  The heading to find, `hook` or `hook:priority`.
	 * @return int|null Null when the file does not already hold that heading.
	 */
	private function find_group_end( string $contents, string $heading ): ?int {
		$tokens = \token_get_all( $contents );
		$count  = \count( $tokens );
		$offset = 0;

		$offsets = array();

		foreach ( $tokens as $index => $token ) {
			$offsets[ $index ] = $offset;
			$offset           += \strlen( \is_array( $token ) ? $token[1] : $token );
		}

		foreach ( $tokens as $index => $token ) {
			if ( ! \is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
				continue;
			}

			// The token carries its quotes; the heading does not.
			if ( \trim( $token[1], "'\"" ) !== $heading ) {
				continue;
			}

			$opened = $this->find_array_open( $tokens, $index, $count );

			if ( null === $opened ) {
				continue;
			}

			$depth = 0;

			for ( $position = $opened; $position < $count; $position++ ) {
				$text = \is_array( $tokens[ $position ] ) ? $tokens[ $position ][1] : $tokens[ $position ];

				if ( '(' === $text || '[' === $text ) {
					++$depth;
				} elseif ( ')' === $text || ']' === $text ) {
					--$depth;

					if ( 0 === $depth ) {
						return $offsets[ $position ];
					}
				}
			}
		}

		return null;
	}

	/**
	 * The index of the bracket opening the array a heading points at.
	 *
	 * Null when what follows the string is not `=> array(` or `=> [`, which is
	 * what a string that merely looks like a heading gives -- one inside a
	 * comparison, or a value rather than a key.
	 *
	 * @param array<int, array{0: int, 1: string}|string> $tokens The tokenized file.
	 * @param int                                         $index  Index of the string token.
	 * @param int                                         $count  How many tokens there are.
	 * @return int|null Index of the opening bracket.
	 */
	private function find_array_open( array $tokens, int $index, int $count ): ?int {
		$arrow = false;

		for ( $position = $index + 1; $position < $count; $position++ ) {
			$token = $tokens[ $position ];
			$text  = \is_array( $token ) ? $token[1] : $token;

			if ( \is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}

			if ( ! $arrow ) {
				if ( \is_array( $token ) && T_DOUBLE_ARROW === $token[0] ) {
					$arrow = true;
					continue;
				}

				return null;
			}

			if ( \is_array( $token ) && T_ARRAY === $token[0] ) {
				continue;
			}

			return '(' === $text || '[' === $text ? $position : null;
		}

		return null;
	}

	/**
	 * The file this project reads its module declarations from.
	 *
	 * `bootstrap.php` beside the entry file is the default and very nearly
	 * always the answer, but `Plugin::bootstrap()` takes any path, and nothing
	 * on disk records which was used. Assuming the default is not a cosmetic
	 * error: `wp zt doctor` reports every module as undeclared, and `wp zt add`
	 * appends a declaration to a file the plugin never reads -- which copies a
	 * module in and leaves it inert, silently.
	 *
	 * So the running plugin is asked first, and it knows because it is the thing
	 * that called `bootstrap()`. A plugin that is not running, or that declares
	 * its modules in the entry file instead, falls back to the default -- which
	 * is where a declaration should go for a plugin that has no other answer.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return string
	 */
	private function get_path( string $plugin_root ): string {
		$declared = $this->with( RuntimePlugin::class )->get( $plugin_root )?->get_bootstrap_file();

		return null === $declared
			? \rtrim( $plugin_root, '/\\' ) . '/bootstrap.php'
			: $declared;
	}

	/**
	 * The byte offset of the closing bracket of the array the file returns.
	 *
	 * Tokenized rather than matched with a pattern, for the same reason
	 * {@see Copier} tokenizes: this is real PHP, and the shapes a pattern gets
	 * wrong are ordinary ones. A `);` inside a closure or a trailing comment
	 * both end a line the same way an array does, and an empty
	 * `return array();` closes on the line it opened on.
	 *
	 * Null when the file does not end in a returned array, in which case there
	 * is no unambiguous place to append and the caller says so rather than
	 * guessing.
	 *
	 * @param string $contents The file's contents.
	 * @return int|null Byte offset of the closing bracket, or null when there is no returned array.
	 */
	private function find_array_end( string $contents ): ?int {
		$tokens  = \token_get_all( $contents );
		$offsets = array();
		$offset  = 0;

		// Token positions are reported as lines, so the byte offsets are
		// accumulated here: an insertion point has to be a position in the file.
		foreach ( $tokens as $index => $token ) {
			$offsets[ $index ] = $offset;
			$offset           += \strlen( \is_array( $token ) ? $token[1] : $token );
		}

		// The last `return` written outside any braces. A configuration closure
		// may contain its own, and so may a helper function declared above --
		// both sit inside braces, and neither is the returned array.
		$return_index = null;
		$braces       = 0;

		foreach ( $tokens as $index => $token ) {
			$text = \is_array( $token ) ? $token[1] : $token;

			// T_CURLY_OPEN and T_DOLLAR_OPEN_CURLY_BRACES are the interpolation
			// forms inside a double-quoted string; each still closes with a
			// plain `}`, so both have to count or the depth drifts negative.
			if ( '{' === $text || '${' === $text ) {
				++$braces;
			} elseif ( '}' === $text ) {
				--$braces;
			} elseif ( 0 === $braces && \is_array( $token ) && T_RETURN === $token[0] ) {
				$return_index = $index;
			}
		}

		if ( null === $return_index ) {
			return null;
		}

		$depth = 0;
		$count = \count( $tokens );

		for ( $index = $return_index; $index < $count; $index++ ) {
			$text = \is_array( $tokens[ $index ] ) ? $tokens[ $index ][1] : $tokens[ $index ];

			// Both syntaxes: `array(` opens with a paren, `[` with a bracket.
			if ( '(' === $text || '[' === $text ) {
				++$depth;
			} elseif ( ')' === $text || ']' === $text ) {
				--$depth;

				if ( 0 === $depth ) {
					return $offsets[ $index ];
				}
			}
		}

		return null;
	}

	/**
	 * The `use` imports already in the file, keyed by the name they bind.
	 *
	 * Tokenized so that the word "use" inside a comment or a string is not
	 * mistaken for an import, and so an aliased `use X as Y` is recorded under
	 * the name actually in scope rather than under `X`.
	 *
	 * @param string $contents The file's contents.
	 * @return array<string, string> Bound name to fully qualified class name.
	 */
	private function get_imports( string $contents ): array {
		$tokens  = \token_get_all( $contents );
		$imports = array();
		$braces  = 0;

		foreach ( $tokens as $index => $token ) {
			$text = \is_array( $token ) ? $token[1] : $token;

			if ( '{' === $text || '${' === $text ) {
				++$braces;
				continue;
			}

			if ( '}' === $text ) {
				--$braces;
				continue;
			}

			// A `use` inside braces is a closure's inherited-variable list or a
			// trait import, neither of which binds a class name here.
			if ( 0 !== $braces || ! \is_array( $token ) || T_USE !== $token[0] ) {
				continue;
			}

			[ $name, $alias ] = $this->read_use_statement( $tokens, $index );

			if ( '' !== $name ) {
				$imports[ '' !== $alias ? $alias : $this->get_short_name( $name ) ] = $name;
			}
		}

		return $imports;
	}

	/**
	 * Read one `use` statement, returning the class it names and its alias.
	 *
	 * @param array<int, array{0: int, 1: string}|string> $tokens The tokenized file.
	 * @param int                                         $index  Index of the T_USE token.
	 * @return array{0: string, 1: string} The class name and its alias, either possibly empty.
	 */
	private function read_use_statement( array $tokens, int $index ): array {
		$name  = '';
		$alias = '';
		$after = false;
		$count = \count( $tokens );

		for ( $position = $index + 1; $position < $count; $position++ ) {
			$token = $tokens[ $position ];
			$text  = \is_array( $token ) ? $token[1] : $token;

			if ( ';' === $text ) {
				break;
			}

			// A grouped or function/const import is not a plain class import,
			// and nothing here needs to resolve one.
			if ( '{' === $text || ( \is_array( $token ) && \in_array( $token[0], array( T_FUNCTION, T_CONST ), true ) ) ) {
				return array( '', '' );
			}

			if ( \is_array( $token ) && T_AS === $token[0] ) {
				$after = true;
				continue;
			}

			if ( ! \is_array( $token ) || T_WHITESPACE === $token[0] ) {
				continue;
			}

			if ( $after ) {
				$alias .= $text;
				continue;
			}

			$name .= $text;
		}

		return array( \ltrim( $name, '\\' ), $alias );
	}

	/**
	 * How a class should be written in an entry, importing it when it can be.
	 *
	 * Imported under its short name unless that name is already bound to a
	 * different class -- a consumer's own `Path` module beside the toolkit's,
	 * say -- in which case the entry stays fully qualified rather than
	 * silently pointing at the wrong one.
	 *
	 * @param string                $class_name Fully qualified module class name.
	 * @param array<string, string> $imports    Names already bound, added to when one is claimed.
	 * @param string[]              $new_uses   Classes needing an import, appended to.
	 * @return string The name to write in the entry.
	 */
	private function resolve_reference( string $class_name, array &$imports, array &$new_uses ): string {
		$short = $this->get_short_name( $class_name );

		if ( isset( $imports[ $short ] ) ) {
			return $imports[ $short ] === $class_name ? $short : '\\' . $class_name;
		}

		$imports[ $short ] = $class_name;
		$new_uses[]        = $class_name;

		return $short;
	}

	/**
	 * Add `use` statements for classes the new entries reference.
	 *
	 * Placed immediately above the returned array rather than at the top of the
	 * file: the imports then sit directly above their only use, and the file's
	 * header comment and `declare` keep the first lines.
	 *
	 * @param string   $contents The file's contents, entries already added.
	 * @param string[] $new_uses Fully qualified class names to import.
	 * @return string
	 */
	private function add_imports( string $contents, array $new_uses ): string {
		if ( array() === $new_uses ) {
			return $contents;
		}

		$statements = '';

		foreach ( $new_uses as $class_name ) {
			$statements .= 'use ' . $class_name . ";\n";
		}

		/*
		 * Joined onto the existing block when there is one. Inserting above the
		 * `return` instead put a blank line between every import, since each
		 * command adding a module brought its own separator -- a plugin that had
		 * run `add` a dozen times ended up with more gaps than imports.
		 */
		$offset = $this->find_imports_end( $contents );

		if ( null !== $offset ) {
			return \substr( $contents, 0, $offset ) . "\n" . \rtrim( $statements, "\n" ) . \substr( $contents, $offset );
		}

		$offset = $this->find_return_start( $contents );

		if ( null === $offset ) {
			return $contents;
		}

		// No block yet, so this one opens it and needs the blank line after.
		return \substr( $contents, 0, $offset ) . $statements . "\n" . \substr( $contents, $offset );
	}

	/**
	 * The byte offset just past the last top-level `use` statement's semicolon.
	 *
	 * @param string $contents The file's contents.
	 * @return int|null Null when the file imports nothing.
	 */
	private function find_imports_end( string $contents ): ?int {
		$tokens = \token_get_all( $contents );
		$offset = 0;
		$braces = 0;
		$end    = null;
		$in_use = false;

		foreach ( $tokens as $token ) {
			$text = \is_array( $token ) ? $token[1] : $token;

			if ( '{' === $text || '${' === $text ) {
				++$braces;
			} elseif ( '}' === $text ) {
				--$braces;
			} elseif ( 0 === $braces && \is_array( $token ) && T_USE === $token[0] ) {
				$in_use = true;
			} elseif ( $in_use && ';' === $text ) {
				$in_use = false;
				$end    = $offset + 1;
			}

			$offset += \strlen( $text );
		}

		return $end;
	}

	/**
	 * The byte offset of the `return` that yields the file's array.
	 *
	 * @param string $contents The file's contents.
	 * @return int|null
	 */
	private function find_return_start( string $contents ): ?int {
		$tokens = \token_get_all( $contents );
		$offset = 0;
		$braces = 0;
		$found  = null;

		foreach ( $tokens as $token ) {
			$text = \is_array( $token ) ? $token[1] : $token;

			if ( '{' === $text || '${' === $text ) {
				++$braces;
			} elseif ( '}' === $text ) {
				--$braces;
			} elseif ( 0 === $braces && \is_array( $token ) && T_RETURN === $token[0] ) {
				$found = $offset;
			}

			$offset += \strlen( $text );
		}

		return $found;
	}

	/**
	 * The last segment of a fully qualified class name.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return string
	 */
	private function get_short_name( string $class_name ): string {
		$position = \strrpos( $class_name, '\\' );

		return false === $position ? $class_name : \substr( $class_name, $position + 1 );
	}

	/**
	 * Whether a class is already declared in the file's contents.
	 *
	 * Matched fully qualified with or without a leading separator, since either
	 * is valid PHP and a consumer may have written one by hand, and also under
	 * the short name whenever an import binds that name to this class.
	 *
	 * @param string                $contents   The file's contents.
	 * @param array<string, string> $imports    Names bound by the file's `use` statements.
	 * @param string                $class_name Fully qualified module class name.
	 * @return bool
	 */
	private function has_module( string $contents, array $imports, string $class_name ): bool {
		if ( \str_contains( $contents, '\\' . $class_name . '::class' )
			|| \str_contains( $contents, $class_name . '::class' ) ) {
			return true;
		}

		foreach ( $imports as $bound => $imported ) {
			if ( $imported === $class_name && \str_contains( $contents, $bound . '::class' ) ) {
				return true;
			}
		}

		return false;
	}
}
