<?php

/**
 * Devtool command: `wp zestry make ability <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate an ability.
	 *
	 * Writes a file into the plugin's `abilities/` directory, where the Abilities
	 * module discovers it. The filename becomes the ability's name, so
	 * `create-order` registers as `{plugin-slug}/create-order` — reachable over
	 * the REST API and offered to any MCP adapter on the site.
	 *
	 * WordPress matches both halves of that name against `^[a-z0-9-]+$` and
	 * refuses anything else, so a name outside it is written as the one it accepts
	 * and the command says what it wrote: `create_order` lands as
	 * `abilities/create-order.php`.
	 *
	 * **Two of the generated methods are placeholders, not defaults to keep.**
	 * `effect()` returns `Effect::Read` and `is_public()` returns `false`, because
	 * those are the two answers that cannot do any harm if you never revisit them
	 * — a read-only ability nothing outside your PHP can call. An ability that
	 * writes has to say so, or WordPress answers the wrong HTTP method with a 405;
	 * and one nothing can reach is one no agent will find. Both are commented in
	 * the file with what to weigh.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The ability's local name, in kebab-case, e.g. `create-order`.
	 *
	 * [--dir=<dir>]
	 * : Write somewhere other than `abilities/`, relative to the plugin root.
	 *
	 * [--extends=<class>]
	 * : Extend one of your own abstracts instead of the toolkit base. A bare name
	 * is looked for under your Abstracts\ namespace; the generated file stubs the
	 * methods that class leaves abstract, and nothing it has already settled.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate abilities/create-order.php.
	 *     $ wp zestry make ability create-order
	 *     Success: Created abilities/create-order.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	protected function get_stub(): string {
		return 'ability.php.stub';
	}

	/**
	 * The name WordPress will accept, which is not always the one given.
	 *
	 * @param string $name The local name given on the command line.
	 * @return string
	 */
	protected function normalize_name( string $name ): string {
		return $this->stub_renderer->to_slug( $name );
	}

	/**
	 * @return string
	 */
	protected function get_name_constraint(): string {
		return 'an ability name is `{plugin-slug}/{name}`, and WordPress matches both halves against `^[a-z0-9-]+$` and refuses anything else.';
	}

	protected function get_default_dir( array $config ): string {
		return 'abilities';
	}

	protected function get_base_class(): ?string {
		return 'Modules\Abilities\Ability';
	}

	protected static function get_type(): string {
		return 'ability';
	}
};
