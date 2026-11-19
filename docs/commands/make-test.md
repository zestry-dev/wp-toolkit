<!--
    Generated from resources/commands/make/test.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make test

Generate a test, for a class name or for a source file.

Given a file you already have, the test lands at the path that mirrors it, so finding one from the other is never a search:

- A PHP file becomes a PHPUnit class under `tests/php/`, every segment
turned into a class name: `resources/post-types/book.php` gives `tests/php/PostTypes/BookTest.php`, `lib/Modules/Reports.php` gives `tests/php/Modules/ReportsTest.php`.

- A script becomes a Jest test under `tests/js/`, mirroring `src/` and
keeping its extension: `src/shared/collections/index.ts` gives `tests/js/shared/collections/index.test.ts`. Only `.js`, `.jsx`, `.ts` and `.tsx` are accepted — Jest collects nothing else, so a test for an `.mjs` file would pass by never running.

- A directory is a package: its `package.json` `main`, or else its one
`index` script. With both `index.ts` and `index.js` there, you are asked to name the one you mean rather than have one picked for you.

Anything else is a class name, written under `tests/php/` as before.

A PHP test needs `wp zt tests` to have run: it extends that suite's `TestCase`, which is what gives it `$this->plugin` and a throwaway directory to write fixtures into. A Jest test needs nothing of it but the `test:unit` script.

The PHP class name carries the `Test` suffix `phpunit.xml.dist` collects on — so `make test Reports` writes `ReportsTest.php`, and giving the suffix yourself is accepted rather than doubled.

## Options

- **`<name>`**  
  A source file or package directory to test, or a class name. A class name is written exactly as given; qualify it to group, so `Modules/Reports` writes `tests/php/Modules/ReportsTest.php`.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

## Examples

```bash
# A test for a post type you ship.
$ wp zt make test resources/post-types/book.php
Success: Created tests/php/PostTypes/BookTest.php

# A test for a script, in its own language.
$ wp zt make test src/shared/collections/index.ts
Success: Created tests/js/shared/collections/index.test.ts

# A package, by its directory.
$ wp zt make test src/shared/collections
Success: Created tests/js/shared/collections/index.test.ts

# A test for something you are about to write.
$ wp zt make test Reports
Success: Created tests/php/ReportsTest.php
```
