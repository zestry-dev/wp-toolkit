# Zestry WP Toolkit

A toolkit for building WordPress plugins where a file in a directory *is* a feature — drop one in `resources/commands/` and it is a WP-CLI command, one in `resources/admin-pages/` and it is a menu page — copied into your plugin under your own namespace, so it becomes your code rather than a dependency.

## Quickstart

```bash
composer require zestry-dev/wp-toolkit --dev

# Write your entry file, then activate — `wp zt` does not exist until
# WordPress has loaded an active plugin's vendor/autoload.php.
wp plugin activate acme-plugin

wp zt init
wp zt add cli
```

Run these from inside your own plugin's directory. `zt` is short for *zestry toolkit*, and the command is registered by your plugin's autoloader, so the plugin has to be **active** before `init`.

`init` asks for a namespace, a text domain and a destination directory (default `lib`), then copies the kernel into `lib/Core/Kernel/` under your namespace. `wp zt add` copies each feature module you want beside it, and declares it for you.

Full walkthrough: **[Getting started](getting-started.md)**.

## What it looks like

Three files, and `wp acme-plugin greet Alice` works.

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

```php
<?php
// bootstrap.php

declare( strict_types=1 );

\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\CLI\CLI;

return array(
    'init' => array(
        CLI::class,
    ),
);
```

```php
<?php
// resources/commands/greet.php

declare( strict_types=1 );

\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\CLI\Command;

return new class extends Command {

    public function handle( array $args, array $assoc_args ): void {
        $this->success( 'Hello, ' . ( $args[0] ?? 'world' ) . '.' );
    }
};
```

```bash
$ wp acme-plugin greet Alice
Success: Hello, Alice.
```

No `WP_CLI::add_command()`, no hook names. The module walks `resources/commands/`, wires each returned object, and registers it under your plugin slug.

## What do you want to do?

Almost everything is the same two steps: **add** the feature once, then **make** a file each time you want one of them. The second command writes a working file into the directory the first command taught your plugin to watch.

| I want to… | Run | Reference |
|---|---|---|
| Add a `wp` command | `wp zt add cli`<br>then `wp zt make command greet` | [`cli`](modules/cli/) |
| Add an admin screen | `wp zt add admin-pages`<br>then `wp zt make page settings` | [`admin-pages`](modules/admin-pages/) |
| Expose an HTTP endpoint | `wp zt add rest-api`<br>then `wp zt make route widgets` | [`rest-api`](modules/rest-api/) |
| Register a post type | `wp zt add post-types`<br>then `wp zt make post-type book` | [`post-types`](modules/post-types/) |
| Add a field to the editor | `wp zt add fields`<br>then `wp zt make field acme_rating` | [`fields`](modules/fields/) |
| Run something on a schedule | `wp zt add cron`<br>then `wp zt make schedule sync` | [`cron`](modules/cron/) |
| Create or change a table | `wp zt add migrations`<br>then `wp zt make migration create-books-table` | [`migrations`](modules/migrations/) |
| Build a block | `wp zt add blocks`<br>then `wp zt make block card --dynamic --view=none`<br>then `npm run build` | [`blocks`](modules/blocks/) |
| Give an AI agent a tool | `wp zt add abilities`<br>then `wp zt make ability publish-post` | [`abilities`](modules/abilities/) |
| Store settings | `wp zt add options` | [`options`](modules/options/) |
| Render markup from a template | `wp zt add views`<br>then `wp zt make view emails/receipt` | [`views`](modules/views/) |
| Share JavaScript between screens | `wp zt add assets`<br>then `wp zt make shared formatting` | [JavaScript](javascript.md) |

Three that are not that shape:

| I want to… | Run | Reference |
|---|---|---|
| See what this plugin already has | `wp zt describe --installed` | [`wp zt describe`](commands/describe.md) |
| Find out why nothing happened | `wp zt doctor` | [Troubleshooting](troubleshooting.md) |
| Take a toolkit release | `wp zt update --dry-run` | [`wp zt update`](commands/update.md) |

