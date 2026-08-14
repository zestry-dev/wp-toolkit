<!--
    Generated from src/Modules/Cron/Cron.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Cron

Discovers `resources/schedules/` &nbsp;·&nbsp; Each file returns [`Schedule`](schedule.md) &nbsp;·&nbsp; Dependencies [`path`](../path/)

Discovers plugin WP-Cron schedules and keeps them registered.

A schedules directory contains PHP files, one per recurring event. Each file returns a `Schedule` instance; a file named `cleanup-logs.php` registers under the hook `{plugin-slug}-cleanup-logs` (see `get_schedule_slug()`). The module binds that hook to the schedule's `run()` on every request, since WP-Cron dispatches through a fresh request with no memory of any other. It calls `wp_schedule_event()` only when the event is not already scheduled, so re-running discovery on every `init` never stacks duplicates.

> [!WARNING]
> **WP-Cron has no background process.** An event fires only when some ordinary page load notices it is due. On a low-traffic site it may fire late, or never. For anything time-sensitive, disable the pseudo-cron with `define( 'DISABLE_WP_CRON', true );` and drive it from a real crontab running `wp cron event run --due-now`.

`wp cron event list` and `wp cron schedule list` show what this module has scheduled, under the hook names `get_schedule_slug()` produces. Both are built into WP-CLI, so this module adds no commands of its own.

