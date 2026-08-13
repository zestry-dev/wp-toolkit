<!--
    Generated from commands/make/health-check.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make health-check

Generate a Site Health check.

Writes a file into the plugin's `health-checks/` directory, where the SiteHealth module discovers it. The filename becomes the check's identifier, so `api-key` registers as `{plugin-slug}-api-key`.

## Options

- **`<name>`**  
  The check's local name, in kebab-case, e.g. `api-key`.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

- **`[--yes]`**  
  Answer both prompts without reading input: overwrite an existing file, and add the `site-health` module when this plugin has none.

## Examples

```bash
# Generate health-checks/api-key.php.
$ wp zt make health-check api-key
Success: Created health-checks/api-key.php
```
