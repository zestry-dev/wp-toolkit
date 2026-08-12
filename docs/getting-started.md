# Getting started

Build a WordPress plugin whose features are files in a directory: drop a file in `commands/` and it is a WP-CLI command, drop one in `admin-pages/` and it is a menu page. No registration calls, no hook names to spell correctly.

You need PHP 8.1+, WordPress 6.9+, Composer and WP-CLI, and a plugin directory to work in.

## 1. Require the toolkit

```bash
composer require zestry-dev/wp-toolkit --dev
```

`wp zt init` **copies** the toolkit into your plugin rather than loading it from `vendor/`, so once that has run nothing from `vendor/zestry-dev/` is needed at runtime — only to run the `wp zt` commands themselves. Hence `--dev`.

## 2. Activate the plugin

`wp zt init` runs through WP-CLI, and WP-CLI only sees the command once WordPress has loaded your plugin's `vendor/autoload.php` — which happens only for an **active** plugin. If you do not have a plugin file yet, this is enough to activate:

```php
<?php
/**
 * Plugin Name: Acme Plugin
 * Text Domain: acme-plugin
 * Version:     1.0.0
 */

declare( strict_types=1 );

require_once __DIR__ . '/vendor/autoload.php';
```

```bash
wp plugin activate acme-plugin
```

> [!IMPORTANT]
> **`wp zt` — short for *zestry toolkit* — only exists inside a plugin that requires this package, and only while that plugin is active.** Run it from inside the plugin's own directory. Anything else reports `'zt' is not a registered wp command`; add `--debug` to any `wp` command to see which condition failed.

## 3. Initialize

```bash
wp zt init
```

It asks three questions, then confirms:

- **Namespace** — e.g. `Acme\Plugin`. Defaults to a PSR-4 namespace your `composer.json` already declares, if it has one.
- **Text domain** — for the translatable strings in the copied source. Defaults to the `Text Domain:` your entry file declares, since that is the domain WordPress loads your translations under and the copied source has to match it.
- **Source directory** — relative to the plugin root. Defaults to `lib`. `src` is refused: it belongs to `@wordpress/scripts`, and is where [JavaScript](javascript.md) goes — `src/blocks/`, `src/entries/`, `src/shared/`.

Answer those and it writes, in order:

- `lib/Core/Kernel/` — the toolkit's kernel copied, with every `Zestry\WPToolkit\` namespace and text-domain literal rewritten to yours;
- `zestry.lock.json` — a hash per copied file, so a later `wp zt update` can tell your edits from upstream's changes. Commit it;
- `zestry.json` — the three answers, read by every later `wp zt` command;
- an `"Acme\\Plugin\\": "lib/"` PSR-4 entry in your `composer.json`, followed by a `composer dump-autoload` so it is live immediately;
- `bootstrap.php` — where modules get declared;
- `.gitignore` — covering what is built rather than authored;
- `phpcs.xml`, `eslint.config.mjs`, `.prettierrc.js` and `.prettierignore`, with their dev dependencies added to `composer.json`/`package.json` and `composer lint`, `npm run lint:js` and `npm run format` to run them;
- `AGENTS.md`, the invariants an AI agent working in your plugin needs, and a `.claude/CLAUDE.md` pointing at it.

Nothing is ever overwritten — an existing config file, dependency or script is left exactly as it is, so running `init` in a configured plugin changes nothing. Skip any of them with `--no-phpcs`, `--no-eslint`, `--no-prettier` or `--no-agents`; [`wp zt init`](commands/init.md) documents what each one writes, and `--yes` runs the whole thing unattended — with one prerequisite on a brand-new plugin: it infers the namespace from a PSR-4 entry in `composer.json`, and `init` is what writes one, so declare that entry yourself first or the unattended run stops rather than guessing. [`wp zt init`](commands/init.md) has the exact message.

## 4. Add the modules you want

`init` copies only the kernel. Each feature is opt-in, and lands beside it under `lib/Core/`:

```bash
wp zt add module cli admin-pages
```

Dependencies come along automatically — nearly everything needs `path`, so it arrives too, and `migrations` also pulls in `db`, `options` and `cli`.

What you add is one of two kinds:

- a **[service](services/)** works only when you call it. Add one on its own with `wp zt add service <name>`:
  <!-- zestry:include generator="service-names" -->
  `cookie`, `db`, `globals`, `path`, `request`, `transients`, `views`
  <!-- /zestry:include -->
- a **[module](modules/)** acts on its own:
  <!-- zestry:include generator="module-names" -->
  `abilities`, `admin-pages`, `ajax`, `assets`, `blocks`, `cli`, `cron`, `fields`, `log`, `meta-boxes`, `migrations`, `options`, `post-types`, `rest-api`, `site-health`
  <!-- /zestry:include -->

## 5. Wire the plugin up

`lib/Core/Kernel/` now exists, so the entry file can build a `Plugin`:

```php
<?php
/**
 * Plugin Name: Acme Plugin
 * Text Domain: acme-plugin
 * Version:     1.0.0
 */

declare( strict_types=1 );

use Acme\Plugin\Core\Kernel\Plugin;

require_once __DIR__ . '/vendor/autoload.php';

function acme_plugin(): Plugin {
    static $plugin = null;

    $plugin ??= ( new Plugin( __FILE__ ) )->bootstrap()->run();

    return $plugin;
}

acme_plugin();
```

That is the whole file, and it stays this size however many modules you add. The slug defaults to the entry file's directory name — `acme-plugin` — and every hook, option and handle the modules register is namespaced with it. `bootstrap()` reads `bootstrap.php`; `run()` builds and boots what it found, synchronously, so you control the timing.

