<!--
    Generated from resources/commands/make/rule.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make rule

Generate a validation rule of your own.

Requires `wp zt init` to have already run in this plugin, and the `validation` module (`wp zt add validation`). The file lands in `{zestry.json root}/Rules/` and is reached by being used: put `#[Slug]` on a property and it applies there. Nothing is declared in `bootstrap.php`.

## Options

- **`<name>`**  
  The class name, e.g. 'Slug'. Becomes both the filename (`{name}.php`) and the attribute's name — give it exactly as it should read after `#[`. Qualify it to group: `Posts/PublishedPost` writes `Rules/Posts/PublishedPost.php`.

- **`[--yes]`**  
  Overwrite an existing rule without asking, for an unattended run.

## Examples

```bash
$ wp zt make rule Slug
Success: Created lib/Rules/Slug.php

$ wp zt make rule Posts/PublishedPost
Success: Created lib/Rules/Posts/PublishedPost.php
```
