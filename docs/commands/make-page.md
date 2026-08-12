<!--
    Generated from commands/make/page.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make page

Generate a new admin page.

The AdminPages module discovers it. At boot it walks your `admin-pages/` directory at any depth, requires every file in it, and registers the `AdminPage` each one returns through `add_menu_page()` or `add_submenu_page()` — nested directories become nested menus. Writing the file is the whole registration; nothing has to be declared anywhere.

The page's slug becomes `?page={plugin-slug}-{name}`, so it can hold only what a URL does not have to encode: a name holding anything else is written as one that survives, and the command says what it wrote.

Two files, the way `make block` writes several: the page class, and the template it renders. An admin page is mostly a form — tables, fields, notices, a second form further down — and markup assembled by concatenation stops being reviewable long before it stops growing. A page with one field does not need a template, and costs nothing for having one; the point is that nobody has to notice when the threshold passed.

Needs the `admin-pages` module, so run `wp zestry add module admin-pages` first if you have not already. It brings `views` with it, which is what renders the template.

## Options

- **`<name>`**  
  The local name, e.g. 'settings'. Becomes the filename (`{name}.php`) under `admin-pages/`.

- **`[--dir=<dir>]`**  
  Write into this plugin-relative directory instead of `admin-pages` — pass it when you have pointed AdminPages's pages root somewhere other than its default.

- **`[--no-view]`**  
  Skip the template, and generate a `render()` that echoes its own markup instead of rendering one. The page class is written either way.

- **`[--views-dir=<dir>]`**  
  Write the template under this plugin-relative directory instead of `views` — pass it when you have pointed the Views service's root somewhere other than its default.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

## Examples

```bash
# Generate an admin page and the template it renders.
$ wp zestry make page settings
Success: Created admin-pages/settings.php
Created views/admin-pages/settings.php

# Just the class, for a page that renders almost nothing.
$ wp zestry make page ping --no-view
Success: Created admin-pages/ping.php
```
