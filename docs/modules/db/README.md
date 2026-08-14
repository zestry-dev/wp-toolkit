<!--
    Generated from src/Modules/DB.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# DB

Names a plugin's own database tables, and WordPress's.

A custom table is `{$wpdb->prefix}{plugin_prefix}_{name}`, so it carries both the site's prefix and the plugin's, and cannot collide with another plugin's table of the same local name. The plugin's half defaults to its slug and is settable with `set_table_prefix()`. `get_table()` builds the whole name; nothing else in a plugin should build it by hand.

`Migrations` uses this module to create tables, and every other module, route, block or command uses it to find them afterwards — which is the point of it being a module every one of them can reach rather than a method on `Migration`: a table created in a migration is queried everywhere else.

[Adding it](#adding-it) &nbsp;·&nbsp; [Naming a table](#naming-a-table) &nbsp;·&nbsp; [Reading and writing rows](#reading-and-writing-rows) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add db
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `DB` does nothing until something asks, so it goes at the top level — which `wp zt add` writes for you. Left out, the first `with( DB::class )` throws rather than building it, which [`wp zt doctor`](../../commands/doctor.md) catches before a request does.

```php
// bootstrap.php
return array(
    DB::class,
);
```

## Naming a table

```php
$db = $plugin->get( DB::class );

$db->get_table( 'submissions' );   // wp_acme_plugin_submissions
$db->get_core_table( 'users' );    // wp_users
```

## Reading and writing rows

`$wpdb` does the querying; this module names the table it runs against. `get_wpdb()` hands you the handle so there is no `global` line — assign it to `$wpdb`, and read that method for why the name of the variable matters.

Pass the table through `%i`, WordPress's identifier placeholder (6.2+), rather than interpolating it: `%i` backtick-quotes what it is given, so the query is correct even for a name that would need quoting. `%s` is for values and would wrap the table in single quotes, which is a string, not a table.

```php
$wpdb = $this->with( DB::class )->get_wpdb();

$wpdb->get_results(
    $wpdb->prepare(
        'SELECT * FROM %i WHERE status = %s',
        $this->with( DB::class )->get_table( 'submissions' ),
        'unread'
    )
);

$wpdb->insert( $this->with( DB::class )->get_table( 'submissions' ), array( 'status' => 'unread' ), array( '%s' ) );
```

There is no `query()`, `insert()` or `get_results()` on this class. Naming a table and running a query are two jobs, and only the first is ambiguous enough to need help: a local name says nothing about whether it is one of yours or one of WordPress's, which is the difference between `get_table()` and `get_core_table()`. A wrapper taking that name would have to guess.

## Changing the defaults

Configure it only to shorten the table prefix. It defaults to the plugin slug, which is usually right — but MySQL caps a table name at 64 characters, and a long slug can leave too little room for the table's own name. Set a shorter prefix rather than renaming the plugin.

Decide it before the first migration runs: changing it later renames nothing, so the existing tables stay under the old name and your plugin stops finding them.

`bootstrap.php` is modules only, so the configuration goes in your entry file, where the callback runs the first time something asks for the module.

```php
// acme-plugin.php
( new Plugin( __FILE__ ) )
    ->configure(
        DB::class,
        static function ( DB $db ): void {
            $db->set_table_prefix( 'mc' );   // wp_mc_submissions
        }
    )
    ->bootstrap()
    ->run();
```

## Constants

### `MAX_IDENTIFIER_LENGTH`

```php
const MAX_IDENTIFIER_LENGTH = 64;
```

The longest identifier MySQL accepts, in characters.

## Methods

### `set_table_prefix( $prefix )`

Set the prefix this plugin's tables carry, in place of its slug.

```php
public function set_table_prefix( string $prefix ): void
```

|  | Details |
|---|---|
| **Parameters** | `$prefix` — The prefix, without a trailing underscore |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the prefix is empty or not a legal identifier fragment |

Call this from `configure()` in your entry file. Rejected outright if it is not a legal SQL identifier fragment, hyphens included: a slug is normalised because a plugin inherits it from its directory name, but a prefix is one you chose here, so silently rewriting it would hide that choice from you. Write the underscore.

<br>

### `get_table( $name )`

The full, plugin-namespaced name of a custom table.

```php
public function get_table( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local table name, e.g. 'submissions' |
| **Return** | The `{$wpdb->prefix}{plugin_prefix}_{name}` table name |
| **Throws** | `InvalidArgumentException` — When the name is empty, illegal, or too long for MySQL |

The plugin's prefix is normalised on the way in: a hyphen is the convention for a WordPress plugin slug (`contact-form-7`, `woocommerce-admin`) and is not legal in an unquoted SQL identifier, which is what `dbDelta()` needs — an unquoted `wp_contact-form-7_entries` fails to create, and `dbDelta()` reports success regardless.

<br>

### `get_core_table( $name )`

The name of one of WordPress's own tables.

```php
public function get_core_table( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The core table's own name, e.g. 'posts' or 'users' |
| **Return** | The table name for the current site |
| **Throws** | `InvalidArgumentException` — When WordPress declares no such table |

Read off `$wpdb` rather than built from its prefix, because the two disagree on multisite: `posts` is per-site (`wp_2_posts`) while `users` and `usermeta` are shared network-wide (`wp_users`, never `wp_2_users`). Composing `$wpdb->prefix . 'users'` by hand is correct on a single site and silently wrong on a network.

Only a name WordPress declares as a table is accepted. Anything else throws, including a `$wpdb` property that is not one.

<br>

### `table_exists( $name )`

Whether one of this plugin's tables exists.

```php
public function table_exists( string $name ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local table name, e.g. 'submissions' |
| **Return** | True when the table is present |
| **Throws** | `InvalidArgumentException` — When the name is empty, illegal, or too long for MySQL |

Worth checking after a migration rather than trusting it: `dbDelta()` reports the statements it decided to run, not the ones that succeeded.

<br>

### `get_wpdb()`

WordPress's own `$wpdb`, so a caller needs no `global` line.

```php
public function get_wpdb(): \wpdb
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | WordPress's database handle |
| **Throws** | — |

**Assign it to a variable called `$wpdb`. Do not chain off this call.**

```php
$wpdb = $this->with( DB::class )->get_wpdb();

$rows = $wpdb->get_results(
    $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s', $this->with( DB::class )->get_table( 'submissions' ), 'unread' )
);
```

`WordPress.DB.PreparedSQL` is what catches a value interpolated into a query instead of prepared, and it finds a query by the *variable name* `$wpdb` — `WPDBTrait::is_wpdb_method_call()` tests the token, and there is no setting for another. So `$wpdb->query( "... $value ..." )` is flagged and `$this->with( DB::class )->get_wpdb()->query( "... $value ..." )` is not. Chaining is the one form that turns `composer lint` green over an injection; the assignment costs the same line the `global` did and keeps every sniff working.

Which is also why there is no `query()`, `prepare()`, `get_var()`, `get_row()`, `get_col()` or `get_results()` on this class, and will not be.

<br>

### `get_charset_collate()`

The `DEFAULT CHARACTER SET ... COLLATE ...` clause for the current site.

```php
public function get_charset_collate(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

`dbDelta()` expects each `CREATE TABLE` statement to end with this, so a new table matches the site's own configured charset and collation rather than the server's default.

<br>

### `get_table_prefix()`

The plugin's own table-name prefix, normalised for SQL.

```php
public function get_table_prefix(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The configured prefix, or the slug, with a trailing underscore |
| **Throws** | — |

Public so you can build a name this module does not cover — an index name, say — against the same prefix your tables use.

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

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be built on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook is behind. This behaves the same either way, so a module never has to care which.

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

- [`Module`](../module.md) — what every module inherits
- [`wp zt add db`](../../commands/add.md) — the command that copies it
