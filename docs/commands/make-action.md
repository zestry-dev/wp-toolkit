<!--
    Generated from commands/make/action.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make action

Generate a new AJAX action.

The Ajax module discovers it. At boot it walks your `actions/` directory, requires every file in it, and maps the `AjaxAction` each one returns onto `wp_ajax_{plugin}-{action}` — plus the matching `wp_ajax_nopriv_` hook if the action opts logged-out visitors in. Writing the file is the whole registration; nothing has to be declared anywhere.

Needs the `ajax` module, so run `wp zt add module ajax` first if you have not already.

## Options

- **`<name>`**  
  The local name, e.g. 'send-welcome-email'. Becomes the filename (`{name}.php`) under `actions/`.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

## Examples

```bash
# Generate an AJAX action at actions/send-welcome-email.php.
$ wp zt make action send-welcome-email
Success: Created actions/send-welcome-email.php
```
