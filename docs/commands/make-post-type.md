<!--
    Generated from commands/make/post-type.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make post-type

Generate a new custom post type.

The PostTypes module discovers it. On `init` it walks your `post-types/` directory, requires every file in it, and hands the `PostType` each one returns to `register_post_type()`, with a full `labels` array built from the singular and plural names below. Writing the file is the whole registration; nothing has to be declared anywhere.

Needs the `post-types` module, so run `wp zt add module post-types` first if you have not already.

## Options

- **`<name>`**  
  The local name, e.g. 'book'. Becomes both the filename (`{name}.php`) under `post-types/` and the registered post type itself — unlike every other `make` type, this name is NOT namespaced to the plugin slug (WordPress caps a post type name at 20 characters), so pick something short and globally unique.

- **`[--dir=<dir>]`**  
  Write into this plugin-relative directory instead of `post-types` — pass it when you have pointed PostTypes's post types root somewhere other than its default.

- **`[--singular=<singular>]`**  
  The singular display name, e.g. 'Book'.  
  Defaults to the title-cased name without prompting.

- **`[--plural=<plural>]`**  
  The plural display name, e.g. 'Books'. Prompted for when not given, since pluralization cannot be guessed reliably from the singular name.

- **`[--yes]`**  
  Overwrite an existing file without asking, and take the default for every prompt below rather than asking, for an unattended run.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

> [!NOTE]
> **This generator asks for anything you leave out.** Give every option above and it never stops.
>
> `--yes` answers every prompt with the documented default, without reading input — which is what an unattended run wants. Without it, and with nothing on standard input, the command waits.

## Examples

```bash
# Generate a post type, prompting only for the plural name.
$ wp zt make post-type book
Plural name: (default: Books)
Success: Created post-types/book.php

# Generate one with both names given explicitly.
$ wp zt make post-type book --singular=Book --plural=Books
Success: Created post-types/book.php
```
