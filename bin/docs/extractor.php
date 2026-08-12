<?php

/**
 * DevTools: shared docblock extraction helpers
 */

declare( strict_types=1 );

/**
 * This package's own root namespace, as PSR-4 maps it to `src/`.
 *
 * Named once because several call sites used to strip it by finding the first
 * backslash. That is exact only while the root is a single segment, and this one
 * is two -- so those derived `src/WPToolkit/Services/Path.php` for a file that
 * sits at `src/Services/Path.php`, a path wrong in a way that still looks like a
 * path. Measure the root, never count it.
 */
const ZESTRY_ROOT_NAMESPACE = 'Zestry\\WPToolkit';

/**
 * A class name with this package's root namespace removed.
 *
 * @param string $class A fully-qualified class name inside the package.
 * @return string The remainder, still backslash-separated.
 */
function zestry_without_root_namespace( string $class ): string {
	return str_starts_with( $class, ZESTRY_ROOT_NAMESPACE . '\\' )
		? substr( $class, strlen( ZESTRY_ROOT_NAMESPACE ) + 1 )
		: $class;
}

/**
 * Where a page wants its contents list, if not straight after the title.
 *
 * Emitted as an ordinary line while the page is being built, then replaced by
 * zestry_insert_toc(). Never appears in a written page.
 */
const ZESTRY_TOC_MARKER = '<!-- zestry:toc -->';

/**
 * Strip the leading asterisks from a raw docblock body.
 *
 * @param string $raw The docblock contents between the opening and closing markers.
 * @return string The body with comment decoration removed.
 */
function zestry_strip_docblock( string $raw ): string {
	$lines = array_map(
		static function ( string $line ): string {
			return (string) preg_replace( '/^\s*\*\s?/', '', $line );
		},
		explode( "\n", $raw )
	);

	return rtrim( implode( "\n", $lines ) );
}

/**
 * Read the docblock immediately preceding a declaration.
 *
 * Anchored on the declaration rather than simply taking the first docblock in
 * the file: every file opens with a `{Area}: {Subject}` header, and taking that
 * instead yields the file's title where the symbol's own summary belongs.
 *
 * @param string $source      The full PHP source.
 * @param string $declaration A regex fragment matching the declaration, e.g. `class Ajax`.
 * @return string|null The stripped docblock body, or null when there is none.
 */
function zestry_docblock_before( string $source, string $declaration ): ?string {
	/*
	 * A PHP attribute may sit between the docblock and the declaration it
	 * documents -- `#[\Attribute( \Attribute::TARGET_PROPERTY )]` does, on both
	 * of this toolkit's own attribute classes -- so it has to be skipped over
	 * rather than breaking the adjacency this match depends on.
	 */
	$pattern = '#/\*\*((?:(?!\*/).)*?)\*/\s*(?:\#\[[^\n]*\]\s*)*' . $declaration . '#s';

	if ( ! preg_match( $pattern, $source, $matches ) ) {
		return null;
	}

	return zestry_strip_docblock( $matches[1] );
}

/**
 * Guess the language of a code block written without a fence.
 *
 * Only what the docblocks here actually contain, and only where the answer is
 * certain: a leading `<` that is not `<?php` is markup, and `import`/`export`
 * open a statement PHP has no form of. Everything else is PHP, which is what
 * nearly every block in this source is.
 *
 * A block that needs saying explicitly can say it -- an `@setup` or `@example`
 * fence takes an info string, and that always wins over this.
 *
 * @param string $code The block's text.
 * @return string A Markdown fence info string.
 */
function zestry_code_language( string $code ): string {
	$text = ltrim( $code );

	if ( str_starts_with( $text, '<' ) && ! str_contains( $code, '<?php' ) ) {
		return 'html';
	}

	if ( preg_match( '/^(import|export)\s/', $text ) ) {
		return 'js';
	}

	return 'php';
}

/**
 * Split a docblock body into prose and its indented code example.
 *
 * A docblock example is written as a four-space indented block, the convention
 * every class in this toolkit follows. Everything else is prose.
 *
 * @param string $body A stripped docblock body.
 * @return array{prose: string, example: string|null}
 */
