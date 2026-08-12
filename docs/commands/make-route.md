<!--
    Generated from commands/make/route.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make route

Generate a new REST route.

The RestApi module discovers it. On `rest_api_init` it walks your `routes/` directory at any depth, requires every file in it, and hands the `Route` each one returns to `register_rest_route()` under `{plugin-slug}/{version}`. Writing the file is the whole registration; nothing has to be declared anywhere, and subdirectories are organization only, not part of the URL.

Needs the `rest-api` module, so run `wp zt add module rest-api` first if you have not already.

## Options

- **`<name>`**  
  The local name, e.g. 'get-widget'. Becomes the filename (`{name}.php`) under `routes/`.

- **`[--dir=<dir>]`**  
  Write into this plugin-relative directory instead of `routes` — pass it when you have pointed RestApi's routes root somewhere other than its default.

- **`[--method=<method>]`**  
  The HTTP method: get, post, put, patch, or delete. Prompted for when not given.

- **`[--version=<version>]`**  
  The REST namespace version, e.g. 'v1'. Prompted for when not given.

- **`[--pattern=<pattern>]`**  
  The URL pattern, e.g. '/widgets/{id}'. Prompted for when not given.

- **`[--yes]`**  
  Overwrite an existing file without asking, and take the default for every prompt below rather than asking, for an unattended run.

> [!NOTE]
> **This generator asks for anything you leave out.** Give every option above and it never stops.
>
> `--yes` answers every prompt with the documented default, without reading input — which is what an unattended run wants. Without it, and with nothing on standard input, the command waits.

## Examples

```bash
# Generate a REST route, prompting for method/version/pattern.
$ wp zt make route get-widget
HTTP method (get, post, put, patch, delete): (default: get)
Namespace version: (default: v1)
URL pattern: (default: /get-widget)
Success: Created routes/get-widget.php
```
