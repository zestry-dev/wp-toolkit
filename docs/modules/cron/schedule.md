<!--
    Generated from src/Modules/Cron/Schedule.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Schedule

[Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for file-based WP-Cron scheduled events.

A schedule file returns a subclass instance. The Cron module wires it (assigning the shared plugin and injecting typed module properties), ensures its recurrence is scheduled with WordPress, and binds `run()` to fire when WP-Cron's pseudo-cron eventually dispatches the hook.

A file at `schedules/cleanup-logs.php` registers under the hook `{plugin-slug}-cleanup-logs` (see `Cron::get_schedule_slug()`). `wp zt make schedule <name>` generates a starting point.

`recurrence()` returns a WordPress built-in (`'hourly'`, `'twicedaily'`, `'daily'`) or a key from `Cron::get_custom_interval_slug()`, for an interval the plugin registered itself with `Cron::add_custom_interval()`. Either way the Cron module checks the key resolves before scheduling anything, so a typo or a missing `add_custom_interval()` call fails at registration rather than silently later.

## Generated starting point

[`wp zt make schedule <name>`](../../commands/make-schedule.md) writes this file:

```php
<?php
/**
 * example scheduled event.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\Cron\Schedule;

return new class() extends Schedule {

	// The hook is this file's name -- {plugin-slug}-example. Renaming the file
	// schedules a new event and leaves the old one in WordPress's cron array,
	// firing on time with nothing listening.

	// How often this event repeats: a WordPress built-in ('hourly',
	// 'twicedaily', 'daily') or a key from Cron::get_custom_interval_slug()
	// for an interval registered via Cron::add_custom_interval() elsewhere.
	public function recurrence(): string {
		return 'daily';
	}

	// Timestamp of this event's FIRST-EVER occurrence only -- every later
	// occurrence falls at initial_run_at() + N * recurrence(), so anchor this
	// to a specific time of day (e.g. next 6am) if that matters. Read only
	// once: once the event is scheduled, changing this method has no effect
	// on a site that already has an occurrence recorded.
	public function initial_run_at(): int {
		return \time();
	}

	// false (default): a transient lock skips this occurrence if the
	// previous one is still running, since WP-Cron itself does not prevent
	// the same hook firing twice at once. Return true only if run() is
	// genuinely safe to execute concurrently with itself.
	public function allow_concurrent_runs(): bool {
		return false;
	}

	// The work to run. A thrown exception here is caught and logged by the
	// Cron module -- it does not stop other due schedules in the same
	// request, and does not affect this schedule's next occurrence.
	public function run(): void {
	}
};
```

## You must implement

These 2 methods are abstract: a subclass that does not declare all of them will not load.

### `recurrence()`

The recurrence this event repeats on.

```php
abstract public function recurrence(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Either a WordPress built-in (`'hourly'`, `'twicedaily'`, `'daily'`) or a key obtained from `Cron::get_custom_interval_slug()` for an interval registered via `Cron::add_custom_interval()`.

<br>

### `run()`

Run the scheduled work.

```php
abstract public function run(): void
```

Called when WP-Cron's pseudo-cron dispatches this event's hook. An exception thrown here is caught and logged by the Cron module rather than propagating — a failed occurrence does not stop other due hooks from firing in the same request, and does not affect the next scheduled occurrence.

## Methods you can use

### `initial_run_at()`

The timestamp of this event's first occurrence.

```php
public function initial_run_at(): int
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Unix timestamp of the first occurrence |
| **Throws** | — |

Defaults to as soon as possible (`time()`). Override to anchor a recurring event to a specific time of day (for example, the next occurrence of 6am) — every later occurrence falls at `initial_run_at() + N * recurrence_seconds`, so anchoring the first one anchors all of them.

> [!NOTE]
> **Read only when an occurrence is actually created.** That means first registration, and again on the re-schedule a changed `recurrence()` forces, since the old occurrence is cleared and replaced. Change this alone in a later release, with the recurrence untouched, and a site that already has the event keeps its existing anchor. `recurrence()` is the opposite — it is re-checked every request.

<br>

### `allow_concurrent_runs()`

Whether more than one occurrence of this event may run concurrently.

```php
public function allow_concurrent_runs(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

Defaults to false: the Cron module wraps `run()` in a transient-based lock, so an occurrence that is still running when the next one becomes due is skipped rather than overlapping it. WP-Cron itself has no built-in concurrency protection — a slow task or a burst of near simultaneous pseudo-cron requests can otherwise dispatch the same hook twice at once. Override to return true only for work that is genuinely safe to run concurrently with itself.

<br>

### `get_hook()`

The hook this event is scheduled under.

```php
final public function get_hook(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Your filename with the plugin slug prefixed, since WP-Cron's event list is shared by every plugin on the site: `schedules/cleanup.php` runs as `{plugin-slug}-cleanup`. This is the name `wp cron event list` shows, and what `wp_next_scheduled()` and `wp_clear_scheduled_hook()` take.

<br>

### `get_plugin()`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

For the plugin's own answers — its slug, its entry file, the headers it declares. To reach another module, `with()` is shorter and says what it is doing.

<br>

### `with( $name )`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Reach another module.

```php
final public function with( string $name ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The module class to reach |
| **Return** | The shared instance |
| **Throws** | `ModuleException` — If it is not declared, or has not booted yet |

The one way anything in a plugin reaches anything else. Returns the same instance every time, so two callers asking for `Options` share its state:

```php
$this->with( Options::class )->get( 'api_key' );
```

**The module has to be listed in `bootstrap.php`.** Asking for one that is not throws, naming the class and the file to add it to — nothing is built because something asked for it, so that file stays the whole inventory of what the plugin is made of.

A module that names a `boots_on` also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.

<br>

### `is_enabled()`

*Inherited from [`WithEnablement`](../../kernel/with-enablement.md).*

Whether this should be registered at all.

```php
public function is_enabled(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

Called once, after the instance is wired and before anything is registered. Return false and nothing happens: no hook is bound and no WordPress registration is made.

The default is true, so a file that says nothing registers — being on disk is the convention, and this is the exception to it.

It registers nothing either way.