function zestry_split_example( string $body ): array {
	/*
	 * Annotation tags are rendered from their own parsed form, so they are cut
	 * here before anything is split. The first tag takes the rest of the body
	 * with it: a multi-line tag body -- an `@stub` description, say -- does not
	 * start with `@`, so dropping only the tag's own line would leave that body
	 * behind and render it twice, once as prose and once as the tag.
	 */
	$body = (string) preg_replace( '/^@\w+.*$/ms', '', $body, 1 );

	$blocks  = array();
	$current = array();
	$pending = array();

	$flush = static function () use ( &$blocks, &$current, &$pending ): void {
		if ( array() !== $current ) {
			$blocks[] = array(
				'type' => 'code',
				'text' => trim( implode( "\n", $current ) ),
			);
			$current  = array();
		}

		$pending = array();
	};

	$fenced = false;

	foreach ( explode( "\n", $body ) as $line ) {
		/*
		 * A ``` fence says where the code starts, so indentation inside one means
		 * nothing but indentation. Without this the rule below fired on the
		 * *body* of a fenced sample and cut a code block out of the middle of it,
		 * leaving the fence markers behind in the prose -- which renders as a
		 * ```php block nested inside a bare ``` one, and a stray closing brace
		 * outside both. Fenced lines go to prose, where zestry_unwrap_prose()
		 * already tracks the same state and passes them through verbatim.
		 */
		if ( preg_match( '/^\s*```/', $line ) ) {
			$flush();

			$fenced   = ! $fenced;
			$blocks[] = array(
				'type' => 'prose',
				'text' => $line,
			);
			continue;
		}

		if ( $fenced ) {
			$blocks[] = array(
				'type' => 'prose',
				'text' => $line,
			);
			continue;
		}

		$is_indented = '' !== trim( $line ) && str_starts_with( $line, '    ' );

		if ( $is_indented ) {
			/*
			 * A blank line inside an example is only part of it if more indented
			 * code follows. Holding blanks back until then keeps two examples
			 * separated by prose from merging into one block.
			 */
			foreach ( $pending as $blank ) {
				$current[] = $blank;
			}

			$pending   = array();
			$current[] = substr( $line, 4 );
			continue;
		}

		if ( array() !== $current && '' === trim( $line ) ) {
			$pending[] = '';
			continue;
		}

		$flush();
		$blocks[] = array(
			'type' => 'prose',
			'text' => $line,
		);
	}

	$flush();

	$prose = array();

	foreach ( $blocks as $block ) {
		if ( 'prose' === $block['type'] ) {
			$prose[] = $block['text'];
		}
	}

	$prose    = implode( "\n", $prose );
	$examples = array();

	foreach ( $blocks as $block ) {
		if ( 'code' === $block['type'] ) {
			$examples[] = $block['text'];
		}
	}

	return array(
		'prose'    => zestry_render_inline_tags( zestry_unwrap_prose( (string) $prose ) ),
		'example'  => array() === $examples ? null : $examples[0],
		'examples' => $examples,
		'blocks'   => $blocks,
	);
}

/**
 * The leading paragraph of a docblock body, used as a summary.
 *
 * The whole paragraph, not the first line: a summary is wrapped to the column
 * limit like any other prose, so stopping at the newline would cut a sentence
 * mid-word.
 *
 * @param string $body A stripped docblock body.
 * @return string The summary, or an empty string.
 */
function zestry_summary( string $body ): string {
	$paragraph = array();

	foreach ( explode( "\n", $body ) as $line ) {
		if ( '' === trim( $line ) ) {
			if ( array() !== $paragraph ) {
				break;
			}

			continue;
		}

		$paragraph[] = trim( $line );
	}

	return zestry_render_inline_tags( implode( ' ', $paragraph ) );
}

/**
 * A docblock body with its summary paragraph and its tags removed.
 *
 * What is left is the explanation a reader needs but a one-line summary has no
 * room for -- which keys an argument takes, when to override, why a default is
 * what it is.
 *
 * @param string $body A stripped docblock body.
 * @return string The remaining prose, or an empty string.
 */
function zestry_description( string $body ): string {
	$lines   = explode( "\n", $body );
	$rest    = array();
	$tagged  = false;
	$started = false;
	$ended   = false;

	foreach ( $lines as $line ) {
		$trimmed = trim( $line );

		if ( ! $ended ) {
			if ( '' === $trimmed ) {
				$ended = $started;
			} else {
				$started = true;
			}

			continue;
		}

		/*
		 * The first tag ends the prose for good. Everything after it belongs to
		 * a tag -- including a tag's own indented body, which does not start
		 * with `@` and would otherwise read as description and be rendered
		 * twice, once here and once wherever that tag is rendered properly.
		 */
		if ( str_starts_with( $trimmed, '@' ) ) {
			$tagged = true;
		}

		if ( $tagged ) {
			continue;
		}

		$rest[] = $line;
	}

	/*
	 * Split before rendering, so an indented example inside a method's own
	 * docblock is fenced like one in a class docblock rather than published as
	 * a bare four-space block with no language -- same input, same output,
	 * whichever docblock it was written in.
	 */
	$split = zestry_split_example( trim( implode( "\n", $rest ) ) );

	return trim( implode( "\n", zestry_render_blocks( $split['blocks'] ) ) );
}

/**
 * The banner every generated page carries.
 *
 * @param string $source_path Repo-relative path the page was generated from.
 * @param string $regenerate  The composer script that rebuilds it.
 * @return string[] Markdown lines.
 */
function zestry_generated_banner( string $source_path, string $regenerate = 'composer docs' ): array {
	return array(
		'<!--',
		'    Generated from ' . $source_path . '.',
		'    Do not edit by hand: run `' . $regenerate . '` after changing the source.',
		'-->',
		'',
	);
}

