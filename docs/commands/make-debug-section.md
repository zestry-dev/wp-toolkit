<!--
    Generated from commands/make/debug-section.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make debug-section

Generate a Site Health debug section.

Writes a file into the plugin's `debug-sections/` directory, where the SiteHealth module discovers it. The filename becomes the section's identifier, so `status` registers as `{plugin-slug}-status`.

## Options

- **`<name>`**  
  The section's local name, in kebab-case, e.g. `status`.

- **`[--dir=<dir>]`**  
  Write somewhere other than `debug-sections/`, relative to the plugin root.

## Examples

```bash
# Generate debug-sections/status.php.
$ wp zestry make debug-section status
Success: Created debug-sections/status.php
```
