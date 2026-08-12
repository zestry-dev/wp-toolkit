<?php

/**
 * Devtool command: `wp zestry make debug-section <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a Site Health debug section.
	 *
	 * Writes a file into the plugin's `debug-sections/` directory, where the
	 * SiteHealth module discovers it. The filename becomes the section's
	 * identifier, so `status` registers as `{plugin-slug}-status`.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The section's local name, in kebab-case, e.g. `status`.
	 *
	 * [--dir=<dir>]
	 * : Write somewhere other than `debug-sections/`, relative to the plugin root.
	 *
	 * [--extends=<class>]
	 * : Extend one of your own abstracts instead of the toolkit base. A bare name
	 * is looked for under your Abstracts\ namespace; the generated file stubs the
	 * methods that class leaves abstract, and nothing it has already settled.
	 *
	 * [--yes]
	 * : Answer both prompts without reading input: overwrite an existing file,
	 * and add the `site-health` module when this plugin has none.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate debug-sections/status.php.
	 *     $ wp zestry make debug-section status
	 *     Success: Created debug-sections/status.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	public function get_base_class(): ?string {
		return 'Modules\SiteHealth\DebugSection';
	}

	protected function get_stub(): string {
		return 'debug-section.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'debug-sections';
	}

	protected static function get_type(): string {
		return 'debug-section';
	}
};
