<!--
    Generated from resources/commands/tests.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt tests

[What it writes](#what-it-writes) &nbsp;·&nbsp; [Running it](#running-it) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Set this plugin up to run PHPUnit tests.

Your features are files in a directory, and the module that discovers them needs real files, real hooks and a real database — so tests run against WordPress's own PHPUnit suite, and each one builds a throwaway `Plugin` pointed at a temporary directory it can write fixtures into.

That takes six files and a handful of manifest entries. This writes all of them.

Requires `wp zt init` to have already run, since the generated files are written under your namespace and import your copy of `Plugin`.

## What it writes

- `phpunit.xml.dist`, collecting `tests/Integration/`. Support code lives
in `tests/Support/` and is deliberately outside the suite, so the base test case is never collected as a test.

- `tests/bootstrap.php`, which locates WordPress's test suite through
`WP_TESTS_DIR` and then the `/wordpress-phpunit` mount, and loads your autoloader before it. It never loads your plugin's entry file: that builds and runs the real `Plugin` against the directories you ship, and your autoloader already provides every class without it.

- `tests/Support/TestCase.php`, the base every test extends. It declares
the modules you have installed, since nothing is built that is not declared, and gives each test `$this->plugin`, `$this->plugin_dir` and `write_plugin_file()`. The declarations are a list in your own file — add to it as you add modules.

- `tests/Support/wp-cli-stubs.php`, recording doubles for `WP_CLI` and
`WP_CLI_Command`. PHPUnit runs without the WP-CLI phar, so any test touching a command file fatals without them.

- `tests/Integration/ExampleTest.php`, one passing test to prove the suite
runs. Delete it once you have written your own.

- `.wp-env.test.json`, the WordPress the suite runs against. Its own file
rather than `.wp-env.json`, on a port of its own, so a plugin already keeping one for development keeps it and both can run at once.

It then adds PHPUnit and three packages your editor needs to `composer.json`, an `autoload-dev` PSR-4 entry mapping `Tests\` to `tests/`, `@wordpress/env` to `package.json`, the `env:start` / `env:stop` / `test:php` scripts, and `.phpunit.result.cache` to `.gitignore`.

Those three are worth naming, because between them they are what makes a test file resolve. `WP_UnitTestCase` ships with the WordPress test suite rather than with a Composer package, so nothing in `vendor/` declares the class a test extends — and an editor that cannot resolve the parent offers no assertion above it, which is why `assertNotWPError()` reads as undefined in a hand-rolled suite. `wp-test-utils` gives the base case a real class to name, `phpunit-polyfills` sits above it, and `wordpress-tests-stubs` fills the gap between them. It declares no autoloader, so nothing loads it at run time: your tests still run against the real suite.

Nothing here is overwritten. A file already on disk is left exactly as it is, a dependency already required keeps whatever constraint it has, and an existing script is never rewritten — so running this a second time changes nothing, and running it after adding a module is how the example of what to declare gets refreshed.

## Running it

Install what was just added, start the WordPress, run the suite:

```bash
npm install && composer update
npm run env:start
npm run test:php
```

Docker is what `wp-env` needs, and nothing else is. Without it, install WordPress's test suite locally with `install-wp-tests.sh` and export `WP_TESTS_DIR`; the generated bootstrap reads it and the rest is unchanged.

## Options

- **`[--no-wp-env]`**  
  Skip `.wp-env.test.json`, `@wordpress/env` and the npm scripts. For a plugin that already has a WordPress to test against.

- **`[--yes]`**  
  Assume yes to any prompt, for an unattended run.

## Examples

```bash
# The whole suite.
$ wp zt tests
Wrote phpunit.xml.dist
Wrote tests/bootstrap.php
Wrote tests/Support/TestCase.php
Wrote tests/Support/wp-cli-stubs.php
Wrote tests/Integration/ExampleTest.php
Wrote .wp-env.test.json
Added to composer.json: phpunit/phpunit, yoast/phpunit-polyfills, ...
Added autoload-dev: Acme\Plugin\Tests\ => tests/
Added scripts: composer test
Added to package.json: @wordpress/env
Added scripts: npm env:start, env:stop, test:php
Added .phpunit.result.cache to .gitignore
Success: Run `composer update`, then `npm run env:start && npm run test:php`.

# Against a WordPress you already have.
$ wp zt tests --no-wp-env
...
Success: Run `composer update`, then `vendor/bin/phpunit` with WP_TESTS_DIR set.
```