/**
 * Parse `@param`, `@return` and `@throws` out of a docblock body.
 *
 * Descriptions may wrap onto following lines; a continuation is any line that
 * is not itself a tag. A tag with no description is dropped, since restating
 * the type the signature already shows adds nothing.
 *
 * @param string $body A stripped docblock body.
 * @return array{params: array<int, array{name: string, description: string}>, return: string, throws: array<int, array{type: string, description: string}>}
 */
function zestry_parse_tags( string $body ): array {
	$params  = array();
	$return  = '';
	$throws  = array();
	$current = null;

	foreach ( explode( "\n", $body ) as $line ) {
		$trimmed = trim( $line );

		/*
		 * The type may contain spaces -- `array<string, mixed>`, `array{a: int}`
		 * -- so it cannot be matched as a single \S+ run. Bracketed groups are
		 * consumed whole and everything before the first `$name` outside one is
		 * the type: a `callable(T $module, self $plugin): void` names variables
		 * of its own, and anchoring on the first `$` would take `$module` for
		 * the parameter and leave the rest of the type as its description.
		 */
		if ( preg_match( '/^@param\s+((?:<[^>]*>|\{[^}]*\}|\([^)]*\)|:\s*|[^\s<{($])+)\s+\$(\w+)\s*(.*)$/', $trimmed, $match ) ) {
			$params[] = array(
				'name'        => $match[2],
				'description' => trim( $match[3] ),
			);
			$current  = 'param';
			continue;
		}

		// A type the pattern above cannot bracket-match still names its
		// parameter, so fall back to the first `$name` rather than dropping it.
		if ( preg_match( '/^@param\s+.+?\s+\$(\w+)\s*(.*)$/', $trimmed, $match ) ) {
			$params[] = array(
				'name'        => $match[1],
				'description' => trim( $match[2] ),
			);
			$current  = 'param';
			continue;
		}

		/*
		 * A return type has no `$name` to anchor on, so the type is matched by
		 * consuming any bracketed group whole -- `array<string, mixed>|false`
		 * contains a space that would otherwise end the type and leave
		 * `mixed>|false` as the description.
		 */
		if ( preg_match( '/^@return\s+((?:[^\s<{(]|<[^>]*>|\{[^}]*\}|\([^)]*\))+)\s*(.*)$/', $trimmed, $match ) ) {
			$return  = trim( $match[2] );
			$current = 'return';
			continue;
		}

		if ( preg_match( '/^@throws\s+(\S+)\s*(.*)$/', $trimmed, $match ) ) {
			$throws[] = array(
				'type'        => ltrim( $match[1], '\\' ),
				'description' => trim( $match[2] ),
			);
			$current  = 'throws';
			continue;
		}

		if ( str_starts_with( $trimmed, '@' ) ) {
			$current = null;
			continue;
		}

		// A continuation line belongs to whichever tag opened last.
		if ( null !== $current && '' !== $trimmed ) {
			if ( 'param' === $current && array() !== $params ) {
				$params[ count( $params ) - 1 ]['description'] .= ' ' . $trimmed;
			} elseif ( 'return' === $current ) {
				$return .= ' ' . $trimmed;
			} elseif ( 'throws' === $current && array() !== $throws ) {
				$throws[ count( $throws ) - 1 ]['description'] .= ' ' . $trimmed;
			}
		}
	}

	foreach ( $params as $index => $param ) {
		$params[ $index ]['description'] = zestry_render_inline_tags( $param['description'] );
	}

	foreach ( $throws as $index => $throw ) {
		$throws[ $index ]['description'] = zestry_render_inline_tags( $throw['description'] );
	}

	$return = zestry_render_inline_tags( $return );

	return array(
		'params' => array_values(
			array_filter(
				$params,
				static function ( array $param ): bool {
					return '' !== $param['description'];
				}
			)
		),
		'return' => trim( $return ),
		'throws' => $throws,
	);
}

/**
 * Join a docblock's hard-wrapped lines back into flowing paragraphs.
 *
 * A docblock is wrapped to roughly 80 columns for reading in an editor, but
 * markdown renders those breaks literally, so a paragraph arrives broken
 * mid-sentence. Blank lines separate paragraphs and are preserved; so are
 * list items, fenced blocks and indented code, where the break is meaningful.
 *
 * @param string $prose A stripped docblock body with the example removed.
 * @return string The same prose with each paragraph on one line.
 */
