# Errors

Four exception classes cover every way a module can fail to come up. They form one chain, so a single `catch` handles all of them:

```
\RuntimeException
└── ModuleException                    declaring, resolving or booting failed
    ├── ModuleNotFoundException        the class cannot be built
    ├── CircularDependencyException    two classes need each other
    └── DiscoveryException             a directory or a discovered file is wrong
```

All four live under `Acme\Plugin\Core\Kernel\Exceptions\` once `wp zt init` has copied the kernel into your plugin.

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

The base class, and thrown directly for four things: a `bootstrap.php` the plugin cannot read, a module that acts on its own left at the top level, a module nothing declared, and a module asked for before its hook.

### A `bootstrap.php` the plugin cannot read

```
Bootstrap file must return an array: /…/acme-plugin/bootstrap.php
Bootstrap entries must name a class.
The `bootstrap.php` entry for Acme\Plugin\Core\Modules\Cron\Cron is array. A class
entry's value is the callback that configures it: `Cron::class => static function
( $module ) { ... }`. A module needing no configuration is written bare, and when
it boots is a group heading above it: `'init' => array( Cron::class )`.
```

**What causes it.** A `bootstrap.php` with no `return`, or one returning something other than an array. A class entry whose value is an array rather than the callback that configures it.

**What to do.** The top level is for modules that do nothing until asked; a module that acts on its own goes under the hook it acts on, and a class entry's value is the callback that configures it:

```php
// bootstrap.php
use Acme\Plugin\Core\Modules\AdminPages\AdminPages;
use Acme\Plugin\Core\Modules\Cron\Cron;

return array(
    Path::class,

    'init' => array(
        AdminPages::class,
        Cron::class => static function ( Cron $cron ): void {
            $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
        },
    ),
);
```

Add `:priority` to order a heading against everything else on that hook — `'init:20'` runs behind the default 10.

### A module that acts on its own, left at the top level

```
Acme\Plugin\Modules\Shortcodes acts when it is built, so it has to be listed under
the hook it acts on. In /…/acme-plugin/bootstrap.php move it into a group:
`'acme_plugin_loaded' => array( Shortcodes::class )` boots it once the whole plugin
is up, and `'init' => array( Shortcodes::class )` waits for WordPress. The top level
is for modules that do nothing until something asks.
```

**What causes it.** The class implements `Bootable`, so it acts the moment it is built — and the top level says nothing about when that is. The two headings the message names cover almost every module.

**What to do.** Move it under a heading. `{slug}_loaded` is your own plugin's action, fired at the end of `run()` once every module exists; `init` is where anything WordPress will not accept earlier belongs.

### A module nothing declared

```
Acme\Plugin\Core\Modules\Options is not declared, so nothing built it. Add it to
/…/acme-plugin/bootstrap.php -- that file is everything this plugin is made of,
and nothing outside it is ever built.
```

**What causes it.** Something called `with()` or `get()` for a module the file never lists. Nothing is built on demand, so there is no instance to hand back.

**What to do.** Add it to `bootstrap.php`, or let `wp zt add <name>` do it. That refusal is what keeps the file worth reading: if an unlisted class could be built by asking, the inventory would be a suggestion rather than the answer.

### A module asked for before its hook

```
Acme\Plugin\Core\Modules\Blocks\Blocks boots on `init`, which has not fired yet. Ask
for it from `init` or later, or list it under a heading this plugin can live
with.
```

**What causes it.** The module is listed under a heading, and something asked for it before that hook fired. Building it now would boot it on the wrong side of whatever it was declared to follow.

**What to do.** Move the `get()` call to that hook or later, or reconsider the heading. A hook that has *already* fired is not an error — the module is built immediately, so the declaration reads as "not before" rather than "exactly at".

---

## `ModuleNotFoundException`

[Reference](kernel/module-not-found-exception.md)

```
Class Acme\Plugin\Modules\Shortcode does not exist.
Class Acme\Plugin\Modules\Shortcode must extend Acme\Plugin\Core\Kernel\Abstracts\Module.
```