Changing `recurrence()` in a later release does take effect: every request compares the scheduled recurrence against what the method now returns and re-schedules the event when they differ. `initial_run_at()` is read only when an occurrence is actually created — on first registration, and again on the re-schedule a changed `recurrence()` forces. Change it on its own and nothing moves on a site that already has the event.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Schedule](#writing-a-schedule) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add cron
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `Cron` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'init' => array(
        Cron::class,
    ),
);
```

## Changing the defaults

Declare a custom interval schedules can then ask for by name. The entry's value is the callback that configures the module, and it runs on the boot hook, before anything is registered.

```php
// bootstrap.php
return array(
    'init' => array(
        Cron::class => static function ( Cron $cron ): void {
            $cron->add_custom_interval( 'every_15_minutes', 15 * MINUTE_IN_SECONDS, 'Every 15 Minutes' );
        },
    ),
);
```

## Writing a Schedule

A file in `resources/schedules/` returns a [`Schedule`](schedule.md) instance, which `wp zt make schedule <name>` generates.

## Constants

### `SCHEDULES_ROOT`

```php
const SCHEDULES_ROOT = 'resources/schedules';
```

Default plugin-relative directory of schedule files.

## Methods

### `add_custom_interval( $name, $seconds, $display )`

Register a custom WP-Cron interval.

```php
public function add_custom_interval( string $name, int $seconds, string $display ): void
```

|  | Details |
|---|---|
| **Parameters** | `$name` — Local interval name, e.g. 'every_15_minutes'<br>`$seconds` — Seconds between occurrences<br>`$display` — Human-readable label shown in wp-admin |
| **Return** | — |
| **Throws** | — |

WordPress has no built-in interval shorter than `'daily'` besides `'hourly'`/`'twicedaily'` — anything else must be added to the `cron_schedules` filter before `wp_schedule_event()` will accept it. Call this from the module initializer, then reference it from a schedule's `recurrence()` via `get_custom_interval_slug()`, so the name a schedule refers to can never drift out of sync with what was actually registered here.

<br>

### `get_custom_interval_slug( $name )`

Build the globally namespaced custom interval slug.

```php
public function get_custom_interval_slug( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local interval name |
| **Return** | The namespaced interval slug |
| **Throws** | — |

<br>

### `get_schedule_slug( $name )`

Build the globally namespaced WordPress cron hook.

```php
public function get_schedule_slug( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local schedule name |
| **Return** | The namespaced hook name |
| **Throws** | — |

<br>

### `schedule( $name )`

Ensure a discovered schedule is scheduled with WP-Cron.

```php
public function schedule( string $name ): void
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local schedule name (its filename without `.php`) |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When no schedule file matches $name, or its recurrence is not registered<br>`DiscoveryException` — When the file returns something other than a Schedule instance |

Loads the schedule the same way discovery does, then applies the same already-scheduled/recurrence-drift check register_schedules() applies to every file it discovers — useful to re-arm a schedule that was previously cleared, without waiting for the next full discovery pass.

<br>

### `run_now( $name )`

Run a schedule's work immediately, bypassing WP-Cron entirely.

```php
public function run_now( string $name ): void
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local schedule name (its filename without `.php`) |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When no schedule file matches $name<br>`DiscoveryException` — When the file returns something other than a Schedule instance |

Loads and wires the schedule the same way discovery does, then calls its run() synchronously in the current request — the same concurrency lock and error handling that a real cron dispatch gets still applies.

<br>

### `unschedule_all()`

Clear every discovered schedule's WP-Cron events.

```php
public function unschedule_all(): void
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | — |
| **Throws** | `DiscoveryException` — When a file returns something other than a Schedule instance |

Exposed for a consuming plugin's own ActivationHandler subclass to call from deactivate() — Cron does not implement ActivationHandler itself, so nothing clears scheduled events automatically; a plugin that schedules events is responsible for unscheduling them.

Clearing runs over the schedules discovery finds, so it fails the same way discovery does on a broken schedules directory.

<br>

### `get_orphaned_events()`

Every event this plugin has scheduled that no schedule file registers.

```php
public function get_orphaned_events(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Hook name => the timestamp it next fires, earliest first |
| **Throws** | `DiscoveryException` — When a file returns something other than a Schedule instance |

A schedule's hook is its filename — `resources/schedules/sync.php` is `{slug}-sync` — so renaming the file schedules a new event and abandons the old one. Nothing cleans it up: booting only schedules what discovery finds, and `unschedule_all()` clears the same set, so an event whose file is gone is in neither list. WordPress keeps firing it, on time, forever, with nothing listening.

This reports; `unschedule_orphaned()` clears.

Every occurrence of a hook is one orphan, so a hook due several times reports once, at the first.

<br>

### `unschedule_orphaned()`

Clear every event `get_orphaned_events()` reports, and say which.

```php
public function unschedule_orphaned(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The hooks cleared, in the order they would next have fired |
| **Throws** | `DiscoveryException` — When a file returns something other than a Schedule instance |

A separate call, and never made by this module: a `{slug}-` event this module did not create is indistinguishable from one it did, so clearing automatically could delete an event you scheduled by hand. Run it from wherever you decide — a deploy step, a reviewed admin action, an activation handler — once you have looked at the list.

<br>

### `get_slug_of( $schedule )`

This schedule's hook name, from the file it was discovered in.

```php
public function get_slug_of( Schedule $schedule ): string
```

|  | Details |
|---|---|
| **Parameters** | `$schedule` — The instance to look up |
| **Return** | The `{plugin-slug}-{name}` hook it is scheduled under |
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module |

<br>

### `on_wp_init( $callback, $priority )`

*Inherited from [`Module`](../module.md).*

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function on_wp_init( callable $callback, int $priority = 10 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run<br>`$priority` — WordPress hook priority, honoured only while `init` is still ahead |
| **Return** | — |
| **Throws** | — |

Reach for this wherever a plain `add_action( 'init', ... )` would go: that callback never runs once `init` has passed, and a module can be built on either side of it.

The callback receives the module, so a closure declared elsewhere needs no `use` to reach it:

```php
public function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only while `init` is still ahead**: a module built after `init` has fired runs its callback immediately, in registration order, whatever priority it asked for. Ordering that has to hold either way belongs inside one callback.

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

A module listed under a heading also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.

## See also

- [`Schedule`](schedule.md) — what a file in `resources/schedules/` returns
- [`path`](../path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add cron`](../../commands/add.md) — the command that copies it
