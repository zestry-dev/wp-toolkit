<!--
    Generated from resources/commands/overwrite.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt overwrite

Copy one or more feature modules into an initialized plugin, replacing any of them (or their dependencies) already present.

Requires `wp zt init` to have already run in this plugin. Resolves dependencies exactly like `wp zt add`, but warns before overwriting anything already on disk: local edits to an already-present module are destroyed by the copy, with no confirmation per file — only one confirmation for the whole resolved batch. Answering "no" cancels the command entirely; nothing is copied, not even modules that were not already present.

A module's dependencies are re-copied with it. To replace one on its own, name it: `wp zt overwrite path`.

## Options

- **`<module>...`**  
  One or more module names to overwrite (or add, if not already present). Available modules: path, request, cookie, globals, transients, db, views, assets, log, options, ajax, admin-pages, rest-api, cli, cron, fields, post-types, blocks, meta-boxes, site-health, abilities, icons-library, migrations.

- **`[--yes]`**  
  Answer any confirmation prompt affirmatively, for an unattended run.

## Examples

```bash
# Re-copy cli from the toolkit, discarding any local edits to it.
$ wp zt overwrite cli
Warning: This will overwrite existing files for: cli
Any local changes to these files will be lost. Continue? [y/N] y
Overwrote cli
Success: Done.

# Declining leaves every file untouched, including new deps.
$ wp zt overwrite rest-api
Also adding required dependencies: path
Warning: This will overwrite existing files for: rest-api
Any local changes to these files will be lost. Continue? [y/N] n
Cancelled.
```