function zestry_unwrap_prose( string $prose ): string {
	$out    = array();
	$buffer = array();

	$flush = static function () use ( &$out, &$buffer ): void {
		if ( array() !== $buffer ) {
			$out[]  = implode( ' ', $buffer );
			$buffer = array();
		}
	};

	$quoted = false;
	$fenced = false;

	$flush_quote = static function () use ( &$out, &$buffer, &$quoted ): void {
		if ( array() !== $buffer ) {
			$out[] = '> ' . implode( ' ', $buffer );
		}

		$buffer = array();
		$quoted = false;
	};

	foreach ( explode( "\n", $prose ) as $line ) {
		$trimmed = rtrim( $line );

		/*
		 * A blockquote's own text wraps in the source like any other prose, so
		 * it is unwrapped the same way and re-prefixed on the way out. Leaving
		 * the source breaks in would render the quote ragged while the prose
		 * around it reflows. An alert marker (`> [!NOTE]`) has to keep its own
		 * line, and a `>` on its own separates paragraphs within the quote.
		 */
		if ( preg_match( '/^\s*>\s?(.*)$/', $trimmed, $quote ) ) {
			$content = trim( $quote[1] );

			if ( ! $quoted ) {
				$flush();
				$quoted = true;
			}

			if ( '' === $content || preg_match( '/^\[!\w+\]$/', $content ) ) {
				$flush_quote();
				$out[]  = '' === $content ? '>' : '> ' . $content;
				$quoted = true;
				continue;
			}

			$buffer[] = $content;
			continue;
		}

		if ( $quoted ) {
			$flush_quote();
		}

		// A blank line ends the paragraph and is kept as the separator.
		if ( '' === trim( $trimmed ) ) {
			$flush();
			$out[] = '';
			continue;
		}

		/*
		 * Inside a fence, every line stands alone: it is code, and a line break
		 * in code is not a soft wrap. Only the fence markers used to be treated
		 * as structural, so the lines *between* them fell through to the
		 * paragraph buffer and were joined -- which turned a two-statement
		 * JavaScript sample into one line, and is why the fence needs state
		 * rather than a pattern.
		 */
		if ( preg_match( '/^\s*```/', $trimmed ) ) {
			$flush();
			$out[]  = $trimmed;
			$fenced = ! $fenced;
			continue;
		}

		if ( $fenced ) {
			$out[] = $trimmed;
			continue;
		}

		/*
		 * Structural lines stand alone: their breaks carry meaning. A bullet
		 * needs whitespace after its marker, which is what tells `* item` from
		 * a paragraph opening with `**bold**` -- matching the latter as a list
		 * would strand the rest of its sentence in a paragraph of its own.
		 */
		if ( preg_match( '/^\s{4,}\S/', $trimmed ) || preg_match( '/^\s*([-*+]\s|\d+\.\s|#{1,6}\s|\|)/', $trimmed ) ) {
			$flush();
			$out[] = $trimmed;
			continue;
		}

		$buffer[] = trim( $trimmed );
	}

	if ( $quoted ) {
		$flush_quote();
	}

	$flush();

	return trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $out ) ) );
}

/**
 * Convert phpDocumentor inline tags into plain markdown.
 *
 * `{@see Foo::bar()}` is meaningful to phpDocumentor's HTML renderer but is
 * literal noise in a markdown file, so it becomes inline code. `{@link}` is
 * treated the same way.
 *
 * @param string $text Any docblock-derived text.
 * @return string The text with inline tags rendered.
 */
function zestry_render_inline_tags( string $text ): string {
	return (string) preg_replace_callback(
		'/\{@(?:see|link)\s+([^}]+)\}/',
		static function ( array $matches ): string {
			$target = trim( $matches[1] );

			// A {@see} may carry a description after the target; keep it as the text.
			if ( preg_match( '/^(\S+)\s+(.+)$/', $target, $parts ) ) {
				return $parts[2];
			}

			// Strip a leading namespace: the short name is what a reader recognizes.
			$short = (string) preg_replace( '/^.*\\\\/', '', $target );

			return '`' . $short . '`';
		},
		$text
	);
}

/**
 * Render a docblock's prose and examples in the order they were written.
 *
 * Keeping the original order matters once a docblock holds more than one
 * example: the sentence introducing each block has to stay attached to it, and
 * a naive "all prose, then all code" split both reorders them and merges the
 * blocks together.
 *
 * @param array<int, array{type: string, text: string}> $blocks Blocks from zestry_split_example().
 * @return string[] Markdown lines.
 */
function zestry_render_blocks( array $blocks ): array {
	$lines = array();
	$prose = array();

	$flush_prose = static function () use ( &$lines, &$prose ): void {
		if ( array() === $prose ) {
			return;
		}

		$text = preg_replace( '/^@\w+.*$/m', '', implode( "\n", $prose ) );
		$text = zestry_render_inline_tags( zestry_unwrap_prose( (string) $text ) );

		if ( '' !== trim( $text ) ) {
			$lines[] = $text;
			$lines[] = '';
		}

		$prose = array();
	};

	foreach ( $blocks as $block ) {
		if ( 'prose' === $block['type'] ) {
			$prose[] = $block['text'];
			continue;
		}

		$flush_prose();

		$lines[] = '```' . zestry_code_language( $block['text'] );
		$lines[] = $block['text'];
		$lines[] = '```';
		$lines[] = '';
	}

	$flush_prose();

	return $lines;
}

