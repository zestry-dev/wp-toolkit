# Testing

Your features are files in a directory, and the module that discovers them needs real files, real hooks and a real database. So tests run against WordPress's own PHPUnit suite, and each test builds a **throwaway `Plugin`** pointed at whichever directory that test wants the modules to read.

A `Plugin` takes an entry file path and derives everything from it — the plugin directory, the roots every module walks, the URLs `Path` and `Assets` produce. Point one at a temporary directory and you get a private, disposable filesystem to write fixtures into.

## 1. Get a WordPress to test against

`wp-env` is the shortest path: Docker, one config file, and a WordPress install with the core test suite already mounted.

```bash
npm install --save-dev @wordpress/env
composer require --dev phpunit/phpunit:^9.6 yoast/phpunit-polyfills:^1.1
```

`.wp-env.json`, in your plugin's root:

```json
{
    "core": "WordPress/WordPress",
    "phpVersion": "8.1",
    "mappings": {
        "wp-content/plugins/acme-plugin": "."
    }
}
```

```bash
npx wp-env start
npx wp-env run --env-cwd='wp-content/plugins/acme-plugin' tests-wordpress vendor/bin/phpunit
```

Run in the **`tests-wordpress`** container, not `wordpress`. Both have the core test suite mounted at `/wordpress-phpunit` and `WP_TESTS_DIR` set to it, but only the tests container has its own database — and the WordPress test suite empties the database it is given on every run.

Worth two `package.json` scripts, since that command is long:

```json
"scripts": {
    "env:start": "wp-env start",
    "test:php": "wp-env run --env-cwd='wp-content/plugins/acme-plugin' tests-wordpress vendor/bin/phpunit"
}
```

Without Docker, install the test suite locally with WordPress's `install-wp-tests.sh` and export `WP_TESTS_DIR` to point at it. Nothing below cares which of the two you chose.

## 2. Bootstrap

```xml
<?xml version="1.0"?>
<!-- phpunit.xml.dist -->
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    failOnWarning="true"
>
    <testsuites>
        <testsuite name="integration">
            <directory suffix="Test.php">tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

```php
<?php
// tests/bootstrap.php

declare( strict_types=1 );

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
    fwrite( STDERR, "No WordPress test suite at {$tests_dir}.\n" );
    exit( 1 );
}

// Your autoloader first: it provides your own classes, and the polyfills the
// WordPress bootstrap checks for before it will start.
require dirname( __DIR__ ) . '/vendor/autoload.php';

// WP-CLI doubles, so anything extending Command is loadable. See section 6.
require __DIR__ . '/wp-cli-stubs.php';

require $tests_dir . '/includes/functions.php';
require $tests_dir . '/includes/bootstrap.php';
```

Give your tests their own PSR-4 prefix, in `autoload-dev` so they stay out of the production autoloader:

```json
"autoload-dev": {
    "psr-4": { "Acme\\Plugin\\Tests\\": "tests/" }
}
```

Then `composer dump-autoload`.

> [!IMPORTANT]
> **Never load your plugin's entry file from a test.** It builds and runs the real `Plugin` — every module booted, every hook bound, against whatever directories your plugin actually ships. Tests build their own instance instead, and control what it can see. Your Composer autoloader already makes every class available without the entry file.

## 3. A base test case

Every test gets a fresh directory, a fake entry file in it, and a `Plugin` pointed at that file.

```php
<?php
// tests/TestCase.php

declare( strict_types=1 );

namespace Acme\Plugin\Tests;

use Acme\Plugin\Core\Kernel\Plugin;
use Acme\Plugin\Core\Modules\Ajax\Ajax;
use Acme\Plugin\Core\Modules\CLI\CLI;
use Acme\Plugin\Core\Modules\Cron\Cron;
use Acme\Plugin\Core\Modules\Options;
use Acme\Plugin\Core\Modules\Path;
use Acme\Plugin\Core\Modules\Views;

abstract class TestCase extends \WP_UnitTestCase {

    protected string $plugin_dir = '';

    protected Plugin $plugin;

