<?php

/**
 * Cron API: Schedule base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Cron;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * Base class for file-based WP-Cron scheduled events.
 *
 * A schedule file returns a subclass instance. The Cron module wires it
 * (assigning the shared plugin, so `with()` reaches every module),
 * ensures its recurrence is scheduled with WordPress, and binds `run()` to
 * fire when WP-Cron's pseudo-cron eventually dispatches the hook.
 *
 * A file at `resources/schedules/cleanup-logs.php` registers under the hook
 * `{plugin-slug}-cleanup-logs` (see {@see Cron::get_schedule_slug()}).
 * `wp zt make schedule <name>` generates a starting point.
 *
 * `recurrence()` returns a WordPress built-in (`'hourly'`, `'twicedaily'`,
 * `'daily'`) or a key from {@see Cron::get_custom_interval_slug()}, for an
 * interval the plugin registered itself with
 * {@see Cron::add_custom_interval()}. Either way the Cron module checks the
 * key resolves before scheduling anything, so a typo or a missing
 * `add_custom_interval()` call fails at registration rather than silently
 * later.
 *
 * @stub schedule.php.stub
 */
abstract class Schedule implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * The recurrence this event repeats on.
	 *
	 * Either a WordPress built-in (`'hourly'`, `'twicedaily'`, `'daily'`) or a
	 * key obtained from {@see Cron::get_custom_interval_slug()} for an
	 * interval registered via {@see Cron::add_custom_interval()}.
	 *
	 * @return string
	 */
	abstract public function recurrence(): string;

	/**
	 * Run the scheduled work.
	 *
	 * Called when WP-Cron's pseudo-cron dispatches this event's hook. An
	 * exception thrown here is caught and logged by the Cron module rather
	 * than propagating — a failed occurrence does not stop other due hooks
	 * from firing in the same request, and does not affect the next
	 * scheduled occurrence.
	 *
	 * @return void
	 */
	abstract public function run(): void;

	/**
	 * The timestamp of this event's first occurrence.
	 *
	 * Defaults to as soon as possible (`time()`). Override to anchor a
	 * recurring event to a specific time of day (for example, the next
	 * occurrence of 6am) — every later occurrence falls at
	 * `initial_run_at() + N * recurrence_seconds`, so anchoring the first one
	 * anchors all of them.
	 *
	 * > [!NOTE]
	 * > **Read only when an occurrence is actually created.** That means first
	 * > registration, and again on the re-schedule a changed `recurrence()`
	 * > forces, since the old occurrence is cleared and replaced. Change this
	 * > alone in a later release, with the recurrence untouched, and a site
	 * > that already has the event keeps its existing anchor. `recurrence()` is
	 * > the opposite -- it is re-checked every request.
	 *
	 * @return int Unix timestamp of the first occurrence.
	 */
	public function initial_run_at(): int {
		return \time();
	}

	/**
	 * Whether more than one occurrence of this event may run concurrently.
	 *
	 * Defaults to false: the Cron module wraps `run()` in a transient-based
	 * lock, so an occurrence that is still running when the next one becomes
	 * due is skipped rather than overlapping it. WP-Cron itself has no
	 * built-in concurrency protection — a slow task or a burst of near
	 * simultaneous pseudo-cron requests can otherwise dispatch the same hook
	 * twice at once. Override to return true only for work that is genuinely
	 * safe to run concurrently with itself.
	 *
	 * @return bool
	 */
	public function allow_concurrent_runs(): bool {
		return false;
	}

	/**
	 * The hook this event is scheduled under.
	 *
	 * Your filename with the plugin slug prefixed, since WP-Cron's event list is
	 * shared by every plugin on the site: `resources/schedules/cleanup.php` runs as
	 * `{plugin-slug}-cleanup`. This is the name `wp cron event list` shows, and
	 * what `wp_next_scheduled()` and `wp_clear_scheduled_hook()` take.
	 *
	 * @return string
	 */
	final public function get_hook(): string {
		return $this->with( Cron::class )->get_slug_of( $this );
	}
}
