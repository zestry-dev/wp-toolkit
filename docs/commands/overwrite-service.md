<!--
    Generated from commands/overwrite/service.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry overwrite service

Copy one or more services into an initialized plugin, replacing any of them (or their dependencies) already present.

Requires `wp zestry init` to have already run in this plugin. Warns before overwriting anything already on disk: local edits to an already-present service are destroyed by the copy, with no confirmation per file — only one confirmation for the whole resolved batch. Answering "no" cancels the command entirely; nothing is copied, not even services that were not already present.

Reach for this to force one named service back to the source the toolkit currently ships. To bring the whole copied tree up to date instead, run `wp zestry update`: it re-copies everything under `Core/` — the kernel and every module and service you have added — and keeps the files you have edited rather than discarding them.

## Options

- **`<service>...`**  
  One or more service names to overwrite (or add, if not already present). Available services: path, request, cookie, globals, transients, db, views.

- **`[--yes]`**  
  Answer any confirmation prompt affirmatively, for an unattended run.

## Examples

```bash
# Re-copy path from the toolkit, discarding any local edits to it.
$ wp zestry overwrite service path
Warning: This will overwrite existing files for: path
Any local changes to these files will be lost. Continue? [y/N] y
Overwrote path
Success: Done.

# Declining leaves every file untouched, including new deps.
$ wp zestry overwrite service views
Also adding required dependencies: path
Warning: This will overwrite existing files for: views
Any local changes to these files will be lost. Continue? [y/N] n
Cancelled.
```