/**
 * Pull custom documentation tags out of a docblock body.
 *
 * These are this toolkit's own tags, not phpDocumentor's, and exist so a
 * docblock can say what a block of code is *for* instead of the generator
 * guessing from indentation and heuristics:
 *
 *     @setup     how to register/configure the module
 *     @example   a usage sample, with an optional caption after the tag name
 *     @discovers a base class a discovered file returns, for a module whose
 *                own guard holds it in a variable and so cannot be read
 *
 * A tag's body is every following line indented by at least one space, so it
 * reads naturally in the source and needs no fence. `@example Caption here`
 * puts the caption above the block on the page.
 *
 * @param string $body A stripped docblock body.
 * @return array{setup: array<int, array{caption: string, description: string, code: string, language: string}>, examples: array<int, array{caption: string, description: string, code: string, language: string}>, body: string}
 */
function zestry_extract_custom_tags( string $body ): array {
	$setup       = array();
	$examples    = array();
	$rest        = array();
	$current     = null;
	$caption     = '';
	$code        = array();
	$description = array();
	$language    = '';
	$had_fence   = false;
	$after       = array();

	$flush = static function () use ( &$current, &$caption, &$code, &$description, &$language, &$had_fence, &$after, &$setup, &$examples ): void {
		if ( null === $current ) {
			return;
		}

		$block = array(
			'caption'     => $caption,
			'description' => zestry_render_inline_tags( zestry_unwrap_prose( trim( implode( "\n", $description ) ) ) ),
			'code'        => trim( implode( "\n", $code ) ),
			'after'       => zestry_render_inline_tags( zestry_unwrap_prose( trim( implode( "\n", $after ) ) ) ),
			'language'    => '' !== $language ? $language : 'php',
		);

		if ( '' !== $block['code'] ) {
			if ( 'setup' === $current ) {
				$setup[] = $block;
			} else {
				$examples[] = $block;
			}
		}

		$current     = null;
		$caption     = '';
		$code        = array();
		$description = array();
		$language    = '';
		$had_fence   = false;
		$after       = array();
	};

	$fenced = false;

	foreach ( explode( "\n", $body ) as $line ) {
		$trimmed = trim( $line );

		if ( ! $fenced && preg_match( '/^@(setup|example)\s*(.*)$/', $trimmed, $match ) ) {
			$flush();
			$current = $match[1];
			$caption = trim( $match[2] );
			continue;
		}

		/*
		 * `@rationale` is a terminator: everything from it to the next tag is
		 * for whoever maintains this file, and is not published.
		 *
		 * A class docblock in this toolkit serves two readers -- someone working
		 * on the code, and whoever reads the page generated from it -- and the
		 * two want different things. The reasoning behind a decision belongs
		 * next to the code it governs, especially here, where the source ships
		 * to your plugin and you are invited to edit it. It does not belong on a
		 * reference page, where it sits between a reader and the thing they came
		 * to look up.
		 *
		 * Publish-by-default is deliberate: a tag that had to be added before
		 * anything appeared would make a new class silently produce an empty
		 * page. A forgotten `@rationale` publishes a paragraph that should not
		 * have been -- visible, greppable, and caught by zestry_check_prose().
		 */
		if ( ! $fenced && preg_match( '/^@rationale\b/', $trimmed ) ) {
			$flush();
			$current = 'rationale';
			continue;
		}

		if ( 'rationale' === $current ) {
			// Runs to the next tag, like every other multi-line tag body.
			if ( preg_match( '/^@\w+/', $trimmed ) && ! preg_match( '/^@rationale\b/', $trimmed ) ) {
				$current = null;
			} else {
				continue;
			}
		}

		if ( null !== $current ) {
			/*
			 * A ``` fence marks the code explicitly, so prose can sit between
			 * the caption and the sample without the generator having to guess
			 * where the code starts. Everything outside the fence is
			 * description; everything inside is the block.
			 */
			if ( str_starts_with( $trimmed, '```' ) ) {
				if ( ! $fenced ) {
					// An info string after the opening fence names the language.
					$language = trim( substr( $trimmed, 3 ) );

					/*
					 * The fence decides, so anything zestry_looks_like_code() had
					 * already guessed into $code was prose after all -- hand it
					 * back. Without this the guess wins by arriving first: a
					 * markdown list item's continuation line is indented, which
					 * reads as code, so the block opened on it and swallowed
					 * every following line -- the rest of the list, the prose
					 * under it, and the real sample -- into one fence. The page
					 * still had balanced fences, so nothing downstream noticed.
					 *
					 * Only lines before the *first* fence are reconsidered. A
					 * block with no fence at all keeps the heuristic, which is
					 * the only thing an older docblock has to go on -- and a
					 * block with two samples must not have the first one taken
					 * for prose when the second one opens.
					 */
					if ( ! $had_fence && array() !== $code ) {
						$description = array_merge( $description, $code );
						$code        = array();
					}

					/*
					 * A second fence in the same block is another sample, not a
					 * new block -- the model holds one `code` per block, so the
					 * two are concatenated. They need the blank line between
					 * them that the trailing-prose branch above swallowed, and
					 * anything that branch collected was between the samples
					 * rather than after them, so it goes back with the code.
					 */
					if ( $had_fence && array() !== $code ) {
						$code[] = '';
						$code   = array_merge( $code, $after );
						$after  = array();
					}

					$had_fence = true;
				}

				$fenced = ! $fenced;
				continue;
			}

			if ( $fenced ) {
				$code[] = $line;
				continue;
			}

			/*
			 * Prose after a fenced sample closes belongs to the block that
			 * sample illustrates -- the paragraph that says what to take from
			 * it. The unfenced path below would instead end the block and hand
			 * the paragraph to the class's own prose, where it surfaces above
			 * the section it was written for and refers to a sample the reader
			 * has not reached yet.
			 *
			 * Only for a fenced block: an unfenced one has nothing marking
			 * where its code stopped, so blank-line-then-prose stays the only
			 * signal it has.
			 */
			if ( $had_fence && array() !== $code ) {
				if ( '' !== $trimmed || array() !== $after ) {
					$after[] = $line;
				}

				continue;
			}

			/*
			 * Before any code is seen, a non-code line is the block's own
			 * description -- the prose that belongs between its title and its
			 * sample. Blank lines are kept too, so a multi-paragraph
			 * description survives; without that the blank would open $code and
			 * every later paragraph would be mistaken for the sample.
			 * Once code has started, the old blank-line-then-prose rule still
			 * closes an unfenced block.
			 */
			if ( array() === $code && ! zestry_looks_like_code( $line ) ) {
				if ( '' !== $trimmed || array() !== $description ) {
					$description[] = $line;
				}

				continue;
			}

			if ( '' === $trimmed ) {
				$blank  = true;
				$code[] = $line;
				continue;
			}

			if ( ( $blank ?? false ) && ! zestry_looks_like_code( $line ) ) {
				$flush();
				$blank  = false;
				$rest[] = $line;
				continue;
			}

			$blank  = false;
			$code[] = $line;
			continue;
		}

		$rest[] = $line;
	}

	$flush();

	return array(
		'setup'    => $setup,
		'examples' => $examples,
		'body'     => implode( "\n", $rest ),
	);
}

