<!--
    Generated from commands/overwrite/module.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt overwrite module

Copy one or more feature modules into an initialized plugin, replacing any of them (or their dependencies) already present.

Requires `wp zt init` to have already run in this plugin. Resolves dependencies exactly like `wp zt add module`, but warns before overwriting anything already on disk: local edits to an already-present module are destroyed by the copy, with no confirmation per file — only one confirmation for the whole resolved batch. Answering "no" cancels the command entirely; nothing is copied, not even modules that were not already present.

Dependencies cross the two kinds, so a module's services are re-copied with it. To replace a service on its own, use `wp zt overwrite service <service>`.

## Options

- **`<module>...`**  
  One or more module names to overwrite (or add, if not already present). Available modules: log, options, assets, ajax, admin-pages, rest-api, cli, cron, fields, meta-boxes, post-types, blocks, site-health, abilities, icons-library, migrations.

- **`[--yes]`**  
  Answer any confirmation prompt affirmatively, for an unattended run.

## Examples

```bash
# Re-copy cli from the toolkit, discarding any local edits to it.
$ wp zt overwrite module cli
Warning: This will overwrite existing files for: cli
Any local changes to these files will be lost. Continue? [y/N] y
Overwrote cli
Success: Done.

# Declining leaves every file untouched, including new deps.
$ wp zt overwrite module rest-api
Also adding required dependencies: path
Warning: This will overwrite existing files for: rest-api
Any local changes to these files will be lost. Continue? [y/N] n
Cancelled.
```
