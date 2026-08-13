<!--
    Generated from commands/make/block.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make block

Generate a new editor block.

The Blocks module discovers it, but only after a build. What this command writes is source: a `block.json` and the scripts, styles and optional PHP it points at, under `src/blocks/{name}/`. `npm run build` compiles that into `build/blocks/`, which is the directory the module walks and registers from — so a block that has never been built registers nothing.

Needs the `blocks` module, so run `wp zt add module blocks` first if you have not already; that is also what writes the npm scripts this build runs through.

WordPress matches both halves of a block name against `^[a-z0-9-]+$`, so a name holding anything else is written as the one it accepts and the command says what it wrote.

## Options

- **`<name>`**  
  The local name, e.g. 'hero'. Becomes the directory (`src/blocks/{name}/`) and the second half of the block's own name, `{plugin-slug}/{name}`. **The slug, not the text domain.** The two are answered separately and are often equal, so it is worth checking which you have: the module decides a block is yours by comparing the namespace in its name against your slug, and looks for its PHP under a `supports.{plugin-slug}-php` entry. A block namespaced anything else registers, works in the editor, and renders nothing on the front end.

- **`[--dynamic]`**  
  Render the block in PHP. Adds a `block.php` returning a Block subclass, and the `supports.{plugin-slug}-php` entry pointing at it. Without this the block is static: its markup is whatever the editor saved. Prompted for when omitted. Two questions settle it. Does the output depend on anything outside the block's own attributes — a query, an option, the current user, another post? Then it has to be dynamic. Otherwise, is the markup settled? Static markup is saved into `post_content`, so it survives the plugin being deactivated, but changing it later means owing a `deprecated` entry and a migration or every saved post shows "This block contains unexpected or invalid content". Dynamic markup is free to change forever, and renders nothing at all once the plugin is gone.

- **`[--view=<kind>]`**  
  Give the block front-end JavaScript. `module` writes an Interactivity API store and registers it as a script module; `script` writes a classic script that runs against the rendered markup; `none` writes neither. Prompted for when omitted — as two questions, since the choice between `script` and `module` only arises once you have said you want JavaScript at all. The two are not interchangeable source: the Interactivity API is itself a script module, and a classic script cannot depend on one — so each mode generates the code its registration can actually load.  
  Accepts `none`, `script`, `module`.

- **`[--js]`**  
  Generate plain JavaScript instead of TypeScript.

- **`[--yes]`**  
  Overwrite an existing file without asking, and take the default for `--dynamic` and `--view` rather than asking — a static block with no front-end script. Pass those flags to choose otherwise.

> [!NOTE]
> **This generator asks for anything you leave out.** Give every option above and it never stops.
>
> `--yes` answers every prompt with the documented default, without reading input — which is what an unattended run wants. Without it, and with nothing on standard input, the command waits.

## Examples

```bash
# Neither flag given, so both are asked for. A run with no terminal --
# CI, a script, an agent -- must pass both, or it will hang here.
$ wp zt make block hero
Render this block in PHP (dynamic)? [y/N] n
Give this block front-end JavaScript? [y/N] n
Success: Created src/blocks/hero (5 files)

# Saying yes to the JavaScript asks which kind, since `--view` was
# not given. Answering here is the same as passing --view=module.
$ wp zt make block hero
Render this block in PHP (dynamic)? [y/N] n
Give this block front-end JavaScript? [y/N] y
Use the Interactivity API? [Y/n] y
Success: Created src/blocks/hero (6 files)

# The same block, non-interactively. `--yes` is what takes the default
# for the prompts a flag has not already answered.
$ wp zt make block hero --view=none --yes
Success: Created src/blocks/hero (5 files)

# A server-rendered block, with an Interactivity API front end. Both
# prompts are answered by flags, so no --yes is needed.
$ wp zt make block toggle --dynamic --view=module
Success: Created src/blocks/toggle (7 files)

# Nothing registers until the block is built: the Blocks module reads
# `build/blocks`, not the `src/blocks` written here.
$ npm run build
```
