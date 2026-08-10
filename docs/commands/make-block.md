<!--
    Generated from commands/make/block.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make block

Generate a new editor block.

The Blocks module discovers it, but only after a build. What this command writes is source: a `block.json` and the scripts, styles and optional PHP it points at, under `src/blocks/{name}/`. `npm run build` compiles that into `build/blocks/`, which is the directory the module walks and registers from — so a block that has never been built registers nothing.

Needs the `blocks` module, so run `wp zestry add module blocks` first if you have not already; that is also what writes the npm scripts this build runs through.

WordPress matches both halves of a block name against `^[a-z0-9-]+$`, so a name holding anything else is written as the one it accepts and the command says what it wrote.

## Options

- **`<name>`**  
  The local name, e.g. 'hero'. Becomes the directory (`src/blocks/{name}/`) and the second half of the block's own name, `{plugin-slug}/{name}`. **The slug, not the text domain.** They are answered separately and are often equal, which is why this went wrong once: the module decides a block is yours by comparing the namespace in its name against your slug, and looks for its PHP under a `supports.{plugin-slug}-php` entry. A block namespaced anything else registers, works in the editor, and renders nothing on the front end.

- **`[--dir=<dir>]`**  
  Write into this plugin-relative directory instead of `src/blocks` — note this is the *source* directory `wp-scripts` compiles from, not the built one the Blocks module discovers.

- **`[--dynamic]`**  
  Render the block in PHP. Adds a `block.php` returning a Block subclass, and the `supports.{plugin-slug}-php` entry pointing at it. Without this the block is static: its markup is whatever the editor saved. Prompted for when omitted.

- **`[--view=<kind>]`**  
  Give the block front-end JavaScript. `module` writes an Interactivity API store and registers it as a script module; `script` writes a classic script that runs against the rendered markup; `none` writes neither. Prompted for when omitted. The two are not interchangeable source: the Interactivity API is itself a script module, and a classic script cannot depend on one — so each mode generates the code its registration can actually load.  
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
$ wp zestry make block hero
Render this block in PHP (dynamic)? [y/N] n
Give this block front-end JavaScript? [y/N] n
Success: Created src/blocks/hero (5 files)

# The same block, non-interactively. `--yes` is what takes the default
# for the prompts a flag has not already answered.
$ wp zestry make block hero --view=none --yes
Success: Created src/blocks/hero (5 files)

# A server-rendered block, with an Interactivity API front end. Both
# prompts are answered by flags, so no --yes is needed.
$ wp zestry make block toggle --dynamic --view=module
Success: Created src/blocks/toggle (7 files)

# Nothing registers until the block is built: the Blocks module reads
# `build/blocks`, not the `src/blocks` written here.
$ npm run build
```
