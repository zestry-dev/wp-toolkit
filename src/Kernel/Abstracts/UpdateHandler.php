<?php

/**
 * Core API: UpdateHandler base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Abstracts;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\Bootable;

/**
 * Base class for work that has to happen once after the plugin's code changes.
 *
 * The counterpart to {@see ActivationHandler}, for the half of the lifecycle
 * WordPress gives you no hook for. Extend it to run migrations, flush rewrite
 * rules, clear a cache whose shape changed, or re-add a capability -- anything
 * that must happen once per release rather than once per request.
 *
 * **There is no update hook, and the two candidates both fail.**
 * `activate_{plugin}` does not fire on an update: the plugin stays active
 * throughout, and nothing re-activates it. `upgrader_process_complete` fires for
 * wp.org, an uploaded zip and `wp plugin update`, but the *old* code is still in
 * memory while the new files are already on disk -- so the work it runs belongs
 * to the release you just replaced. Neither fires at all for an FTP upload.
 *
 * So this compares instead of listening. The plugin's `Version:` header is read
 * from the entry file that is actually loaded, and checked against the version
 * recorded the last time {@see update()} completed. They disagree exactly when
 * new code is running, whatever delivered it -- wp.org, a zip, a private update
 * server, WP-CLI, or a file copied over by hand. It is what WordPress does for
 * itself with `db_version`, for the same reason.
 *
 * ## When the check runs
 *
 * Whenever `bootstrap.php` says to build it. There is no hook setting on this
 * class: the heading a module is listed under is already its timing, and a
 * second way to say the same thing could only disagree with the first.
 *
 * `admin_init` is the one to want, and what `wp zt make update` writes. It is
 * late enough that `init` has finished, so anything registered there is
 * available to `update()`; narrow enough that no front-end visitor ever carries
 * the work; and immediate in practice, because every updater-driven update
 * redirects into wp-admin.
 *
 * ```
 * // bootstrap.php
 * 'admin_init' => array( Update::class ),
 * ```
 *
 * Move it if you must -- `'plugins_loaded'` runs the check before `init`-listed
 * modules of your own, and `'admin_init:5'` just moves the priority. Know what
 * an earlier heading costs: on anything that fires for visitors, the first
 * front-end request after an update is the one that carries it, and on a busy
 * site that is many requests at once. The lock keeps them from overlapping; it
 * does not make them fast.
 *
 * ## On multisite
 *
 * **Per site, and lazily** -- each site records its own version, and updates
 * itself the first time somebody opens its dashboard. Deliberately unlike
 * {@see ActivationHandler}, which loops every site on a network activation.
 *
 * The difference is that activation gets one chance. WordPress fires it once, on
 * whichever site the network administrator happened to be on, so a handler that
 * did not loop would set up one site and leave the rest without their tables.
 * This check runs on every admin request of every site, so each one gets endless
 * chances and fixes itself. Looping here would take the timeout risk that
 * `wp_is_large_network()` exists to avoid, and take it again on every admin
 * request until it finished.
 *
 * What that costs is a site nobody administers: its update work waits until
 * somebody opens it. WordPress has the same gap and answers it the same way --
 * per-site on visit, plus a network upgrade screen for driving the rest.
 * {@see update_site()} is that lever here, for a command or an action of your
 * own:
 *
 * ```
 * foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
 *     $plugin->get( Update::class )->update_site( (int) $site_id );
 * }
 * ```
 *
 * > [!IMPORTANT]
 * > **No hook closes the gap between new code and its update work.** WordPress
 * > swaps the files first, so some request runs new code against the old state
 * > no matter what you pick here -- an earlier hook narrows the window without
 * > closing it. Where you control deployment, run the work as a deploy step
 * > (`wp {slug} migrations run`) with the site in maintenance mode. Where you do
 * > not, write releases that tolerate the gap: add the column in one release and
 * > read it in the next, and nothing depends on this having run yet.
 *
 * @example Writing one
 * `$previous` is null when this site has no recorded version -- a fresh install,
 * or one that predates this handler. You cannot tell those apart, so do the work
 * that brings a site up to date from anywhere; `Migrations::run_pending()` is
 * already exactly that, running the baseline on a new site and the pending
 * migrations on an old one.
 *
 * ```
 * namespace Acme\Plugin\Modules;
 *
 * use Acme\Plugin\Core\Kernel\Abstracts\UpdateHandler;
 * use Acme\Plugin\Core\Modules\Migrations\Migrations;
 *
 * class Update extends UpdateHandler {
 *
 *     public function update( ?string $previous, string $current ): bool|WP_Error {
 *         $this->with( Migrations::class )->run_pending();
 *
 *         // Version-specific work, for sites coming from before a change.
 *         if ( null !== $previous && version_compare( $previous, '2.0.0', '<' ) ) {
 *             delete_option( 'acme_plugin_legacy_cache' );
 *         }
 *
 *         flush_rewrite_rules();
 *
 *         return true;
 *     }
 * }
 * ```
 *
 * ```
 * // bootstrap.php -- `wp zt make update` writes this line for you.
 * return array(
 *     'admin_init' => array( Update::class ),
 * );
 * ```
 *
 * The heading is the timing: this is built when `admin_init` fires, and being
 * built is what runs the check.
 *
 * @example Keeping the state with the rest of your settings
 * Two `wp_option` rows by default, written with `update_option()` directly --
 * because this class lives in the kernel, which every plugin gets from
 * `wp zt init`, while [`options`](../modules/options/) is one you add. Reaching
 * for a module from here would make the kernel stop working on a plugin that
 * never added it.
 *
 * So it is a seam instead. Override the two readers and the two writers and the
 * state goes wherever you keep the rest of yours -- one row, in its own group:
 *
 * ```
 * public function get_recorded_version(): ?string {
 *     return $this->with( Options::class )->group( '_update_' )->get( 'version' );
 * }
 *
 * protected function record_version( string $version ): void {
 *     $options = $this->with( Options::class )->group( '_update_' );
 *     $options->set( 'version', $version );
 *     $options->save();
 * }
 * ```
 *
 * `get_last_error()` and `record_error()` are the same pair for the failure
 * message. A group rather than the ungrouped row, so this can never collide with
 * a setting of yours -- and it does not autoload unless you name it in
 * `add_autoloaded_groups()`, which is worth doing here since the check reads it
 * on every admin request.
 *
 */
