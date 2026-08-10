# Errors

Four exception classes cover every way a service or module can fail to come up. They form one chain, so a single `catch` handles all of them:

```
\RuntimeException
└── ModuleException                    declaring, resolving or booting failed
    ├── ModuleNotFoundException        the class cannot be built
    ├── CircularDependencyException    two classes need each other
    └── DiscoveryException             a directory or a discovered file is wrong
```

All four live under `Acme\Plugin\Core\Kernel\Exceptions\` once `wp zestry init` has copied the kernel into your plugin.

One more sits outside that chain: [`RenamedMigrationException`](modules/migrations/), raised when a pending migration shares a timestamp with one that already ran and no longer has a file. Nothing runs when it is thrown.

## Catching them

Everything above is thrown while the plugin comes up, so one `catch` around `run()` covers the lot:

```php
use Acme\Plugin\Core\Kernel\Exceptions\ModuleException;
use Acme\Plugin\Core\Kernel\Plugin;

function acme_plugin(): ?Plugin {
    static $plugin = null;

    if ( null !== $plugin ) {
        return $plugin;
    }

    try {
        return $plugin = ( new Plugin( __FILE__ ) )->bootstrap()->run();
    } catch ( ModuleException $exception ) {
        add_action(
            'admin_notices',
            static function () use ( $exception ): void {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html( 'Acme Plugin failed to start: ' . $exception->getMessage() )
                );
            }
        );

        return null;
    }
}
```

Catching narrows the blast radius to your plugin instead of the whole site. It does not fix anything, so keep the message visible — every one of these names the exact file, class or directory at fault.

Catch the subclasses individually only when you genuinely handle them differently. `catch ( DiscoveryException $e )` alone, for instance, treats a malformed feature file as recoverable while still letting a broken `bootstrap.php` take the request down.

---

## `ModuleException`

[Reference](kernel/module-exception.md)

The base class, and thrown directly for a `bootstrap.php` the plugin cannot read.

```
Bootstrap file must return an array: /…/acme-plugin/bootstrap.php
Bootstrap entries must name a class.
Bootstrap entry for Acme\Plugin\Core\Modules\CLI\CLI must be a callable, or omitted.
Module initializer must be callable.
```

**What causes it.** A `bootstrap.php` with no `return`, or one returning something other than an array. An entry whose key is a string but whose value is neither a callable nor omitted — usually a stray configuration array where an initializer closure was meant.

**What to do.** The file is one flat array. A module needing no configuration is written bare; one that needs it gets a callable:

```php
// bootstrap.php
use Acme\Plugin\Core\Modules\AdminPages\AdminPages;
use Acme\Plugin\Core\Modules\CLI\CLI;

return array(
    CLI::class => static function ( CLI $cli ): void {
        $cli->set_commands_root( 'cli/commands' );
    },
    AdminPages::class,
);
```

---

## `ModuleNotFoundException`

[Reference](kernel/module-not-found-exception.md)

```
Class Acme\Plugin\Modules\Shortcode does not exist.
Class Acme\Plugin\Modules\Shortcode must extend Acme\Plugin\Core\Kernel\Abstracts\Service.
```

**What causes it.** Something asked for a class the plugin cannot build — via `get()`, `make()`, a `bootstrap.php` entry, or an injected property typed as it.

The first message is almost always a namespace or autoload problem, and the copied source carries a `Core` segment your own code does not. The second means the class exists but extends neither `Service` nor `Module`; the repository builds only those two.

**What to do.** Check the namespace against the map in [Troubleshooting](troubleshooting.md#class-not-found), then `composer dump-autoload`. If the class is yours, make sure it extends one of the base classes:

```php
use Acme\Plugin\Core\Kernel\Abstracts\Module;   // acts on its own
use Acme\Plugin\Core\Kernel\Abstracts\Service;  // works when called
```

A declaration in `bootstrap.php` whose file has since been deleted or renamed is the other common source. `wp zestry doctor` finds those before they reach a request.

---

## `CircularDependencyException`

[Reference](kernel/circular-dependency-exception.md)

```
Circular module dependency detected: Acme\Plugin\Modules\Reports -> Acme\Plugin\Modules\Exporter -> Acme\Plugin\Modules\Reports.
```

**What causes it.** Injected properties formed a cycle: resolving `Reports` injected `Exporter`, whose own injected property is `Reports`, which is still mid-construction. The message prints the whole chain in resolution order, ending with the class that closed it.

**What to do.** Break the cycle at one end by fetching lazily instead of declaring the property:

```php
namespace Acme\Plugin\Modules;

