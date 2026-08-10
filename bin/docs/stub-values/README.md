# Stub sample values

Sample values for one `wp zestry make` type's stubs, each file returning a
`placeholder => value` array. They stand in for what the command would prompt
for, so a generated page shows real code rather than a template.

One file per type, named for the `make` word — `route.php` for
`stubs/route.php.stub`, `block.php` for every stub under `stubs/block/`. The
answers that decide what a stub renders as belong to the type, not to each of
its files, so a multi-file type still needs only one.

Values every type shares — `{{namespace}}`, `{{name}}`, `{{title}}`, `{{slug}}`
— live in `zestry_stub_values()` in `../module-pages.php` and do not belong here.
A file here carries only what its own type asks for, and overrides a shared
value when it needs a different one.

A type with nothing of its own needs no file at all.

A stub that renders with a placeholder nothing supplies fails the docs build,
naming the stub and the token. The fix is to add it to the matching file here,
or to `zestry_stub_values()` once a second type wants it too.
