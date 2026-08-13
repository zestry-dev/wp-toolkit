<!--
    Generated from commands/init.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt init

[Tooling](#tooling) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Set up a plugin to receive wp-toolkit source.

One-time, interactive setup for the plugin that required `wp-toolkit` as a Composer dependency. Prompts for the namespace the copied source should be rewritten to, the text domain its translation calls should be rewritten to, and the directory (relative to your plugin's root) to copy it into, then copies the kernel — Plugin, Service, Module, ActivationHandler, the exceptions your plugin catches, the PluginAware contract, and the shared traits, attributes and helpers every class needs — into `{root}/Core/Kernel/`.

Four files are written around that copy: `zestry.json`, recording the three choices above; `zestry.lock.json`, recording the hash of every copied file as it was written, which is what later lets `wp zt update` tell an edit of yours from an upstream change; `bootstrap.php`, the file your modules are declared in; and `.gitignore`, covering the directories that are built rather than authored. It then adds a matching PSR-4 autoload entry to your own composer.json and shells out to `composer dump-autoload`, so the copied classes load without a further step.

Refuses to run if zestry.json already exists, since that means the plugin has already been initialized; run `wp zt add module <name>` instead to copy in additional feature modules.

That directory is where the plugin's *own* classes belong too, beside the copied source rather than in a second root of their own. After this command there is no "toolkit" half to keep separate from: it is all the plugin's code, under one namespace and one PSR-4 entry. A second root would need a second PSR-4 prefix, which every class beneath it then carries as an extra namespace segment.

Do not choose `src`: `@wordpress/scripts` treats it as its source path, and `wp zt make block` writes into `src/blocks/`.

## Tooling

After the source is copied, this sets up the things a plugin usually wants and nothing else provides. Each is written without asking — pass the matching `--no-` flag below to skip one.

Each writes only what is missing: a config file already on disk is left alone, a dependency already required keeps whatever constraint it has, and an existing script is never rewritten — so running this in a configured plugin changes nothing.

- `phpcs.xml`, with the same ruleset the copied source is written to, so
`composer lint` passes on the code you were just handed rather than flagging it. Adds the three phpcs packages, allows the standards installer to run, and adds `composer lint` / `composer lint:fix`.

- `eslint.config.mjs`, extending `@wordpress/eslint-plugin`. Flat config,
because that is the only format the ESLint bundled with current `@wordpress/scripts` reads. Adds its npm packages and `npm run lint:js`.

- `.prettierrc.js`, re-exporting `@wordpress/prettier-config`. It is
CommonJS, so rename it to `.prettierrc.cjs` if your package.json declares `"type": "module"`. Adds its npm packages and `npm run format`, plus a `.prettierignore` — that script is `prettier --write .`, and without one it reformats your `composer.json` and your Markdown too.

- `AGENTS.md`, the invariants an agent working in this plugin needs, and a
`.claude/CLAUDE.md` pointing at it. Rendered from the toolkit's own rules page rather than written twice, and describing no feature of your plugin — `wp zt describe` answers that from the plugin itself.

Dependencies are added unversioned. The pin belongs in your lock file, written the first time you install.

## Options

- **`[--yes]`**  
  Take the default answer to every prompt, for an unattended run. The namespace is inferred from composer.json's PSR-4 entry, the text domain from the entry file's own `Text Domain:` header (or the directory name when it declares none), and the root is whatever composer.json already maps that namespace to, falling back to `lib`. Fails with a message naming what to fix when either inferred value is unusable, rather than proceeding with a wrong one. A brand-new plugin has no PSR-4 entry yet — `init` is what writes one — so there is nothing to infer a namespace from and this stops rather than guessing. For an unattended first run, declare the entry in composer.json yourself before calling this; every run after that has one.

- **`[--no-phpcs]`**  
  Skip the phpcs.xml and its Composer dev dependencies.

- **`[--no-eslint]`**  
  Skip the ESLint config and its npm dev dependencies.

- **`[--no-agents]`**  
  Skip AGENTS.md, the instructions an agent working in this plugin reads.

- **`[--no-prettier]`**  
  Skip the Prettier config and its npm dev dependencies.

## Examples

```bash
# A full run. The copied source becomes the plugin's own code, so a
# plain project namespace is normal here.
$ wp zt init
Namespace (e.g. Vendor\MyPlugin): Vendor\MyPlugin
Text domain: (default: my-plugin) my-plugin
Source directory: (default: lib) lib
Copy the kernel into lib/Core/Kernel/ under Vendor\MyPlugin? [Y/n] y
Created bootstrap.php. Read it with `$plugin->bootstrap()` in your entry file.
Added to .gitignore: build/, vendor/, node_modules/, .DS_Store, *.log
Wrote phpcs.xml
Added to composer.json: wp-coding-standards/wpcs, ...
Wrote eslint.config.mjs
Wrote .prettierrc.js
Wrote .prettierignore
Added to package.json: eslint, @wordpress/eslint-plugin, prettier, ...
Success: Initialized. Run `wp zt add module <name>` to copy in feature modules.

# Unattended, taking every inferred default and setting up all four.
$ wp zt init --yes
Success: Initialized. Run `wp zt add module <name>` to copy in feature modules.

# Unattended, and without the JS tooling.
$ wp zt init --yes --no-eslint --no-prettier
Success: Initialized. Run `wp zt add module <name>` to copy in feature modules.
```
