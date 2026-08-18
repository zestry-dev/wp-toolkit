<!--
    Generated from resources/commands/make/test.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make test

Generate a test class.

Requires `wp zt tests` to have already run: the generated class extends that suite's `TestCase`, which is what gives it `$this->plugin` and a throwaway directory to write fixtures into.

The file lands in `tests/Integration/` with `Test` appended to the name, which is the suffix `phpunit.xml.dist` collects on — so `make test Reports` writes `ReportsTest.php` and giving the suffix yourself is accepted rather than doubled.

## Options

- **`<name>`**  
  The class name, e.g. 'Reports'. Written exactly as given — this is a class name, not a kebab-case local one. Qualify it to group: `Modules/Reports` writes `tests/Integration/Modules/ReportsTest.php`.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

## Examples

```bash
# A test for something you are about to write.
$ wp zt make test Reports
Success: Created tests/Integration/ReportsTest.php

# The suffix is optional, and never doubled.
$ wp zt make test ReportsTest
Success: Created tests/Integration/ReportsTest.php

# Grouped, the way the suite grows.
$ wp zt make test Modules/Reports
Success: Created tests/Integration/Modules/ReportsTest.php
```
