<!--
    Generated from commands/describe.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt describe

[What it cannot tell you](#what-it-cannot-tell-you) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Report what this plugin has, where each module looks, and what it expects.

Answers the questions someone arriving at an unfamiliar plugin has to answer before touching anything: which features are installed, which of them are actually built, which directory each one reads, and what a file dropped in there has to return. All of it is derived — from `registry.php`, your `zestry.json`, your `bootstrap.php` and the classes on disk — so it cannot describe a plugin that is not the one you have.

`--format=json` is the same answer for a script or an agent, which is why this exists as well as the documentation: a page describes the toolkit, this describes *your* plugin.

Reads only. Nothing here edits a file, and no module is ever built.

## What it cannot tell you

The directory reported for a module is its **default**. A `set_*_root()` call inside an initializer changes it, and finding that out would mean running your closures against live module instances — which this command does not do, for the same reason `wp zt doctor` does not. A module whose entry carries an initializer is marked `configured`, so the report says where to look rather than guessing.

## Options

- **`[--format=<format>]`**  
  Render output in a particular format. `report` is the default read by a person; the rest are the same rows for a script.  
  Accepts `report`, `table`, `csv`, `json`, `yaml`.

- **`[--kind=<kind>]`**  
  Limit to modules or to services.  
  Accepts `all`, `modules`, `services`.

- **`[--installed]`**  
  Only what is actually in this plugin. Without it, everything installable is listed, so you can see what you have not added yet.

## Examples

```bash
# What this plugin has.
$ wp zt describe --installed
Acme\Plugin -> lib/   text domain: acme-plugin

MODULES
  ajax           actions/         AjaxAction    wp zt make action
  cli            commands/        Command       wp zt make command
  cron           schedules/       Schedule      wp zt make schedule   NOT DECLARED
  fields         fields/          Field         wp zt make field
      fields/ 40 files via Acme\Plugin\Abstracts\EntityField

SERVICES
  path           —
  views          views/

# For a script, or an agent.
$ wp zt describe --format=json --installed
[{"name":"ajax","kind":"module","installed":true,"declared":true,
  "configured":false,"reads":"actions/","returns":"AjaxAction",
  "via":"","make":"action","file":"lib/Core/Modules/Ajax/Ajax.php"}]
```
