<!--
    Generated from resources/commands/tests.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt tests

[What it writes](#what-it-writes) &nbsp;·&nbsp; [Running it](#running-it) &nbsp;·&nbsp; [Options](#options) &nbsp;·&nbsp; [Examples](#examples)

Set this plugin up to run PHP, JavaScript and end-to-end tests.

Every test lives under `tests/`, in one directory per runner, so each runner collects its own directory and nothing else:

- `tests/php/` — PHPUnit, against WordPress's own test suite. Your
features are files in a directory, and the module that discovers them needs real files, real hooks and a real database, so each test builds a throwaway `Plugin` pointed at a temporary directory it can write fixtures into.

- `tests/js/` — Jest, through `wp-scripts test-unit-js`, for the
JavaScript under `src/`. Its tree mirrors `src/`.

- `tests/e2e/` — Playwright, on the config `@wordpress/scripts` ships,
driving a real browser against a real WordPress.

Requires `wp zt init` to have already run, since the generated files are written under your namespace and import your copy of `Plugin`.

## What it writes

For PHPUnit:

- `phpunit.xml.dist`, collecting `tests/php/`. Support code lives in
`tests/php/Support/` and is excluded from the suite, so the base test case is never collected as a test.

- `tests/php/bootstrap.php`, which locates WordPress's test suite through
`WP_TESTS_DIR` and then the `/wordpress-phpunit` mount, and loads your autoloader before it. It never loads your plugin's entry file: that builds and runs the real `Plugin` against the directories you ship, and your autoloader already provides every class without it.

- `tests/php/Support/TestCase.php`, the base every test extends. It
declares the modules you have installed, since nothing is built that is not declared, and gives each test `$this->plugin`, `$this->plugin_dir`, `write_plugin_file()` and `get_shipped_path()`. The declarations are a list in your own file — add to it as you add modules.

- `tests/php/Support/PluginTestCase.php`, for the tests that are about
what you ship rather than about a fixture. It extends the base and adds a second `Plugin` pointed at your real entry file, so every module walks `resources/` itself — still under the test slug, so what it writes is never the row the real plugin reads.

- `tests/php/Support/wp-cli-stubs.php`, recording doubles for `WP_CLI` and
`WP_CLI_Command`. PHPUnit runs without the WP-CLI phar, so any test touching a command file fatals without them.

- `tests/php/ExampleTest.php`, one passing test to prove the suite runs.
Delete it once you have written your own.

For Jest, one example test, and only if there is something to test: the first package under `src/shared/` or `src/entries/` whose entry is one file — its `package.json` `main`, or its single `index` script. A plugin with no JavaScript of its own gets the scripts and no test.

For Playwright:

- `tests/e2e/playwright.config.js`, which extends the one in
`@wordpress/scripts` and points it at `.wp-env.test.json`: that file's port, and `npm run env:start` to start it. The default would run `npm run wp-env start`, your development site.

- `tests/e2e/specs/plugin.spec.js`, one spec that activates the plugin and
finds it active on the Plugins screen.

And `.wp-env.test.json`, the WordPress the PHP and end-to-end tests run against. Its own file rather than `.wp-env.json`, on a port of its own, so a plugin already keeping one for development keeps it and both can run at once.

Then the manifests. For PHPUnit, PHPUnit and three packages your editor needs go into `composer.json`, with an `autoload-dev` PSR-4 entry mapping `Tests\` to `tests/php/`. `package.json` gains `@wordpress/env` and the `env:start` / `env:stop` / `test:php` scripts, `@wordpress/scripts` and `test:unit` for Jest, and the two Playwright packages with `test:e2e`. `.gitignore` gains `.phpunit.result.cache` and Playwright's `artifacts/`.

The three editor packages are worth naming, because between them they are what makes a test file resolve. `WP_UnitTestCase` ships with the WordPress test suite rather than with a Composer package, so nothing in `vendor/` declares the class a test extends — and an editor that cannot resolve the parent offers no assertion above it, which is why `assertNotWPError()` reads as undefined in a hand-rolled suite. `wp-test-utils` gives the base case a real class to name, `phpunit-polyfills` sits above it, and `wordpress-tests-stubs` fills the gap between them. It declares no autoloader, so nothing loads it at run time: your tests still run against the real suite.

Nothing here is overwritten. A file already on disk is left exactly as it is, a dependency already required keeps whatever constraint it has, and an existing script is never rewritten — so running this a second time changes nothing, and running it after adding a module is how the example of what to declare gets refreshed.

## Running it

Install what was just added, start the WordPress, run the suites:

```bash
composer update && npm install
npx playwright install chromium
npm run env:start
npm run test:php
npm run test:unit
npm run test:e2e
```

`npx playwright install` is a one-time browser download, which is why it is left to you. `test:e2e` starts the WordPress itself when nothing is answering yet.

Docker is what `wp-env` needs, and nothing else is. Without it, install WordPress's test suite locally with `install-wp-tests.sh` and export `WP_TESTS_DIR` for PHPUnit, and point `WP_BASE_URL` at any WordPress for Playwright.

## Options

- **`[--no-php]`**  
  Skip the PHPUnit suite and its Composer entries.

- **`[--no-unit]`**  
  Skip Jest: no example test, no `test:unit` scripts.

- **`[--no-e2e]`**  
  Skip Playwright: no config, no spec, no `test:e2e` scripts.

- **`[--no-wp-env]`**  
  Skip `.wp-env.test.json`, `@wordpress/env` and the scripts that start it. For a plugin that already has a WordPress to test against.

- **`[--yes]`**  
  Assume yes to any prompt, for an unattended run.

## Examples

```bash
# All three suites.
$ wp zt tests
Wrote phpunit.xml.dist
Wrote tests/php/bootstrap.php
Wrote tests/php/Support/TestCase.php
Wrote tests/php/Support/PluginTestCase.php
Wrote tests/php/Support/wp-cli-stubs.php
Wrote tests/php/ExampleTest.php
Wrote tests/js/shared/collections/index.test.ts
Wrote tests/e2e/playwright.config.js
Wrote tests/e2e/specs/plugin.spec.js
Wrote .wp-env.test.json
Added to composer.json: phpunit/phpunit, yoast/phpunit-polyfills, ...
Added autoload-dev: Acme\Plugin\Tests\ => tests/php/
Added scripts: composer test
Added to package.json: @wordpress/env, @wordpress/scripts, ...
Added scripts: npm env:start, env:stop, test:php, test:unit, ...
Added .phpunit.result.cache to .gitignore
Added artifacts/ to .gitignore
Success: Run `composer update`, `npm install` and `npx playwright install chromium`, then `npm run env:start`, `npm run test:php`, `npm run test:unit` and `npm run test:e2e`.

# PHPUnit only, against a WordPress you already have.
$ wp zt tests --no-unit --no-e2e --no-wp-env
...
Success: Run `composer update`, then `vendor/bin/phpunit` with WP_TESTS_DIR set.
```