    public function set_up(): void {
        parent::set_up();

        $this->plugin_dir = sys_get_temp_dir() . '/' . uniqid( 'acme-', true );
        mkdir( $this->plugin_dir, 0777, true );

        $entry = $this->plugin_dir . '/plugin.php';
        file_put_contents( $entry, "<?php\n/* Plugin Name: Acme Test */\n" );

        $this->plugin = new Plugin( $entry, 'acme-test' );

        // Nothing is built that is not declared, so a test case declares what
        // its tests reach for. The headings are nominal here: these tests call
        // get() directly rather than run(), which is what waits on a hook.
        $this->plugin->declare_multiple(
            array(
                Path::class,
                Options::class,
                Views::class,

                'init' => array(
                    CLI::class,
                    Ajax::class,
                    Cron::class,
                ),
            )
        );
    }

    public function tear_down(): void {
        $this->remove_dir( $this->plugin_dir );
        parent::tear_down();
    }

    /**
     * Write a file (and its parent directories) inside the throwaway plugin.
     */
    protected function write_plugin_file( string $relative_path, string $contents ): string {
        $absolute = $this->plugin_dir . '/' . ltrim( $relative_path, '/' );

        if ( ! is_dir( dirname( $absolute ) ) ) {
            mkdir( dirname( $absolute ), 0777, true );
        }

        file_put_contents( $absolute, $contents );

        return $absolute;
    }

    protected function remove_dir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $items as $item ) {
            $item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
        }

        rmdir( $dir );
    }
}
```

The explicit slug matters. Every name a module registers carries it — option rows, hook names, AJAX actions, `wp {slug} …` commands — so a test slug keeps test data out of anything your real plugin stores.

The examples below import from your own tree, not from the toolkit's:

```php
use Acme\Plugin\Core\Kernel\Exceptions\DiscoveryException;
use Acme\Plugin\Core\Modules\Ajax\Ajax;
use Acme\Plugin\Core\Modules\CLI\CLI;
use Acme\Plugin\Core\Modules\Options;
use Acme\Plugin\Core\Modules\Views;
```

To exercise the files you actually ship instead, point the `Plugin` at your real entry file:

```php
$plugin = new Plugin( dirname( __DIR__ ) . '/acme-plugin.php', 'acme-test' );
$plugin->declare( CLI::class, 'init' );
$plugin->get( CLI::class );   // discovers your real resources/commands/ directory
```

That is a second, test-owned instance. It shares nothing with the one your entry file builds in production.

## 4. Give a module files to discover

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

    $this->assertSame( 'acme-test greet', \WP_CLI::last( 'add_command' )[0] );
}
```

