<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The docblock parser behind `composer docs`, at the seam where it guesses.
 *
 * `composer docs:check` cannot cover this. It regenerates the pages and diffs
 * them against the committed copies, so a parser that corrupts a page corrupts
 * the committed copy in the same way and the diff comes back empty -- the guard
 * agrees with itself indefinitely. These assertions read the parser's output
 * directly, which is the only place the corruption is visible.
 *
 * @covers ::zestry_extract_custom_tags
 */
final class DocblockExtractorTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		require_once dirname( __DIR__, 3 ) . '/bin/docs/extractor.php';
	}

	/**
	 * A multi-line bullet must not be mistaken for the start of the sample.
	 *
	 * `zestry_looks_like_code()` calls any indented line code, and a markdown list
	 * item's continuation line is indented by definition. That opened the code
	 * block on it and swallowed everything after -- the rest of the list, the
	 * prose under it, and the real fenced sample -- into one fence. Fence counts
	 * stayed balanced, so nothing downstream noticed.
	 */
	public function test_a_multi_line_bullet_stays_in_the_description(): void {
		$parsed = zestry_extract_custom_tags(
			"@example Which properties get injected\n"
				. "Two cases are worth knowing:\n"
				. "\n"
				. "- **`private` is never injected.** Reflection cannot reach a private\n"
				. "  property declared on an ancestor class, so it would work on the\n"
				. "  declaring class and stop working in every subclass.\n"
				. "- **`#[NoInject]`** opts a property out.\n"
				. "\n"
				. "```\n"
				. "class Reports extends Service {\n"
				. "}\n"
				. "```\n"
		);

		$this->assertCount( 1, $parsed['examples'] );
		$block = $parsed['examples'][0];

		// Both bullets survive, and the continuation stays with its own item.
		$this->assertStringContainsString( 'private` is never injected', $block['description'] );
		$this->assertStringContainsString( 'stop working in every subclass', $block['description'] );
		$this->assertStringContainsString( 'NoInject', $block['description'] );

		// The fence decides what the sample is, and it holds only the sample.
		$this->assertSame( "class Reports extends Service {\n}", $block['code'] );
		$this->assertStringNotContainsString( 'NoInject', $block['code'] );
	}

	/**
	 * Prose after the sample belongs to the block, not to the class.
	 *
	 * Handing it to the class's own prose surfaced it above the section it was
	 * written for, where it referred to a sample the reader had not reached.
	 */
	public function test_prose_after_a_fenced_sample_stays_with_the_block(): void {
		$parsed = zestry_extract_custom_tags(
			"@example Doing something\n"
				. "Lead-in prose.\n"
				. "\n"
				. "```\n"
				. "\$x = 1;\n"
				. "```\n"
				. "\n"
				. "The closing note that explains the sample.\n"
		);

		$block = $parsed['examples'][0];

		$this->assertSame( 'Lead-in prose.', $block['description'] );
		$this->assertSame( '$x = 1;', $block['code'] );
		$this->assertSame( 'The closing note that explains the sample.', $block['after'] );
		$this->assertStringNotContainsString( 'closing note', $parsed['body'] );
	}

	/**
	 * Two samples in one block concatenate, and keep the blank line between
	 * them. Reconsidering the first one when the second fence opens would hand
	 * a whole working sample to the description, where it renders as mangled
	 * prose.
	 */
	public function test_two_fenced_samples_in_one_block_are_kept_separate(): void {
		$parsed = zestry_extract_custom_tags(
			"@example A module\n"
				. "Lead-in.\n"
				. "\n"
				. "```\n"
				. "class Shortcode extends Module {\n"
				. "}\n"
				. "```\n"
				. "\n"
				. "```\n"
				. "// bootstrap.php\n"
				. "return array();\n"
				. "```\n"
		);

		$block = $parsed['examples'][0];

		$this->assertSame( 'Lead-in.', $block['description'] );
		$this->assertSame(
			"class Shortcode extends Module {\n}\n\n// bootstrap.php\nreturn array();",
			$block['code']
		);
	}

	/**
	 * A block with no fence has nothing but the heuristic to go on, so it keeps
	 * working exactly as before -- two docblocks in the toolkit still rely on it.
	 */
	public function test_an_unfenced_block_still_falls_back_to_the_heuristic(): void {
		$parsed = zestry_extract_custom_tags(
			"@example Something\n"
				. "Lead-in prose.\n"
				. "\n"
				. "    \$plugin->get( Path::class );\n"
		);

		$block = $parsed['examples'][0];

		$this->assertSame( 'Lead-in prose.', $block['description'] );
		$this->assertStringContainsString( 'Path::class', $block['code'] );
	}

}
