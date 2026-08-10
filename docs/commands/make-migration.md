<!--
    Generated from commands/make/migration.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make migration

Generate a new database migration.

The Migrations module discovers it, but unlike every other discovery type this one does not run itself. The module reads your `migrations/` directory in filename order and runs each `Migration` at most once per site, when something asks it to: `wp {slug} migrations run`, or a `run_pending()` call from whatever trigger fits your release process.

Needs the `migrations` module, so run `wp zestry add module migrations` first if you have not already.

## Options

- **`<name>`**  
  The local description, e.g. 'create-books-table'. The file is written to `migrations/{timestamp}-{name}.php`, and that timestamp prefix is what makes migrations run in the order they were created. Never rename a migration that may already have run somewhere — the whole filename is its identity, so a renamed one reads as never having run. `migrations run` refuses when it spots that, but only once it has already happened.

- **`[--dir=<dir>]`**  
  Write into this plugin-relative directory instead of `migrations` — pass it when you have pointed Migrations's migrations root somewhere other than its default.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

## Examples

```bash
$ wp zestry make migration create-books-table
Success: Created migrations/20260115120000-create-books-table.php
```
