<!--
    Generated from src/Modules/Migrations/Migrations.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Migrations

Discovers `resources/migrations/` &nbsp;·&nbsp; Each file returns [`Migration`](migration.md) &nbsp;·&nbsp; Dependencies [`path`](../path/), [`db`](../db/), [`options`](../options/), [`cli`](../cli/)

Discovers plugin database migrations and runs each one, at most once, in filename order.

> [!IMPORTANT]
> **Nothing here runs on its own. You decide when.** Booting this module only registers the `wp {slug} migrations run`/`migrations list` commands, and those are invoked by hand. Call `run_pending()` from whatever fits your release process.

A hook cannot close the gap between new code and its migration: WordPress swaps the code in first, so a request landing mid-migration runs new code against the old schema. A release process can. Put the site in maintenance mode, migrate, then let requests back in — or migrate as a deploy step before the new code goes live at all.

A migrations directory contains PHP files named `{timestamp}-{description}.php`, e.g. `20260115120000-create-books-table.php`. The leading timestamp is the sort key, so migrations run in the order you authored them — not alphabetically by description, and not in filesystem order. Each file returns a `Migration` instance. The module wires it and calls `up()` exactly once per site, recording the whole filename without its `.php` extension in a dedicated `Options` group so a later run skips it.

That recorded name is the migration's identity, description and all, so renaming any part of the file — not only its timestamp — makes it a migration your site has never run, and the next run runs it again.

That consequence is visible rather than silent. The identifier that ran is still recorded, so a rename leaves an *orphan*: a recorded name with no file. `migrations list` reports one as `orphaned`, next to the new filename's `pending` row and sharing its timestamp prefix, and `run_pending()` refuses the whole batch when it sees that pair rather than running the migration a second time. Both are reporting only — identity is still the filename, and a migration still cannot name itself.

The two registered commands are `wp {slug} migrations run`, which runs every pending migration and takes `--force`, and `wp {slug} migrations list`, which prints each identifier with a `ran`, `pending` or `orphaned` status and takes `--format=<table|csv|json|yaml|count>`, defaulting to `table`.

> [!WARNING]
> **Keep every migration's timestamp the same width.** Filenames are sorted as plain strings, with no numeric-aware pass, so mixing widths (some zero-padded, some not) silently sorts them wrong. `wp zt make migration` generates a correct `YYYYMMDDHHmmss` prefix — in UTC, so migrations authored from different timezones still sort against each other correctly.

[Adding it](#adding-it) &nbsp;·&nbsp; [Triggering a run](#triggering-a-run) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Migration](#writing-a-migration) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add migrations
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `Migrations` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'acme_plugin_loaded' => array(
        Migrations::class,
    ),
);
```

`acme_plugin_loaded` is your plugin's own action, fired at the end of `run()` once every module is built — `{slug}_loaded`, so a plugin slugged `acme-crm` spells it `acme_crm_loaded`. It is the earliest heading that still has the whole plugin behind it.

## Triggering a run

Call `run_pending()` from wherever fits your release process — here, immediately on activation. Or trigger it from `wp {slug} migrations run` as an explicit deploy step, from a reviewed action on an admin screen, or from anything else that lets you decide exactly when migrations run relative to the new code.

A PHP timeout can still cut `run_pending()` off partway through a batch (some migrations recorded as run, some not) — see `maybe_resume_interrupted_run()` for the opt-in recovery mechanism a periodic hook of your own can call to detect and resume that.

[`wp zt make activation`](../../commands/make-activation.md) writes the class and declares it. `ActivationHandler` makes both `activate()` and `deactivate()` abstract, so neither can be forgotten — even when, as here, there is nothing to undo. Its one timing rule is that your entry file must call `run()` as it loads: see [`ActivationHandler`](../activation-handler.md).

```php
class MyActivation extends ActivationHandler {
    public function activate( bool $network_wide ): void {
        $this->with( Migrations::class )->run_pending();
    }