abstract class UpdateHandler extends Module implements Bootable {

	/**
	 * Do the work this release needs doing once.
	 *
	 * Called on the first request that notices the version has changed, and not
	 * again until it changes once more.
	 *
	 * **`true` is the only thing that means done.** Return `false` or a
	 * `WP_Error`, or throw, and the version is not recorded -- so the work is
	 * retried rather than skipped -- and an administrator is told, on every admin
	 * screen, what went wrong. Much of WordPress already hands you a `WP_Error`,
	 * so returning it straight through is usually the whole of your error
	 * handling.
	 *
	 * Nothing propagates out of here. An update that throws on every request
	 * would take the admin down with it, and an administrator locked out of
	 * wp-admin cannot fix the plugin that locked them out.
	 *
	 * Two requests arriving together after an update cannot both run it: the
	 * second sees a lock and leaves. A failure leaves that lock in place until it
	 * expires, so a broken update is retried every few minutes rather than on
	 * every single admin request.
	 *
	 * @param string|null $previous The version recorded last time, or null when this site has none.
	 * @param string      $current  The version in the entry file that is running now.
	 * @return bool|\WP_Error True when the work is done; false or a WP_Error to be retried.
	 */
	abstract public function update( ?string $previous, string $current ): bool|\WP_Error;