`wp zt add module` already appended to `bootstrap.php` in step 4, so it now reads:

```php
<?php
// bootstrap.php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\AdminPages\AdminPages;
use Acme\Plugin\Core\Modules\CLI\CLI;

return array(
    CLI::class,
    AdminPages::class,
);
```

**This file is modules only, and listing one is what builds it.** Every name here is something the plugin starts. Give an entry a value to configure the module before it boots — that value is its initializer, and a module needing none is written bare:

```php
return array(
    CLI::class => static function ( CLI $cli ): void {
        $cli->set_commands_root( 'cli/commands' );
    },
    AdminPages::class,
);
```

A service never appears here. It is built the moment something asks for it — a `$plugin->get()`, or another class declaring a property of its type. One that takes configuration gets it from `configure()` in your entry file:

```php
use Acme\Plugin\Core\Services\DB;

$plugin ??= ( new Plugin( __FILE__ ) )
    ->configure( DB::class, static fn ( DB $db ) => $db->set_table_prefix( 'acme' ) )
    ->bootstrap()
    ->run();

return $plugin;
```

The one mistake left is leaving a module out of `bootstrap.php`: nothing builds it, its `on_boot()` never runs, and no error says so. That is what [`wp zt doctor`](commands/doctor.md) exists to catch.

```bash
wp zt doctor
```

`bootstrap.php` is not the only way — [`Plugin`](plugin.md) documents declaring modules from the entry file instead, and every method the class offers.

## 6. Generate a feature

Every module that discovers files has a `make` command:

```bash
wp zt make command greet
```

That writes `commands/greet.php` with your namespace already filled in, and it is live immediately:

```bash
$ wp acme-plugin greet Alice
Success: Done.
```

Fill in the `handle()` method it generated, and keep its docblock up to date — WP-CLI reads the `## OPTIONS` and `## EXAMPLES` sections for `wp acme-plugin greet --help`, so it is not just a comment.

There is no `WP_CLI::add_command()` to write: the module walks `commands/`, wires the returned object, and registers it under your plugin slug. The same holds for every other module — see [`wp zt make`](commands/) for each type, and each module's page for what its files look like.

## What just happened

Three conventions do the work, and they are the same in every module:

1. **A directory is a feature set.** `commands/` holds WP-CLI commands, `admin-pages/` holds pages, `routes/` holds REST routes. The [module index](modules/) maps every one.
2. **A file returns an object.** The module requires the file and expects an instance of that module's base class. Anything else throws a `DiscoveryException` naming the file and what was expected.
3. **Dependencies are declared, not fetched.** Type a public property as another service or module — `public Options $options;` — and it is injected before your code runs, in every discovered file as well as in every service and module.

## JavaScript, if you need it

Nothing so far touched the front end. When you want it:

```bash
wp zt add module assets
wp zt make entry settings
npm install && npm run build
```

That copies the [`assets`](modules/assets/) module, writes a `webpack.config.js`, and gives you `src/entries/settings/` with a script and a stylesheet. Loading it is one call, with no registration first:

```php
$this->assets->enqueue_entry( 'settings' );
```

Everything JavaScript lives under `src/`, in three directories that differ only in who registers the result: `src/blocks/` (WordPress does, from `block.json`), `src/entries/` (your own scripts), and `src/shared/` (code two of them import by name, built once rather than copied into each).

The build configuration matters more than it looks. `@wordpress/scripts` picks entry points three mutually exclusive ways, so on a stock setup adding one block silently stops `src/index.ts` being built — merging them is what the generated config is for. Full detail: **[JavaScript](javascript.md)**.

## What is yours, and what came from the toolkit

`lib/Core/` is the copied source; the rest of `lib/` is code you wrote. That one directory is the whole boundary, and it decides one thing: [`wp zt update`](commands/update.md) and `wp zt overwrite` may replace anything inside it, and can never touch anything outside it.

```
lib/
├── Core/                  ← copied in; `update` may replace it
│   ├── Kernel/            ← wp zt init
│   ├── Modules/Ajax/      ← wp zt add module ajax
│   └── Services/Path.php  ← wp zt add service path
├── Modules/Shortcode.php  ← wp zt make module Shortcode — yours
├── Services/Cache.php     ← wp zt make service Cache — yours
└── Data/LineItem.php      ← no generator, no command — just yours
```

It is all your code, under one namespace and one PSR-4 entry — which is why the last line needs no command: that entry maps the whole of `lib/`, so a plain class autoloads from wherever you put it. `Modules/` and `Services/` are where the two generators write, not a list of what may exist. The segment appears in the namespace too — `Acme\Plugin\Core\Modules\Ajax\Ajax` against your own `Acme\Plugin\Modules\Shortcode` — so which kind you are looking at shows in every `use` statement.

Edit anything under `lib/Core/` freely. `wp zt update` names the files you changed before it would replace them, and keeps them unless you pass `--force`. If you want a module to stop being upstream's altogether, move it out of `lib/Core/` and rename its namespace; `update` then has nothing to say about it.

## Next

- [Your first plugin](first-plugin.md) — the same pieces, used to build something real.
- [Modules](modules/) — what each one discovers, and what its files return.
- [JavaScript](javascript.md) — entries, shared code, and what the build hands to PHP.
- [Services](services/) — paths, views, db, globals.
- [Command reference](commands/) — every `wp zt` command, including `make module` and `make service` for your own.
