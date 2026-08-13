<?php

/**
 * Core API: Plugin coordinator class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Exceptions\CircularDependencyException;
use Zestry\WPToolkit\Kernel\Exceptions\ModuleException;
use Zestry\WPToolkit\Kernel\Exceptions\ModuleNotFoundException;

/**
 * The one object your plugin builds, in its entry file.
 *
 * It holds every service and module the plugin uses, builds each the first time
 * it is needed, and answers what the plugin knows about itself -- its slug, its
 * own directory, the headers its entry file declares. Nothing else has to be
 * constructed by hand: a class asks for another by declaring a typed property,
 * and this is what fills it in.
 *
 * Modules are declared in a `bootstrap.php`, which {@see bootstrap()} reads and
 * {@see run()} builds and boots. `wp zt init` creates that file and `wp zt add`
 * appends to it, so a module is active as soon as it is copied and the entry
 * file never has to change. {@see configure()} and {@see autoload()} are public,
 * so a plugin that prefers to declare its modules in the entry file can do that
 * instead, and the two approaches can be combined.
 *
 * A {@see \Zestry\WPToolkit\Kernel\Abstracts\Service} is never declared there: it resolves on
 * demand through {@see get()}, or is injected into another class by type. One
 * that takes configuration is given it with {@see configure()} in the entry
 * file.
 *
 * There is nothing to register: `get()` builds any {@see Service} subclass the
 * first time you ask for it -- both kinds, since a Module is a Service that
 * also acts on its own -- so asking whether the plugin "has" one is never a
 * question you need to answer. To reach a module your plugin may not have
 * added, emit a hook instead of asking for it, the way `Options` and `Cron`
 * reach `Log`.
 *
 * @example The entry file
 * Constructs the plugin and runs it. Module declarations live in
 * `bootstrap.php`, so this file is unchanged by how many modules the plugin
 * uses.
 *
 * The accessor holds the instance for anything outside the module system --
 * a test, a template, a hand-registered callback -- since modules themselves
 * are injected by type and never need it.
 *
 * ```
 * // my-plugin.php
 * use Acme\Plugin\Core\Kernel\Plugin;
 *
 * require_once __DIR__ . '/vendor/autoload.php';
 *
 * function my_plugin(): Plugin {
 *     static $plugin = null;
 *
 *     $plugin ??= ( new Plugin( __FILE__, 'my-plugin' ) )->bootstrap()->run();
 *
 *     return $plugin;
 * }
 *
 * my_plugin();
 * ```
 *
 * @example The bootstrap file
 * Declares every module the plugin uses, with the configuration each requires.
 * `wp zt init` creates the file and `wp zt add` appends to it, so a module
 * is active as soon as it is copied.
 *
 * With this in place, a file returned from `actions/save-profile.php` becomes
 * an AJAX action (see {@see \Zestry\WPToolkit\Modules\Ajax\AjaxAction}), a file returned
 * from `commands/greet.php` becomes the WP-CLI command `wp my-plugin greet`
 * (see {@see \Zestry\WPToolkit\Modules\CLI\Command}), and a file returned from
 * `admin-pages/settings.php` becomes an admin menu page (see
 * {@see \Zestry\WPToolkit\Modules\AdminPages\AdminPage}) — none of them need registering
 * by hand.
 *
 * Every entry is a module, and listing one is what builds it. Its value -- when
 * it has one -- is the initializer that configures it; a module needing none is
 * written bare, as `AdminPages::class` below.
 *
 * ```
 * // bootstrap.php
 * use Acme\Plugin\Core\Modules\AdminPages\AdminPages;
 * use Acme\Plugin\Core\Modules\Ajax\Ajax;
 * use Acme\Plugin\Core\Modules\CLI\CLI;
 *
 * return array(
 *     Ajax::class => static function ( Ajax $ajax ): void {
 *         $ajax->set_actions_root( 'actions' );
 *     },
 *     CLI::class => static function ( CLI $cli ): void {
 *         $cli->set_commands_root( 'commands' );
 *     },
 *     AdminPages::class,
 * );
 * ```
 *
 * @example Declaring modules in the entry file instead
 * `bootstrap.php` is optional. It calls {@see configure()} and
 * {@see autoload()}, both of which are public, so a plugin that prefers a
 * single file can call them directly.
 *
 * ```
 * // my-plugin.php
 * function my_plugin(): Plugin {
 *     static $plugin = null;
 *
 *     $plugin ??= ( new Plugin( __FILE__, 'my-plugin' ) )
 *         ->configure(
 *             Ajax::class,
 *             static function ( Ajax $ajax ): void {
 *                 $ajax->set_actions_root( 'actions' );
 *             }
 *         )
 *         ->autoload( array( Ajax::class ) )
 *         ->run();
 *
 *     return $plugin;
 * }
 *
 * my_plugin();
 * ```
 */