	/**
	 * Run {@see update()} if the running code is not the recorded version.
	 *
	 * Called by `on_boot()`, and public so a command or a deploy step of your own
	 * can force the check at a moment it chooses.
	 *
	 * Does nothing when the entry file declares no `Version:` header: there
	 * would be nothing to compare, and recording the absence would make every
	 * request look like an update.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function maybe_update(): void {
		$current = $this->get_plugin()->get_version();

		if ( null === $current ) {
			return;
		}

		$previous = $this->get_recorded_version();

		if ( ! $this->should_update( $previous, $current ) ) {
			return;
		}

		$lock = $this->get_version_option_name() . '_updating';

		if ( \get_transient( $lock ) ) {
			return;
		}

		// The finally below clears this, so the expiry is only a safety net for
		// an interruption no catch can trap -- a fatal error, a timeout.
		\set_transient( $lock, true, $this->get_lock_duration() );

		try {
			$result = $this->update( $previous, $current );
		} catch ( \Throwable $exception ) {
			$this->record_failure( self::get_error_for( $exception ) );

			return;
		}

		// True is the only thing that means done. A returned false or WP_Error is
		// the same statement as a thrown exception, and takes the same path.
		if ( true !== $result ) {
			$this->record_failure( self::get_error_for( $result ) );

			return;
		}

		// Only now. An update that failed is one this site still needs.
		$this->record_version( $current );
		$this->record_error( null );

		\delete_transient( $lock );
	}

	/**
	 * What went wrong the last time an update was attempted.
	 *
	 * Cleared by the first attempt that succeeds. Present means this site is
	 * still running code whose update work has not completed.
	 *
	 * @return string|null The failure message.
	 */
	public function get_last_error(): ?string {
		$stored = \get_option( $this->get_error_option_name(), null );

		return \is_string( $stored ) && '' !== $stored ? $stored : null;
	}

	/**
	 * The option a failure message is kept in.
	 *
	 * Not a transient: a failed update is not a cache, and it should still be on
	 * screen tomorrow if nobody has fixed it.
	 *
	 * @return string
	 */
	public function get_error_option_name(): string {
		return $this->get_plugin()->get_namespaced_name( 'update_error', '_' );
	}

	/**
	 * Run the check for one site by ID.
	 *
	 * The counterpart to {@see ActivationHandler::activate_site()}: what a WP-CLI
	 * command or a network-admin action calls to bring a site up to date without
	 * waiting for somebody to open its dashboard. Switches into the site, checks,
	 * and switches back.
	 *
	 * @param int $site_id The site to bring up to date.
	 * @return void
	 */
	public function update_site( int $site_id ): void {
		\switch_to_blog( $site_id );

		try {
			$this->maybe_update();
		} finally {
			\restore_current_blog();
		}
	}

	/**
	 * The version recorded the last time {@see update()} completed.
	 *
	 * Null on a site that has never recorded one, which is a fresh install or a
	 * plugin that added this handler in a later release.
	 *
	 * @return string|null
	 */
	public function get_recorded_version(): ?string {
		$stored = \get_option( $this->get_version_option_name(), null );

		return \is_string( $stored ) && '' !== $stored ? $stored : null;
	}

	/**
	 * The option the recorded version lives in.
	 *
	 * Autoloaded, since the check reads it on every request the hook fires on.
	 * Per site on multisite, so each site of a network updates itself the first
	 * time somebody opens its admin -- deliberately not looped the way
	 * activation is, because an update check costs one autoloaded read and
	 * fixes itself, where a loop over a large network times out half way.
	 *
	 * @return string
	 */
	public function get_version_option_name(): string {
		// `_`, because this name's destination is an option rather than a hook.
		return $this->get_plugin()->get_namespaced_name( 'version', '_' );
	}

	/**
	 * Check, as the plugin builds this.
	 *
	 * There is no hook of its own to configure: the heading this module is listed
	 * under in `bootstrap.php` is when it is built, and being built is the check.
	 * One place decides the timing, and it is the same place that decides it for
	 * every other module -- `'admin_init:5'` moves the priority too.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function on_boot(): void {
		// Bound before the check runs, and separately from it: a failure on a
		// request that is not an admin one still has to reach somebody, and the
		// next admin screen is where.
		if ( \is_admin() ) {
			\add_action( 'admin_notices', $this->render_failure_notice( ... ) );
		}

		$this->maybe_update();
	}

	/**
	 * How long the lock is trusted if it is never cleared explicitly.
	 *
	 * Long enough for a slow batch of migrations, short enough that a process
	 * killed mid-update does not block the retry for the rest of the day.
	 *
	 * @return int Seconds; five minutes unless a subclass overrides it.
	 */
	protected function get_lock_duration(): int {
		return 5 * MINUTE_IN_SECONDS;
	}

