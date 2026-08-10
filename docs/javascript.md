# JavaScript

Everything JavaScript lives under `src/`, in three directories that differ in one thing: who registers the built result with WordPress.

```
src/
  blocks/toggle/         a block          → WordPress registers it, from block.json
  entries/settings/      your own script  → the assets module, as {plugin-slug}-settings
  entries/cart/          …or an ES module  → the assets module, via wp_register_script_module()
  shared/formatting/     shared code      → the assets module, under the build's own handle
```

One command sets all of it up:

```bash
wp zestry add module assets
```

which copies the [`assets`](modules/assets/) module and writes the `webpack.config.js`, the npm workspace declaration and the `build`/`start` scripts. `add module blocks` writes those same two scripts from the same definition, so whichever you add first writes them and the second leaves them alone. An existing `webpack.config.js` is never replaced, so the generated one is yours to edit.

> [!IMPORTANT]
> **Blocks alone need no config.** `wp-scripts` finds every block by globbing for a `block.json`, so a plugin that only builds blocks can stop reading here.
>
> **The config matters the moment you add anything else.** `@wordpress/scripts` decides entry points three mutually exclusive ways — files listed on the command line, `block.json` scanning, or the `src/index` fallback — and each one *disables the others*. So a plugin with blocks *and* an entry gets one or the other, silently. Merging the three is what this config is for, and why it comes with `assets` rather than with `blocks`.

## Your own scripts

```bash
wp zestry make entry settings
npm run build
```

writes `src/entries/settings/index.ts` and `style.scss` beside it. Use it from anywhere in PHP:

```php
public Assets $assets;

public function enqueue_assets(): void {
    $this->assets->enqueue_entry( 'settings' );
}
```

There is no registration call. The module registers every built entry on `init`, under the handle `{plugin-slug}-settings`, so the local name is all you ever pass.

The stylesheet comes along under that same handle — scripts and styles are separate WordPress registries, so one name serves both, and there is no second thing to remember. It is built because `index.ts` imports it; delete the import and it stops being built.

An entry can also be **only** a stylesheet: leave out `index.ts` and put `style.scss` in the directory on its own. The build drops the JavaScript webpack would otherwise generate for it — a file of pure runtime — and registers just the style.

### An entry can be a module

```bash
wp zestry make entry cart --kind=module
```

which adds a `package.json` saying so. It is then built as an ES module and registered with `wp_register_script_module()` — what you want for Interactivity API code that is not inside a block.

The catch is what a module may import: only what WordPress itself ships as a script module. `@wordpress/interactivity` is one; `@wordpress/element` is not, and webpack says so outright rather than building something that cannot load. Use `enqueue_entry()` rather than `enqueue_script()` and changing an entry's kind stays a one-line change in its own `package.json`.

## Shared code

When two entries need the same code, the obvious move quietly costs you: webpack copies that file into every entry that imports it, so an admin screen and a block on the same page each ship their own.

```bash
wp zestry make shared formatting --kind=script
npm install
npm run build
```

Now import it by name, from anywhere:

```js
import { formatMoney } from '@acme-plugin/formatting';
```

It is built once into `build/shared/`, and every importer declares it as a dependency instead of copying it — the same treatment `@wordpress/element` already gets. The scope, `@acme-plugin`, is your plugin's slug — the same name `assets` registers the built package under, so the import and the handle cannot disagree.

> [!IMPORTANT]
> **`npm install` after making one.** npm is what links `src/shared/formatting` into `node_modules/`. Until it has, the import resolves to nothing and the build fails with "module not found".

### The two kinds

`--kind=script` registers a classic script handle. It works everywhere, in every context. Reach for it unless you have a reason not to.

`--kind=module` registers an ES module under the package's npm name. Modules are how the Interactivity API and the block editor's newer front-end code load, and they only import each other — a classic script cannot import one. Needs WordPress 6.5 or newer.

You can have both in one plugin; each package picks its own.

## The manifest

