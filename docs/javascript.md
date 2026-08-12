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
wp zt add module assets
```

which copies the [`assets`](modules/assets/) module and writes the `webpack.config.js`, the npm workspace declaration and the `build`/`start` scripts. `add module blocks` writes those same two scripts from the same definition, so whichever you add first writes them and the second leaves them alone. An existing `webpack.config.js` is never replaced, so the generated one is yours to edit.

> [!IMPORTANT]
> **Blocks alone need no config.** `wp-scripts` finds every block by globbing for a `block.json`, so a plugin that only builds blocks can stop reading here.
>
> **The config matters the moment you add anything else.** `@wordpress/scripts` decides entry points three mutually exclusive ways — files listed on the command line, `block.json` scanning, or the `src/index` fallback — and each one *disables the others*. So a plugin with blocks *and* an entry gets one or the other, silently. Merging the three is what this config is for, and why it comes with `assets` rather than with `blocks`.

## Your own scripts

```bash
wp zt make entry settings
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

### Getting PHP data into it

Export an `initialize()` from the entry, and call it with the data:

```php
$handle = $this->assets->enqueue_entry( 'settings' );

wp_add_inline_script(
    $handle,
    sprintf( 'acmeSettings.initialize( %s );', wp_json_encode( $data ) ),
    'after'
);
```

`enqueue_entry()` returns the real handle, so it goes straight to WordPress's own function — there is nothing to prefix and no second name to keep in step.

**Hand the data to the script rather than leaving it on a global for the script to find.** Both work, and this one fails better: printed `after`, it calls a function the bundle defined, so a bundle that failed to load throws `initialize is not a function` in the console instead of leaving an unread global and a screen with nothing on it. It is also the shape core starts its own editors with — `wp.editWidgets.initialize( … )`, `wp.editSite.initialize( … )`.

Two things that make `after` the right position:

- **An entry registers blocking, in the footer.** So by the time the inline script runs, the bundle has evaluated and `initialize` exists. Give a script a `defer` strategy of your own and you need core's `wp.domReady()` wrapper as well.
- **`wp_json_encode()`, not `wp_localize_script()`.** That function casts every scalar it passes to a string, so `'bindable' => false` arrives in JavaScript as `""` — which is truthy, and every field reads as bindable.

### An entry can be a module

```bash
wp zt make entry cart --kind=module
```

which adds a `package.json` saying so. It is then built as an ES module and registered with `wp_register_script_module()` — what you want for Interactivity API code that is not inside a block.

Reach for it when you are writing Interactivity API code, and stay on the default `script` otherwise.

Use `enqueue_entry()` either way: it picks the right registry, so changing an entry's kind stays a one-line change in its own `package.json`.

## Shared code

When two entries need the same code, the obvious move quietly costs you: webpack copies that file into every entry that imports it, so an admin screen and a block on the same page each ship their own.

```bash
wp zt make shared formatting --kind=script
npm install
npm run build
```

Now import it by name, from anywhere:

```js
import { formatMoney } from '@acme-plugin/formatting';
```

It is built once into `build/shared/`, and every importer declares it as a dependency instead of copying it — the same treatment `@wordpress/element` already gets. The scope, `@acme-plugin`, is your plugin's slug.

Its `package.json` says only how WordPress should load it:

```json
"wordpress": { "kind": "script" }
```

The handle it registers under — `acme-plugin-shared-formatting` — and the `window.acmePlugin.formatting` global it publishes are both composed by the build, from that slug and the directory name. Neither is yours to write down, because the build is also what records the handle in every importer's `.asset.php`; a second copy could only ever disagree with the one that counts.

> [!IMPORTANT]
> **`npm install` after making one.** npm is what links `src/shared/formatting` into `node_modules/`. Until it has, the import resolves to nothing and the build fails with "module not found".

### The two kinds

`--kind=script` registers a classic script handle. It works everywhere, in every context. Reach for it unless you have a reason not to.

