<!--
    Generated from commands/make/shared.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make shared

Generate a shared JavaScript package.

Writes an npm workspace under `src/shared/`, imported by name from anywhere in the plugin — an admin entry, a block, another shared package:

```js
import { greet } from '@acme-plugin/formatting';
```

Nothing that imports it bundles a copy. The generated `webpack.config.js` reads the `wordpress` block in the package's own `package.json`, builds it once into `build/shared/`, and makes every importer declare it as a dependency instead — the same treatment `@wordpress/element` already gets.

The scope is your plugin slug. A script package registers as `{slug}-shared-{name}` and a module keeps the npm name it is imported by; either way the build composes it, so there is nothing here to keep in step. A package name is an npm one and takes no capitals or spaces, so a name holding either is written as the one npm accepts and the command says what it wrote.

Run `npm install` afterwards: npm is what links the new directory into `node_modules/`, and until it has, the import resolves to nothing.

Add the `assets` module to register what the build produces: `wp zt add module assets`.

## Options

- **`<name>`**  
  The package's name, in kebab-case, e.g. `formatting`.

- **`[--kind=<kind>]`**  
  How WordPress loads it. `script` registers a handle other scripts depend on, and works everywhere. `module` registers an ES module, which needs WordPress 6.5 or newer and importers that are modules themselves. Asked for when omitted.  
  Accepts `script`, `module`.

- **`[--yes]`**  
  Overwrite an existing file without asking, and take the default for `--kind` rather than asking — a `script` package, which works everywhere.

> [!NOTE]
> **This generator asks for anything you leave out.** Give every option above and it never stops.
>
> `--yes` answers every prompt with the documented default, without reading input — which is what an unattended run wants. Without it, and with nothing on standard input, the command waits.

## Examples

```bash
# A package other scripts depend on by handle.
$ wp zt make shared formatting --kind=script
Success: Created src/shared/formatting (2 files)

# An ES module, imported by name at run time.
$ wp zt make shared runtime --kind=module
Success: Created src/shared/runtime (2 files)
```