/**
 * List a class's public constants with their descriptions.
 *
 * Private constants are implementation detail; a public one is part of the
 * surface a consumer can reference, such as `Options::DEFAULT_GROUP_NAME`.
 *
 * @param string $source The full PHP source of the class file.
 * @return array<int, array{name: string, value: string, summary: string, deprecated: string}>
 */
function zestry_public_constants( string $source ): array {
	$constants = array();
	$tokens    = token_get_all( $source );
	$docblock  = '';
	$visible   = true;

	foreach ( $tokens as $index => $token ) {
		if ( is_array( $token ) && T_DOC_COMMENT === $token[0] ) {
			$docblock = $token[1];

			continue;
		}

		if ( is_array( $token ) && in_array( $token[0], array( T_PUBLIC, T_PRIVATE, T_PROTECTED ), true ) ) {
			$visible = T_PUBLIC === $token[0];

			continue;
		}

		if ( ! is_array( $token ) || T_CONST !== $token[0] ) {
			// Anything else between a docblock and a declaration means that
			// docblock belonged to something other than a constant.
			if ( is_array( $token ) && in_array( $token[0], array( T_FUNCTION, T_VARIABLE, T_CLASS ), true ) ) {
				$docblock = '';
				$visible  = true;
			}

			continue;
		}

		[ $name, $value ] = zestry_read_constant( $tokens, $index );

		if ( '' === $name ) {
			continue;
		}

		if ( $visible ) {
			$body       = zestry_strip_docblock( zestry_docblock_body( $docblock ) );
			$deprecated = '';

			if ( preg_match( '/@deprecated\s+(.+?)(?=\n@|\z)/s', $body, $tag ) ) {
				$deprecated = trim( (string) preg_replace( '/\s+/', ' ', $tag[1] ) );
			}

			$constants[] = array(
				'name'       => $name,
				'value'      => $value,
				'summary'    => zestry_render_inline_tags( zestry_summary( $body ) ),
				'deprecated' => $deprecated,
			);
		}

		$docblock = '';
		$visible  = true;
	}

	return $constants;
}

/**
 * One constant's name and value, read forward from its `const` token.
 *
 * @param array<int, array{0: int, 1: string}|string> $tokens The tokenized source.
 * @param int                                        $index  The index of the `const` token.
 * @return array{0: string, 1: string} The name, and the value as written.
 */
