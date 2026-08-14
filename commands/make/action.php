<?php

/**
 * Devtool command: `wp zt make action <name>`.
 *
 * Generates a new AJAX action stub into a project already set up with
 * `wp zt init`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a new AJAX action.
	 *
	 * The Ajax module discovers it. At boot it walks your `actions/` directory,
	 * requires every file in it, and maps the `AjaxAction` each one returns onto
	 * `wp_ajax_{plugin}-{action}` -- plus the matching `wp_ajax_nopriv_` hook if
	 * the action opts logged-out visitors in. Writing the file is the whole
	 * registration; nothing has to be declared anywhere.
	 *
	 * Needs the `ajax` module, so run `wp zt add ajax` first if you have
	 * not already.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local name, e.g. 'send-welcome-email'. Becomes the filename
	 * (`{name}.php`) under `actions/`.
	 *
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * [--extends=<class>]
	 * : Extend one of your own abstracts instead of the toolkit base. A bare name
	 * is looked for under your Abstracts\ namespace; the generated file stubs the
	 * methods that class leaves abstract, and nothing it has already settled.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate an AJAX action at actions/send-welcome-email.php.
	 *     $ wp zt make action send-welcome-email
	 *     Success: Created actions/send-welcome-email.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	public function get_base_class(): ?string {
		return 'Modules\Ajax\AjaxAction';
	}

	protected function get_stub(): string {
		return 'action.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'actions';
	}

	protected static function get_type(): string {
		return 'action';
	}
};
