<!--
    Generated from commands/add/module.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt add module

[Dependencies cross the two kinds](#dependencies-cross-the-two-kinds) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Copy one or more feature modules into an initialized plugin.

Requires `wp zt init` to have already run in this plugin (it reads zestry.json for the namespace and destination directory chosen there). Resolves each requested module's dependencies before copying anything, so `rest-api`, for example, also brings in `path` without needing to be asked for by name. Every `namespace Zestry\WPToolkit\...;` declaration and `use Zestry\WPToolkit\...;` import in each copied file is rewritten to the project's own namespace. A module already present at its destination is left untouched and logged as skipped — run `wp zt overwrite module <module>` to replace it deliberately.

Each copied module is also declared in the plugin's `bootstrap.php`, which is what builds it — so a module works the moment it arrives. With no `bootstrap.php` to append to, the entry line is printed for you to paste wherever the plugin declares its modules instead.

Two modules also write build tooling outside their own tree. Everything either writes is additive: anything already there is kept as it is and reported as such.

`add module blocks` writes the toolchain — the scripts and devDependencies in your package.json, a tsconfig.json, an eslint.config.mjs, a `.prettierrc.js` if you have no Prettier config already, and `build/`, `vendor/` and `node_modules/` in your .gitignore. It writes **no** `webpack.config.js`: `wp-scripts` finds every block by globbing for a `block.json` anywhere under `src/`, so blocks alone need no config file.

`add module assets` writes the `webpack.config.js`, which is what lets one build produce all three directories — blocks, entries and shared packages. The JavaScript guide covers what it does, and why a stock `wp-scripts` setup cannot.

## Dependencies cross the two kinds

A module may depend on services, and most do: everything but `log` and `options` needs `path`, and `migrations` also needs `db`. Those arrive with it. This command asks which kind you are naming, not which kinds it is allowed to copy.

To copy a service on its own, use `wp zt add service <service>`.

## Options

- **`<module>...`**  
  One or more module names to copy in. Available modules: log, options, assets, ajax, admin-pages, rest-api, cli, cron, fields, meta-boxes, post-types, blocks, site-health, abilities, icons-library, migrations.

## Examples

```bash
# Copy the REST API module, and the service it needs.
$ wp zt add module rest-api
Also adding required dependencies: path
Added path
Added rest-api
Declared in bootstrap.php: rest-api
Success: Done.

# Copy several in one call.
$ wp zt add module cli admin-pages
Success: Done.

# Already on disk, so it is left exactly as it is.
$ wp zt add module cli
Skipped cli (already present)
Success: Done.

# Naming a service here says where to find it.
$ wp zt add module path
Error: "path" is a service, not a module. Run `wp zt add service path`.
```