use Acme\Plugin\Core\Kernel\Abstracts\Module;

class Exporter extends Module {

    public function run(): void {
        // Resolved when the hook fires, by which point Reports is fully built.
        $reports = $this->get_plugin()->get( Reports::class );
    }

    protected function on_boot(): void {
        add_action( 'acme_export', array( $this, 'run' ) );
    }
}
```

`get_plugin()->get()` at *use* time is safe where a property is not, because the cycle only exists during construction. If the two classes need each other in both directions permanently, that is usually a sign the shared part wants to be a third class both depend on.

---

## `DiscoveryException`

[Reference](kernel/discovery-exception.md)

The one you will meet most, because it is the one your own feature files trigger. It arrives in five shapes — the two below that any module can raise, and three that only a particular destination produces.

**A discovered file returned the wrong thing.**

```
The file "/…/acme-plugin/commands/greet.php" must return an instance of
Acme\Plugin\Core\Modules\CLI\Command. Got: integer
```

`Got: integer` is a missing `return` — `require` yields `1` for a file that returns nothing. `Got: SomeClass` means the returned object does not extend the base class that module expects. Every discovery module requires each file and expects an instance back:

```php
// commands/greet.php
use Acme\Plugin\Core\Modules\CLI\Command;

return new class extends Command {

    public function handle( array $args, array $assoc_args ): void {
        $this->success( 'Hello.' );
    }
};
```

**A directory you named does not exist.**

```
Commands root directory does not exist: /…/acme-plugin/cli/commands
```

Each module names its own root, so the message says which one threw:

| Module | Message prefix |
|---|---|
| [`ajax`](modules/ajax/) | `Actions root directory does not exist: ` |
| [`admin-pages`](modules/admin-pages/) | `Pages root directory does not exist: ` |
| [`rest-api`](modules/rest-api/) | `Routes root directory does not exist: ` |
| [`cli`](modules/cli/) | `Commands root directory does not exist: ` |
| [`cron`](modules/cron/) | `Schedules root directory does not exist: ` |
| [`post-types`](modules/post-types/) | `Post types root directory does not exist: `, `Taxonomies root directory does not exist: ` |
| [`blocks`](modules/blocks/) | `Blocks root directory does not exist: ` |
| [`migrations`](modules/migrations/) | `Migrations root directory does not exist: ` |
| [`abilities`](modules/abilities/) | `Abilities root directory does not exist: ` |
| [`fields`](modules/fields/) | `Fields root directory does not exist: ` |
| [`meta-boxes`](modules/meta-boxes/) | `Meta boxes root directory does not exist: ` |
| [`site-health`](modules/site-health/) | `Health checks root directory does not exist: `, `Debug sections root directory does not exist: ` |

> [!IMPORTANT]
> **Only a root named through a `set_*_root()` call throws.** A *default* root that does not exist is not an error — the module discovers nothing and says nothing, so adding `cron` before you have written your first schedule is fine. Asking for a directory by name and getting nothing is a typo worth hearing about; having no files yet is not.

**Two files claim one name.**

```
Two admin pages resolve to the name "acme-plugin-reports": admin-pages/reports.php
and admin-pages/reports/index.php. Only one of them can be it, so rename the other.
```

Your filenames register as written, so this only happens where a name is built from more than the filename — `reports.php` and `reports/index.php` are two paths meaning one admin page. Neither is dropped in favour of the other, because keeping the first leaves the second registered against nothing and keeping the last makes the answer depend on directory order.

**A name the destination cannot carry.**

```
The admin page "Monthly Report.php" would register as "acme-plugin-Monthly Report",
which cannot appear in a URL as `?page=acme-plugin-Monthly Report`.
```

```
The ability "create_order.php" would register as "acme-plugin/create_order", which
WordPress refuses: an ability name takes only lowercase letters, digits and dashes
on either side of the `/`.
```

Two destinations hold a filename to their own character set: an [admin page](modules/admin-pages/)'s slug goes into `?page=`, and an [ability](modules/abilities/)'s name is matched against `^[a-z0-9-]+$`. Neither is repaired for you — a name spelled for you is one you cannot find again — so rename the file. `wp zestry make` writes an acceptable name in the first place, and says when it had to.

**WordPress refused the registration.**

```
WordPress refused to register the post type "acme_book" from acme_book.php. It gave
no reason, which usually means the name is not one it accepts.
```

Raised where WordPress reports a refusal by returning something falsy rather than by saying anything — `register_post_type()`, `register_taxonomy()` and `register_block_type()`. Check the name against what that function accepts: a post type is capped at 20 characters, a taxonomy at 32.

---

## What is not a `ModuleException`

Two families sit outside the hierarchy, so `catch ( ModuleException $e )` around `run()` does not swallow them.

**`\InvalidArgumentException` — you passed something wrong.** These are mistakes visible by reading the calling code, and they are your bug rather than a layout problem:

| Where | Message shape |
|---|---|
| [`Path`](services/path/) | `Resource path must stay within the plugin directory.` |
| [`Views`](services/views/) | `View file does not exist: dashboard (in views root: views)`, `Invalid view name.` |
| [`Assets`](modules/assets/) | `Asset manifest does not exist: /…/build/panel.asset.php` |
| [`DB`](services/db/) | `Table name "acme-reports" must contain only letters, digits and underscores.` |
| [`CLI`](modules/cli/) | `Command name collision: "report" (report.php) is also used as a subdirectory by "report list" (report/list.php).` |
| [`Cron`](modules/cron/) | `Schedule "digest" has an unregistered recurrence "fortnightly". Register it with Cron::add_custom_interval() first, or use a WordPress built-in (hourly, twicedaily, daily).` |
| [`RestApi`](modules/rest-api/) | `The file "/…/routes/report.php" has a pattern placeholder with no matching #[…\Attributes\RequestArgument] property: id.` |
| [`Request`](services/request/) | `The argument Acme\Plugin\Routes\Report::$id needs a single declared type. A union or an untyped property cannot be described to a caller.` |
| [`Request`](services/request/) | `The argument …::$rows is an array that does not say what it holds. Name a class with of: Thing::class, or describe the items with schema: array( 'items' => ... ).` |
| [`Request`](services/request/) | `Acme\Plugin\Reports\Filter declares no arguments, so nothing describes it.` |
| [`Request`](services/request/) | `The argument …::$total is static. An argument belongs to one call, and a static property belongs to every call at once.` |
| [`Request`](services/request/) | `The argument "id" is readonly on an object that answers more than one call, so it can only ever be set once.` |
| [`Request`](services/request/) | `A file cannot be described in a schema, so only a REST route can take one.` |
| [`Log`](modules/log/) | `Unknown log level "verbose". Expected one of: emergency, alert, critical, error, warning, notice, info, debug.` |

Every one of those is thrown while your route or ability registers, not while it answers a call, and names the property. [Arguments](arguments.md) lists what you can declare and what you cannot.

**`\RuntimeException` — the environment refused.** WordPress or MySQL would not do what was asked: `Could not create upload directory: …`, `Options::save() failed to persist option "acme-plugin__options_"`, `dbDelta() reported creating "wp_acme_reports", but the table does not exist.` These mean a filesystem permission, a full disk, or a database privilege — not a wiring mistake.

The `wp zestry` commands throw plain `\RuntimeException` too, for a missing or malformed `zestry.json` or `zestry.lock.json` — but only under WP-CLI, never during a request your plugin serves.

---

## See also

- [Troubleshooting](troubleshooting.md) — the failures that throw nothing at all.
- [Kernel reference](kernel/) — a page per exception, plus the contracts and traits every module shares.
- [`wp zestry doctor`](commands/doctor.md) — catches the wiring mistakes before they reach a request.