Nothing needs adding up front. Reach for one when you hit what it solves — the full list is under [Reference](#documentation) below, and [`wp zt add`](commands/add.md) brings along whatever the thing you asked for depends on.

## One kind of thing

Everything a plugin is made of is a **[module](modules/)**, and `bootstrap.php` lists every one of them. Nothing is built that is not listed there — asking for an undeclared module throws — so reading that file tells you what the plugin has, and the key above each one says when it starts.

```php
// bootstrap.php
return array(
    Path::class,      // does nothing until something asks
    Options::class,

    'init' => array(  // acts, and this is when
        CLI::class,
    ),
);
```

**A module that does something on its own implements [`Bootable`](kernel/bootable.md).** That is the only difference between one module and another, and it is on the line that names the class:

```php
class Shortcode extends Module implements Bootable {

    public function on_boot(): void {
        add_shortcode( 'acme_form', array( $this, 'render' ) );
    }
}
```

`Path` resolves a path when you ask it and has no `on_boot()`; `Ajax` binds hooks the moment the plugin builds it and does. Both are listed the same way.

**`with()` is how anything reaches anything** — in a module, and in every file a module discovers:

```php
use Acme\Plugin\Core\Modules\CLI\Command;
use Acme\Plugin\Core\Modules\Options;

return new class extends Command {

    public function handle( array $args, array $assoc_args ): void {
        $this->success( $this->with( Options::class )->get( 'greeting', 'hi' ) );
    }
};
```

The same instance every time, so two callers asking for `Options` share its state.

## Documentation

**Read once**

- [Getting started](getting-started.md) — install, initialize, and get a command running.
- [Your first plugin](first-plugin.md) — build something real, end to end.

**Keep open**

- [Cheat sheet](cheat-sheet.md) — every command, directory and base class on one page.
- [Rules](rules.md) — every absolute on one page, with nothing arguing for them. The page to reread.

**Guides**

- [`#[RequestArgument]`](modules/request/request-argument.md) — how a route or an ability declares what it accepts, and what a caller is told.
- [JavaScript](javascript.md) — sharing code between screens without shipping it twice.
- [Testing](testing.md) — how to test a plugin built this way.
- [Troubleshooting](troubleshooting.md) — including [`wp zt doctor`](commands/doctor.md), which finds the wiring mistakes that fail silently.
- [Shipping](shipping.md) — what to commit, what to build, what to leave out of the zip.

**Reference**

Every name below is what you pass to `wp zt add` — `wp zt add meta-boxes`, `wp zt add request`.

- [Modules](modules/) — everything a plugin can be made of:
  <!-- zestry:include generator="module-names" -->
  `abilities`, `admin-pages`, `ajax`, `assets`, `blocks`, `cli`, `cookie`, `cron`, `db`, `fields`, `globals`, `icons-library`, `log`, `meta-boxes`, `migrations`, `options`, `path`, `post-types`, `request`, `rest-api`, `site-health`, `transients`, `views`
  <!-- /zestry:include -->
- [`Plugin`](plugin.md) — the class your entry file builds, and everything it can be told to do.
- [Command reference](commands/) — every `wp zt` command.
- [Errors](errors.md) — every exception this toolkit throws, the message it carries, and what to do about it.
- [Kernel reference](kernel/) — the exceptions you catch, the contracts you implement, the traits every module shares.

## Requirements

- **PHP 8.1 or later.**
- **WordPress 6.9 or later** — the version this toolkit is developed and tested against.
- **Composer**, and a working WordPress install with **WP-CLI**. `wp zt` runs against your plugin once it is active, so the environment comes first and the toolkit goes into it.

## Updates

Copying is one-way: a later release of the toolkit does not reach a plugin that has already run `init`. Nothing upstream can break your plugin, and nothing upstream can fix it either.

[`wp zt update`](commands/update.md) is how you take a release when you want one. It re-copies everything under `lib/Core/` — the kernel and every module you added — and before writing anything it names the files you have edited, so a fix never arrives at the cost of your own work. Files you changed are kept unless you pass `--force`; `--dry-run` reports and stops. Everything outside `lib/Core/` is yours and is never touched.
