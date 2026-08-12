<!--
    Generated from commands/make/command.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make command

Generate a new WP-CLI command.

The CLI module discovers it. At boot it walks your `commands/` directory at any depth, requires every file in it, and registers the `Command` each one returns under your plugin's slug — so `commands/greet.php` becomes `wp {slug} greet`, and nested directories become nested command namespaces. Writing the file is the whole registration.

Needs the `cli` module, so run `wp zt add module cli` first if you have not already.

## Options

- **`<name>`**  
  The local name, e.g. 'greet'. Becomes the filename (`{name}.php`) under `commands/`. May include `/` to nest it under a command namespace, e.g. 'cache/clear' — but one name can be a leaf command or a command namespace, never both, because WP-CLI cannot attach subcommands to a command. `commands/cache.php` and `commands/cache/` therefore exclude each other, and this command refuses to write the second.

- **`[--dir=<dir>]`**  
  Write into this plugin-relative directory instead of `commands` — pass it when you have pointed CLI's commands root somewhere other than its default.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

## Examples

```bash
# Generate a WP-CLI command at commands/greet.php.
$ wp zt make command greet
Success: Created commands/greet.php
```
