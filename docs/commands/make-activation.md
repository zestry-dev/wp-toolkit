<!--
    Generated from commands/make/activation.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make activation

Generate an activation handler.

Writes a class extending `ActivationHandler`, which runs when your plugin is activated and deactivated, and declares it in `bootstrap.php` so the plugin builds it. A plugin usually has one.

Being declared matters more here than for other modules: WordPress fires the activation hook immediately after your plugin file loads, so the class has to be built at load for `activate()` to bind in time. Listing it in `bootstrap.php` and calling `run()` from your entry file does that.

## Options

- **`<name>`**  
  The class name, in PascalCase, e.g. `Activation`.

- **`[--dir=<dir>]`**  
  Write somewhere other than your `Modules/` directory, relative to the plugin root.

## Examples

```bash
# Generate lib/Modules/Activation.php and declare it.
$ wp zestry make activation Activation
Success: Created lib/Modules/Activation.php
Declared Activation in bootstrap.php.
```
