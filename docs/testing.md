# Testing

Your features are files in a directory, and the module that discovers them needs real files, real hooks and a real database. So tests run against WordPress's own PHPUnit suite, and each test builds a **throwaway `Plugin`** pointed at whichever directory that test wants the modules to read.

A `Plugin` takes an entry file path and derives everything from it — the plugin directory, the roots every module walks, the URLs `Path` and `Assets` produce. Point one at a temporary directory and you get a private, disposable filesystem to write fixtures into.

## 1. Scaffold the suite

```bash
wp zt tests
```

That writes everything below, and nothing it writes is overwritten on a second run — see [`wp zt tests`](commands/tests.md) for the full list.

| | |
|---|---|
| `phpunit.xml.dist` | Collects `tests/Integration/`. Support code sits in `tests/Support/` and is deliberately outside the suite. |
| `tests/bootstrap.php` | Finds WordPress's test suite, then loads your autoloader. |
| `tests/Support/TestCase.php` | The base every test extends. Declares the modules you have installed. |
| `tests/Support/wp-cli-stubs.php` | Recording doubles for `WP_CLI` and `WP_CLI_Command`. |
| `tests/Integration/ExampleTest.php` | One passing test. Delete it once you have your own. |
| `.wp-env.test.json` | The WordPress to run against. |

