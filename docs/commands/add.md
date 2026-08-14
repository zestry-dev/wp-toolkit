<!--
    Generated from resources/commands/add.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt add

Copy one or more feature modules into an initialized plugin.

Requires `wp zt init` to have already run in this plugin (it reads zestry.json for the namespace chosen there, and the `lib` source root). Resolves each requested module's dependencies before copying anything, so `rest-api`, for example, also brings in `path` without needing to be asked for by name. Every `namespace Zestry\WPToolkit\...;` declaration and `use Zestry\WPToolkit\...;` import in each copied file is rewritten to the project's own namespace. A module already present at its destination is left untouched and logged as skipped — run `wp zt overwrite <module>` to replace it deliberately.

Each copied module is also declared in the plugin's `bootstrap.php`, which is what builds it — so a module works the moment it arrives. With no `bootstrap.php` to append to, the entry line is printed for you to paste wherever the plugin declares its modules instead.

**Two modules need a newer WordPress than the rest**: `abilities` needs 6.9, `icons-library` needs 7.1. Both are measured against your entry file's own `Requires at least:` header — the version your users' sites are held to — rather than against the WordPress you are developing on, since a module that works here and not on the oldest site you support is a module you would ship broken. A header promising less, or missing entirely, refuses the whole batch and copies nothing, naming the version to set.

Two modules also write build tooling outside their own tree. Everything either writes is additive: anything already there is kept as it is and reported as such.

`add blocks` writes the toolchain — the scripts and devDependencies in your package.json, a tsconfig.json, an eslint.config.mjs, a `.prettierrc.js` if you have no Prettier config already, and `build/`, `vendor/` and `node_modules/` in your .gitignore. It writes **no** `webpack.config.js`: `wp-scripts` finds every block by globbing for a `block.json` anywhere under `src/`, so blocks alone need no config file.

`add assets` writes the `webpack.config.js`, which is what lets one build produce all three directories — blocks, entries and shared packages. The JavaScript guide covers what it does, and why a stock `wp-scripts` setup cannot.

## Options

- **`<module>...`**  
  One or more module names to copy in. Available modules: path, request, cookie, globals, transients, db, views, assets, log, options, ajax, admin-pages, rest-api, cli, cron, fields, post-types, blocks, meta-boxes, site-health, abilities, icons-library, migrations.

## Examples

```bash
# Copy the REST API module, and the modules it depends on.
$ wp zt add rest-api
Also adding required dependencies: path
Added path
Added rest-api
Declared in bootstrap.php: rest-api
Success: Done.

# Copy several in one call.
$ wp zt add cli admin-pages
Success: Done.

# Already on disk, so it is left exactly as it is.
$ wp zt add cli
Skipped cli (already present)
Success: Done.
```
