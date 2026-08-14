<!--
    Generated from commands/make/migration.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make migration

Generate a new database migration.

The Migrations module discovers it, but unlike every other discovery type this one does not run itself. The module reads your `migrations/` directory in filename order and runs each `Migration` at most once per site, when something asks it to: `wp {slug} migrations run`, or a `run_pending()` call from whatever trigger fits your release process.

Needs the `migrations` module, so run `wp zt add migrations` first if you have not already.

## Options

- **`<name>`**  
  The local description, e.g. 'create-books-table'. The file is written to `migrations/{timestamp}-{name}.php`, and that timestamp prefix is what makes migrations run in the order they were created. Never rename a migration that may already have run somewhere — the whole filename is its identity, so a renamed one reads as never having run. `migrations run` refuses when it spots that, but only once it has already happened.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

## Examples

```bash
$ wp zt make migration create-books-table
Success: Created migrations/20260115120000-create-books-table.php
```
