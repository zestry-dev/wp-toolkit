<!--
    Generated from commands/make/meta-box.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make meta-box

Generate a post edit screen meta box.

Writes a file into the plugin's `meta-boxes/` directory, where the MetaBoxes module discovers it. The filename becomes the box's identifier, prefixed with your plugin slug.

## Options

- **`<name>`**  
  The box's local name, in kebab-case, e.g. `book-details`.

- **`[--dir=<dir>]`**  
  Write somewhere other than `meta-boxes/`, relative to the plugin root.

## Examples

```bash
# Generate meta-boxes/book-details.php.
$ wp zestry make meta-box book-details
Success: Created meta-boxes/book-details.php
```