It also adds PHPUnit and the three packages your editor needs to `composer.json` (see [below](#8-why-three-packages-just-for-the-editor)), an `autoload-dev` entry mapping `Acme\Plugin\Tests\` to `tests/`, and the npm scripts:

```bash
npm install && composer update
npm run env:start
npm run test:php
```

Docker is what `wp-env` needs, and nothing else is. Without it, install the test suite with WordPress's `install-wp-tests.sh` and export `WP_TESTS_DIR`; the generated bootstrap reads it and nothing below changes.

A single test method runs with `--filter`:

```bash
npm run test:php -- --filter test_greet_says_hello
```

Each new test file is one command:

```bash
wp zt make test Reports
```

> [!IMPORTANT]
> **Never load your plugin's entry file from a test.** It builds and runs the real `Plugin` — every module booted, every hook bound, against whatever directories your plugin actually ships. Tests build their own instance instead, and control what it can see. Your Composer autoloader already makes every class available without the entry file.

## 2. What the base test case gives you

Every test gets a fresh directory, a fake entry file in it, and a `Plugin` pointed at that file:

```php
namespace Acme\Plugin\Tests\Integration;

use Acme\Plugin\Tests\Support\TestCase;

final class ReportsTest extends TestCase {

    public function test_something(): void {
        $this->plugin;       // a Plugin on a temporary directory
        $this->plugin_dir;   // that directory
        $this->write_plugin_file( 'resources/views/card.php', '…' );
    }
}
```

The explicit slug matters, and the generated file uses `{your-slug}-test`. Every name a module registers carries the slug — option rows, hook names, AJAX actions, `wp {slug} …` commands — so a test slug keeps test data out of anything your real plugin stores.

**The module declarations are yours to edit.** Nothing is built that is not declared, so the generated file lists the modules you had installed when it was written:

```php
$this->plugin = ( new Plugin( $this->entry_file, 'acme-plugin-test' ) )->declare_multiple(
    array(
        Path::class,
        Views::class,

        'acme-plugin-test_loaded' => array(
            PostTypes::class,
        ),
    )
);
```

Add to that list as you add modules, or call `declare()` in the test itself. The headings are nominal for a test that calls `get()` directly — `get()` builds and boots on the spot, and only `run()` waits on the hook — but a module that acts on its own still has to be under one, since [leaving it at the top level throws](kernel/bootable.md).

To exercise the files you actually ship instead, point a second `Plugin` at your real entry file:

```php
$plugin = new Plugin( dirname( __DIR__, 2 ) . '/acme-plugin.php', 'acme-test' );
$plugin->declare( CLI::class, 'init' );
$plugin->get( CLI::class );   // discovers your real resources/commands/ directory
```

That instance shares nothing with the one your entry file builds in production.

## 3. Give a module files to discover

Write the fixture first, then resolve the module. Resolving it is what boots it, and boot is what walks the directory.

```php
public function test_a_command_is_registered_from_the_commands_directory(): void {
    // CLI discovers nothing unless it believes it is running under WP-CLI.
    if ( ! defined( 'WP_CLI' ) ) {
        define( 'WP_CLI', true );
    }

    $this->write_plugin_file(
        'resources/commands/greet.php',
        '<?php
        use Acme\Plugin\Core\Modules\CLI\Command;

        return new class extends Command {
            public function handle( array $args, array $assoc_args ): void {
                $this->success( "Hello, " . $args[0] );
            }
        };'
    );

    $this->plugin->get( CLI::class );

    $this->assertSame( 'acme-plugin-test greet', \WP_CLI::last( 'add_command' )[0] );
}
```

> [!IMPORTANT]
> **Order is the whole thing here.** A discovery module walks its directory inside `on_boot()`, and `on_boot()` runs inside the `get()` call. A fixture written afterwards is never seen. Write files first, resolve second.

A module hanging its work on a later hook needs that hook fired: `AdminPages` registers on `admin_menu`, so resolve it, then `do_action( 'admin_menu' )`, then assert. Each module's page names the hook it uses.

Configuration goes through `configure()` before resolving — that is the only window in which it still counts, since resolving is what boots:

```php
$this->plugin->configure(
    Cron::class,
    static function ( Cron $cron ): void {
        $cron->add_custom_interval( 'quarter_hourly', 900, 'Quarter hourly' );
    }
);

$this->plugin->get( Cron::class );   // configured, then booted, then discovered
```

There is no `boot()` to reach for instead: `get()` constructs the module, runs its configurator, and calls `on_boot()` if the class implements `Bootable` — all in one step, once.

Each module gates itself on the request it serves, and the gate runs before discovery: `CLI` checks the `WP_CLI` constant, `Ajax` checks `wp_doing_ajax()`, `AdminPages` checks `is_admin()`. Satisfy it first or the module does nothing and your assertion reports an empty result rather than a wrong one. `WP_CLI` is a constant, so it is process-global and cannot be undefined — if you also want to assert the "not under WP-CLI" branch, do it in the first test in the file, before anything defines it.

Every module reads one fixed directory, so write your fixtures into the one its page names. An absent directory registers nothing and throws nothing — which is worth a test of its own, since it is what a module looks like before you have written the first file:

```php
$this->plugin->get( CLI::class );

$this->assertNull( \WP_CLI::last( 'add_command' ) );
```

## 4. Test one file, without its module

A `Command`, an `AjaxAction`, a `Route`, a `Schedule` — each is a plain object its module `require`s and wires. You can do both halves yourself, and skip discovery entirely.

```php
public function test_greet_says_hello(): void {
    $command = require dirname( __DIR__, 2 ) . '/resources/commands/greet.php';

    $this->plugin->wire( $command );
    $command->set_arguments( array( 'Ada' ), array() );

    $command->handle( array( 'Ada' ), array() );

    $this->assertSame( array( 'Hello, Ada' ), \WP_CLI::last( 'success' ) );
}
```

`wire()` is the whole trick: it assigns the plugin, exactly as the module would, on an object the module has never seen. Skip it and the first `$this->with( … )` in your handler fatals on an uninitialised property.

`set_arguments()` is only needed if the command reads `get_args()`/`get_assoc_args()`, or calls `confirm()`/`ask()` — those consult `--yes` from the recorded arguments.

An `AjaxAction` needs one more thing, because `wp_send_json_*` calls `wp_die()`. Replace the die handler with one that throws, and read the buffered JSON:

```php
private function dispatch( callable $run ): array {
    $die_handler = static function (): callable {
        return static function (): void {
            throw new \RuntimeException( '__ajax_die__' );
        };
    };

    add_filter( 'wp_die_ajax_handler', $die_handler );
    ob_start();

    try {
        $run();
    } catch ( \RuntimeException $e ) {
        if ( '__ajax_die__' !== $e->getMessage() ) {
            throw $e;
        }
    } finally {
        $json = ob_get_clean();
        remove_filter( 'wp_die_ajax_handler', $die_handler );
    }

    return json_decode( (string) $json, true );
}

public function test_the_action_returns_the_report(): void {
    $action = require dirname( __DIR__, 2 ) . '/resources/actions/report.php';
    $this->plugin->wire( $action );

    $response = $this->dispatch( static function () use ( $action ): void { $action->handle(); } );

    $this->assertTrue( $response['success'] );
}
```

Calling `handle()` directly bypasses the capability and nonce checks the module performs before it. To assert *those*, let the module register the action and fire the hook instead:

```php
// All three before resolving: Ajax discovers nothing outside an AJAX request,
// discovery happens inside get(), and authenticating first makes the nonce it
// creates verifiable.
add_filter( 'wp_doing_ajax', '__return_true' );
wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
$this->write_plugin_file( 'resources/actions/report.php', $this->action_fixture() );

$ajax = $this->plugin->get( Ajax::class );

$nonce = $ajax->create_action_nonce( 'report' );
$_REQUEST['_wpnonce'] = $nonce;
$_POST['_wpnonce']    = $nonce;

$response = $this->dispatch(
    static function () use ( $ajax ): void {
        do_action( 'wp_ajax_' . $ajax->get_action_slug( 'report' ) );
    }
);
```

## 5. The WP-CLI doubles

`Command` extends `\WP_CLI_Command`, and `WP_CLI` is a static facade — neither exists under PHPUnit, because the WP-CLI phar is not loaded. `tests/Support/wp-cli-stubs.php` stands in for both, and `tests/bootstrap.php` requires it before the WordPress bootstrap.

Four properties make it work, and each matters if you extend it:

1. **Guarded with `class_exists( …, false )`**, so a real WP-CLI runtime is never shadowed.
2. **`WP_CLI_Command` has a no-argument constructor**, because `Command::__construct()` calls `parent::__construct()`.
3. **`error()` and `halt()` record instead of terminating**, so a test can assert on a failure path rather than losing the process to it.
4. **Every call is recorded**, which is what you assert against — `\WP_CLI::last( 'success' )` rather than captured output.

Cover any method your own commands call that is not there yet — `Command::error_box()` reaches `error_multi_line()`, `debug()` reaches `debug()` — and call `\WP_CLI::reset()` in `set_up()` so one test's recorded calls do not leak into the next.

## 6. Reaching a module in a test

A module is built the same way anything else is:

```php
$this->write_plugin_file( 'resources/views/card.php', 'Hi <?php echo esc_html( $name ); ?>' );

$views = $this->plugin->get( Views::class );

$this->assertSame( 'Hi Ada', $views->get( 'card', array( 'name' => 'Ada' ) ) );
```

A module that is not `Bootable` reads the filesystem when you call it rather than at boot, so ordering is less strict here than in section 3 — but keeping fixtures first costs nothing and never surprises you.

> [!IMPORTANT]
> **Never `new` a module in a test.** The constructor is `final` and takes no arguments, so `new Path()` compiles — and then fatals on the first method call, because nothing assigned the plugin. `get()`, `make()` and `wire()` are the only three things that assign it.

Three ways to get an instance, and the difference matters in tests:

- **`get()`** caches. The second call returns the same object, and a module is not re-booted.
- **`make()`** does not, which is how you test two configurations side by side:

  ```php
  $api = $this->plugin->make(
      Options::class,
      static function ( Options $options ): void {
          $options->set_group_name( 'api' );
      }
  );
  ```

- **`wire()`** gives the plugin to an object you built yourself.

`with()` resolves by class name, so a fake stands in only by *being* the class the code under test names. Declaring a `FakeViews` alongside `Views` changes nothing — anything calling `$this->with( Views::class )` still gets the real one.

Where you need a seam, give the class under test a setter and call it after `wire()`. A module reaches its dependencies through `with()` rather than through properties you can overwrite, so the seam has to be one the class offers.

## 7. Things that bite

- **`Options` writes only on `save()`.** Assert against the database only after calling it, and delete the row in `tear_down()`. The option name is `{slug}_{group}` — the default group is `_options_`, so a plugin with slug `acme-plugin-test` stores under `acme-plugin-test__options_`.
- **Do not try to reproduce activation.** `ActivationHandler` detects a late boot and calls `_doing_it_wrong()`, which PHPUnit turns into a failed test — and `register_activation_hook()` fires from WordPress's own upgrade path, not from anything a test controls. Call `activate()` and `deactivate()` directly; they are public and abstract, so they are the whole contract.
- **Migrations never run themselves.** Call `run_pending()` explicitly in the test that needs the schema.
- **Database changes are rolled back after each test, but the filesystem is not.** The generated base case removes its temporary directory in `tear_down()`; anything you create outside it is yours to clean up.

## 8. Why three packages just for the editor

`vendor/` holds no `WP_UnitTestCase`. It ships with the WordPress test suite — the thing `wp-env` mounts at `/wordpress-phpunit` — not with any Composer package, so a base test case that extends it directly extends something your editor cannot see. Nothing above that point resolves either, which is what produces:

```
Undefined method 'assertNotWPError'.  intelephense(P1013)
```

on a method WordPress really does define. The same goes for `assertSame()` and every other assertion, since PHPUnit's own `TestCase` is further up the same chain.

Three `require-dev` packages close it, and each covers one link:

| Package | Supplies |
|---|---|
| `yoast/wp-test-utils` | `Yoast\WPTestUtils\WPIntegration\TestCase`, the real class your base case extends |
| `php-stubs/wordpress-tests-stubs` | `WP_UnitTestCase`, `WP_UnitTestCase_Base`, `WP_UnitTest_Factory` — every WordPress assertion |
| `yoast/phpunit-polyfills` | `Yoast\PHPUnitPolyfills\TestCases\TestCase`, which reaches PHPUnit's own |

With all three the chain is unbroken from your test file to `PHPUnit\Framework\TestCase`, and `assertNotWPError()` resolves like anything else — no `@method` annotations to maintain.

**The stubs package declares no autoloader**, which is what makes it safe to have installed beside the real suite. Composer never loads the file, so its `WP_UnitTestCase` cannot collide with the one your tests actually run against; the declarations exist for your editor and for nothing else. Do not `require` it yourself — it names core classes it does not declare, and only an indexer is meant to read it. Pointing PHPStan or Psalm at the same file works, alongside `php-stubs/wordpress-stubs` for those core classes.

Its version tracks WordPress's, so keep it at or above the WordPress you test against.

## Next

- [`wp zt tests`](commands/tests.md) — every file the scaffold writes, and what each is for
- [`wp zt make test`](commands/make-test.md) — one command per new test file
- [`Plugin`](plugin.md) — `get()`, `make()`, `wire()`, `configure()` and the rest of what a test drives
- [Modules](modules/) — the directory each one discovers, and what its files return
- [`wp zt doctor`](commands/doctor.md) — the wiring mistakes that produce no error at all, and no test either
