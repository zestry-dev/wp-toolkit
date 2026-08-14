<!--
    Generated from src/Kernel/ and the classes it declares.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Kernel reference

The classes underneath every plugin: what you catch, what you implement, and
what the discovery modules share. You rarely name these directly — but when
something fails, this is what it fails with.

- [`ModuleException`](module-exception.md) — Every module failure, and the one class that catches them all
- [`DiscoveryException`](discovery-exception.md) — A discovered file returned the wrong thing, or a named directory is missing
- [`ModuleNotFoundException`](module-not-found-exception.md) — A class was asked for that cannot be built
- [`CircularDependencyException`](circular-dependency-exception.md) — Two classes depend on each other
- [`PluginAware`](plugin-aware.md) — The contract that makes an object wireable
- [`WithPlugin`](with-plugin.md) — The trait that satisfies it
- [`Bootable`](bootable.md) — What marks a module that acts on its own
- [`WithFolderWalker`](with-folder-walker.md) — How every discovery module reads its directory
- [`WithEnablement`](with-enablement.md) — Let a discovered file say it should not register
- [`Arr`](arr.md) — Nested array paths, and the operations you reach for on a list of rows
- [`Str`](str.md) — Spelling a name the way the thing you hand it to spells names

See also [`Plugin`](../plugin.md) and [`Module`](../modules/module.md).