class Plugin {

	/**
	 * The longest slug a registered name can carry.
	 *
	 * A table name is `{wpdb->prefix}{slug}_{name}` inside MySQL's 64-character
	 * identifier, so this leaves about as much of it to the plugin as it takes.
	 *
	 * @var int
	 */
	public const MAX_SLUG_LENGTH = 32;

	/**
	 * Plugin identifier used to namespace settings, hooks, and debug constants.
	 *
	 * @var string
	 */
	private string $slug = '';

	/**
	 * Absolute path to the main plugin file used for lifecycle hook registration.
	 *
	 * @var string
	 */
	private string $entry_file = '';

	/**
	 * Repository responsible for module registration and resolution.
	 *
	 * @var ServicesRepository
	 */
	private ServicesRepository $modules;

	/**
	 * The file {@see bootstrap()} was pointed at, or null until it is called.
	 *
	 * @var string|null
	 */
	private ?string $bootstrap_file = null;

	/**
	 * Construct the plugin and its service repository.
	 *
	 * Pass `__FILE__` from your entry file. Everything else the plugin needs to
	 * know about itself -- its own directory, and the headers it declares --
	 * is read from that path.
	 *
	 * The slug is your plugin's namespace, and every name a module registers
	 * carries it: option names, hook names, script and style handles, AJAX
	 * action names, REST route namespaces, admin page slugs, WP-CLI commands
	 * (`wp {slug} greet`), the default table prefix, and the
	 * `{SLUG}_DEBUG` constant {@see is_plugin_debug()} reads. Omit it and it
	 * defaults to the *directory* the entry file sits in, so
	 * `plugins/acme-crm/plugin.php` gives `acme-crm`. Pass one explicitly when
	 * the registered names should read differently from the directory name.
	 *
	 * Choose it once: changing it later renames everything registered under
	 * the old one, which orphans stored options and the schedules pointing at
	 * the old hook names.
	 *
	 * **Spell it `acme`, `acme-crm` or `acme-crm2`**: a lowercase letter, then
	 * lowercase letters and digits, single dashes between them, up to
	 * {@see MAX_SLUG_LENGTH} characters. Anything else throws. A directory named
	 * otherwise is fine -- pass the slug you want as the second argument.
	 *
	 * @rationale
	 * Checked here rather than where each name is built, because this is the one
	 * name every other name is composed from. Each half of the rule answers to a
	 * destination that cannot take the alternative: WordPress matches an ability
	 * name and a block namespace against `^[a-z0-9-]+$` and refuses anything
	 * else; an admin page slug has to survive a URL; `wp {slug} greet` reads a
	 * leading dash as a flag; a CSS class cannot begin with a digit; and every
	 * composed name puts a separator after the slug, so a trailing dash would
	 * register `acme--greet`.
	 *
	 * @param string      $entry The absolute path to your plugin's entry file.
	 * @param string|null $slug  The plugin slug; defaults to the entry file's directory name.
	 * @throws \InvalidArgumentException When the slug is not one a registered name can carry.
	 */
	public function __construct(
		string $entry,
		?string $slug = null
	) {
		$this->entry_file = $entry;
		$this->slug       = $slug ? $slug : \basename( \dirname( $entry ) );

		// One expression for four rules: a letter first, then alphanumeric runs
		// joined by single dashes -- which leaves no way to lead with, end with,
		// or double a dash.
		if ( ! \preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $this->slug ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The plugin slug "%s" takes characters a registered name cannot: start with a'
						. ' lowercase letter, then lowercase letters and digits, with single dashes'
						. ' between them. It came from %s, so pass the slug you want as the second'
						. ' argument to %s.',
					$this->slug,
					null === $slug ? 'the directory "' . \basename( \dirname( $entry ) ) . '"' : 'the argument given',
					self::class
				)
			);
		}

		if ( \strlen( $this->slug ) > self::MAX_SLUG_LENGTH ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The plugin slug "%s" is %d characters; %d is the most a registered name can'
						. ' carry and leave room for your own half of it.',
					$this->slug,
					\strlen( $this->slug ),
					self::MAX_SLUG_LENGTH
				)
			);
		}

		$this->modules = new ServicesRepository( $this );
	}

	/**
	 * Configure a service or a module before anything builds it.
	 *
	 * Either kind: what is stored is a callback against a class name, and the
	 * two are configured identically. The initializer runs when the class is
	 * first built, after wiring and -- for a {@see Module} -- before `on_boot()`,
	 * so it can set what boot depends on. Only needed by a class that takes
	 * configuration; anything else resolves fine without one.
	 *
	 * ```
	 * $plugin->configure( Ajax::class, function ( Ajax $ajax ) {
	 *     $ajax->set_actions_root( 'actions' );
	 * } );
	 * ```
	 *
	 * Nothing here declares a class to the plugin, and nothing here loads one:
	 * every service and module is found by type, so this only remembers a
	 * callback against a name. A module still has to be queued -- by
	 * {@see autoload()}, or by being listed in `bootstrap.php` -- for anything to
	 * happen. **This is where a service is configured**, since `bootstrap.php`
	 * is modules only: the callback runs when something first asks for it, and
	 * never at all if nothing does.
	 *
	 * @template T of object
	 * @param class-string<T> $name        The class name to configure.
	 * @param callable(T $instance, self $plugin): void $initializer Callback receiving the instance and plugin.
	 * @return $this Fluent interface for method chaining.
	 */
	public function configure( string $name, callable $initializer ): self {
		$this->modules->configure( $name, $initializer );
		return $this;
	}

	/**
	 * Queue modules to be resolved when `run()` is called.
	 *
	 * Only remembers the class names -- nothing is built here, and no hook of
	 * this method's own decides the timing. Your entry file does, by choosing
	 * when it calls {@see run()}, which resolves the queue synchronously and
	 * boots each module as it goes.
	 *
	 * @param array<class-string> $modules Module classes to resolve automatically.
	 * @return $this
	 */
	public function autoload( array $modules = array() ): self {
		foreach ( $modules as $name ) {
			$this->modules->set_autoload( $name );
		}

		return $this;
	}

	/**
	 * Register and queue every module a `bootstrap.php` declares.
	 *
	 * The file returns one flat list, so the entry file never changes as modules
	 * are added -- and `wp zt add` has somewhere to register what it copies,
	 * meaning a module works the moment it arrives rather than after a
	 * hand-edit:
	 *
	 * ```
	 * // bootstrap.php
	 * return array(
	 *     Ajax::class => static function ( Ajax $ajax ): void {
	 *         $ajax->set_actions_root( 'actions' );
	 *     },
	 *     CLI::class => static function ( CLI $cli ): void {
	 *         $cli->set_commands_root( 'commands' );
	 *     },
	 *     Options::class,
	 * );
	 * ```
	 *
	 * An entry's value is its initializer -- the callback
	 * {@see configure()} would take -- and an entry needing none is
	 * written bare, as `Options::class,` above.
	 *
	 * **The file is modules only, and listing one is what builds it.** That is
	 * its whole job, which is what makes it readable at a glance: every name
	 * here is something the plugin starts, and an entry's value -- when it has
	 * one -- configures it on the way.
	 *
	 * A {@see Service} does not belong here. It is built the moment something
	 * asks for it, so listing it would only build it sooner than it needed to
	 * be. Configure one from the entry file instead, where {@see configure()}
	 * takes the same callback:
	 *
	 * ```
	 * ( new Plugin( __FILE__ ) )
	 *     ->configure( DB::class, static fn ( DB $db ) => $db->set_table_prefix( 'acme' ) )
	 *     ->bootstrap()
	 *     ->run();
	 * ```
	 *
	 * Because every entry means one thing, nothing here has to ask what a class
	 * *is* -- so reading this file compiles none of the classes it names. They
	 * load when `run()` builds them.
	 *
	 * A missing file is not an error. If there is no `bootstrap.php` the plugin
	 * is returned unchanged, so you can call this unconditionally from a
	 * template entry file and declare everything in the entry file itself.
	 *
	 * A plugin with a hand-written entry file needs none of this: `configure()`
	 * and `autoload()` are public, and the two approaches can be mixed.
	 *
	 * @param string|null $file Absolute path to the bootstrap file; defaults to `bootstrap.php` beside the entry file.
	 * @return $this
	 * @throws ModuleException When the file does not return an array, or an entry is malformed.
	 */
	public function bootstrap( ?string $file = null ): self {
		$file ??= \dirname( $this->entry_file ) . '/bootstrap.php';

		// Recorded before the existence check, since this is the file this
		// plugin reads declarations from whether or not it exists yet -- which
		// is the question `wp zt add` asks when it needs somewhere to append.
		$this->bootstrap_file = $file;

		// Absent is not an error: a plugin may configure everything in its entry
		// file, and `bootstrap()` is worth calling unconditionally in a template.
		if ( ! \is_file( $file ) ) {
			return $this;
		}

		$declared = require $file;

		if ( ! \is_array( $declared ) ) {
			throw new ModuleException( 'Bootstrap file must return an array: ' . $file );
		}

		$this->declare_all( $declared );

		return $this;
	}

	/**
	 * Tell WordPress where this plugin keeps its own translations.
	 *
	 * Only needed for a plugin shipping a `languages/` directory of its own.
	 * WordPress already looks in `wp-content/languages/plugins` without being
	 * asked, which is where a wordpress.org-hosted plugin's translations are
	 * installed -- so a plugin distributed that way needs no call at all.
	 *
	 * The text domain defaults to the plugin slug, matching what `wp zt init`
	 * writes into `zestry.json` and stamps into every copied file, so the two
	 * cannot disagree unless a consumer deliberately changes one.
	 *
	 * ```
	 * // my-plugin.php, inside the accessor that builds the plugin.
	 * $plugin ??= ( new Plugin( __FILE__ ) )
	 *     ->set_languages_path( 'languages' )
	 *     ->bootstrap()
	 *     ->run();
	 * ```
	 *
	 * This registers a path rather than loading anything: translations load on
	 * the first `__()` call that needs them. Calling it here, as the plugin file
	 * loads, is therefore both early enough and not too early -- what WordPress
	 * warns about is *using* a translation before `init`, not registering where
	 * they live.
	 *
	 * @param string      $path        Plugin-relative directory holding the `.mo` files.
	 * @param string|null $text_domain Text domain; defaults to the plugin slug.
	 * @return $this
	 */
	public function set_languages_path( string $path, ?string $text_domain = null ): self {
		\load_plugin_textdomain(
			$text_domain ?? $this->slug,
			false,
			// load_plugin_textdomain() resolves this against WP_PLUGIN_DIR, so
			// it wants the plugin's own directory name on the front rather than
			// an absolute path.
			\basename( \dirname( $this->entry_file ) ) . '/' . \trim( $path, '/' )
		);

		return $this;
	}

	/**
	 * Get the given service or module from the plugin.
	 *
	 * Resolved once and cached, so repeated calls return the same object. One
	 * accessor for both kinds, since a {@see Module} *is* a {@see Service}: what
	 * differs is that resolving a module also boots it.
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to resolve.
	 * @return T The resolved instance.
	 * @throws ModuleNotFoundException If the class does not exist or does not extend Service.
	 * @throws CircularDependencyException If the dependency graph re-enters itself.
	 */
	public function get( string $name ): object {
		return $this->modules->get( $name );
	}

	/**
	 * Build a fresh, fully wired instance of a service or module class.
	 *
	 * Unlike get(), never cached: every call returns a new wired instance.
	 * The configurator runs after wiring and before boot(). Use it for a second
	 * instance of a module, such as a dedicated Options group:
	 *
	 * ```
	 * $api_options = $plugin->make( Options::class, function ( Options $o ) {
	 *     $o->set_group_name( 'api' );
	 * } );
	 * ```
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to construct.
	 * @param callable(T $instance, self $plugin): void|null $configurator Optional callback run after wiring, before boot.
	 * @return T A new, wired instance.
	 * @throws ModuleNotFoundException If the class does not exist or does not extend Service.
	 * @throws CircularDependencyException If the dependency graph re-enters itself.
	 */
	public function make( string $name, ?callable $configurator = null ): object {
		return $this->modules->make( $name, $configurator );
	}

	/**
	 * Assign the plugin and inject declared dependencies into an existing object.
	 *
	 * Lets an object built outside the resolution lifecycle -- a CLI command or
	 * an AJAX action loaded from a file -- declare typed properties and receive
	 * them the way a service does, without being one itself. The object must
	 * implement {@see PluginAware}, which the {@see \Zestry\WPToolkit\Kernel\Traits\WithPlugin}
	 * trait satisfies.
	 *
	 * Each typed property is resolved through {@see get()} as it is injected,
	 * so wiring an object can raise the same failures resolving one does.
	 *
	 * @template T of PluginAware
	 * @param T $instance The object to wire.
	 * @return T The same instance, now wired.
	 * @throws CircularDependencyException If the dependency graph re-enters itself.
	 */
	public function wire( PluginAware $instance ): PluginAware {
		return $this->modules->wire( $instance );
	}

	/**
	 * Get the plugin slug, used to namespace every module's registered names.
	 *
	 * @return string The plugin identifier.
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * A local name, namespaced to this plugin.
	 *
	 * Every global name this plugin registers comes through here -- an action or
	 * filter of your own, and behind the scenes a script handle, a transient key,
	 * a meta box id, a cron hook, a Site Health identifier, a REST namespace, a
	 * WP-CLI command, an option name. One function, so anything this plugin puts
	 * into a namespace it shares with every other plugin on the site is prefixed
	 * the same way and cannot collide.
	 *
	 * ```
	 * do_action( $plugin->get_namespaced_name( 'import-finished' ), $count );
	 * ```
	 *
	 * Both halves are passed through exactly as written, so your slug and your
	 * local name appear in the result the way you spelled them. `$glue` is what
	 * joins them, and defaults to the hyphen a hook, handle or id wants; the
	 * destinations that want something else say so -- an option name joins with
	 * `_`, a REST namespace with `/`, a WP-CLI command with a space.
	 *
	 * @param string $name The local name, without the plugin prefix.
	 * @param string $glue What to join the two halves with.
	 * @return string The namespaced name.
	 */
	public function get_namespaced_name( string $name, string $glue = '-' ): string {
		// Nothing is rewritten here. A name you hand this is a name you have
		// spelled somewhere else too -- in a filename, in a `fetch()` call, in a
		// `wp_next_scheduled()` lookup -- and a method that silently respelled it
		// would make the two disagree without saying so.
		return $this->slug . $glue . $name;
	}

	/**
	 * The file this plugin reads its module declarations from.
	 *
	 * The path `bootstrap()` read: `bootstrap.php` beside your entry file unless
	 * you passed it another. Null until `bootstrap()` is called, which is also
	 * the answer for a plugin declaring its modules in the entry file instead.
	 *
	 * @rationale
	 * Nothing on disk records which file was used, so a tool reading the plugin
	 * from outside would have to guess -- and guessing wrong means reporting
	 * every module as undeclared, or appending a declaration to a file this
	 * plugin never reads. `wp zt doctor` and `wp zt make module` both ask this.
	 *
	 * @return string|null The path, or null when `bootstrap()` has not run.
	 */
	public function get_bootstrap_file(): ?string {
		return $this->bootstrap_file;
	}

	/**
	 * The plugin's entry file, as passed to the constructor.
	 *
	 * @return string
	 */
	public function get_entry_file(): string {
		return $this->entry_file;
	}

	/**
	 * Read a single plugin header field from the entry file's own docblock.
	 *
	 * Reads the same header comment WordPress itself parses for the plugin list,
	 * so nothing needs declaring twice. Not cached -- read fresh on every call.
	 *
	 * @param string $header The header name as WordPress declares it, e.g. 'Version', 'Text Domain'.
	 * @return string|null The header's value, or null if absent or blank.
	 */
	public function get_header( string $header ): ?string {
		$data  = \get_file_data( $this->entry_file, array( $header => $header ) );
		$value = $data[ $header ] ?? '';

		return '' === $value ? null : $value;
	}

	/**
	 * Get the plugin's own declared version.
	 *
	 * Shorthand for `get_header( 'Version' )`.
	 *
	 * @return string|null The plugin's `Version:` header value, or null if absent.
	 */
	public function get_version(): ?string {
		return $this->get_header( 'Version' );
	}

	/**
	 * Check if WordPress debug mode is enabled.
	 *
	 * Read fresh, so a late `define( 'WP_DEBUG' )` is still reflected.
	 *
	 * @return bool True if the WP_DEBUG constant is defined and set to true.
	 */
	public function is_wp_debug(): bool {
		return \defined( 'WP_DEBUG' ) && WP_DEBUG === true;
	}

	/**
	 * Check if WP-CLI is active.
	 *
	 * @return bool True if the WP_CLI constant is defined and set to true.
	 */
	public function is_wp_cli(): bool {
		return \defined( 'WP_CLI' ) && WP_CLI === true;
	}

	/**
	 * Check if plugin debug mode is enabled.
	 *
	 * Checks for a plugin-specific debug constant based on the plugin slug.
	 * Constant name format: {PLUGIN_SLUG}_DEBUG (e.g., ACME_PLUGIN_DEBUG).
	 *
	 * @return bool True if the plugin's debug constant is defined and set to true.
	 */
	public function is_plugin_debug(): bool {
		$prefix        = \str_replace( '-', '_', \strtoupper( $this->get_slug() ) );
		$constant_name = $prefix . '_DEBUG';
		return \defined( $constant_name ) && \constant( $constant_name ) === true;
	}

	/**
	 * Resolve autoloaded modules and run an optional ready callback.
	 *
	 * Call this from the plugin entry file once modules are registered. It runs
	 * synchronously, so the caller controls timing: invoke it directly at plugin
	 * load, or from inside a `plugins_loaded`/`init` hook when a later point is
	 * needed. Queued classes resolve first -- and a {@see Module} boots as it
	 * resolves -- then the callback runs with all of them available.
	 *
	 * An {@see \Zestry\WPToolkit\Kernel\Abstracts\ActivationHandler} subclass is the one case where
	 * *when* this is called is load-bearing: WordPress fires `activate_{plugin}`
	 * immediately after the entry file loads, so a `run()` deferred to a later
	 * hook is already too late to register the activation callback.
	 *
	 * **Modules boot in the order they are listed**, each fully resolved and
	 * booted before the next begins. A module that throws stops the ones after it,
	 * and nothing wraps what it threw: the toolkit's own failures are
	 * {@see \Zestry\WPToolkit\Kernel\Exceptions\ModuleException}s, and whatever your `on_boot()`
	 * raises arrives as itself.
	 *
	 * @param callable(self $plugin): void|null $on_boot_callback Optional callback receiving this plugin after modules are ready.
	 * @return $this
	 * @throws ModuleException When a queued class cannot be built, or a discovery module cannot read its root.
	 * @throws \Throwable Whatever a module's own `on_boot()` raises, unchanged.
	 */
	public function run( ?callable $on_boot_callback = null ): self {
		$this->modules->run_autoload();
		if ( $on_boot_callback ) {
			( $on_boot_callback )( $this );
		}

		$this->expose_to_devtool();

		return $this;
	}

	/**
	 * Register and queue every module a bootstrap file declares.
	 *
	 * Two entry shapes, because a module with nothing to configure still has to
	 * be listed for it to be built: `Foo::class => $initializer` gives a string
	 * key and a callable, `Foo::class,` gives an integer key and the class name
	 * as the value. Either shape queues the class; the callable, when there is
	 * one, is registered as its initializer first.
	 *
	 * Nothing here loads a class. Registering an initializer only stores a
	 * closure against a name, and queueing only remembers a name, so a file
	 * naming a dozen classes reads without compiling any of them -- the
	 * {@see Module}s compile when `run()` builds them, and the {@see Service}s
	 * when something first asks. Deciding a class's kind here instead would mean
	 * autoloading every entry to ask, which is the cost this avoids.
	 *
	 * @param array<array-key, mixed> $entries The bootstrap file's entries.
	 * @return void
	 * @throws ModuleException When an entry names no class, or its value is neither a callable nor a class name.
	 */
	private function declare_all( array $entries ): void {
		foreach ( $entries as $key => $value ) {
			$name        = \is_string( $key ) ? $key : $value;
			$initializer = \is_string( $key ) ? $value : null;

			if ( ! \is_string( $name ) || '' === $name ) {
				throw new ModuleException( 'Bootstrap entries must name a class.' );
			}

			if ( null !== $initializer && ! \is_callable( $initializer ) ) {
				throw new ModuleException( 'Bootstrap entry for ' . $name . ' must be a callable, or omitted.' );
			}

			if ( null !== $initializer ) {
				$this->configure( $name, $initializer );
			}

			/*
			 * Listing a class is what queues it, and nothing here asks what the
			 * class is. That is the whole reason this file is modules only: with
			 * one meaning per entry there is no kind to determine, so reading it
			 * compiles nothing -- the classes load when `run()` builds them.
			 *
			 * A service listed here is not an error, just pointless: it gets
			 * built a little earlier than it needed to be.
			 */
			$this->modules->set_autoload( $name );
		}
	}

	/**
	 * Make this plugin reachable by `wp zt`, and by nothing else.
	 *
	 * The devtool commands read a plugin from its files -- `zestry.json`,
	 * `bootstrap.php`, what is on disk -- which answers what a plugin is
	 * *declared* to be. It cannot answer what it *became*: a slug the entry file
	 * passed explicitly, a root an initializer moved. Those live only on the
	 * instance WordPress just built, and that instance already exists by the
	 * time a `wp zt` command runs. This publishes it rather than rebuilding it.
	 *
	 * Guarded by `ZESTRY_DEVTOOL`, which this package's autoload shim defines only
	 * after establishing that this is a `wp` run from inside a plugin requiring
	 * it. A web request never defines it, so nothing here runs on a page load.
	 *
	 * A plain global, deliberately. A static on this class would be per-copy:
	 * every plugin owns its own rewritten `Plugin`, so two plugins on one site
	 * have two unrelated classes and two unrelated statics, and a devtool
	 * looking for "the plugin here" would find whichever namespace it happened
	 * to be compiled against. The key is the plugin's own directory, which is
	 * also what `ConsumerPlugin` resolves from the working directory, so the
	 * lookup is exact rather than "the last one to run".
	 *
	 * @return void
	 */
	private function expose_to_devtool(): void {
		if ( ! \defined( 'ZESTRY_DEVTOOL' ) ) {
			return;
		}

		if ( ! isset( $GLOBALS['zestry_runtime_plugins'] ) || ! \is_array( $GLOBALS['zestry_runtime_plugins'] ) ) {
			$GLOBALS['zestry_runtime_plugins'] = array();
		}

		$GLOBALS['zestry_runtime_plugins'][ \dirname( $this->entry_file ) ] = $this;
	}
}
