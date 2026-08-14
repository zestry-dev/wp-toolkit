<!--
    Generated from src/Modules/CLI/CLI.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# CLI

Discovers `commands/` &nbsp;·&nbsp; Each file returns [`Command`](command.md) &nbsp;·&nbsp; Dependencies [`path`](../../services/path/)

Discovers plugin WP-CLI commands and registers them with WP-CLI.

A commands directory contains PHP files, one per command, each returning a `Command` instance. The module registers every one it finds under the plugin slug, so adding a command means dropping a file in place rather than writing another `WP_CLI::add_command()` call.

Command files can be organized in subdirectories. A file at `commands/cache/clear.php`, for example, is registered as `plugin-slug cache clear`, where `plugin-slug` is the plugin slug.

> [!IMPORTANT]
> **A name can be a command or a namespace, never both.** WP-CLI does not allow a command to have subcommands, so `commands/cache.php` cannot sit alongside a `commands/cache/` directory. Pick one: either `cache` is a command, or it is the namespace holding `cache clear`. Discovery checks this before loading anything and throws `\InvalidArgumentException` naming both files if they collide, which aborts the `wp` invocation.

A discovered file that returns anything other than a `Command` throws `DiscoveryException`, as does a commands directory you named yourself with a file beneath `commands/` that returns something other than a Command.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Command](#writing-a-command) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add module cli
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `CLI` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    CLI::class,
);
```

## Changing the defaults

`CLI` takes no configuration. The bare `modules` entry above is all it needs — reach it with `$plugin->get( CLI::class )`, or declare a property of its type and have it injected.

## Writing a Command

A file in `commands/` returns a [`Command`](command.md) instance, which `wp zt make command <name>` generates.

## Constants

### `COMMANDS_ROOT`

```php
const COMMANDS_ROOT = 'commands';
```

Default plugin-relative directory of command files.

## Methods

### `register_command( $name, $instance )`

Wire (if applicable) and register an already-built command instance under a WP-CLI command name, namespaced under the plugin slug.

```php
public function register_command( string $name, object $instance ): void
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The command name relative to the plugin slug, e.g. `'migrations run'`<br>`$instance` — The command instance; wired automatically if it is a `Command` |
| **Return** | — |
| **Throws** | — |

Use this when a module of your own builds its command instances in PHP instead of shipping a file in `commands/`. The command is wired and namespaced exactly as a discovered one is, and needs no file at all. `Migrations` works this way: it registers `migrations run` and `migrations list` here, so `wp {slug} migrations run` exists the moment the module is added, with nothing to generate or maintain.

`$name` is plugin-relative, matching `walk_and_load()`'s own behavior of prefixing every discovered command with the slug — pass only the command's own name/namespace, never the slug itself.

Only instances of `Command` are passed to `Plugin::wire()`, since the plugin-assignment and property injection it performs is meaningful only for that base class; an instance of some other type is registered as-is, on the assumption that it exposes a compatible `handle()` method without needing plugin services.

This is a thin instance-side wrapper over `register_command_for()`, which is `static` precisely so a module can register a command without holding — and therefore without booting — a CLI instance. Prefer the static form from another module; see `Migrations`.

<br>

### `register_command_for( $plugin, $name, $instance )`

Wire and register a command instance without needing a CLI instance.

```php
public static function register_command_for( Plugin $plugin, string $name, object $instance ): void
```

|  | Details |
|---|---|
| **Parameters** | `$plugin` — The plugin the command belongs to; supplies wiring and the slug<br>`$name` — The command name relative to the plugin slug, e.g. `'migrations run'`<br>`$instance` — The command instance; wired automatically if it is a `Command` |
| **Return** | — |
| **Throws** | — |

`static`, and taking the plugin as its first argument, so you never have to resolve the CLI module to reach it. That matters: resolving a module boots it, and CLI's boot walks `commands/` and throws when that directory is absent — so a plugin that added `migrations` but wanted no file-based commands would fail on every `wp` invocation.

If you already hold a CLI instance, `register_command()` is the same thing without the first argument.

The plugin slug is prepended here rather than by the caller, so every command name is namespaced identically whether it came from file discovery or from a module registering its own — no caller can forget the prefix, and `$name` stays plugin-relative in both paths.

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

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be resolved on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook — or a `get()` during a request — is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, matching the initializer signature, so a closure declared elsewhere needs no `use` to reach it:

```php
protected function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only while `init` is still ahead**: a module resolved after `init` has fired runs its callback immediately, in registration order, whatever priority it asked for. Ordering that has to hold either way belongs inside one callback.

## See also

- [`Command`](command.md) — what a file in `commands/` returns
- [`path`](../../services/path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add module cli`](../../commands/add-module.md) — the command that copies it
