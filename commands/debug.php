<?php

/**
 * Devtool command: `wp zt debug`.
 *
 * Turns this plugin's own debug constant on and off, by writing it to
 * wp-config.php through WP-CLI's own `config` command.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\CLI\Command;

return new class() extends Command {

	/**
	 * Turn this plugin's debug mode on or off.
	 *
	 * Your plugin has a debug switch of its own, separate from `WP_DEBUG`: the
	 * constant `{SLUG}_DEBUG`, which `$plugin->is_plugin_debug()` reads. It exists
	 * for the case `WP_DEBUG` misses -- working on one plugin against a site that
	 * is not otherwise in debug mode, where turning `WP_DEBUG` on would fill the
	 * screen with everyone else's notices.
	 *
	 * The toolkit reads it too. The [`icons-library`](../modules/icons-library/) module checks
	 * every SVG against WordPress's sanitizer while either switch is on, and skips
	 * the check entirely when neither is.
	 *
	 * The constant is written to `wp-config.php` by WP-CLI's own `wp config`, so
	 * it is a fact about this install and never ships with the plugin. Running
	 * this on a production site is not something to do by habit.
	 *
	 * Called with nothing, it reports which the constant currently is and changes
	 * nothing.
	 *
	 * ## OPTIONS
	 *
	 * [<state>]
	 * : Whether debug mode should be on. Omit to report which it currently is,
	 * without writing anything.
	 * ---
	 * options:
	 *   - on
	 *   - off
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Which is it?
	 *     $ wp zt debug
	 *     ACME_PLUGIN_DEBUG is off.
	 *
	 *     # Turn it on for this install.
	 *     $ wp zt debug on
	 *     Success: ACME_PLUGIN_DEBUG is on.
	 *
	 *     # And off again, which removes the line rather than setting it false.
	 *     $ wp zt debug off
	 *     Success: ACME_PLUGIN_DEBUG is off.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		try {
			$plugin_root = $this->with( ConsumerPlugin::class )->get_plugin_root();
		} catch ( \RuntimeException $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}

		$constant = Plugin::get_debug_constant( $this->with( RuntimePlugin::class )->get_slug_or_default( $plugin_root ) );

		/*
		 * Read from this process, which loaded wp-config.php on the way in. A
		 * constant defined as anything but `true` reads as off, which is what
		 * is_plugin_debug() means by it.
		 */
		$is_on = defined( $constant ) && constant( $constant ) === true;
		$state = (string) ( $args[0] ?? '' );

		if ( '' === $state ) {
			$this->log( sprintf( '%s is %s.', $constant, $is_on ? 'on' : 'off' ) );
			return;
		}

		if ( 'on' !== $state && 'off' !== $state ) {
			$this->error( sprintf( 'Say `on` or `off`, not "%s".', $state ) );
			return;
		}

		if ( ( 'on' === $state ) === $is_on ) {
			$this->success( sprintf( '%s is already %s.', $constant, $state ) );
			return;
		}

		if ( 'on' === $state ) {
			// --raw so the value is the boolean `true` rather than the string
			// "true", which is truthy but not what is_plugin_debug() accepts.
			$this->run_command( sprintf( 'config set %s true --raw --type=constant', $constant ) );
			$this->success( sprintf( '%s is on.', $constant ) );
			return;
		}

		// Deleted rather than set false: the constant is absent by default, and a
		// line saying so is one more thing to read past in wp-config.php.
		$this->run_command( sprintf( 'config delete %s --type=constant', $constant ) );
		$this->success( sprintf( '%s is off.', $constant ) );
	}
};