`src/` is source, and a shipped plugin need not contain it — so everything PHP needs travels with the build output instead, in `build/assets-manifest.php`:

```php
<?php return array(
    'blocks/toggle/index' => array(
        'asset' => array( 'dependencies' => array(), 'version' => '96f42e92' ),
    ),
    'entries/settings'    => array(
        'css'   => 'entries/style-settings.css',
        'rtl'   => 'entries/style-settings-rtl.css',
        'asset' => array( 'dependencies' => array( 'acme-plugin-formatting' ), 'version' => 'dd8b2e6d' ),
    ),
    'shared/formatting'   => array(
        'kind'  => 'script',
        'id'    => 'acme-plugin-formatting',
        'asset' => array( 'dependencies' => array(), 'version' => '137631dc' ),
    ),
);
```

One `require` tells the module every entry that exists, what each depends on, which ones are shared packages, and what stylesheet each produced. Read it yourself with `$assets->get_build_manifest()`.

The stylesheet is recorded rather than derived because its name is not predictable: a source file called `style.scss` is split into a chunk of its own and written as `style-{entry}.css`, while any other name lands as `{entry}.css`. Asking the build is right for both.

There is a second file, `assets-module-manifest.php`, only because `--experimental-modules` runs two webpack compilations — a single name would let one silently discard the other's entries.

## Nothing empty is registered

An asset that compiles to nothing is deleted by the build and left out of the manifest, so it is never registered and never reaches a page:

| | |
| --- | --- |
| A stylesheet that compiles to nothing | deleted; no `<link>` |
| An entry that is only a stylesheet | its generated JavaScript deleted; no `<script>` |
| A block's `style` naming a stylesheet that compiled away | the field is dropped from its `block.json` |

That last one matters most, because it is not yours to fix by hand: `blocks-manifest.php` is generated from those files after the build, so removing the field is what stops WordPress registering the empty stylesheet.

This is why a freshly generated entry costs nothing until you write something. `wp zestry make entry` scaffolds a `style.scss` with no rules in it, and an empty scaffold should not be a request on every page load.

## Tips

- **A shared directory with no `wordpress` block is an ordinary dependency.** It is bundled into whatever imports it, like anything from npm. That is the right choice when a copy costs less than a request.
- **`npm run build` asks for three things `wp-scripts` does not do by default.** The generated scripts pass `--webpack-copy-php`, so a block's `block.php` reaches `build/`; `--experimental-modules`, without which a `"kind": "module"` package is silently skipped; and `--blocks-manifest`. You do not add these — `wp zestry add module blocks` and `wp zestry add module assets` write them.
- **A global is two segments.** `[ "acmePlugin", "formatting" ]` puts a shared package on `window.acmePlugin.formatting`, so everything you share sits under one global and cannot collide with another plugin's.
- **A module can only import a module.** That is the real limit on `--kind=module`, for an entry or a shared package alike: `@wordpress/interactivity` is available as a script module, `@wordpress/element` is not.
- **`webpack.config.js` is yours.** It is written once and nothing overwrites it, including [`wp zestry update`](commands/update.md). Edit it freely.
- **The manifest is build output.** Gitignored with the rest of `build/`, regenerated every build. Never edit or commit it.
- **Nothing registers what was never built.** A plugin that ships without running `npm run build` ships without its JavaScript.
- **Emptiness is decided at build time, not run time.** PHP never checks a file's size; it registers what the manifest lists, and the manifest lists what survived the build.

## See also

- [`assets`](modules/assets/) — the module reference: asset URLs, script and style registration, entries and shared packages.
- [`blocks`](modules/blocks/) — the third directory, which WordPress registers itself.
- [`wp zestry make entry`](commands/make-entry.md) &nbsp;·&nbsp; [`wp zestry make shared`](commands/make-shared.md) &nbsp;·&nbsp; [`wp zestry make block`](commands/make-block.md)
- [Shipping](shipping.md) — what belongs in the zip.
