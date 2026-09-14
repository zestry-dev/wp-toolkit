<!--
    Generated from resources/commands/make/update.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make update

Generate an update handler.

Writes a class extending `UpdateHandler`, which runs once after your plugin's code changes, and declares it in `bootstrap.php` so the plugin builds it. A plugin usually has one, alongside its activation handler.

It exists because WordPress has no update hook. `activate_{plugin}` does not fire on an update — the plugin stays active throughout — and `upgrader_process_complete` runs while the previous release is still in memory, and never fires at all for a file copied in over FTP. So the handler compares your `Version:` header against the version it recorded last time, which catches every way an update can arrive.

## Options

- **`<name>`**  
  The class name, in PascalCase, e.g. `Update`.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

## Examples

```bash
# Generate lib/Modules/Update.php and declare it.
$ wp zt make update Update
Success: Created lib/Modules/Update.php
Declared Update in bootstrap.php, under admin_init.
```
