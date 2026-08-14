<!--
    Generated from resources/commands/make/taxonomy.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make taxonomy

Generate a new custom taxonomy.

The PostTypes module discovers it. On `init` it walks your `resources/taxonomies/` directory, requires every file in it, and hands the `Taxonomy` each one returns to `register_taxonomy()`, attached to the post types the file names. Writing the file is the whole registration; nothing has to be declared anywhere.

Needs the `post-types` module — the same one that registers post types — so run `wp zt add post-types` first if you have not already.

## Options

- **`<name>`**  
  The local name, e.g. 'genre'. Becomes both the filename (`{name}.php`) under `resources/taxonomies/` and the registered taxonomy itself — unlike every other `make` type, this name is NOT namespaced to the plugin slug (WordPress caps a taxonomy name at 32 characters), so pick something short and globally unique.

- **`[--singular=<singular>]`**  
  The singular display name, e.g. 'Genre'.  
  Defaults to the title-cased name without prompting.

- **`[--plural=<plural>]`**  
  The plural display name, e.g. 'Genres'. Prompted for when not given, since pluralization cannot be guessed reliably from the singular name.

- **`[--object-type=<object_type>]`**  
  The post type this taxonomy attaches to, e.g. 'book' (or WordPress's own built-in 'post'). Prompted for when not given.

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
# Generate a taxonomy, prompting for the plural name and object type.
$ wp zt make taxonomy genre
Plural name: (default: Genres)
Post type this taxonomy attaches to: (default: post)
Success: Created resources/taxonomies/genre.php

# Generate one with every value given explicitly.
$ wp zt make taxonomy genre --singular=Genre --plural=Genres --object-type=book
Success: Created resources/taxonomies/genre.php
```
