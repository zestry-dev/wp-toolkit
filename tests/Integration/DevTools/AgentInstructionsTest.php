<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\AgentInstructions;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The instructions `wp zt init` leaves for an agent working in the plugin.
 *
 * The point of them is that they are *rendered* from the toolkit's own rules
 * page rather than written a second time. A summary would be a second thing to
 * keep true, which is the failure the whole rules page exists to remove.
 *
 * @covers \Zestry\WPToolkit\DevTools\AgentInstructions
 */
final class AgentInstructionsTest extends TestCase {

	private AgentInstructions $instructions;

	public function set_up(): void {
		parent::set_up();

		/*
		 * Rooted at this package rather than at the throwaway plugin directory,
		 * because the rules page it reads is this package's own. That is also
		 * how it resolves in use: `wp zt` builds a second Plugin entry-rooted
		 * here, and every devtool service comes from that one.
		 */
		$package_plugin = ( new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-agents-test' ) )->declare_multiple( $this->get_toolkit_modules() );

		$this->instructions = $package_plugin->get( AgentInstructions::class );
	}

	public function test_names_the_plugin_it_was_written_for(): void {
		$rendered = $this->render();

		$this->assertStringContainsString( '`Acme\Plugin`', $rendered );
		$this->assertStringContainsString( '`lib/`', $rendered );
		$this->assertStringContainsString( '`acme-plugin`', $rendered );
	}

	/**
	 * The line that decides what an agent may safely edit.
	 */
	public function test_says_which_tree_is_upstream_and_which_is_theirs(): void {
		$this->assertStringContainsString( '`lib/Core/`', $this->render() );
	}

	/**
	 * Every rule, rendered from the page rather than restated. Checked by count
	 * so a rule added there cannot be quietly missing here.
	 */
	public function test_carries_every_rule_from_the_rules_page(): void {
		$page = dirname( __DIR__, 3 ) . '/' . AgentInstructions::RULES_PAGE;

		$this->assertSame(
			(int) preg_match_all( '/^\d+\.\s+\*\*/m', (string) file_get_contents( $page ) ),
			substr_count( $this->render(), "\n- **" ),
			'Every numbered rule on the page has to reach the rendered file.'
		);
	}

	/**
	 * The citations point at documentation pages, and a consuming plugin has
	 * none of them. A link to nowhere is worse than no link.
	 */
	public function test_strips_the_links_that_argue_for_each_rule(): void {
		$rendered = $this->render();

		$this->assertStringNotContainsString( '](services/', $rendered );
		$this->assertStringNotContainsString( '](modules/', $rendered );
		$this->assertStringNotContainsString( '](kernel/', $rendered );
		$this->assertStringNotContainsString( '](commands/', $rendered );
	}

	/**
	 * Three rules carry an em dash inside the sentence, so the citation strip
	 * has to anchor to the end of the line rather than to the first dash.
	 */
	public function test_keeps_a_rule_whose_own_sentence_contains_an_em_dash(): void {
		$this->assertStringContainsString(
			'`src/blocks/`, `src/entries/`, `src/shared/`',
			$this->render(),
			'Rule 23 has an em dash mid-sentence and its tail must survive.'
		);
	}

	public function test_keeps_the_section_headings_that_group_the_rules(): void {
		$rendered = $this->render();

		$this->assertStringContainsString( '### What a plugin is made of', $rendered );
		$this->assertStringContainsString( '### Files that are features', $rendered );
	}

	/**
	 * The page's own introduction and "see also" are about reading the site,
	 * not about working in a plugin.
	 */
	public function test_leaves_the_pages_own_framing_behind(): void {
		$rendered = $this->render();

		$this->assertStringNotContainsString( 'See also', $rendered );
		$this->assertStringNotContainsString( 'the same ground with the tables', $rendered );
	}

	/**
	 * Which modules a plugin has changes with every `wp zt add`, so a file
	 * written once at `init` would start drifting immediately. The command
	 * answers it from the plugin instead.
	 */
	public function test_points_at_describe_rather_than_snapshotting_it(): void {
		$this->assertStringContainsString( 'wp zt describe --format=json', $this->render() );
	}

	/**
	 * A pointer rather than a copy: two files saying the same thing is the drift
	 * this approach exists to remove.
	 */
	public function test_the_claude_file_points_at_agents_md_rather_than_repeating_it(): void {
		$pointer = $this->instructions->render_pointer();

		$this->assertStringContainsString( 'AGENTS.md', $pointer );
		$this->assertStringNotContainsString( '### The two kinds', $pointer );
		$this->assertLessThan( 300, strlen( $pointer ) );
	}

	/**
	 * @return string
	 */
	private function render(): string {
		return $this->instructions->render(
			array(
				'namespace'   => 'Acme\Plugin',
				'root'        => 'lib',
				'text_domain' => 'acme-plugin',
			)
		);
	}
}
