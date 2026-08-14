# Rules

Every absolute in this toolkit, on one page, with nothing arguing for them. Each links to the page that does.

Read once, then use this to check yourself. The pages behind the links explain *why*; this page exists so you do not have to reopen them.

## What a plugin is made of

1. **Everything is a `Module`.** One base class for everything a plugin is made of. — [Modules](modules/)
2. **A module that acts on its own implements `Bootable`.** Its `on_boot()` runs once, when the plugin builds it. A module without it works only when you call it. — [`Bootable`](kernel/bootable.md)
3. **`bootstrap.php` is the whole inventory.** Every module is listed there, and nothing outside it is ever built. — [Getting started](getting-started.md)
4. **Asking for an undeclared module throws.** That is what keeps the inventory true. — [Errors](errors.md)
5. **An entry is a class name, or a class name keyed to its configurator.** When a module boots is a heading above it — `'init' => array( … )`, optionally `'init:20'`. — [`Plugin`](plugin.md)

## Building and wiring

6. **One `Plugin` per plugin**, built in the entry file from `__FILE__`. — [`Plugin`](plugin.md)
7. **Your slug is a lowercase letter, then lowercase letters and digits, single dashes between them.** It defaults to the directory name; pass a second argument when that name is spelled otherwise. Anything else throws, because every registered name is built from it. — [`Plugin`](plugin.md)
8. **A module may not declare a constructor.** `Module::__construct()` is `final` and takes nothing. A class you construct yourself inside a discovered file may have one. — [`Module`](modules/module.md)
9. **`$this->with( X::class )` is how anything reaches anything.** The same instance every time, in a module and in every discovered file. — [`WithPlugin`](kernel/with-plugin.md)
10. **A module listed under a heading cannot be reached before that hook fires.** Asking early throws, naming the hook. — [Errors](errors.md)
11. **A module that acts on its own must be under a heading.** Left at the top level it throws — the top level is for modules that do nothing until asked. — [`Bootable`](kernel/bootable.md)

## Files that are features

12. **A discovered file returns an instance of that module's base class.** Returning anything else throws. — [Modules](modules/)
13. **The filename is the identifier of whatever the file registers, exactly as written**, so renaming the file renames the thing and nothing renames it for you. A name the toolkit *builds* from yours — a hook, a page slug, a command — is your slug joined to your filename with the separator that destination takes; a name it *takes* — a post type, a taxonomy, a meta key — is yours and is left alone. — [Cheat sheet](cheat-sheet.md#which-names-get-your-plugin-slug)
14. **A name a destination could not carry is refused, never repaired.** An admin page whose filename a URL would have to encode, an ability outside WordPress's `[a-z0-9-]`, and an icon outside its own, each throw where they are discovered. — [`DiscoveryException`](kernel/discovery-exception.md)
15. **A file or directory whose name begins with `.` or `-` is skipped.** A leading `_` is not: WordPress gives it meaning. — [Modules](modules/)
16. **The directory each module reads is fixed**, and one that does not exist discovers nothing and says nothing. — [Modules](modules/)

## Names

17. **Your plugin slug is prefixed onto names that land somewhere every plugin shares** — admin pages, cron hooks, AJAX actions, Site Health checks, abilities, REST namespaces, WP-CLI commands. — [Cheat sheet](cheat-sheet.md#which-names-get-your-plugin-slug)
18. **It is not prefixed onto names that are your own public API** — post types, taxonomies, meta keys, blocks. Put your prefix in the filename yourself. — [Cheat sheet](cheat-sheet.md#which-names-get-your-plugin-slug)

## What is yours

19. **Everything under `Core/` came from upstream**, and [`wp zt update`](commands/update.md) or [`wp zt overwrite`](commands/overwrite.md) may replace any of it. — [Getting started](getting-started.md)
20. **Everything else in your source root is yours**, and no command touches it. — [Getting started](getting-started.md)
21. **`zestry.lock.json` is committed.** Without it an update cannot tell your edit from an upstream change. — [`wp zt init`](commands/init.md)

## When it fails

22. **`ModuleException` is the base of every declaration, resolution and boot failure**, so one `catch` covers all of them. — [Errors](errors.md)
23. **A bad argument is an `InvalidArgumentException`**, not a `ModuleException`. — [Errors](errors.md)

## The rest

24. **All JavaScript lives under `src/`** — `src/blocks/`, `src/entries/`, `src/shared/`. — [JavaScript](javascript.md)
25. **Nothing registers what was never built.** A plugin shipped without running `npm run build` ships without its JavaScript. — [JavaScript](javascript.md)
26. **Inside a view template, `$this` is the `views` module**, so a subview is `$this->render( … )`. — [`views`](modules/views/)
27. **A migration runs at most once per site, identified by its filename, and never triggers itself.** — [`migrations`](modules/migrations/)

## See also

- [Cheat sheet](cheat-sheet.md) — the same ground with the tables, examples and exceptions.
- [Getting started](getting-started.md) — the order these come up in.
- [Errors](errors.md) — what each exception means when you hit one.
