<?php

/**
 * Log API: Log module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Abstracts\Module;

/**
 * Writes namespaced, levelled messages to wherever WordPress sends errors.
 *
 * Records go to `error_log()`, which WordPress already routes for the site --
 * `WP_DEBUG_LOG` decides whether that is `wp-content/debug.log`, a path of the
 * site's choosing, or the server's own error log.
 *
 * Every line carries the plugin slug and the level, so one plugin's records
 * stay greppable in a log every other plugin also writes to.
 *
 * The levels are PSR-3's names. The PSR-3 interface is not implemented.
 *
 * @setup
 * Register an initializer only to change what gets through. Everything at
 * `info` and above is logged by default, plus `debug` when `WP_DEBUG` is on.
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Log::class => array(
 *         'configure' => static function ( Log $log ): void {
 *             $log->set_min_level( Log::LEVEL_WARNING );
 *         },
 *     ),
 * );
 * ```
 *
 * @example Recording something
 * The context array is for the values that make a message worth reading. It is
 * JSON-encoded onto the line, so it lands in the log with the message rather
 * than being dropped on the way.
 *
 * ```
 * $log = $plugin->get( Log::class );
 *
 * $log->error( 'Payment capture failed', array( 'order' => 42 ) );
 * // acme-plugin.ERROR: Payment capture failed {"order":42}
 *
 * $log->debug( 'Raw gateway response', array( 'body' => $body ) );
 * ```
 *
 * @example Sending records somewhere else
 * There is no hook to attach to, because this file belongs to the plugin:
 * edit `write()` and every record goes wherever it says.
 *
 * ```
 * private function write( string $level, string $message, array $context = array() ): void {
 *     if ( self::LEVEL_ERROR === $level ) {
 *         Sentry::captureMessage( $message, $context );
 *     }
 * }
 * ```
 *
 * The one hook this module does bind is for its siblings, not for extension.
 * `Options` and `Cron` announce their failures on a `{plugin-slug}-log` action
 * because they must keep working for a plugin that never added this module --
 * naming a hook rather than a class is what keeps the three independent. When
 * nothing is listening, they fall back to `error_log()` rather than lose the
 * message.
 */
class Log extends Module implements Bootable {

	/**
	 * The system is unusable.
	 */
	public const LEVEL_EMERGENCY = 'emergency';

	/**
	 * Action must be taken immediately.
	 */
	public const LEVEL_ALERT = 'alert';

	/**
	 * Critical conditions.
	 */
	public const LEVEL_CRITICAL = 'critical';

	/**
	 * A runtime error that does not need immediate action.
	 */
	public const LEVEL_ERROR = 'error';

	/**
	 * An exceptional occurrence that is not an error.
	 */
	public const LEVEL_WARNING = 'warning';

	/**
	 * A normal but significant event.
	 */
	public const LEVEL_NOTICE = 'notice';

	/**
	 * An interesting event.
	 */
	public const LEVEL_INFO = 'info';

	/**
	 * Detailed debugging information.
	 */
	public const LEVEL_DEBUG = 'debug';

	/**
	 * The action sibling modules announce their failures on, before the plugin
	 * slug is added. A record made through this module's own methods does not
	 * travel it: `log()` writes directly.
	 *
	 * Named as a bare noun rather than `log_record` or `write_log`, matching
	 * the slug-prefixed shape every other module's identifiers take.
	 */
	public const HOOK = 'log';

	/**
	 * Every level, ordered by severity, most severe first.
	 *
	 * The order is what {@see set_min_level()} compares against, so a level's
	 * position here is the whole of what "more severe" means to this module.
	 *
	 * @var array<int, string>
	 */
	private const LEVELS = array(
		self::LEVEL_EMERGENCY,
		self::LEVEL_ALERT,
		self::LEVEL_CRITICAL,
		self::LEVEL_ERROR,
		self::LEVEL_WARNING,
		self::LEVEL_NOTICE,
		self::LEVEL_INFO,
		self::LEVEL_DEBUG,
	);

	/**
	 * The least severe level that gets written, or null to decide on WP_DEBUG.
	 *
	 * Null is the default: `debug` is written only when `WP_DEBUG` is on, and
	 * everything else always. Set explicitly to override that entirely.
	 *
	 * @var string|null
	 */
	private ?string $min_level = null;

	/**
	 * Set the least severe level that gets written.
	 *
	 * Call this from the module initializer. Anything less severe than the
	 * given level is dropped before it is written, so raising the threshold
	 * costs nothing for the records it excludes.
	 *
	 * @param string $level One of this class's `LEVEL_*` constants.
	 * @return void
	 * @throws \InvalidArgumentException When the level is not one this module knows.
	 */
	public function set_min_level( string $level ): void {
		if ( ! \in_array( $level, self::LEVELS, true ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'Unknown log level "%s". Expected one of: %s.',
					$level,
					\implode( ', ', self::LEVELS )
				)
			);
		}