function zestry_read_constant( array $tokens, int $index ): array {
	$name  = '';
	$value = '';

	for ( $next = $index + 1; $next < count( $tokens ); $next++ ) {
		$token = $tokens[ $next ];

		if ( ';' === $token ) {
			break;
		}

		if ( '' === $name ) {
			if ( is_array( $token ) && T_STRING === $token[0] ) {
				$name = $token[1];
			}

			continue;
		}

		// Everything after the `=`, verbatim, so an array constant reads as it
		// was written rather than as a var_export of it.
		if ( '=' === $token || ( is_array( $token ) && T_WHITESPACE === $token[0] && '' === $value ) ) {
			continue;
		}

		$value .= is_array( $token ) ? $token[1] : $token;
	}

	return array( $name, trim( (string) preg_replace( '/\s+/', ' ', $value ) ) );
}

/**
 * A doc comment's inner text, without its delimiters.
 *
 * @param string $docblock The raw `/** ... *\/` token, or an empty string.
 * @return string
 */
function zestry_docblock_body( string $docblock ): string {
	if ( '' === $docblock ) {
		return '';
	}

	return (string) preg_replace( '#^/\*\*|\*/$#', '', $docblock );
}

/**
 * Build a table of contents from a page's own headings.
 *
 * Anchors follow GitHub's rule: lowercase, non-word characters dropped,
 * whitespace to hyphens -- so "### `set_pages_root()`" becomes
 * `#set_pages_root`. Underscores survive, backticks and parentheses do not.
 *
 * @param string[] $lines The rendered page, before the TOC is inserted.
 * @param int      $min   The shallowest heading level to include.
 * @param int      $max   The deepest heading level to include.
 * @return string[] Markdown lines, empty when the page has too few headings.
 */
function zestry_build_toc( array $lines, int $min = 2, int $max = 3 ): array {
	$entries  = array();
	$in_fence = false;

	foreach ( $lines as $line ) {
		// A "### heading" inside a code sample is not a heading.
		if ( str_starts_with( ltrim( $line ), '```' ) ) {
			$in_fence = ! $in_fence;
			continue;
		}

		if ( $in_fence || ! preg_match( '/^(#{1,6})\s+(.+)$/', $line, $match ) ) {
			continue;
		}

		$level = strlen( $match[1] );

		if ( $level < $min || $level > $max ) {
			continue;
		}

		$entries[] = array(
			'level' => $level,
			'text'  => trim( $match[2] ),
		);
	}

	$link = static function ( array $entry ): string {
		/*
		 * A method is listed by name alone: the parameter list belongs in the
		 * entry, not in an index, where a column of full signatures is harder to
		 * scan than the names it exists to help you find. Cosmetic only -- the
		 * link still points at the heading's own anchor, which is built from the
		 * full heading text.
		 *
		 * Constants keep their backticks: `DEFAULT_VIEWS_ROOT` in a proportional
		 * face reads as prose rather than as an identifier.
		 */
		$label = $entry['text'];

		if ( preg_match( '/^`?(\w+)\(.*\)`?$/', $label, $method ) ) {
			$label = $method[1];
		}

		return sprintf( '[%s](#%s)', $label, zestry_anchor( $entry['text'] ) );
	};

	/*
	 * One line of separated links rather than a bulleted list, and sections
	 * only: a page with thirty methods gives a nested list its own screenful,
	 * and every method already appears as a heading a few lines further down.
	 * What the list is for is finding the section.
	 */
	$sections = array();

	foreach ( $entries as $entry ) {
		if ( $min === $entry['level'] ) {
			$sections[] = $link( $entry );
		}
	}

	// Below three, the line is longer than the sections it indexes.
	if ( count( $sections ) < 3 ) {
		return array();
	}

	return array( implode( ' &nbsp;·&nbsp; ', $sections ), '' );
}

/**
 * Convert heading text into the anchor GitHub generates for it.
 *
 * @param string $text The heading text, markdown included.
 * @return string The anchor, without its leading `#`.
 */
function zestry_anchor( string $text ): string {
	/*
	 * Mirrors github-slugger exactly: lowercase, delete disallowed characters,
	 * then replace each literal space with a hyphen. Deliberately no trimming
	 * and no collapsing of repeated hyphens -- GitHub does neither, so a
	 * heading ending in `)` really does anchor to a trailing hyphen, and
	 * "tidying" the slug here would break every link it generates.
	 */
	$anchor = strtolower( $text );
	$anchor = (string) preg_replace( '/[^\w\s-]/u', '', $anchor );

	return str_replace( ' ', '-', $anchor );
}

/**
 * Insert a table of contents after a page's title.
 *
 * @param string[] $page The rendered page.
 * @return string[] The page with a TOC, or unchanged when there is little to index.
 */
