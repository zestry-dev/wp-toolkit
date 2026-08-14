<!--
    Generated from resources/commands/make/view.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make view

Generate a view template.

Writes `resources/views/<name>.php`, which the `views` module renders by that name. A name may contain slashes, so `wp zt make view admin-pages/settings` writes `resources/views/admin-pages/settings.php` and creates the directory.

A template receives exactly what its caller passes, plus `$this` — the `views` module — so it renders a subview with the same `render()` call everything else uses. Nothing else is in scope, which is what keeps a template's inputs readable without opening it.

Needs the `views` module: `wp zt add views`. It arrives on its own with `admin-pages`, which renders its markup this way.

## Options

- **`<name>`**  
  The view name, as the caller will ask for it, e.g. `emails/receipt`.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

## Examples

```bash
# Rendered with $views->render( 'emails/receipt', array( ... ) ).
$ wp zt make view emails/receipt
Success: Created resources/views/emails/receipt.php
```
