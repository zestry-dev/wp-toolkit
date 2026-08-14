<!--
    Generated from resources/commands/make/module.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make module

Generate a new plain Module subclass.

**This is where your own WordPress hooks go.** Every other `make` type writes into a directory some module already walks, which covers the hooks this toolkit has a convention for — a post type, a route, a cron event. For anything it does not, a module of your own is the thing that acts without being called, so `on_boot()` is where an `add_filter()` or an `add_action()` belongs:

```php
final class Editor extends Module implements Bootable {

    public function on_boot(): void {
        // Restrict which blocks a form post can hold.
        \add_filter( 'allowed_block_types_all', array( $this, 'filter_allowed' ), 10, 2 );
    }
}
```

The `implements Bootable` is what makes `on_boot()` run. A module without it works only when something calls it, and is listed just the same.

Anything that has to wait for `init` goes through `Module::on_wp_init()` instead, since a module can be built on either side of it.

Requires `wp zt init` to have already run in this plugin. Unlike every other `make` type, there is no fixed conventional directory to default to — a plain module is not discovered by anything — so its home is your own `{zestry.json root}/Modules/` directory, beside the copied `Core/` tree rather than inside it, which `wp zt update` never touches.

Because nothing discovers it, this is also the one `make` type that writes to your `bootstrap.php`: the new class is appended there, and being listed is the only thing that builds a module.

A generated file that does not yet parse is not declared at all. The command says so, and declaring it is one edit away once the file parses.

## Options

- **`<name>`**  
  The class name, e.g. 'RequestLog'. Becomes both the filename (`{name}.php`) and the class name itself — unlike every other `make` type, this is NOT a kebab-case local name; give it exactly as it should appear after `class`. Group related modules by qualifying the name: `Services/Mailer` writes `Modules/Services/Mailer.php` declaring `{namespace}\Modules\Services`. The destination is fixed, since PSR-4 ties a namespace to one directory and the name decides both.

- **`[--bootable]`**  
  Give the module an `on_boot()` that runs without being called, and declare it in `bootstrap.php` against the plugin's own `{slug}_loaded` action — so it boots after every other module the plugin has, rather than in the middle of the list. Leave it off for a module that only works when something calls it.

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

# One that acts on its own, booting after every other module.
$ wp zt make module Shortcodes --bootable
Declared in bootstrap.php, booting on `acme_plugin_loaded`.
Success: Created lib/Modules/Shortcodes.php
```
