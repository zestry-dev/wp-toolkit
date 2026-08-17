<!--
    Generated from resources/commands/doctor.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt doctor

[What is not checked](#what-is-not-checked) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Check this plugin's module wiring for silent misconfiguration.

Six checks, each targeting a mistake that produces no error at runtime:

- a `bootstrap.php` that declares modules where nothing built any of them,
because the entry file never reached `->bootstrap()->run()` — so every module is inert, every hook unbound and every directory unread;
- a module on disk that `bootstrap.php` does not list — never built, so
`on_boot()` never runs and the feature is simply absent;
- a declaration whose class file is gone;
- a `zestry.json` naming a root directory that is not there;
- no `Requires at least:` header, so WordPress will activate the plugin on
any version it likes;
- a module needing a newer WordPress than the plugin promises, which on an
older site registers against an API that is not there.

Needs an initialized plugin: with no `zestry.json` in the current directory it exits non-zero telling you to run `wp zt init` first, and it stops the same way when `bootstrap.php` does not parse.

Reads only. Nothing here edits a file, so it is safe to run at any point.

## What is not checked

Whether a module's directory holds anything. Every module reads one fixed directory, so this command can see them all — but an empty one is what a module looks like before its first file is written, which is ordinary and not worth failing a build over.

## Options

- **`[--format=<format>]`**  
  Render output in a particular format. `report` is the default read by a person: the two summary lines, then each problem with what it causes and where. The machine-readable formats print the problems alone, with a `file` and a `problem` field — the advice is guidance for a reader, not data for a script, so it is left out. Every format exits non-zero when there is at least one problem, so any of them can gate a build.  
  Accepts `report`, `csv`, `json`, `yaml`.

## Examples

```bash
# Check the plugin in the current directory.
$ wp zt doctor
zestry.json    Acme\Plugin -> lib/
bootstrap.php  6 classes declared

! The "cron" module is copied in but never declared.
  A module is built because bootstrap.php lists it, so one that is not
  listed is never built: it discovers no files and binds no hooks.
  lib/Core/Modules/Cron/Cron.php

Error: 1 problem found.

# Nothing wrong.
$ wp zt doctor
Success: No problems found.

# For tooling. Exits non-zero, so it gates a build on its own.
$ wp zt doctor --format=json
[{"file":"lib\/Core\/Modules\/Cron\/Cron.php","problem":"The \"cron\" module is copied in but never declared."}]

# Same fields, easier to read over someone's shoulder.
$ wp zt doctor --format=yaml
---
-
  file: lib/Core/Modules/Cron/Cron.php
  problem: The "cron" module is copied in but never declared.
```
