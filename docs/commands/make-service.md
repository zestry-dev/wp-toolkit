<!--
    Generated from commands/make/service.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make service

[Service or module?](#service-or-module) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Generate a new Service subclass.

Requires `wp zestry init` to have already run in this plugin. Like `make module`, there is no fixed conventional directory to default to, so it goes in your own `{zestry.json root}/Services/` directory — beside the copied `Core/Services/` rather than inside it, since `Core/` is what `wp zestry update` may replace and nothing you write belongs there.

## Service or module?

Ask whether the class does anything *without being called*.

A **service** does not. It is built the first time something asks for it — a `$plugin->get()` call, or another class declaring a property of its type — and works only when called. `Path` resolves a path when asked; `Views` renders when asked. Nothing happens until then, so it needs no `bootstrap.php` entry at all — that file is modules only. One that takes configuration gets it from `$plugin->configure()` in the entry file.

A **module** does. It binds a hook, registers a post type, walks a directory. Because it acts on its own it has to be built for that to happen, so it is listed in `bootstrap.php` and the plugin builds it as the plugin loads. `wp zestry make module` generates that shape, with the `on_boot()` the base class requires.

The line is not "is it a thing I call?" — `Options` is something you call, and it is a module, because it also loads its persisted values and binds `shutdown` without being asked. Getting it wrong is cheap to fix: change what the class extends, and move the file.

## Options

- **`<name>`**  
  The class name, e.g. 'Cache'. Becomes both the filename (`{name}.php`) and the class name itself — unlike the discovery types, this is NOT a kebab-case local name; give it exactly as it should appear after `class`. Group related services by qualifying the name: `Billing/Invoices` writes `Services/Billing/Invoices.php` declaring `{namespace}\Services\Billing`. There is no `--dir`, since PSR-4 ties a namespace to one directory and the name decides both.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

## Examples

```bash
# Generate a service at lib/Services/Cache.php (given a project
# initialized with root "lib").
$ wp zestry make service Cache
Success: Created lib/Services/Cache.php

# Grouped: the directory and the namespace come from the same name.
$ wp zestry make service Billing/Invoices
Success: Created lib/Services/Billing/Invoices.php
```