		$this->min_level = $level;
	}

	/**
	 * The system is unusable.
	 *
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values that make the message worth reading.
	 * @return void
	 */
	public function emergency( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_EMERGENCY, $message, $context );
	}

	/**
	 * Action must be taken immediately.
	 *
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values that make the message worth reading.
	 * @return void
	 */
	public function alert( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ALERT, $message, $context );
	}

	/**
	 * A critical condition.
	 *
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values that make the message worth reading.
	 * @return void
	 */
	public function critical( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_CRITICAL, $message, $context );
	}

	/**
	 * A runtime error that does not need immediate action.
	 *
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values that make the message worth reading.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * An exceptional occurrence that is not an error.
	 *
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values that make the message worth reading.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * A normal but significant event.
	 *
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values that make the message worth reading.
	 * @return void
	 */
	public function notice( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_NOTICE, $message, $context );
	}

	/**
	 * An interesting event.
	 *
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values that make the message worth reading.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Detailed debugging information.
	 *
	 * Written only when `WP_DEBUG` is on, unless {@see set_min_level()} says
	 * otherwise.
	 *
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values that make the message worth reading.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context );
	}

	/**
	 * Record something at a level decided at runtime.
	 *
	 * The level-named methods all call this. Use it directly when the level is
	 * computed -- mapping an exception's severity, say -- rather than known
	 * where the call is written.
	 *
	 * @param string              $level    One of this class's `LEVEL_*` constants.
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values that make the message worth reading.
	 * @return void
	 * @throws \InvalidArgumentException When the level is not one this module knows.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! \in_array( $level, self::LEVELS, true ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'Unknown log level "%s". Expected one of: %s.',
					$level,
					\implode( ', ', self::LEVELS )
				)
			);
		}

		if ( ! $this->is_loggable( $level ) ) {
			return;
		}

		$this->write( $level, $message, $context );
	}

	/**
	 * The action name sibling modules announce their failures on, namespaced to
	 * the plugin.
	 *
	 * Your slug joined to `log` with a hyphen, so the plugin `acme-plugin` gets
	 * `acme-plugin-log`. Call this rather than composing the name yourself --
	 * `do_action( $log->get_hook(), ... )` -- so a module of your own reports
	 * on the same hook this one listens to.
	 *
	 * @rationale
	 * {@see Plugin::get_namespaced_name()} is the only place this name is composed.
	 * A caller building the string itself would put the slug in verbatim and get
	 * the mixed-separator `acme-plugin_log`, and a second copy of the rule means
	 * a change to either silently unbinds the other. `Options` and `Cron` reach
	 * the hook without depending on this class, which is what lets them report
	 * to a `Log` that may not be there.
	 *
	 * @return string The action name, e.g. `acme-plugin-log`.
	 */
	public function get_hook(): string {
		return $this->get_plugin()->get_namespaced_name( self::HOOK );
	}

	/**
	 * Listen for what sibling modules report.
	 *
	 * The only reason this module binds a hook at all. `Options` and `Cron`
	 * announce their failures on it because they must keep
	 * working for a plugin that never added this module -- so they name a hook
	 * rather than a class. Resolving this module is what turns those
	 * announcements into written records.
	 *
	 * A record made through this module's own methods does not travel the
	 * hook: `log()` calls {@see write()} directly, since routing a call
	 * through a hook only to catch it again here would buy nothing.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function on_boot(): void {
		// A first-class callable rather than array( $this, 'write' ), so write()
		// can stay private: the plugin edits it, nothing calls it from outside.
		\add_action( $this->get_hook(), $this->write( ... ), 10, 3 );
	}

	/**
	 * Whether a level is severe enough to record.
	 *
	 * @param string $level One of this class's `LEVEL_*` constants.
	 * @return bool True when the record should go on.
	 */
	private function is_loggable( string $level ): bool {
		if ( null === $this->min_level ) {
			// The default: everything but `debug`, which waits for WP_DEBUG.
			return self::LEVEL_DEBUG !== $level || $this->get_plugin()->is_wp_debug();
		}

		// Lower index is more severe, so a record passes when its index is at
		// or above the threshold's.
		return \array_search( $level, self::LEVELS, true ) <= \array_search( $this->min_level, self::LEVELS, true );
	}

	/**
	 * Write a record to wherever WordPress sends errors.
	 *
	 * **This is the method to change.** The plugin owns this file, so sending
	 * records to Sentry, a database table or a file of its own is an edit here
	 * rather than a hook to attach to -- there is no extension point because
	 * the code itself is the extension point.
	 *
	 * Called for every record this module accepts, and for every one a sibling
	 * module announces on the log action.
	 *
	 * @param string              $level    The record's level.
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values that make the message worth reading.
	 * @return void
	 */
	private function write( string $level, string $message, array $context = array() ): void {
		$line = \sprintf(
			'%s.%s: %s',
			$this->get_plugin()->get_slug(),
			\strtoupper( $level ),
			$message
		);

		/*
		 * error_log() timestamps a line it writes to a file, but not one it
		 * sends to stderr -- which is where it goes under WP-CLI, so a
		 * `wp {slug} ...` run would produce lines with no time at all. Adding
		 * one only in that case avoids both a missing timestamp and a doubled
		 * one.
		 *
		 * gmdate() in error_log()'s own format, so a log holding both kinds of
		 * line stays sortable and greppable by time.
		 */
		if ( '' === (string) \ini_get( 'error_log' ) ) {
			$line = '[' . \gmdate( 'd-M-Y H:i:s' ) . ' UTC] ' . $line;
		}

		if ( array() !== $context ) {
			// Encoded onto the line rather than passed only to a handler: a
			// context a reader cannot see in the default sink is close to
			// useless. Failed encoding must not lose the message, so the line
			// is written either way.
			$encoded = \json_encode( $context );

			if ( false !== $encoded ) {
				$line .= ' ' . $encoded;
			}
		}

		\error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
