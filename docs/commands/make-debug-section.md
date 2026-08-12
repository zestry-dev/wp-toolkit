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

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

- **`[--yes]`**  
  Answer both prompts without reading input: overwrite an existing file, and add the `site-health` module when this plugin has none.

## Examples

```bash
# Generate debug-sections/status.php.
$ wp zestry make debug-section status
Success: Created debug-sections/status.php
```
