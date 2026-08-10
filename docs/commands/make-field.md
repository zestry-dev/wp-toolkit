<!--
    Generated from commands/make/field.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make field

Generate a post meta field.

Writes a file into the plugin's `fields/` directory, where the Fields module discovers it. The name becomes the meta key, which you should prefix if the field attaches to a post type you do not own.

## Options

- **`<name>`**  
  The meta key, e.g. `acme-rating`. Written exactly as given — a meta key is the `meta_key` column and appears in your REST responses, so nothing respells it. To mark the field protected whatever it is called, uncomment `is_protected()` in the generated file and return true.

- **`[--dir=<dir>]`**  
  Write somewhere other than `fields/`, relative to the plugin root.

## Examples

```bash
# Generate fields/acme-rating.php.
$ wp zestry make field acme-rating
Success: Created fields/acme-rating.php
```
