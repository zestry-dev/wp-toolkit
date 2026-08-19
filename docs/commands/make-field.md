<!--
    Generated from resources/commands/make/field.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make field

Generate a post meta field.

Writes a file into the plugin's `resources/fields/` directory, where the Fields module discovers it. The name becomes the meta key, which you should prefix if the field attaches to a post type you do not own.

The file is filed under `{object-type}/{subtype}/`, so `resources/fields/` reads as an index of what is stored where. Folders are organization only — the module reads the filename and `subtypes()`, never the path — so move one whenever the grouping stops helping.

## Options

- **`<name>`**  
  The meta key, e.g. `acme-rating`. Written exactly as given — a meta key is the `meta_key` column and appears in your REST responses, so nothing respells it. To mark the field protected whatever it is called, uncomment `is_protected()` in the generated file and return true.

- **`[--object-type=<object-type>]`**  
  Which meta table the key lives in: post, term, user or comment. Prompted for when not given. Post meta is the common case and the default.

- **`[--subtypes=<subtypes>]`**  
  What the field attaches to within that table, comma-separated — post type names for post meta, taxonomy names for term meta. Prompted for when not given. Naming them is what makes the field own its key: `Fields::set()` refuses this key on anything else, where nothing would sanitize or validate the value. An empty answer attaches it to every subtype. User and comment meta have no subtypes — WordPress answers `user` and `comment` for every one of them — so this is refused there rather than registering meta nothing can ever match.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

- **`[--yes]`**  
  Answer every prompt without reading input: take the default for the object type and subtypes, overwrite an existing file, and add the `fields` module when this plugin has none.

> [!NOTE]
> **This generator asks for anything you leave out.** Give every option above and it never stops.
>
> `--yes` answers every prompt with the documented default, without reading input — which is what an unattended run wants. Without it, and with nothing on standard input, the command waits.

## Examples

```bash
# Generate a rating on the book post type, prompting for both.
$ wp zt make field acme-rating
Which meta table (post, term, user, comment): (default: post)
Post type(s) this field attaches to, comma-separated: (default: post) book
Success: Created resources/fields/post/book/acme-rating.php

# The same, given explicitly.
$ wp zt make field acme-rating --object-type=post --subtypes=book
Success: Created resources/fields/post/book/acme-rating.php

# Two post types, so no single folder is right: filed under the table.
$ wp zt make field acme-rating --subtypes=book,film
Success: Created resources/fields/post/acme-rating.php

# User meta, which has no subtypes.
$ wp zt make field acme-tier --object-type=user
Success: Created resources/fields/user/acme-tier.php
```
