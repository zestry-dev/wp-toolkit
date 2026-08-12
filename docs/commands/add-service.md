<!--
    Generated from commands/add/service.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt add service

Copy one or more services into an initialized plugin.

Requires `wp zt init` to have already run in this plugin (it reads zestry.json for the namespace and destination directory chosen there). Every `namespace Zestry\WPToolkit\...;` declaration and `use Zestry\WPToolkit\...;` import in each copied file is rewritten to the project's own namespace. A service already present at its destination is left untouched and logged as skipped — run `wp zt overwrite service <service>` to replace it deliberately.

Nothing is written to `bootstrap.php`. A service is built the first time something asks for it — a `$plugin->get()`, or another class declaring a property of its type — so an entry naming one would do nothing. List it yourself only when you want to configure it, and the entry's value is the initializer that does so.

Most services arrive on their own as module dependencies, `path` above all: every module but `log` and `options` needs it. Naming one here is for the case where you want it without the module that would have brought it.

## Options

- **`<service>...`**  
  One or more service names to copy in. Available services: path, request, cookie, globals, transients, db, views.

## Examples

```bash
# Copy a service on its own.
$ wp zt add service views
Also adding required dependencies: path
Added path
Added views
Success: Done.

# Copy several in one call.
$ wp zt add service db globals
Success: Done.

# Naming a module here says where to find it.
$ wp zt add service cli
Error: "cli" is a module, not a service. Run `wp zt add module cli`.
```
