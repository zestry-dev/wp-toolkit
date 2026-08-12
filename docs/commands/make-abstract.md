<!--
    Generated from commands/make/abstract.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make abstract

[What to extend](#what-to-extend) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Generate an intermediate abstract of your own.

Requires `wp zt init` to have already run in this plugin. The file lands in `{zestry.json root}/Abstracts/`, which is the first place `--extends=` looks — so `make field acme-rating --extends=EntityField` finds it by the bare name afterwards.

## What to extend

`--for=<type>` extends that `make` type's own base, so an abstract for fields is written `--for=field` rather than by naming `Core\Modules\Fields\Field` — the `Core` segment is the toolkit's to know, not yours to type.

`--extends=<class>` extends one of your own classes instead, which is how a second abstract is layered onto the first.

Neither one extends nothing, which is a plain abstract class: useful for something shared that is not a discovered file at all.

## Options

- **`<name>`**  
  The class name, e.g. 'EntityField'. Becomes both the filename (`{name}.php`) and the class name — give it exactly as it should appear after `abstract class`. Qualify it to group: `Fields/EntityField` writes `Abstracts/Fields/EntityField.php`. There is no `--dir`, since PSR-4 ties a namespace to one directory and the name decides both.

- **`[--for=<type>]`**  
  Extend the base class files of this `make` type return, e.g. `field`, `post-type`, `ability`.

- **`[--extends=<class>]`**  
  Extend one of your own classes instead. A bare name is looked for under your Abstracts\ namespace.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

## Examples

```bash
# An abstract every post type file will extend.
$ wp zt make abstract EntityPostType --for=post-type
Success: Created lib/Abstracts/EntityPostType.php

# Layered onto that one.
$ wp zt make abstract CuratedPostType --extends=EntityPostType
Success: Created lib/Abstracts/CuratedPostType.php

# Shared by something that is not a discovered file.
$ wp zt make abstract Importer
Success: Created lib/Abstracts/Importer.php
```
