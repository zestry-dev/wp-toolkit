<!--
    Generated from commands/make/meta-box.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make meta-box

Generate a post edit screen meta box.

Writes a file into the plugin's `meta-boxes/` directory, where the MetaBoxes module discovers it. The filename becomes the box's identifier, prefixed with your plugin slug.

## Options

- **`<name>`**  
  The box's local name, in kebab-case, e.g. `book-details`.

- **`[--dir=<dir>]`**  
  Write somewhere other than `meta-boxes/`, relative to the plugin root.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

- **`[--yes]`**  
  Answer both prompts without reading input: overwrite an existing file, and add the `meta-boxes` module when this plugin has none.

## Examples

```bash
# Generate meta-boxes/book-details.php.
$ wp zt make meta-box book-details
Success: Created meta-boxes/book-details.php
```