**What causes it.** Something asked for a class the plugin cannot build — via `with()`, `get()`, `make()` or a `bootstrap.php` entry.

The first message is almost always a namespace or autoload problem, and the copied source carries a `Core` segment your own code does not. The second means the class exists but is not a module.

**What to do.** Check the namespace against the map in [Troubleshooting](troubleshooting.md#class-not-found), then `composer dump-autoload`. If the class is yours, make sure it extends the base class:

```php
use Acme\Plugin\Core\Kernel\Abstracts\Module;
```

A declaration in `bootstrap.php` whose file has since been deleted or renamed is the other common source. `wp zt doctor` finds those before they reach a request.

---

## `CircularDependencyException`

[Reference](kernel/circular-dependency-exception.md)

```
Circular module dependency detected: Acme\Plugin\Modules\Reports -> Acme\Plugin\Modules\Exporter -> Acme\Plugin\Modules\Reports.
```

**What causes it.** Two modules built with `make()` reached for each other while building. `get()` cannot cycle — it publishes the shared instance before the module boots, so anything reaching back for it during that boot gets the in-flight one — but `make()` never publishes. The message prints the whole chain, ending with the class that closed it.

**What to do.** Break the cycle at one end by reaching for the other module when you use it rather than while building:

```php
namespace Acme\Plugin\Modules;

use Acme\Plugin\Core\Kernel\Abstracts\Module;
use Acme\Plugin\Core\Kernel\Contracts\Bootable;

class Exporter extends Module implements Bootable {

    public function run(): void {
        // Reached when the hook fires, by which point Reports is fully built.
        $reports = $this->with( Reports::class );
    }

    public function on_boot(): void {
        add_action( 'acme_export', array( $this, 'run' ) );
    }
}
```

`with()` at *use* time is safe where reaching for it during boot is not, because the cycle only exists while both are being built. If the two classes need each other in both directions permanently, that is usually a sign the shared part wants to be a third class both depend on.

---

## `DiscoveryException`

[Reference](kernel/discovery-exception.md)

The one you will meet most, because it is the one your own feature files trigger. It arrives in five shapes — the first two from any module, the last three only from a particular destination.

**A discovered file returned the wrong thing.**

```
The file "/…/acme-plugin/resources/commands/greet.php" must return an instance of
Acme\Plugin\Core\Modules\CLI\Command. Got: integer
```

`Got: integer` is a missing `return` — `require` yields `1` for a file that returns nothing. `Got: SomeClass` means the returned object does not extend the base class that module expects. Every discovery module requires each file and expects an instance back:

```php
// resources/commands/greet.php
use Acme\Plugin\Core\Modules\CLI\Command;

return new class extends Command {

    public function handle( array $args, array $assoc_args ): void {
        $this->success( 'Hello.' );
    }
};
```

> [!NOTE]
> **A missing directory is not one of the five.** The directory each module reads is fixed, so one that does not exist means you have none of those files yet — the module discovers nothing and says nothing. Adding `cron` before writing your first schedule is fine. [Modules](modules/) lists the directory each one reads.

**Two files claim one name.**

```
Two admin pages resolve to the name "acme-plugin-reports": resources/admin-pages/reports.php
and resources/admin-pages/reports/index.php. Only one of them can be it, so rename the other.
```

Your filenames register as written, so this only happens where a name is built from more than the filename — `reports.php` and `reports/index.php` are two paths meaning one admin page. Neither is dropped in favour of the other, because keeping the first leaves the second registered against nothing and keeping the last makes the answer depend on directory order.

**A name the destination cannot carry.**

```
The admin page "Monthly Report.php" would register as "acme-plugin-Monthly Report",
which cannot appear in a URL as `?page=acme-plugin-Monthly Report`. Rename the file
using only letters, digits, `-`, `_`, `.` or `~`.
```

```
The ability "create_order.php" would register as "acme-plugin/create_order", which
WordPress refuses: an ability name takes only lowercase letters, digits and dashes
on either side of the `/`. Rename the file.
```

Three destinations hold a filename to their own character set: an [admin page](modules/admin-pages/)'s slug goes into `?page=`, an [ability](modules/abilities/)'s name is matched against `^[a-z0-9-]+$`, and an [icon](modules/icons-library/)'s takes lowercase letters, digits, dashes and underscores, starting and ending with a letter or digit — so an icon name fires for capitals, spaces and punctuation rather than for the separator you chose. None is repaired for you — a name spelled for you is one you cannot find again — so rename the file. `wp zt make` writes an acceptable name in the first place, and says when it had to.

**An SVG icon WordPress would quietly alter.**

```
WordPress would remove stroke, stroke-width from the icon "logo.svg", which leaves it
rendering as less than it is. Only `<svg>`, `<path>` and `<polygon>` survive, with a
few attributes each.
```

WordPress runs every icon through `wp_kses()` and keeps whatever survives, so an icon drawn as outlines loses `stroke`, keeps `fill="none"`, and registers as invisible. Raised only while your plugin's own debug constant is on — `wp zt debug on` — which is where a picture that renders blank is still cheap to fix. Redraw it with filled paths; most vector editors offer that as "outline stroke" or "expand".

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
| [`Path`](modules/path/) | `Resource path must stay within the plugin directory.` |
| [`Views`](modules/views/) | `View file does not exist: dashboard (in views root: views)`, `Invalid view name.` |
| [`Assets`](modules/assets/) | `Asset manifest does not exist: /…/build/panel.asset.php` |
| [`DB`](modules/db/) | `Table name "acme-reports" must contain only letters, digits and underscores.` |
| [`CLI`](modules/cli/) | `Command name collision: "report" (report.php) is also used as a subdirectory by "report list" (report/list.php).` |
| [`Cron`](modules/cron/) | `Schedule "digest" has an unregistered recurrence "fortnightly". Register it with Cron::add_custom_interval() first, or use a WordPress built-in (hourly, twicedaily, daily).` |
| [`RestApi`](modules/rest-api/) | `The file "/…/routes/report.php" has a pattern placeholder with no matching #[…\Attributes\RequestArgument] property: id.` |
| [`Request`](modules/request/) | `The argument Acme\Plugin\Routes\Report::$id needs a single declared type. A union or an untyped property cannot be described to a caller.` |
| [`Request`](modules/request/) | `The argument …::$rows is an array that does not say what it holds. Name a class with of: Thing::class, or describe the items with schema: array( 'items' => ... ).` |
| [`Request`](modules/request/) | `Acme\Plugin\Reports\Filter declares no arguments, so nothing describes it.` |
| [`Request`](modules/request/) | `The argument …::$total is static. An argument belongs to one call, and a static property belongs to every call at once.` |
| [`Request`](modules/request/) | `The argument "id" is readonly on an object that answers more than one call, so it can only ever be set once.` |
| [`Request`](modules/request/) | `A file cannot be described in a schema, so only a REST route can take one.` |
| [`Log`](modules/log/) | `Unknown log level "verbose". Expected one of: emergency, alert, critical, error, warning, notice, info, debug.` |

Every one of those is thrown while your route or ability registers, not while it answers a call, and names the property. [`#[RequestArgument]`](modules/request/request-argument.md) lists what you can declare and what you cannot.

**`\RuntimeException` — the environment refused.** WordPress or MySQL would not do what was asked: `Could not create upload directory: …`, `Options::save() failed to persist option "acme-plugin__options_"`, `dbDelta() reported creating "wp_acme_reports", but the table does not exist.` These mean a filesystem permission, a full disk, or a database privilege — not a wiring mistake.

The `wp zt` commands throw plain `\RuntimeException` too, for a missing or malformed `zestry.json` or `zestry.lock.json` — but only under WP-CLI, never during a request your plugin serves.

---

## See also

- [Troubleshooting](troubleshooting.md) — the failures that throw nothing at all.
- [Kernel reference](kernel/) — a page per exception, plus the contracts and traits every module shares.
- [`wp zt doctor`](commands/doctor.md) — catches the wiring mistakes before they reach a request.
