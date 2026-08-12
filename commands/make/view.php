<?php

/**
 * Devtool command: `wp zt make view <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a view template.
	 *
	 * Writes `views/<name>.php`, which the Views service renders by that name.
	 * A name may contain slashes, so `wp zt make view admin-pages/settings`
	 * writes `views/admin-pages/settings.php` and creates the directory.
	 *
	 * A template receives exactly what its caller passes, plus `$this` -- the
	 * Views service -- so it renders a subview with the same `render()` call
	 * everything else uses. Nothing else is in scope, which is what keeps a
	 * template's inputs readable without opening it.
	 *
	 * Needs the `views` service: `wp zt add service views`. It arrives on its
	 * own with `admin-pages`, which renders its markup this way.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The view name, as the caller will ask for it, e.g. `emails/receipt`.
	 *
	 * [--dir=<dir>]
	 * : Write into this plugin-relative directory instead of `views` -- pass it
	 * when you have pointed the Views service's root somewhere else.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Rendered with $views->render( 'emails/receipt', array( ... ) ).
	 *     $ wp zt make view emails/receipt
	 *     Success: Created views/emails/receipt.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	protected function get_stub(): string {
		return 'view.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'views';
	}

	protected static function get_type(): string {
		return 'view';
	}
};