	/**
	 * Whether there is anything to do.
	 *
	 * True when the running version is newer than the recorded one, by
	 * `version_compare()`, and on the first run of a site that has recorded
	 * nothing. A rollback does not run: the recorded version stays ahead until a
	 * release passes it again.
	 *
	 * @param string|null $previous The version recorded last time, or null when this site has none.
	 * @param string      $current  The version running now.
	 * @return bool
	 */
	protected function should_update( ?string $previous, string $current ): bool {
		return null === $previous || \version_compare( $previous, $current, '<' );
	}

	/**
	 * Write the version this site is now up to date with.
	 *
	 * One half of the storage seam, with {@see get_recorded_version()}. Override
	 * both to keep this wherever the rest of your plugin's state lives -- see the
	 * class docblock for doing that with the `options` module.
	 *
	 * @param string $version The version to record.
	 * @return void
	 */
	protected function record_version( string $version ): void {
		\update_option( $this->get_version_option_name(), $version, true );
	}

	/**
	 * Write, or clear, the last failure message.
	 *
	 * The other half of the seam, with {@see get_last_error()}. Null clears,
	 * which is what a successful update does.
	 *
	 * @param string|null $message The failure, or null to clear it.
	 * @return void
	 */
	protected function record_error( ?string $message ): void {
		if ( null === $message ) {
			\delete_option( $this->get_error_option_name() );

			return;
		}

		\update_option( $this->get_error_option_name(), $message, true );
	}

	/**
	 * Put the last failure on screen, for whoever can act on it.
	 *
	 * Overridable, since a plugin with its own notice system will want this in
	 * it. Deliberately not translated: this class is copied into your plugin and
	 * has no text domain of its own -- wrap the strings in yours if you override.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function render_failure_notice(): void {
		$message = $this->get_last_error();

		if ( null === $message ) {
			return;
		}

		\printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
			\esc_html(
				\sprintf(
					'%s could not finish updating.',
					$this->get_plugin()->get_header( 'Plugin Name' ) ?? $this->get_plugin()->get_slug()
				)
			),
			\esc_html( $message )
		);
	}

	/**
	 * Record a failed update, and say so where it will be read.
	 *
	 * The lock is deliberately left in place rather than cleared: it is what
	 * stops a broken update from re-running on every admin request, turning the
	 * concurrency guard into a retry interval for as long as the failure lasts.
	 *
	 * Also announced on the plugin's `{slug}-log` action, where a Log module --
	 * or a handler of your own -- picks it up, falling back to `error_log()` when
	 * nothing is listening. The action rather than the module itself, because the
	 * Kernel is copied into every plugin and most of them have no Log.
	 *
	 * @param \WP_Error $error What went wrong.
	 * @return void
	 */
	private function record_failure( \WP_Error $error ): void {
		$this->record_error( $error->get_error_message() );

		$hook    = $this->get_plugin()->get_namespaced_name( 'log' );
		$message = \sprintf( 'Plugin update work failed: %s', $error->get_error_message() );

		if ( ! \has_action( $hook ) ) {
			\error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return;
		}

		\do_action( $hook, 'error', $message, array( 'code' => $error->get_error_code() ) );
	}

	/**
	 * However the update reported failure, as one `WP_Error`.
	 *
	 * Three things arrive here and they all say the same thing -- this did not
	 * work -- so they are made one type before anything acts on them: the notice
	 * has one message to render and the log one code to carry, whichever the
	 * handler happened to use. A thrown exception keeps its stack trace, under
	 * the `exception` key of the error's data.
	 *
	 * @param \Throwable|\WP_Error|bool $failure What the handler threw or returned. Only `false` ever reaches here.
	 * @return \WP_Error
	 */
	private static function get_error_for( \Throwable|\WP_Error|bool $failure ): \WP_Error {
		if ( $failure instanceof \WP_Error ) {
			return $failure;
		}

		if ( $failure instanceof \Throwable ) {
			return new \WP_Error(
				'update_exception',
				$failure->getMessage(),
				array( 'exception' => $failure )
			);
		}

		return new \WP_Error( 'update_failed', 'The update handler returned false.' );
	}
}
