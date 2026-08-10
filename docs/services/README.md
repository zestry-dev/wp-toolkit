<!--
    Generated from src/DevTools/registry.php and each class it names.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Services

A service does nothing on its own. The plugin builds it the first time something asks for it — a `$plugin->get()` call, or another class declaring a property of its type — and it works only when called. Nothing happens until then, so a service is never listed in `bootstrap.php`; one that takes configuration gets it from `$plugin->configure()` in your entry file.

All of them extend [`Service`](service.md), which supplies plugin access and the typed-property injection they rely on. For the things that *do* act on their own, see [Modules](../modules/).

Everything here is optional. `wp zestry add service <name>` copies one into your plugin, along with anything it depends on.

## Every service

| Service | Reach for it to… | Reads from | Also copies |
|---|---|---|---|
| [`cookie`](cookie/) | read and write a cookie, and carry a value across a redirect | — | `transients` |
| [`db`](db/) | name a database table, yours or WordPress's | — | — |
| [`globals`](globals/) | pass a value between classes within one request | — | — |
| [`path`](path/) | resolve a path or URL inside the plugin | — | — |
| [`request`](request/) | declare and validate what a route, ability, action or page accepts | — | — |
| [`transients`](transients/) | keep a value past the request, with an expiry | — | — |
| [`views`](views/) | render a PHP template | `views/` (read, not walked) | `path` |

**`path` arrives on its own** with almost every other entry, so it is rarely worth naming.