`--kind=module` registers an ES module under the package's npm name. Modules are how the Interactivity API and the block editor's newer front-end code load.

You can have both in one plugin; each package picks its own.

## The manifest

`src/` is source, and a shipped plugin need not contain it — so everything PHP needs travels with the build output instead, in `build/assets-manifest.php`:

```php
<?php return array(
    'acme-plugin-settings'           => array(
        'source'       => 'entry',
        'name'         => 'settings',
        'kind'         => 'script',
        'js'           => 'entries/settings.js',
        'css'          => 'entries/style-settings.css',
        'rtl'          => 'entries/style-settings-rtl.css',
        'dependencies' => array( 'acme-plugin-shared-formatting' ),
        'version'      => 'dd8b2e6d',
    ),
    'acme-plugin-shared-formatting'  => array(
        'source'       => 'shared',
        'name'         => 'formatting',
        'kind'         => 'script',
        'global'       => array( 'acmePlugin', 'formatting' ),
        'js'           => 'shared/formatting.js',
        'dependencies' => array(),
        'version'      => '137631dc',
    ),
);
```

**Each row is keyed by the handle WordPress registers it under**, and carries everything registering it takes. The build composes those handles — `{plugin-slug}-{name}` for an entry, `{plugin-slug}-shared-{name}` for a script package, and for a module package its own npm name, because that is the specifier its importers import — and writes the package's into every importer's own `.asset.php`, so what a thing is registered as and what depends on it come from one place and cannot disagree. `source` and `name` are how you look one up; the handle is how WordPress does.

That `shared` segment is why `src/entries/collections` and `src/shared/collections` can both exist. Composed without it they would be one handle, and WordPress keeps the first registration and discards the second without a word.

Blocks are not here. WordPress registers those from their own `block.json`, resolving each `file:` against the directory that file sits in, so a row here would describe them a second time and nothing would read it.

One `require` tells the module every entry that exists, what each depends on, which are shared packages, and what stylesheet each produced. Read it yourself with `$assets->get_build_manifest()`.

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

This is why a freshly generated entry costs nothing until you write something. `wp zt make entry` scaffolds a `style.scss` with no rules in it, and an empty scaffold should not be a request on every page load.

## Tips

- **A shared directory with no `wordpress` block is an ordinary dependency.** It is bundled into whatever imports it, like anything from npm. That is the right choice when a copy costs less than a request.
- **`npm run build` asks for three things `wp-scripts` does not do by default.** The generated scripts pass `--webpack-copy-php`, so a block's `block.php` reaches `build/`; `--experimental-modules`, without which a `"kind": "module"` package is silently skipped; and `--blocks-manifest`. You do not add these — `wp zt add module blocks` and `wp zt add module assets` write them.
- **A global is two segments.** `[ "acmePlugin", "formatting" ]` puts a shared package on `window.acmePlugin.formatting`, so everything you share sits under one global and cannot collide with another plugin's.
- **`webpack.config.js` is yours.** It is written once and nothing overwrites it, including [`wp zt update`](commands/update.md). Edit it freely.
- **The manifest is build output.** Gitignored with the rest of `build/`, regenerated every build. Never edit or commit it.
- **Nothing registers what was never built.** A plugin that ships without running `npm run build` ships without its JavaScript.
- **Emptiness is decided at build time, not run time.** PHP never checks a file's size; it registers what the manifest lists, and the manifest lists what survived the build.

## See also

- [`assets`](modules/assets/) — the module reference: asset URLs, script and style registration, entries and shared packages.
- [`blocks`](modules/blocks/) — the third directory, which WordPress registers itself.
- [`wp zt make entry`](commands/make-entry.md) &nbsp;·&nbsp; [`wp zt make shared`](commands/make-shared.md) &nbsp;·&nbsp; [`wp zt make block`](commands/make-block.md)
- [Shipping](shipping.md) — what belongs in the zip.
