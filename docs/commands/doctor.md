<!--
    Generated from commands/doctor.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry doctor

[What is not checked](#what-is-not-checked) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Check this plugin's module wiring for silent misconfiguration.

Three checks, each targeting a mistake that produces no error at runtime:

- a module on disk that `bootstrap.php` does not list — never built, so
`on_boot()` never runs and the feature is simply absent;
- a declaration whose class file is gone;
- an `zestry.json` naming a root directory that is not there.

Needs an initialized plugin: with no `zestry.json` in the current directory it exits non-zero telling you to run `wp zestry init` first, and it stops the same way when `bootstrap.php` does not parse.

Reads only. Nothing here edits a file, so it is safe to run at any point.

## What is not checked

A directory named through a `set_*_root()` call inside an initializer is not verified: finding out would mean running that closure against a live module instance, and this command never builds your plugin. You will hear about that one anyway — a root named by a setter and then not found throws a `DiscoveryException` naming the exact path.

## Options

- **`[--format=<format>]`**  
  Render output in a particular format. `report` is the default read by a person: the two summary lines, then each problem with what it causes and where. The machine-readable formats print the problems alone, with a `file` and a `problem` field — the advice is guidance for a reader, not data for a consumer, so it is left out. Every format exits non-zero when there is at least one problem, so any of them can gate a build.  
  Accepts `report`, `csv`, `json`, `yaml`.

## Examples

```bash
# Check the plugin in the current directory.
$ wp zestry doctor
zestry.json       Acme\Plugin -> lib/
bootstrap.php  6 classes declared

! The "cron" module is copied in but never declared.
  A module is built because bootstrap.php lists it, so one that is not
  listed is never built: it discovers no files and binds no hooks.
  lib/Core/Modules/Cron/Cron.php

Error: 1 problem found.

# Nothing wrong.
$ wp zestry doctor
Success: No problems found.

# For tooling. Exits non-zero, so it gates a build on its own.
$ wp zestry doctor --format=json
[{"file":"lib\/Core\/Modules\/Cron\/Cron.php","problem":"The \"cron\" module is copied in but never declared."}]

# Same fields, easier to read over someone's shoulder.
$ wp zestry doctor --format=yaml
---
-
  file: lib/Core/Modules/Cron/Cron.php
  problem: The "cron" module is copied in but never declared.
```
