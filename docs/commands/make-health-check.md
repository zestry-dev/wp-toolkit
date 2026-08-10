<!--
    Generated from commands/make/health-check.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make health-check

Generate a Site Health check.

Writes a file into the plugin's `health-checks/` directory, where the SiteHealth module discovers it. The filename becomes the check's identifier, so `api-key` registers as `{plugin-slug}-api-key`.

## Options

- **`<name>`**  
  The check's local name, in kebab-case, e.g. `api-key`.

- **`[--dir=<dir>]`**  
  Write somewhere other than `health-checks/`, relative to the plugin root.

## Examples

```bash
# Generate health-checks/api-key.php.
$ wp zestry make health-check api-key
Success: Created health-checks/api-key.php
```
