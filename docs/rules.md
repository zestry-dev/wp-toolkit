# Rules

Every absolute in this toolkit, on one page, with nothing arguing for them. Each links to the page that does.

Read once, then use this to check yourself. The pages behind the links explain *why*; this page exists so you do not have to reopen them.

## The two kinds

1. **Extending `Module` or `Service` is the declaration of which kind a class is.** There is no flag, and no second place that says. — [Services](services/) · [Modules](modules/)
2. **A `Service` does nothing until something asks for it**, and is never listed in `bootstrap.php`. — [Services](services/)
3. **A `Module` acts without being called, so it must be listed in `bootstrap.php`.** Listing it is what builds it. Nothing else does. — [Modules](modules/)
4. **A service that needs configuration gets it from `$plugin->configure()`** in the entry file, not from `bootstrap.php`. — [`Plugin`](plugin.md)
5. **`bootstrap.php` returns one flat array, modules only.** An entry's value, when it has one, is the initializer that configures it before boot. — [Getting started](getting-started.md)

## Building and wiring

6. **One `Plugin` per plugin**, built in the entry file from `__FILE__`. — [`Plugin`](plugin.md)
7. **Your slug is a lowercase letter, then lowercase letters and digits, single dashes between them.** It defaults to the directory name; pass a second argument when that name is spelled otherwise. Anything else throws, because every registered name is built from it. — [`Plugin`](plugin.md)
8. **A class the container builds may not declare a constructor.** `Service::__construct()` is `final` and takes nothing. This covers every `Service` and `Module`; a class you construct yourself inside a discovered file may have one. — [`Service`](services/service.md)
9. **A `public` or `protected` property typed as a `Service` is injected.** Declare it and it is there. — [`WithPlugin`](kernel/with-plugin.md)
10. **A `private` property is never injected.** It stays uninitialised and the first read is a fatal. — [`WithPlugin`](kernel/with-plugin.md)
11. **`#[NoInject]` opts a property out** of that. — [`NoInject`](kernel/no-inject.md)

## Files that are features

12. **A discovered file returns an instance of that module's base class.** Returning anything else throws. — [Modules](modules/)
13. **The filename is the identifier of whatever the file registers, exactly as written**, so renaming the file renames the thing and nothing renames it for you. A name the toolkit *builds* from yours — a hook, a page slug, a command — is your slug joined to your filename with the separator that destination takes; a name it *takes* — a post type, a taxonomy, a meta key — is yours and is left alone. — [Cheat sheet](cheat-sheet.md#which-names-get-your-plugin-slug)
14. **A name a destination could not carry is refused, never repaired.** An admin page whose filename a URL would have to encode, and an ability whose filename is outside WordPress's `[a-z0-9-]`, each throw where they are discovered. — [`DiscoveryException`](kernel/discovery-exception.md)
15. **A file or directory whose name begins with `.` or `-` is skipped.** A leading `_` is not: WordPress gives it meaning. — [Modules](modules/)
16. **A default root that does not exist discovers nothing and says nothing.** A root named by a `set_*_root()` call and then missing throws. — [`DiscoveryException`](kernel/discovery-exception.md)
17. **A root is set from the module's own initializer**, which runs before it boots. — [Getting started](getting-started.md)

## Names

18. **Your plugin slug is prefixed onto names that land somewhere every plugin shares** — admin pages, cron hooks, AJAX actions, Site Health checks, abilities, REST namespaces, WP-CLI commands. — [Cheat sheet](cheat-sheet.md#which-names-get-your-plugin-slug)
19. **It is not prefixed onto names that are your own public API** — post types, taxonomies, meta keys, blocks. Put your prefix in the filename yourself. — [Cheat sheet](cheat-sheet.md#which-names-get-your-plugin-slug)

## What is yours

20. **Everything under `Core/` came from upstream**, and [`wp zt update`](commands/update.md) or [`wp zt overwrite`](commands/overwrite-module.md) may replace any of it. — [Getting started](getting-started.md)
21. **Everything else in your source root is yours**, and no command touches it. — [Getting started](getting-started.md)
22. **`zestry.lock.json` is committed.** Without it an update cannot tell your edit from an upstream change. — [`wp zt init`](commands/init.md)

## When it fails

23. **`ModuleException` is the base of every declaration, resolution and boot failure**, so one `catch` covers all of them. — [Errors](errors.md)
24. **A bad argument is an `InvalidArgumentException`**, not a `ModuleException`. — [Errors](errors.md)

## The rest

25. **All JavaScript lives under `src/`** — `src/blocks/`, `src/entries/`, `src/shared/`. — [JavaScript](javascript.md)
26. **Nothing registers what was never built.** A plugin shipped without running `npm run build` ships without its JavaScript. — [JavaScript](javascript.md)
27. **Inside a view template, `$this` is the `views` service**, so a subview is `$this->render( … )`. — [`views`](services/views/)
28. **A migration runs at most once per site, identified by its filename, and never triggers itself.** — [`migrations`](modules/migrations/)

## See also

- [Cheat sheet](cheat-sheet.md) — the same ground with the tables, examples and exceptions.
- [Getting started](getting-started.md) — the order these come up in.
- [Errors](errors.md) — what each exception means when you hit one.
