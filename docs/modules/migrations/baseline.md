<!--
    Generated from src/Modules/Migrations/Baseline.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Baseline

[Constants](#constants) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

A migration that stands in for every migration before it, on a site that has never run any of them.

A plugin two years old has forty migrations, and a fresh install runs all forty in a row — each one introspecting and diffing the same tables through `dbDelta()` to arrive at a schema the last one could have created outright. On an activation hook that is slow enough to hit a timeout, and none of the work was ever needed: there was no old schema to migrate.

A baseline is that last step, written down. `wp {slug} migrations squash` generates one from the schema your migrations have actually produced, so what it creates and what they build cannot disagree.

## When it runs, and when it does not

**Only on a site where no migration has ever run.** That is the whole rule, and it is what makes a baseline safe: a site with a history has some of these migrations behind it and the rest genuinely pending, and only running them one by one gets it to the same place.

- **Fresh install.** The baseline runs. Every migration sorting before it is
recorded as run without being executed, and anything after it runs as normal.
- **Existing install.** The baseline is recorded as run *without* being
executed — the schema it describes is already here — and the migrations it would have subsumed run individually, exactly as they did before it existed.

Nothing changes at the call site either way: `Migrations::run_pending()` is still the one entry point, and your activation handler does not learn that baselines exist.

> [!WARNING]
> **A baseline carries schema, and only schema.** If a migration it subsumes also seeded a default option, inserted a row or registered a term, a fresh install now never does that — the migration is recorded as run without running. `migrations squash` lists everything it is about to subsume for exactly this reason: read that list, and carry anything non-schema into the baseline's own `up()` by hand.

## What it stands in for

`subsumes()` names them, one identifier per migration, and nothing else decides. Every other file here takes its place in the run order from its filename; a baseline is not a step in that order but a replacement for a stretch of it, so what it covers is a list. Which is why the file is simply `baseline.php`, with no timestamp to carry: one file, rewritten in place each time you squash, so a squash reads as a diff.

The list being explicit is what makes it exact. A migration added later with a backdated filename is not in it, so it still runs; a subsumed migration that has since been deleted is simply skipped. Positional subsumption — everything sorting before this file — got both of those wrong, quietly.

There is at most one baseline. Squashing again rewrites it; two on disk is a `ManyBaselinesException`, since nothing could say which of them a fresh install should believe.

## Constants

### `FILENAME`

```php
const FILENAME = 'baseline';
```

The name `wp {slug} migrations squash` writes this under, without `.php`.

## You must implement

These 2 methods are abstract: a subclass that does not declare all of them will not load.

### `subsumes()`

Every migration this baseline stands in for.

```php
abstract public function subsumes(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

Identifiers, exactly as `migrations list` prints them: a filename without its `.php`. Each is recorded as run, without running, on the fresh install this baseline serves.

Abstract rather than defaulting to none, because a baseline that stands in for nothing is not a smaller baseline — it is one that runs and then lets every migration run as well, which is the slow path it was written to replace. Saying so has to be deliberate.

An identifier here with no file on disk is skipped rather than recorded, so deleting an old migration does not leave a phantom in the ledger.

<br>

### `up()`

Run this migration's schema or data change.

```php
abstract public function up(): void
```

Called at most once, ever, per site — the Migrations module records this migration's identifier as run immediately after this returns without throwing, and never calls it again. Throwing leaves the migration unrecorded, so it is retried the next time migrations run.

## Methods you can use

### `db_delta( $queries )`

Run one or more `CREATE TABLE`/`ALTER TABLE` statements through WordPress core's own `dbDelta()`.

```php
final public function db_delta( array|string $queries ): array
```

|  | Details |
|---|---|
| **Parameters** | `$queries` — One or more `CREATE TABLE` statements |
| **Return** | DbDelta()'s own per-statement result strings |
| **Throws** | `RuntimeException` — When a table dbDelta() reported creating does not exist |

Loads `wp-admin/includes/upgrade.php` on demand, since `dbDelta()` is not available on an ordinary front-end request. `dbDelta()` has strict, well-documented formatting requirements of its own (two spaces between `PRIMARY KEY` and the column list, each `KEY`/`UNIQUE KEY` on its own line, ...) that this method does not validate or relax — write the SQL the way WordPress's own Codex documents `dbDelta()` requiring it.

Name the table with `get_table()` rather than composing it: the plugin slug is hyphenated by convention and a hyphen is illegal in an unquoted SQL identifier, which is what `dbDelta()` needs.

Verifies afterwards that every table `dbDelta()` claimed to create really exists, and throws when one does not. `dbDelta()`'s return value reports the statements it decided to run rather than the ones that succeeded, so without this check a `CREATE TABLE` that MySQL rejected would report success, create nothing, and — because `up()` returned without throwing — be recorded as run and never retried.

<br>

### `get_table( $name )`

The full, plugin-namespaced name of a custom table.

```php
final public function get_table( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local table name, e.g. 'books' |
| **Return** | The `{$wpdb->prefix}{plugin_slug}_{name}` table name |
| **Throws** | `InvalidArgumentException` — When the name is empty, illegal, or too long for MySQL |

Delegates to `DB::get_table()`, which is also what everything outside a migration uses: a table created here is queried from routes, blocks and commands, and all of them have to agree on its name.

<br>

### `get_charset_collate()`

The `DEFAULT CHARACTER SET ... COLLATE ...` clause for the current site.

```php
final public function get_charset_collate(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

`dbDelta()` expects each `CREATE TABLE` statement to end with this, so a new table matches the site's own configured charset/collation rather than silently defaulting to the server's.

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
