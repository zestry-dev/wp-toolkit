<!--
    Generated from src/Modules/CLI/CLI.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# CLI

Discovers `resources/commands/` &nbsp;·&nbsp; Each file returns [`Command`](command.md) &nbsp;·&nbsp; Dependencies [`path`](../path/)

Discovers plugin WP-CLI commands and registers them with WP-CLI.

A commands directory contains PHP files, one per command, each returning a `Command` instance. The module registers every one it finds under the plugin slug, so adding a command means dropping a file in place rather than writing another `WP_CLI::add_command()` call.

Command files can be organized in subdirectories. A file at `resources/commands/cache/clear.php`, for example, is registered as `plugin-slug cache clear`, where `plugin-slug` is the plugin slug.

> [!IMPORTANT]
> **A name can be a command or a namespace, never both.** WP-CLI does not allow a command to have subcommands, so `resources/commands/cache.php` cannot sit alongside a `resources/commands/cache/` directory. Pick one: either `cache` is a command, or it is the namespace holding `cache clear`. Discovery checks this before loading anything and throws `\InvalidArgumentException` naming both files if they collide, which aborts the `wp` invocation.

A discovered file that returns anything other than a `Command` throws `DiscoveryException`, as does a commands directory you named yourself with a file beneath `resources/commands/` that returns something other than a Command.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Command](#writing-a-command) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add cli
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `CLI` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'init' => array(
        CLI::class,
    ),
);
```

## Changing the defaults

`CLI` takes no configuration. The entry above is all it needs — reach it with `$this->with( CLI::class )` from any module or discovered file, or `$plugin->get( CLI::class )` from your entry file.

## Writing a Command

A file in `resources/commands/` returns a [`Command`](command.md) instance, which `wp zt make command <name>` generates.

## Constants

### `COMMANDS_ROOT`

```php
const COMMANDS_ROOT = 'resources/commands';
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

Use this when a module of your own builds its command instances in PHP instead of shipping a file in `resources/commands/`. The command is wired and namespaced exactly as a discovered one is, and needs no file at all. `Migrations` works this way: it registers `migrations run` and `migrations list` here, so `wp {slug} migrations run` exists the moment the module is added, with nothing to generate or maintain.

`$name` is plugin-relative, matching `walk_and_load()`'s own behavior of prefixing every discovered command with the slug — pass only the command's own name/namespace, never the slug itself.

Only instances of `Command` are passed to `Plugin::wire()`, since the plugin it assigns is only meaningful to that base class; an instance of some other type is registered as-is, on the assumption that it exposes a compatible `handle()` method without needing to reach any module.

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

`static`, and taking the plugin as its first argument, so you never have to resolve the CLI module to reach it. That matters: resolving a module boots it, and CLI's boot walks `resources/commands/` and throws when that directory is absent — so a plugin that added `migrations` but wanted no file-based commands would fail on every `wp` invocation.

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

Reach for this wherever a plain `add_action( 'init', ... )` would go: that callback never runs once `init` has passed, and a module can be built on either side of it.

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

- [`Command`](command.md) — what a file in `resources/commands/` returns
- [`path`](../path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add cli`](../../commands/add.md) — the command that copies it
