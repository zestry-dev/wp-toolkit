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

An entry is built by the `webpack.config.js` that `wp zt add module assets` writes, which is what lets one build produce blocks and entries together. A stock `@wordpress/scripts` setup cannot; the JavaScript guide covers why.

The stylesheet beside `index.ts` is imported by it, which is what gets it built; it is registered under the same handle, so enqueuing the script brings it along.

Needs the `assets` module, which brings the build configuration with it: `wp zt add module assets`.

## Options

- **`<name>`**  
  The entry's name, in kebab-case, e.g. `settings`.

- **`[--kind=<kind>]`**  
  How WordPress loads it. `script` registers a classic handle and works everywhere. `module` registers an ES module, for Interactivity API code that is not inside a block.  
  Defaults to `script`.  
  Accepts `script`, `module`.

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