> [!IMPORTANT]
> **Order is the whole thing here.** Most modules defer discovery to `init` with `on_wp_init()` — and `init` fired long before your test method ran, so the callback runs *immediately*, inside the `get()` call. A fixture written afterwards is never seen, and `do_action( 'init' )` will not give you a second pass (it only re-runs every other `init` callback the suite has registered). Write files first, resolve second.

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
$this->assertSame( array(), $this->plugin->get( CLI::class )->get_discovered_commands() );
```

## 5. Test one file, without its module

A `Command`, an `AjaxAction`, a `Route`, a `Schedule` — each is a plain object its module `require`s and wires. You can do both halves yourself, and skip discovery entirely.

```php
public function test_greet_says_hello(): void {
    $command = require dirname( __DIR__ ) . '/resources/commands/greet.php';

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
    $action = require dirname( __DIR__ ) . '/actions/report.php';
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

## 6. Provide WP-CLI doubles

`Command` extends `\WP_CLI_Command`, and `WP_CLI` is a static facade — neither exists under PHPUnit, because the WP-CLI phar is not loaded. Any test that touches a command file fatals without stand-ins.

Write your own `tests/wp-cli-stubs.php` and `require` it from `tests/bootstrap.php`, before the WordPress bootstrap. The shape that matters:

```php
<?php

declare( strict_types=1 );

namespace {
    // The `false` is load-bearing: it claims the name without autoloading,
    // so a real wp-cli/wp-cli dev dependency cannot win the race.
    if ( ! class_exists( 'WP_CLI_Command', false ) ) {
        class WP_CLI_Command {
            public function __construct() {}
        }
    }

    if ( ! class_exists( 'WP_CLI', false ) ) {
        class WP_CLI {

            /** @var array<int, array<int, mixed>> */
            public static array $calls = array();

            public static function reset(): void {
                self::$calls = array();
            }

            /** Arguments of the last call to a given method, or null. */
            public static function last( string $method ): ?array {
                for ( $i = count( self::$calls ) - 1; $i >= 0; $i-- ) {
                    if ( self::$calls[ $i ][0] === $method ) {
                        return array_slice( self::$calls[ $i ], 1 );
                    }
                }

                return null;
            }

            public static function add_command( $name, $callable ): void {
                self::$calls[] = array( 'add_command', $name, $callable );
            }

            public static function log( $message ): void {
                self::$calls[] = array( 'log', $message );
            }

            public static function success( $message ): void {
                self::$calls[] = array( 'success', $message );
            }

            public static function warning( $message ): void {
                self::$calls[] = array( 'warning', $message );
            }

            public static function error( $message, $exit = true ): void {
                self::$calls[] = array( 'error', $message, $exit );
            }

            public static function error_multi_line( $messages ): void {
                self::$calls[] = array( 'error_multi_line', $messages );
            }

            public static function debug( $message, $group = false ): void {
                self::$calls[] = array( 'debug', $message, $group );
            }

            public static function halt( $code ): void {
                self::$calls[] = array( 'halt', $code );
            }
        }
    }
}

namespace WP_CLI\Utils {
    if ( ! function_exists( __NAMESPACE__ . '\get_flag_value' ) ) {
        function get_flag_value( $assoc_args, $flag, $default = null ) {
            return $assoc_args[ $flag ] ?? $default;
        }
    }
}
```

Four properties make it work:

1. **Guarded with `class_exists( …, false )`**, so a real WP-CLI runtime is never shadowed.
2. **`WP_CLI_Command` has a no-argument constructor**, because `Command::__construct()` calls `parent::__construct()`.
3. **`error()` and `halt()` record instead of terminating**, so a test can assert on a failure path rather than losing the process to it.
4. **Every call is recorded**, which is what you assert against — `\WP_CLI::last( 'success' )` rather than captured output.

Cover every method your commands actually call — `Command::error_box()` reaches `error_multi_line()`, `debug()` reaches `debug()` — and call `\WP_CLI::reset()` in `set_up()` so one test's recorded calls do not leak into the next.

## 7. Reaching a module in a test

A module is built the same way anything else is:

```php
$this->write_plugin_file( 'resources/views/card.php', 'Hi <?php echo esc_html( $name ); ?>' );

$views = $this->plugin->get( Views::class );

$this->assertSame( 'Hi Ada', $views->get( 'card', array( 'name' => 'Ada' ) ) );
```

A module that is not `Bootable` reads the filesystem when you call it rather than at boot, so ordering is less strict here than in section 4 — but keeping fixtures first costs nothing and never surprises you.

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

A module the plugin never declared throws rather than being built. The `TestCase` above declares the modules these examples use; add yours to that list, or call `declare()` in the test itself.

## 8. Things that bite

- **`Options` writes only on `save()`.** Assert against the database only after calling it, and delete the row in `tear_down()`. The option name is `{slug}_{group}` — the default group is `_options_`, so a plugin with slug `acme-test` stores under `acme-test__options_`.
- **Do not try to reproduce activation.** `ActivationHandler` detects a late boot and calls `_doing_it_wrong()`, which PHPUnit turns into a failed test — and `register_activation_hook()` fires from WordPress's own upgrade path, not from anything a test controls. Call `activate()` and `deactivate()` directly; they are public and abstract, so they are the whole contract.
- **Migrations never run themselves.** Call `run_pending()` explicitly in the test that needs the schema.
- **Database changes are rolled back after each test**, but the filesystem is not. Remove your temp directory in `tear_down()`, or a long run fills `/tmp`.
- **A single test method** runs with `--filter`:

  ```bash
  npx wp-env run --env-cwd='wp-content/plugins/acme-plugin' tests-wordpress \
      vendor/bin/phpunit --filter test_greet_says_hello
  ```

## Next

- [`Plugin`](plugin.md) — `get()`, `make()`, `wire()`, `configure()` and the rest of what a test drives
- [Modules](modules/) — the directory each one discovers, and what its files return
- [`wp zt doctor`](commands/doctor.md) — the wiring mistakes that produce no error at all, and no test either