    public function deactivate( bool $network_wide ): void {
    }
}
```

## Changing the defaults

`Migrations` takes no configuration. The entry above is all it needs — reach it with `$this->with( Migrations::class )` from any module or discovered file, or `$plugin->get( Migrations::class )` from your entry file.

## Writing a Migration

A file in `resources/migrations/` returns a [`Migration`](migration.md) instance, which `wp zt make migration <name>` generates.

## Constants

### `OPTIONS_GROUP_NAME`

```php
const OPTIONS_GROUP_NAME = '_migrations_';
```

The Options group the list of migrations that have run is stored under.

### `MIGRATIONS_ROOT`

```php
const MIGRATIONS_ROOT = 'resources/migrations';
```

Default plugin-relative directory of migration files.

## Methods

### `get_ran_migrations()`

Every migration identifier — its filename without the `.php` extension, e.g. `20260115120000-create-books-table` — already recorded as run, in the order they ran.

```php
public function get_ran_migrations(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

<br>

### `get_discovered_migrations()`

Every migration identifier discovered in the migrations directory, in filename order, regardless of whether it has run.

```php
public function get_discovered_migrations(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

Exposed for `wp {slug} migrations list` (`ListMigrationsCommand`), separate from `run_pending()` so listing never requires (and cannot accidentally trigger) requiring or running any migration file.

<br>

### `get_orphaned_migrations()`

Every migration identifier recorded as run for which no file exists.

```php
public function get_orphaned_migrations(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

An orphan means one of exactly two things, and nothing here tries to tell them apart: the file was renamed, or it was deleted. The first is dangerous — the migration is about to run a second time under its new name — and the second is usually deliberate. Both are worth seeing.

Returned in the order the identifiers ran, since that is the order the ran-list already holds them in.

<br>

### `get_probable_renames()`

Pending migrations that look like a rename of one that has already run.

```php
public function get_probable_renames(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Each pending identifier, mapped to the orphan it probably renames |
| **Throws** | — |

A pending migration is a probable rename when an orphan (`get_orphaned_migrations()`) shares its timestamp prefix and differs in the rest. That is precise because the prefix is the one part of a filename this module documents as never safe to change: a rename in practice keeps it and edits the description, which is exactly this shape.

A heuristic all the same. Two migrations authored in the same second would match, which is why `run_pending()` refuses rather than repairs, and why it takes a `$force` to go ahead anyway.

An identifier with no leading digits has no timestamp to compare and never matches — a plugin naming migrations some other way gets no guesses rather than wrong ones.

<br>

### `run_pending( $force )`

Discover every migration file, in filename order, and run each one not already recorded as run.

```php
public function run_pending( bool $force = false ): void
```

|  | Details |
|---|---|
| **Parameters** | `$force` — Run even when a pending migration looks like a rename of one that already ran |
| **Return** | — |
| **Throws** | `DiscoveryException` — When a file returns the wrong value<br>`RenamedMigrationException` — When a pending migration looks like a rename and $force is false |

Public because nothing calls it automatically. Call it from wherever the plugin decides migrations should run: an `ActivationHandler::activate()`, a reviewed action on an admin screen, a deploy script, `wp {slug} migrations run`, or a hook of the plugin's own.

> [!IMPORTANT]
> **A failing migration stops the batch, and its exception propagates.** The schema is now not what the plugin assumes, and later migrations likely build on the one that just failed — continuing would compound the damage silently. Cron's dispatch catches and logs instead, because one failed schedule must not stop the others.

A probable rename (`get_probable_renames()`) stops the batch before anything runs at all, since running a renamed migration a second time is the damage rather than a symptom of it. Pass `$force` to go ahead: that runs the rename as the new migration it now looks like, and leaves the old identifier recorded.

<br>

### `maybe_resume_interrupted_run()`

Re-run `run_pending()` if a previous run never reached its own end.

```php
public function maybe_resume_interrupted_run(): void
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | — |
| **Throws** | `DiscoveryException` — When a file returns the wrong value |

`run_pending()` records a `running_since` timestamp before it starts and clears it only once every pending migration has run (or thrown). If PHP is killed mid-run — `max_execution_time`, an OOM kill — neither of which a `try`/`finally` can trap, that timestamp is left behind: proof migrations 1-3 of 5 ran but 4 and 5 did not.

This module never calls it for you, the same way it never calls `run_pending()` for you. For automatic recovery, hook something periodic of your own — `admin_init`, a cron schedule, whatever fits — and call this from there. Without that, an interrupted run stays incomplete until your own trigger calls `run_pending()` again.

A `running_since` younger than five minutes is left alone. It reads as a slow run still going on another request, not an interrupted one — without that, two requests arriving mid-run would both try to resume it at once. Override `get_stale_run_threshold()` in a subclass to move that line.

Exempt from the probable-rename refusal, which is why this forces the run rather than repeating it. This exists to finish a batch a timeout cut in half, and that batch's pending set was already vetted when it started; blocking a resume on a heuristic would strand a half-migrated site, which is the one state worse than either outcome the heuristic guards against.

<br>

### `get_stale_run_threshold()`

Seconds a `running_since` timestamp is trusted to reflect a run still genuinely in progress, before `maybe_resume_interrupted_run()` treats it as abandoned and safe to resume.

```php
protected function get_stale_run_threshold(): int
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Seconds; five minutes unless a subclass overrides it |
| **Throws** | — |

Deliberately generous relative to typical PHP execution limits, since treating a merely slow (but still running) migration as interrupted would resume it concurrently with itself.

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

## See also

- [`Migration`](migration.md) — what a file in `resources/migrations/` returns
- [`path`](../path/) — copied in alongside this one
- [`db`](../db/) — copied in alongside this one
- [`options`](../options/) — copied in alongside this one
- [`cli`](../cli/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add migrations`](../../commands/add.md) — the command that copies it
