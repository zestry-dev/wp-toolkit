<!--
    Generated from src/Modules/Migrations/Migration.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Migration

[Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for a file-based, one-time database migration.

A migration file returns a subclass instance. The Migrations module wires it, assigning the shared plugin and injecting typed module dependencies, then calls `up()` exactly once for a given site. Once it has run successfully it never runs again, tracked by the migration's own identifier — see `Migrations` for how that identifier comes from the filename.

Forward-only: there is no `down()`. A WordPress plugin has no staging/production migration pipeline to roll back through the way a Rails or Laravel app might — a schema change either ships forward in a later migration or is left alone. Write a new migration to undo a mistake, rather than reversing an old one in place.

A file at `migrations/20260115120000-create-books-table.php` runs once, in filename order. `wp zt make migration <name>` generates a starting point, timestamp prefix included. A migration doing something `dbDelta()` cannot express (a data backfill, an index `dbDelta()` cannot parse, a one-off `UPDATE`) uses `$wpdb` directly — declare it as a typed property like any other injected dependency, or reach `$GLOBALS['wpdb']` the way WordPress code ordinarily does.

## Generated starting point

[`wp zt make migration <name>`](../../commands/make-migration.md) writes this file:

```php
<?php
/**
 * Example.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\Migrations\Migration;

return new class() extends Migration {

	// This file's name is the migration's identity, description and all. Rename
	// it and this becomes a migration your sites have never run, so the next
	// run runs it again -- harmless for a dbDelta, not for a backfill.
	// `wp {plugin-slug} migrations run` refuses when it spots that, but only
	// once it has already happened somewhere.

	// Runs at most once, ever, per site -- recorded as run immediately after
	// this returns without throwing, and never called again. Throwing leaves
	// it unrecorded, so it retries next time (any side effects before the
	// throw are NOT rolled back automatically). There is no down(): write a
	// new migration to undo a mistake, don't reverse this one in place.
	//
	// $this->db_delta( "CREATE TABLE {$this->get_table( 'books' )} (
	//     id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	//     title VARCHAR(255) NOT NULL,
	//     PRIMARY KEY  (id)
	// ) {$this->get_charset_collate()};" );
	//
	// $this->get_table( 'books' ) -> the plugin-namespaced table name
	// ({$wpdb->prefix}{plugin_slug}_books), with the slug normalised for SQL.
	// Always name a table this way rather than composing it: a hyphen is legal
	// in a plugin slug and illegal in an unquoted SQL identifier, and db_delta()
	// reports success either way.
	//
	// db_delta() has its own strict formatting rules this does not relax (two
	// spaces before PRIMARY KEY, each KEY on its own line). It does verify that
	// a table it reported creating really exists, and throws if not, so a failed
	// migration stays unrecorded and is retried.
	//
	// For anything db_delta() can't express -- a data backfill, an index it
	// can't parse, a one-off UPDATE -- use $wpdb directly instead.
	public function up(): void {
	}
};
```

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

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
