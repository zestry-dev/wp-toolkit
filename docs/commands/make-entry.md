<!--
    Generated from commands/make/entry.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make entry

Generate a script entry of this plugin's own.

Writes `src/entries/<name>/`, which the build compiles to `build/entries/<name>` and the `assets` module registers on `init`. Using it is then one call, from an admin page, a shortcode, anywhere:

```php
$this->assets->enqueue_entry( 'settings' );
```

This exists because `@wordpress/scripts` has no answer for it.  It decides entry points three mutually exclusive ways — files listed on the command line, `block.json` scanning, or the `src/index` fallback — so adding a single block silently stops `src/index` being built, and there is no supported way to have both. The generated `webpack.config.js` merges them.

The stylesheet beside `index.ts` is imported by it, which is what gets it built; it is registered under the same handle, so enqueuing the script brings it along.

Needs the `assets` module, which brings the build configuration with it: `wp zt add module assets`.

## Options

- **`<name>`**  
  The entry's name, in kebab-case, e.g. `settings`.

- **`[--kind=<kind>]`**  
  How WordPress loads it. `script` registers a classic handle and works everywhere. `module` registers an ES module, for Interactivity API code that is not inside a block.  
  Defaults to `script`.  
  Accepts `script`, `module`.

- **`[--dir=<dir>]`**  
  Write somewhere other than `src/entries/`, relative to the plugin root. The module reads `{build}/entries/`, so an entry written elsewhere is yours to register.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

## Examples

```bash
# A script for an admin screen.
$ wp zt make entry settings
Success: Created src/entries/settings (2 files)

# An ES module, for Interactivity API code outside a block.
$ wp zt make entry cart --kind=module
Success: Created src/entries/cart (3 files)
```
