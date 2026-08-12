<?php

/**
 * Devtool command: `wp zestry make health-check <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a Site Health check.
	 *
	 * Writes a file into the plugin's `health-checks/` directory, where the
	 * SiteHealth module discovers it. The filename becomes the check's
	 * identifier, so `api-key` registers as `{plugin-slug}-api-key`.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The check's local name, in kebab-case, e.g. `api-key`.
	 *
	 * [--dir=<dir>]
	 * : Write somewhere other than `health-checks/`, relative to the plugin root.
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
	 *     # Generate health-checks/api-key.php.
	 *     $ wp zestry make health-check api-key
	 *     Success: Created health-checks/api-key.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	public function get_base_class(): ?string {
		return 'Modules\SiteHealth\HealthCheck';
	}

	protected function get_stub(): string {
		return 'health-check.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'health-checks';
	}

	protected static function get_type(): string {
		return 'health-check';
	}
};
