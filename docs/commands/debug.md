<!--
    Generated from commands/debug.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt debug

Turn this plugin's debug mode on or off.

Your plugin has a debug switch of its own, separate from `WP_DEBUG`: the constant `{SLUG}_DEBUG`, which `$plugin->is_plugin_debug()` reads. It exists for the case `WP_DEBUG` misses — working on one plugin against a site that is not otherwise in debug mode, where turning `WP_DEBUG` on would fill the screen with everyone else's notices.

The toolkit reads it too. The [`icons-library`](../modules/icons-library/) module checks every SVG against WordPress's sanitizer while either switch is on, and skips the check entirely when neither is.

The constant is written to `wp-config.php` by WP-CLI's own `wp config`, so it is a fact about this install and never ships with the plugin. Running this on a production site is not something to do by habit.

Called with nothing, it reports which the constant currently is and changes nothing.

## Options

- **`[<state>]`**  
  Whether debug mode should be on. Omit to report which it currently is, without writing anything.  
  Accepts `on`, `off`.

## Examples

```bash
# Which is it?
$ wp zt debug
ACME_PLUGIN_DEBUG is off.

# Turn it on for this install.
$ wp zt debug on
Success: ACME_PLUGIN_DEBUG is on.

# And off again, which removes the line rather than setting it false.
$ wp zt debug off
Success: ACME_PLUGIN_DEBUG is off.
```
