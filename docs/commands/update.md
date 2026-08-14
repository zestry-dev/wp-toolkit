<!--
    Generated from resources/commands/update.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt update

[What it reports](#what-it-reports) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Re-copy the toolkit source this plugin already has.

Copying is one-way: a later release of the toolkit does not reach a plugin that has already run `wp zt init`. This is how you go and get one. It looks at everything under your `Core/` directory — the kernel, and each module you have added — and replaces it with what the currently installed `zestry-dev/wp-toolkit` would write.

Nothing outside `Core/` is touched. Your own modules live beside it, and this command cannot see them.

## What it reports

Every file is one of five things, and the distinction is the point:

- **unchanged** — matches what was copied, and upstream has not moved.
Nothing to do.
- **upstream** — upstream changed it and you have not. This is the
update; taking it loses nothing.
- **missing** — recorded as copied, and no longer on disk. Written back
along with the upstream changes, without needing `--force`. A file whose whole module you deleted is reported as removed instead, and named with the command that puts it back: this copies what the plugin has, so it never reintroduces a module you took out.
- **edited** — you changed it and upstream has not. Replacing it would
discard your work for no gain, so it is **kept as it is**.
- **conflict** — both. Only these need a decision.

Edited and conflicted files are the ones named individually, since they are the ones something of yours is at stake in. The upstream and missing ones are only counted.

Telling the two apart needs `zestry.lock.json`, written by `init` and every `add` since. A plugin that never committed it has none: the command says so and falls back to reporting only that a file differs, which cannot say whether you or upstream changed it.

## Options

- **`[--dry-run]`**  
  Report and stop. Writes nothing, and exits zero whatever it finds.

- **`[--force]`**  
  Replace edited and conflicted files too, discarding those changes. Without this they are kept and named in the summary.

- **`[--yes]`**  
  Answer the confirmation prompt affirmatively, for an unattended run.

## Examples

```bash
# See what a later release would change, before changing anything.
$ wp zt update --dry-run
Copied from wp-toolkit 1.2.0; 1.4.0 is installed.
3 files to update, 1 you have edited, 1 conflicted.
  conflict  lib/Core/Kernel/Abstracts/Module.php
  edited    lib/Core/Modules/Ajax/Ajax.php
Success: Dry run; nothing written.

# A module deleted from the plugin. This copies what you have, so it
# says so rather than offering to put back what you took out.
$ wp zt update
2 files removed with the "ajax" module. `wp zt add ajax` puts it back.
Success: Already up to date.

# Take it. Your edited files are kept.
$ wp zt update
3 files to update, 1 you have edited, 1 conflicted.
Replace 3 files? [y/N] y
Success: Updated 3 files. 2 kept as they are.
```
