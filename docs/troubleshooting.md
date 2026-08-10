# Troubleshooting

Most of what goes wrong here goes wrong *loudly* — a `DiscoveryException` naming the exact file, a `ModuleNotFoundException` naming the exact class. Those are covered in [Errors](errors.md).

This page is for the rest: the failures with no message attached. Find your symptom.

| You see | Jump to |
|---|---|
| `'zestry' is not a registered wp command` | [below](#zestry-is-not-a-registered-wp-command) |
| A module is there, and nothing it does happens | [below](#a-module-does-nothing-and-nothing-errors) |
| `Class "Acme\Plugin\..." not found` | [below](#class-not-found) |
| `Typed property ... must not be accessed before initialization` | [below](#an-injected-property-is-not-there) |
| A post type's permalinks 404 | [below](#a-post-type-404s) |
| `DiscoveryException` from a file you wrote | [below](#discoveryexception-from-a-file-you-wrote) |
| A script or style you registered never loads | [below](#a-script-or-style-never-loads) |
| `wp zestry update` is about to replace files you edited | [below](#wp-zestry-update-and-your-edits) |
| `wp zestry init` says the plugin is already initialized | [below](#wp-zestry-init-refuses-to-run) |

Run this first, whatever the symptom — it is read-only and takes a second:

```bash
wp zestry doctor
```

---

## `'zestry' is not a registered wp command`

Three conditions have to hold at once, and failing any one of them produces this same line.

1. **The plugin is active.** WordPress loads a plugin's `vendor/autoload.php` only for an active plugin, and that autoload is what registers `wp zestry`.
2. **The plugin requires this package.** `composer require zestry-dev/wp-toolkit --dev`, in that plugin, so that `vendor/zestry-dev/wp-toolkit/devtool.php` exists on disk.
3. **Your working directory is inside that plugin.** `wp zestry` resolves which plugin to operate on from the current directory: the nearest ancestor sitting directly under `WP_PLUGIN_DIR`. Running from the plugins directory itself, or anywhere outside it, targets nothing.

```bash
cd wp-content/plugins/acme-plugin
wp plugin activate acme-plugin
composer require zestry-dev/wp-toolkit --dev
wp zestry init
```

To find out which one failed, ask:

```bash
wp --debug cli version
```

Each condition explains itself through a `zestry:`-prefixed debug line — the wrong directory, the missing `vendor/zestry-dev/wp-toolkit/devtool.php`, the unreadable package name.

> [!NOTE]
> One case cannot report itself. If the plugin is **inactive**, or the package is installed through a Composer path repository whose symlink does not resolve — inside a container whose mounts do not include the target, say — none of this toolkit's code runs at all, so there is nothing to log with and `--debug` stays silent. Both are fixed by making the plugin's `vendor/` genuinely reachable from where WordPress is running.

---

## A module does nothing, and nothing errors

**It is not listed in `bootstrap.php`.**

A module acts on its own, so it has to be *built* for any of that to happen, and being listed is what builds it. Leave it out and the class is never constructed, `on_boot()` never runs, no hook is bound, no directory is walked — and nothing anywhere says so. The feature is simply absent, which reads as the module being broken.

```php
// bootstrap.php
use Acme\Plugin\Core\Modules\Cron\Cron;
use Acme\Plugin\Modules\Shortcode;

return array(
    Cron::class,
    Shortcode::class,
);
```

`wp zestry add module <name>` writes that entry for you, and so does `wp zestry make module`. A module added by hand, or one whose declaration was lost in a merge, is the case to check.

`wp zestry doctor` finds it:

```bash
$ wp zestry doctor
zestry.json       Acme\Plugin -> lib/
bootstrap.php  6 classes declared

! The "cron" module is copied in but never declared.
  A module is built because bootstrap.php lists it, so one that is not
  listed is never built: it discovers no files and binds no hooks.
  lib/Core/Modules/Cron/Cron.php

Error: 1 problem found.
```

Two limits on that check, so you know when to look yourself:

- It knows the modules **it** copied in, under `lib/Core/Modules/`. A module *you* wrote under `lib/Modules/` is not in its registry, so an undeclared one is not flagged.
- A **service** never needs declaring, and one that is not listed is doing exactly what it should. If a service seems dead, the problem is elsewhere — nothing has asked for it yet.

Two other causes worth ruling out once the declaration is there:

- **`run()` never happens.** `bootstrap()` only queues; `run()` builds. Check the entry file actually calls it.
- **`run()` happens too late for the hook.** `run()` resolves and boots synchronously, so a module deferred to `plugins_loaded` has already missed anything that fired before it. ActivationHandler is the sharp case — see [below](#a-post-type-404s).

---

## Class not found

`Class "Acme\Plugin\Modules\Ajax\Ajax" not found` and its variants mean the namespace does not match where the file is. Copied source carries a `Core` segment; your own code does not.

| What it is | On disk | Class |
|---|---|---|
| The kernel (`wp zestry init`) | `lib/Core/Kernel/Plugin.php` | `Acme\Plugin\Core\Kernel\Plugin` |
| A module you added (`wp zestry add module ajax`) | `lib/Core/Modules/Ajax/Ajax.php` | `Acme\Plugin\Core\Modules\Ajax\Ajax` |
| A service you added (`wp zestry add service path`) | `lib/Core/Services/Path.php` | `Acme\Plugin\Core\Services\Path` |
| A module you wrote (`wp zestry make module`) | `lib/Modules/Shortcode.php` | `Acme\Plugin\Modules\Shortcode` |
| A service you wrote (`wp zestry make service`) | `lib/Services/Cache.php` | `Acme\Plugin\Services\Cache` |

The base classes and exceptions follow the same rule, since they are copied in:

```php
use Acme\Plugin\Core\Kernel\Abstracts\Module;
use Acme\Plugin\Core\Kernel\Abstracts\Service;
use Acme\Plugin\Core\Kernel\Exceptions\ModuleException;
use Acme\Plugin\Core\Modules\CLI\Command;
```

`Acme\Plugin` here is whatever you answered `init`'s namespace prompt with, and `lib` whatever you answered for the root. Both are recorded in `zestry.json`; `wp zestry doctor` prints them on its first line.

If the namespace is right and the class still is not found:

```bash
composer dump-autoload
```

`init` adds one PSR-4 entry — `"Acme\\Plugin\\": "lib/"` — and runs `dump-autoload` for you. Every file under `lib/` is covered by that single entry, so `add` and `make` need no further dumping. You do need to re-dump when the entry itself is missing (`init` warned that it left your `composer.json` alone, or you have no `composer.json`), or when you build with `--optimize-autoloader` or `--classmap-authoritative`, which pin the class map to the files that existed at dump time.

---

## An injected property is not there

The symptom is a PHP `Error`, not a null: reading an uninitialized typed property throws `Typed property Acme\Plugin\Modules\Reports::$path must not be accessed before initialization`.

A property is injected when **all** of these hold:

- it is `public` or `protected` — `private` is never injected, because reflection cannot reach a private property declared on an ancestor class;
- its type is a **single named class** that extends `Service`, which includes every `Module`;
- it does not carry `#[NoInject]`.

Everything else is left alone as your own state: scalars, untyped properties, unrelated class types, and — the one that catches people — **union and intersection types**, which are skipped whole, even when every member of the union qualifies.

```php
use Acme\Plugin\Core\Kernel\Abstracts\Module;
use Acme\Plugin\Core\Kernel\Attributes\NoInject;
use Acme\Plugin\Core\Modules\Options;
use Acme\Plugin\Core\Services\Path;

class Reports extends Module {

    public Path $path;              // injected
    protected Options $options;     // injected

    private Path $cache;            // NOT injected: private
    public Path|Options $either;    // NOT injected: union type
    public ?Path $maybe = null;     // injected: nullable is still one named type

    #[NoInject]
    public Path $manual;            // NOT injected: opted out

    protected function on_boot(): void {}
}
```

The other cause is an object that was **never wired**. Injection runs when the plugin builds an instance (`get()`, `make()`, or booting a module) and when a discovery module wires the object a file returned. An object you built with `new` yourself gets neither:

```php
$reporter = $plugin->wire( new Reporter() );
```

`wire()` assigns the plugin and injects the properties, and is how anything outside the lifecycle — a hand-registered callback, a template helper — gets the same treatment.

---

## A post type 404s

The post type registers (it appears in the admin), but its single or archive URLs return a 404. That is WordPress's rewrite rules, not the module: rules are cached in the database, and adding a post type or changing its `rewrite()` does not rebuild them.

Flush once, on activation — never on every request, which is expensive enough to matter on a busy site:

```php
namespace Acme\Plugin\Modules;

use Acme\Plugin\Core\Kernel\Abstracts\ActivationHandler;

class Activation extends ActivationHandler {

    public function activate( bool $network_wide ): void {
        flush_rewrite_rules();
    }

    public function deactivate( bool $network_wide ): void {
        flush_rewrite_rules();
    }
}
```

Declare it in `bootstrap.php` like any other module, and make sure your entry file calls `run()` **as it loads** — WordPress fires `activate_{plugin}` immediately after the plugin file loads, so a `run()` deferred to `plugins_loaded` has already missed it. See [`ActivationHandler`](modules/activation-handler.md) for the full timing rule; it emits a `_doing_it_wrong()` when it detects a late boot.

For a post type you have already added to a live site, visiting **Settings → Permalinks** once flushes the rules by hand.

---

## `DiscoveryException` from a file you wrote

Two causes, and the message says which.

**The file returned the wrong thing.**

```
The file "/…/acme-plugin/actions/save.php" must return an instance of
Acme\Plugin\Core\Modules\Ajax\AjaxAction. Got: integer
```

`Got: integer` is the missing `return` — a `require` of a file that returns nothing yields `1`. Every discovered file is `require`d and must *return* an instance:

```php
// actions/save.php
use Acme\Plugin\Core\Modules\Ajax\AjaxAction;

return new class extends AjaxAction {
    // …
};
```

`Got: SomeClass` means the class does not extend the base class that module expects. [Modules](modules/) lists the directory and base class for each.

**A directory you named does not exist.**

```
Actions root directory does not exist: /…/acme-plugin/ajax-actions
```

This one only ever comes from a root you asked for by name:

```php
// bootstrap.php
Ajax::class => static fn ( Ajax $ajax ) => $ajax->set_actions_root( 'ajax-actions' ),
```

Fix the path, or drop the setter and use the default.

> [!NOTE]
> **A missing *default* root is not an error.** Adding `cron` before you have written your first schedule is fine: the module discovers nothing and says nothing. Only a directory named through a `set_*_root()` call and then not found throws — asking for a directory by name and getting nothing is a typo worth hearing about.

`wp zestry doctor` does not check roots named inside an initializer — finding out would mean running your closures against live instances. This failure is already loud, which is why it is on this page and not in `doctor`'s output.

---

## A script or style never loads

`register_script()` returns the **namespaced** handle — `acme-plugin-main`, not `main` — and `$deps` is passed to WordPress untouched. Give a dependency the local name and you have named a handle nothing registered; WordPress then drops the dependent script silently, with no notice and no console error.

```php
$main = $assets->register_script( 'main', 'js/main.js', array( 'jquery' ) );

// Right: the returned handle.
$assets->register_script( 'panel', 'js/panel.js', array( $main, 'wp-element' ) );

// Wrong: 'main' is not a registered handle. The panel script never loads.
$assets->register_script( 'panel', 'js/panel.js', array( 'main' ) );
```

The rule reads the same in both directions. In `$deps`, use:

- the **return value** of a previous `register_script()`/`register_style()` call for one of your own assets;
- the **plain handle** for anything registered outside the service — `jquery`, `wp-element`, `wp-components`. Those pass through as-is, which is exactly what you want; namespacing `jquery` would yield `acme-plugin-jquery`, which nothing registered.

Every *other* method takes the **local** name and namespaces it for you — `enqueue_script( 'main' )`, `localize_script( 'main', … )`, `add_inline_script( 'main', … )`. `$deps` is the one place that does not, because it is the one place the value might not be yours.

---

## `wp zestry update` and your edits

Nothing is replaced without being reported first, and your edits are kept by default. Every recorded file lands in one of five states:

| State | What it means | What `update` does |
|---|---|---|
| `unchanged` | Matches what was copied; upstream has not moved. | Nothing. |
| `upstream` | Upstream changed it; you did not. | Replaced. This is the update. |
| `missing` | Recorded as copied, no longer on disk. | Written back. |
| `edited` | You changed it; upstream did not. | **Kept**, and named in the summary. |
| `conflict` | Both. | **Kept**, and named in the summary. |

```bash
wp zestry update --dry-run
```

`--dry-run` reports and stops, writing nothing and exiting zero whatever it finds. Run it before every update. `edited` and `conflict` files are named individually, since those are the ones something of yours is at stake in; `upstream` and `missing` are only counted.

`--force` replaces the `edited` and `conflict` files too, discarding those changes. Reach for it only after you have looked at the named files and decided you do not want them.

Nothing outside `lib/Core/` is ever touched — your own `lib/Modules/` and `lib/Services/` are invisible to this command. If you want a copied module to stop being upstream's altogether, move it out of `lib/Core/` and rename its namespace to match; `update` then has nothing to say about it.

**If every file reports as `upstream`,** you have no `zestry.lock.json` — most often because it was not committed. The command warns and degrades to a plain difference report, which cannot tell your edit from upstream's change. Commit `zestry.lock.json` the way you commit `composer.lock`.

---

## `wp zestry init` refuses to run

```
Error: zestry.json already exists at /…/acme-plugin -- already initialized.
```

`init` is one-time: running it again would re-copy the kernel over whatever you have since edited. What you want instead is one of:

- **A later release of the toolkit** — [`wp zestry update`](commands/update.md).
- **More modules** — [`wp zestry add module <name>`](commands/add-module.md).
- **A copied module back to its original state** — [`wp zestry overwrite module <name>`](commands/overwrite-module.md), which asks before replacing anything.
- **A genuinely fresh start** — delete `zestry.json`, `zestry.lock.json` and your root directory, then run `init` again.

---

## Still stuck

- [Errors](errors.md) — every exception this toolkit throws, and what each one means.
- [`wp zestry doctor`](commands/doctor.md) — the full list of what it checks, and its machine-readable formats.
- [Getting started](getting-started.md) — the whole setup path, in order.
