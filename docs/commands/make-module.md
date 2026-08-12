<!--
    Generated from commands/make/module.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make module

[Why it is declared](#why-it-is-declared) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Generate a new plain Module subclass.

**This is where your own WordPress hooks go.** Every other `make` type writes into a directory some module already walks, which covers the hooks this toolkit has a convention for — a post type, a route, a cron event. For anything it does not, a module of your own is the thing that acts without being called, so `on_boot()` is where an `add_filter()` or an `add_action()` belongs:

```php
final class Editor extends Module {

    protected function on_boot(): void {
        // Restrict which blocks a form post can hold.
        \add_filter( 'allowed_block_types_all', array( $this, 'filter_allowed' ), 10, 2 );
    }
}
```

Anything that has to wait for `init` goes through `Module::on_wp_init()` instead, since a module can be built on either side of it.

Requires `wp zt init` to have already run in this plugin. Unlike every other `make` type, there is no fixed conventional directory to default to — a plain module is not discovered by anything — so its home is your own `{zestry.json root}/Modules/` directory, beside the copied `Core/` tree rather than inside it. That separation is the point: `Core/` is what `wp zt update` may replace, and nothing you write belongs there.

Because nothing discovers it, this is also the one `make` type that writes to your `bootstrap.php`: the new class is appended there, which is what builds it at all.

## Why it is declared

A module acts on its own — it binds a hook, registers a post type, walks a directory — so it has to be built for any of that to happen, and being listed is what builds it. `on_boot()` runs then, once. Left out of the file, nothing builds the class, `on_boot()` never runs, and there is no error either way: the feature is simply absent, which reads as the module being broken rather than undeclared.

```php
// bootstrap.php
Shortcode::class,
```

Its sibling `wp zt make service` deliberately declares nothing. A service is built the moment something asks for it, so an entry naming one would do nothing but build it sooner than needed; configure one from `$plugin->configure()` in your entry file instead.

A generated file that does not yet parse is not declared at all, since building a broken class on every request would take the site down until it is fixed. The command says so, and declaring it is one edit away once the file parses.

## Options

- **`<name>`**  
  The class name, e.g. 'RequestLog'. Becomes both the filename (`{name}.php`) and the class name itself — unlike every other `make` type, this is NOT a kebab-case local name; give it exactly as it should appear after `class`. Group related modules by qualifying the name: `Services/Mailer` writes `Modules/Services/Mailer.php` declaring `{namespace}\Modules\Services`. There is no `--dir`, since PSR-4 ties a namespace to one directory and the name decides both.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

## Examples

```bash
# Generate a new module at lib/Modules/RequestLog.php (given a
# project initialized with root "lib").
$ wp zt make module RequestLog
Success: Created lib/Modules/RequestLog.php

# Grouped: the directory and the namespace come from the same name.
$ wp zt make module Services/Mailer
Success: Created lib/Modules/Services/Mailer.php
```