function zestry_insert_toc( array $page ): array {
	$toc = zestry_build_toc( $page );

	if ( array() === $toc ) {
		// The marker is internal; it never survives into the written page.
		return array_values(
			array_filter(
				$page,
				static function ( string $line ): bool {
					return ZESTRY_TOC_MARKER !== $line;
				}
			)
		);
	}

	/*
	 * A page can place the list itself with a marker, so the opening -- what the
	 * module is, how to install it -- reads before an index of its sections. The
	 * marker is dropped whether or not a list was built.
	 */
	foreach ( $page as $index => $line ) {
		if ( ZESTRY_TOC_MARKER === $line ) {
			return array_merge(
				array_slice( $page, 0, $index ),
				$toc,
				array_slice( $page, $index + 1 )
			);
		}
	}

	foreach ( $page as $index => $line ) {
		if ( str_starts_with( $line, '# ' ) ) {
			// After the title and the blank line that follows it.
			$at = $index + 2;

			return array_merge(
				array_slice( $page, 0, $at ),
				$toc,
				array_slice( $page, $at )
			);
		}
	}

	return $page;
}

/**
 * Write a rendered page, normalising its typography on the way out.
 *
 * The one place a page reaches disk, so a convention applied here is applied
 * everywhere. Docblock prose is written in a PHP comment, where an em dash is
 * typed `--`; published unchanged it reads as a stray double hyphen, and the
 * generator's own template strings use a real dash beside it -- so the same
 * sentence could show both.
 *
 * Fence-aware, because inside a code block `--` is a flag: `wp zestry update
 * --dry-run` has to survive a pass that rewrites the prose around it.
 *
 * @param string   $path  Absolute path to write to.
 * @param string[] $lines The rendered page.
 * @return void
 */
function zestry_write_page( string $path, array $lines ): void {
	$fenced = false;

	foreach ( $lines as $index => $line ) {
		if ( preg_match( '/^\s*(```|~~~)/', $line ) ) {
			$fenced = ! $fenced;
			continue;
		}

		if ( $fenced ) {
			continue;
		}

		// Indented code blocks are code too, and are how a docblock's own
		// examples arrive when they were never fenced.
		if ( preg_match( '/^(?: {4}|\t)/', $line ) ) {
			continue;
		}

		$lines[ $index ] = zestry_typography( $line );
	}

	file_put_contents( $path, implode( "\n", $lines ) );
}

/**
 * Normalise one line of published prose.
 *
 * Split out so it can be tested and reused without a file write.
 *
 * @param string $line A single line of prose, never code.
 * @return string The line with docblock punctuation rendered as prose.
 */
function zestry_typography( string $line ): string {
	/*
	 * Inline code spans are quoted source and keep their punctuation, but the
	 * dash often sits directly against one -- "for a `Module` -- before" -- so
	 * they are masked rather than split around. Splitting made each span a
	 * boundary, and the pattern needs to see the words on both sides.
	 */
	$spans  = array();
	$masked = (string) preg_replace_callback(
		'/`[^`]*`/',
		static function ( array $span ) use ( &$spans ): string {
			$spans[] = $span[0];

			return "\x00" . ( count( $spans ) - 1 ) . "\x00";
		},
		$line
	);

	$masked = (string) preg_replace( '/(?<=\S) -- (?=\S)/', ' — ', $masked );

	return (string) preg_replace_callback(
		'/\x00(\d+)\x00/',
		static function ( array $placeholder ) use ( $spans ): string {
			return $spans[ (int) $placeholder[1] ];
		},
		$masked
	);
}

/**
 * Guess whether a line is code rather than prose.
 *
 * Used to decide where an `@example` body ends: a blank line followed by prose
 * closes the block, so a docblock can explain the next example without that
 * sentence being fenced with the previous one. Deliberately conservative --
 * anything that opens with PHP syntax, or is indented as a continuation, stays
 * in the block.
 *
 * @param string $line A single line from a tag body.
 * @return bool True when the line looks like code.
 */
function zestry_looks_like_code( string $line ): bool {
	if ( str_starts_with( $line, ' ' ) || str_starts_with( $line, "\t" ) ) {
		return true;
	}

	$trimmed = trim( $line );

	// A closing brace, a statement, a declaration, an attribute or a comment.
	return (bool) preg_match(
		'/^(\$|\}|\)|use\s|class\s|abstract\s|final\s|function\s|public\s|private\s|protected\s|return\s|if\s*\(|foreach\s*\(|\/\/|#\[|<\?php|echo\s|require|include)/',
		$trimmed
	);
}

/**
 * A pattern matching any kind of type declaration, by name.
 *
 * The generator's declaration patterns were `class`-only, which is why fifteen
 * types had no page: the four exceptions, `RestRoute` (the class every route
 * file subclasses), both REST attributes, `ParentMenu`'s ten menu cases, and
 * the `PluginAware`/`WithPlugin` pair. An enum, an interface and a trait are
 * each as much a thing a consumer writes against as a class is.
 *
 * @param string $name The type's short name, e.g. `ParentMenu`.
 * @return string A regex fragment matching its declaration.
 */
function zestry_type_declaration( string $name ): string {
	return '(?:abstract |final |readonly )*(?:class|interface|trait|enum) ' . preg_quote( $name, '#' ) . '\b';
}
