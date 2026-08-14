<?php

/**
 * DevTools: the instructions an agent finds in a consuming plugin
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Modules\Path;

/**
 * Renders the `AGENTS.md` that `wp zt init` leaves in a consuming plugin.
 *
 * An agent opening a plugin built this way sees a `lib/Core/` it did not write,
 * a `bootstrap.php` whose entries look optional, and a `resources/commands/` directory
 * that registers nothing visible. Every convention that explains those is in
 * this toolkit's documentation, which is not in that repository -- so the
 * choice is to infer them or to go looking, and inference gets the load-bearing
 * ones wrong: that listing a module is what builds it, that a `private`
 * property is never injected, that a filename is an identifier.
 *
 * So the invariants travel with the plugin. What is written is
 * {@see \Zestry\WPToolkit\DevTools\AgentInstructions::RULES_PAGE} rendered, not summarised
 * and not restated: the same numbered rules a person reads on the
 * documentation site, with their citations stripped because the pages they
 * cite are not there either. A second wording would be a second thing to keep
 * true, which is the failure this whole file exists to avoid.
 *
 * **What is deliberately not written is a description of the plugin.** Which
 * modules are installed, which are declared, what each directory holds -- all
 * of that changes every time someone runs `wp zt add`, and a file written once
 * at `init` would start drifting immediately. `wp zt describe --format=json`
 * answers it from the plugin itself, so the instructions point at the command
 * rather than snapshotting its output.
 */
class AgentInstructions extends Module {

	/**
	 * The page the rules are read from, relative to this package's root.
	 *
	 * @var string
	 */
	public const RULES_PAGE = 'docs/rules.md';

	/**
	 * The `AGENTS.md` for a plugin.
	 *
	 * @param array{namespace: string, root: string, text_domain?: string|null} $config The project's zestry.json.
	 * @return string
	 * @throws \RuntimeException When the rules page cannot be read.
	 */
	public function render( array $config ): string {
		$root      = \trim( $config['root'], '/\\' );
		$namespace = \rtrim( $config['namespace'], '\\' );

		$lines = array(
			'# Working in this plugin',
			'',
			\sprintf(
				'Built with [wp-toolkit](https://github.com/zestry-dev/wp-toolkit). Namespace `%s`, source in `%s/`,'
					. ' text domain `%s`.',
				$namespace,
				$root,
				$config['text_domain'] ?? '(none)'
			),
			'',
			\sprintf(
				'Everything under `%1$s/%2$s/` came from the toolkit and `wp zt update` may replace it.'
					. ' Everything else in `%1$s/` is yours and no command touches it.',
				$root,
				Copier::COPIED_SEGMENT
			),
			'',
			'## Before you change anything',
			'',
			'Run `wp zt describe --format=json` from this directory. It reports every module, whether'
				. ' each is installed and declared, the directory it reads, the base class a file there'
				. ' must return, and the `wp zt make` that writes one.',
			'',
			'It is derived from this plugin rather than written down, so it cannot be out of date.'
				. ' Nothing about which features this plugin has is repeated below, for that reason.',
			'',
			'## Rules',
			'',
			'These do not change. The rest is discoverable; these are not.',
			'',
		);

		$lines = \array_merge( $lines, $this->get_rules() );

		$lines[] = '';
		$lines[] = '## Doing the work';
		$lines[] = '';
		$lines[] = '- `wp zt make <type> <name>` writes a working file into the directory its module discovers.'
			. ' Run `wp zt make` to list the types.';
		$lines[] = '- `wp zt add <name>` copies a module in and declares it in `bootstrap.php`.';
		$lines[] = '- `wp zt doctor` reports the wiring mistakes that raise no error. Run it after changing'
			. ' `bootstrap.php` or adding a module by hand.';

		return \implode( "\n", $lines ) . "\n";
	}

	/**
	 * The `.claude/CLAUDE.md` for a plugin.
	 *
	 * A pointer rather than a copy. Claude Code reads `CLAUDE.md`; most other
	 * tools read `AGENTS.md`; and two files saying the same thing is the drift
	 * this whole approach exists to remove, so one of them is three lines long.
	 *
	 * @return string
	 */
	public function render_pointer(): string {
		return \implode(
			"\n",
			array(
				'# Working in this plugin',
				'',
				'See [AGENTS.md](../AGENTS.md). It is generated from the toolkit\'s own rules,'
					. ' so edit that file rather than duplicating it here.',
				'',
			)
		);
	}

	/**
	 * Every rule on the rules page, renumbered and stripped of its citations.
	 *
	 * The page's shape is a contract, checked by `zestry_check_rules_page()` in the
	 * docs build: a rule is a numbered list item, its statement is the leading
	 * `**bold**`, and a trailing run of markdown links after an em dash is the
	 * citation list. Those links point at pages that do not exist in a
	 * consuming plugin, so they come off -- and the regex anchors to the end of
	 * the line, which is what makes the em dashes *inside* several rules safe.
	 *
	 * Section headings come across as-is: they group twenty-six rules into
	 * something a reader can hold, and losing them would leave a wall.
	 *
	 * @return string[]
	 * @throws \RuntimeException When the rules page cannot be read.
	 */
	private function get_rules(): array {
		$page = $this->with( Path::class )->get_plugin_path( self::RULES_PAGE );

		if ( ! \is_file( $page ) ) {
			throw new \RuntimeException( 'Cannot read the rules to render: ' . $page );
		}

		$rules = array();
		$seen  = false;

		foreach ( \explode( "\n", (string) \file_get_contents( $page ) ) as $line ) {
			// Everything before the first rule is the page's own introduction,
			// and everything after the last is its "see also".
			if ( \str_starts_with( $line, '## ' ) ) {
				if ( $seen && 'See also' === \substr( $line, 3 ) ) {
					break;
				}

				$rules[] = '### ' . \substr( $line, 3 );
				$rules[] = '';

				continue;
			}

			if ( 1 !== \preg_match( '/^\d+\.\s+(.*)$/', $line, $match ) ) {
				continue;
			}

			$seen    = true;
			$rules[] = '- ' . $this->strip_citations( $match[1] );
		}

		if ( array() === $rules ) {
			throw new \RuntimeException( 'The rules page has no rules on it: ' . $page );
		}

		return $rules;
	}

	/**
	 * A rule with nothing left in it that points at a page.
	 *
	 * Two passes, because the links come in two shapes. The trailing run after
	 * an em dash is the citation list and goes entirely -- anchored to the end
	 * of the line, which is what keeps an em dash *inside* a rule's own sentence
	 * safe. What is left can still hold a link mid-sentence, the way the rule
	 * about the copied tree names `wp zt update` and `wp zt overwrite`. Those
	 * are flattened to their text rather than dropped, since the words are the
	 * rule.
	 *
	 * Every target is a documentation page, and a consuming plugin has none of
	 * them. A link to nowhere reads as something worth following and is not.
	 *
	 * @param string $rule One rule's text.
	 * @return string
	 */
	private function strip_citations( string $rule ): string {
		$rule = (string) \preg_replace( '/\s+—\s+(?:\[[^\]]+\]\([^)]+\)(?:\s*·\s*)?)+$/u', '', $rule );

		return \rtrim( (string) \preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $rule ) );
	}
}
