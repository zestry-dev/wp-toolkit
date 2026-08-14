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
 * It holds every module the plugin is made of, builds each one, and answers what
 * the plugin knows about itself -- its slug, its own directory, the headers its
 * entry file declares. Nothing has to be constructed by hand: a module reaches
 * another with `$this->with( Path::class )`, and this is what hands it over.
 *
 * Every module is declared in a `bootstrap.php`, which {@see bootstrap()} reads
 * and {@see run()} builds. `wp zt init` creates that file and `wp zt add`
 * appends to it, so a module works as soon as it is copied and the entry file
 * never has to change. {@see declare_modules()} is public and takes the same
 * entries, so a plugin that prefers to declare everything in the entry file can
 * do that instead, and the two approaches can be combined.
 *
 * **That file is the whole inventory.** Nothing is built without being listed
 * there, and asking for an undeclared class throws -- so reading it tells you
 * what the plugin is made of, and that stays true. To reach a module your plugin
 * may not have declared, emit a hook instead of asking for it, the way `Options`
 * and `Cron` reach `Log`.
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
 * // acme-plugin.php
 * use Acme\Plugin\Core\Kernel\Plugin;
 *
 * require_once __DIR__ . '/vendor/autoload.php';
 *
 * function acme_plugin(): Plugin {
 *     static $plugin = null;
 *
 *     $plugin ??= ( new Plugin( __FILE__ ) )->bootstrap()->run();
 *
 *     return $plugin;
 * }
 *
 * acme_plugin();
 * ```
 *
 * @example The bootstrap file
 * Declares every module the plugin uses, with the configuration each requires.
 * `wp zt init` creates the file and `wp zt add` appends to it, so a module
 * is active as soon as it is copied.
 *
 * With this in place, a file returned from `actions/save-profile.php` becomes
 * an AJAX action (see {@see \Zestry\WPToolkit\Modules\Ajax\AjaxAction}), a file returned
 * from `commands/greet.php` becomes the WP-CLI command `wp acme-plugin greet`
 * (see {@see \Zestry\WPToolkit\Modules\CLI\Command}), and a file returned from
 * `admin-pages/settings.php` becomes an admin menu page (see
 * {@see \Zestry\WPToolkit\Modules\AdminPages\AdminPage}) — none of them need registering
 * by hand.
 *
 * Every entry is a module, and listing one is what makes it exist. A module
 * needing no configuration is written bare, as `Ajax::class` and
 * `AdminPages::class` below; one that needs some gets an array, whose
 * `configure` is the callback that sets it up.
 *
 * ```
 * // bootstrap.php
 * use Acme\Plugin\Core\Modules\AdminPages\AdminPages;
 * use Acme\Plugin\Core\Modules\Ajax\Ajax;
 * use Acme\Plugin\Core\Modules\Cron\Cron;
 *
 * return array(
 *     Cron::class => array(
 *         'configure' => static function ( Cron $cron ): void {
 *             $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
 *         },
 *     ),
 *     Ajax::class,
 *     AdminPages::class,
 * );
 * ```
 *
 * @example Declaring modules in the entry file instead
 * `bootstrap.php` is optional -- {@see bootstrap()} hands what it read to
 * {@see declare_modules()}, which is public and takes the same entries, so a
 * plugin that prefers a single file calls it directly.
 *
 * ```
 * // acme-plugin.php
 * function acme_plugin(): Plugin {
 *     static $plugin = null;
 *
 *     $plugin ??= ( new Plugin( __FILE__ ) )
 *         ->declare_modules(
 *             array(
 *                 Path::class,
 *                 Cron::class => array(
 *                     'configure' => static function ( Cron $cron ): void {
 *                         $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
 *                     },
 *                 ),
 *             )
 *         )
 *         ->run();
 *
 *     return $plugin;
 * }
 *
 * acme_plugin();
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
	 * Repository that builds and holds this plugin's modules.
	 *
	 * @var ModulesRepository
	 */
	private ModulesRepository $modules;

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

		$this->modules = new ModulesRepository( $this );
	}

	/**
	 * Configure a module before the plugin builds it.
	 *
	 * The callback runs when the module is built, after it has the plugin and
	 * before `on_boot()`, so it can set what boot depends on. The same callback
	 * a `bootstrap.php` entry's `configure` key takes -- this is for a plugin
	 * that prefers to keep its configuration in the entry file.
	 *
	 * ```
	 * $plugin->configure( Cron::class, function ( Cron $cron ) {
	 *     $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
	 * } );
	 * ```
	 *
	 * **This does not declare the module.** It remembers a callback against a
	 * name and loads nothing; the module still has to be listed, either in
	 * `bootstrap.php` or through {@see declare_modules()}, for anything to build it.
	 *
	 * @template T of object
	 * @param class-string<T> $name         The class name to configure.
	 * @param callable(T $instance, self $plugin): void $configurator Callback receiving the module and plugin.
	 * @return $this Fluent interface for method chaining.
	 */
	public function configure( string $name, callable $configurator ): self {
		$this->modules->configure( $name, $configurator );
		return $this;
	}

	/**
	 * Declare the modules this plugin is made of.
	 *
	 * What {@see bootstrap()} calls with the entries it read, and what an entry
	 * file calls directly when it prefers to keep its declarations in one file.
	 * Both take the same two entry shapes, because they are the same
	 * declaration written in different places -- a bare class name, or a class
	 * name with a configuration array:
	 *
	 * ```
	 * CLI::class,                   // bare
	 * Options::class => array(      // configured
	 *     'boots_on'  => 'init',
	 *     'priority'  => 10,
	 *     'configure' => static function ( Options $options ): void { … },
	 * ),
	 * ```
	 *
	 * Both shapes declare the class, and declaring is what makes a module exist:
	 * nothing outside this list is ever built. `configure` runs immediately
	 * before `on_boot()`, which is what makes a `__()` in there safe once a hook
	 * is named. `boots_on` lives in the entry and nowhere else -- this is where a
	 * plugin says what it has and when each part starts, so a default on the
	 * class would make a bare listing boot on a hook nothing mentions.
	 *
	 * Configuration is an array and never a bare callable, so all three keys are
	 * written the same way: adding a `boots_on` to an entry that already
	 * configures the module is one more line rather than a rewrite of the entry.
	 *
	 * Nothing here loads a class. An entry remembers a name and a `configure`
	 * remembers a closure against it, so a list naming a dozen classes reads
	 * without compiling any of them -- they compile when {@see run()} builds them.
	 *
	 * Every shape, in one list:
	 *
	 * ```
	 * ( new Plugin( __FILE__ ) )
	 *     ->declare_modules(
	 *         array(
	 *             // Bare: nothing to configure, built as `run()` reaches it.
	 *             Path::class,
	 *
	 *             // Configured.
	 *             Cron::class => array(
	 *                 'configure' => static function ( Cron $cron ): void {
	 *                     $cron->add_custom_interval( 'quarter_hourly', 900, 'Quarter hourly' );
	 *                 },
	 *             ),
	 *
	 *             // Held back until a hook, with nothing to configure.
	 *             Blocks::class => array(
	 *                 'boots_on' => 'init',
	 *             ),
	 *
	 *             // Held back, and ordered against everything else on that hook.
	 *             IconsLibrary::class => array(
	 *                 'boots_on' => 'init',
	 *                 'priority' => 100,
	 *             ),
	 *
	 *             // All three. `configure` runs on the hook too, right before
	 *             // `on_boot()`, which is what makes the `__()` in it safe.
	 *             Abilities::class => array(
	 *                 'boots_on'  => 'init',
	 *                 'priority'  => 20,
	 *                 'configure' => static function ( Abilities $abilities ): void {
	 *                     $abilities->add_categories(
	 *                         array( 'acme-billing' => __( 'Acme billing', 'acme-plugin' ) )
	 *                     );
	 *                 },
	 *             ),
	 *         )
	 *     )
	 *     ->run();
	 * ```
	 *
	 * @param array<array-key, mixed> $entries The entries `bootstrap.php` would hold.
	 * @return $this
	 * @throws ModuleException When an entry names no class, or its configuration is not an array.
	 */
	public function declare_modules( array $entries = array() ): self {
		foreach ( $entries as $key => $value ) {
			$name  = \is_string( $key ) ? $key : $value;
			$entry = \is_string( $key ) ? $value : null;

			if ( ! \is_string( $name ) || '' === $name ) {
				throw new ModuleException( 'Bootstrap entries must name a class.' );
			}

			if ( null !== $entry && ! \is_array( $entry ) ) {
				throw ModuleException::bootstrap_entry_shape( $name, \get_debug_type( $entry ) );
			}

			$configurator = null === $entry ? null : ( $entry['configure'] ?? null );

			if ( null !== $configurator && ! \is_callable( $configurator ) ) {
				throw new ModuleException(
					'The `configure` for ' . $name . ' must be a callable, or left out.'
				);
			}

			if ( null !== $configurator ) {
				$this->configure( $name, $configurator );
			}

			if ( null !== $entry && isset( $entry['boots_on'] ) ) {
				$this->modules->set_boot_hook(
					$name,
					(string) $entry['boots_on'],
					isset( $entry['priority'] ) ? (int) $entry['priority'] : null
				);
			}

			// Listing a class is what makes it exist. Nothing here asks what the
			// class is, so reading the file compiles none of the classes it names.
			$this->modules->declare_module( $name );
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
	 *     Cron::class => array(
	 *         'configure' => static function ( Cron $cron ): void {
	 *             $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
	 *         },
	 *     ),
	 *     Options::class,
	 * );
	 * ```
	 *
	 * **A module needing no configuration is written bare**, as `Options::class,`
	 * above. One that needs some gets an array, which takes three keys:
	 *
	 * | Key | What it does |
	 * |---|---|
	 * | `configure` | Runs when the module is built, immediately before `on_boot()`. The same callback {@see configure()} takes. |
	 * | `boots_on` | A WordPress hook to boot on, for a module that cannot do its work as the plugin loads. Without it the module is built the moment `run()` reaches it. |
	 * | `priority` | What `boots_on` binds at. Defaults to 10. |
	 *
	 * Configuration is always the array, never a bare callback, so adding a
	 * `boots_on` to an entry that already configures the module is one more line
	 * rather than a rewrite:
	 *
	 * ```
	 * Blocks::class => array(
	 *     'boots_on'  => 'init',
	 *     'configure' => static function ( Blocks $blocks ): void {
	 *         $blocks->add_categories( $categories );
	 *     },
	 * ),
	 * ```
	 *
	 * A module that names a `boots_on` cannot be built before that hook: asking
	 * for it beforehand throws, naming the hook, rather than booting it on the
	 * wrong side of whatever it was waiting for.
	 *
	 * **This file is the whole inventory of what the plugin is made of.** Every
	 * module is here -- the ones that act on their own and the ones that only
	 * work when called -- and nothing outside it is ever built: asking for an
	 * undeclared class throws rather than quietly constructing it. That is what
	 * makes reading this file worth doing.
	 *
	 * Nothing here loads a class. An entry remembers a name, and a `configure`
	 * remembers a closure against it, so a file naming a dozen classes reads
	 * without compiling any of them -- they load when `run()` builds them.
	 *
	 * A missing file is not an error. If there is no `bootstrap.php` the plugin
	 * is returned unchanged, so you can call this unconditionally from a
	 * template entry file and declare everything in the entry file itself.
	 *
	 * A plugin with a hand-written entry file needs none of this:
	 * {@see declare_modules()} takes the same entries directly, and the two
	 * approaches can be mixed.
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

		$this->declare_modules( $declared );

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
	 * // acme-plugin.php, inside the accessor that builds the plugin.
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
	 * Get a module the plugin declared.
	 *
	 * The same instance every time. Inside a module or anything the plugin
	 * wired, `$this->with( X::class )` is the shorter way to say this; use
	 * `get()` from an entry file, a template, or anywhere holding the plugin
	 * itself.
	 *
	 * **The module has to be declared.** Asking for one that is not throws,
	 * because `bootstrap.php` is the whole inventory of what the plugin is made
	 * of -- and that only holds while nothing is built without being listed
	 * there.
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to get.
	 * @return T The shared instance.
	 * @throws ModuleException If the class was never declared, or has not reached its `boots_on` hook.
	 * @throws ModuleNotFoundException If the class does not exist or does not extend Module.
	 * @throws CircularDependencyException If the dependency graph re-enters itself.
	 */
	public function get( string $name ): object {
		return $this->modules->get( $name );
	}

	/**
	 * Build a fresh, unshared instance of a module class.
	 *
	 * Unlike get(), never shared: every call returns a new instance. The
	 * configurator runs before boot(). Use it for a second instance of a module,
	 * such as a dedicated Options group:
	 *
	 * ```
	 * $api_options = $plugin->make( Options::class, function ( Options $o ) {
	 *     $o->set_group_name( 'api' );
	 * } );
	 * ```
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to construct.
	 * @param callable(T $instance, self $plugin): void|null $configurator Optional callback run before boot.
	 * @return T A new instance.
	 * @throws ModuleNotFoundException If the class does not exist or does not extend Module.
	 * @throws CircularDependencyException If the dependency graph re-enters itself.
	 */
	public function make( string $name, ?callable $configurator = null ): object {
		return $this->modules->make( $name, $configurator );
	}

	/**
	 * Give an object the plugin, so it can reach modules through `with()`.
	 *
	 * Lets an object the plugin did not build -- a CLI command, an AJAX action,
	 * an admin page loaded from a file -- reach every module exactly the way a
	 * module does, without being one itself. The object must implement
	 * {@see PluginAware}, which the {@see \Zestry\WPToolkit\Kernel\Traits\WithPlugin}
	 * trait satisfies.
	 *
	 * @template T of PluginAware
	 * @param T $instance The object to wire.
	 * @return T The same instance, now holding the plugin.
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
		$constant_name = self::get_debug_constant( $this->get_slug() );

		return \defined( $constant_name ) && \constant( $constant_name ) === true;
	}

	/**
	 * Build every declared module, and run an optional ready callback.
	 *
	 * Call this from the plugin entry file once the modules are declared. It runs
	 * synchronously, so the caller controls timing: invoke it directly at plugin
	 * load, or from inside a `plugins_loaded`/`init` hook when a later point is
	 * needed. Every declared class is built first -- booting as it goes, unless
	 * it named a `boots_on` -- then the callback runs with all of them available.
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
	 * @throws ModuleException When a declared class cannot be built, or a discovery module cannot read its root.
	 * @throws \Throwable Whatever a module's own `on_boot()` raises, unchanged.
	 */
	public function run( ?callable $on_boot_callback = null ): self {
		$this->modules->run();
		if ( $on_boot_callback ) {
			( $on_boot_callback )( $this );
		}

		$this->expose_to_devtool();

		return $this;
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

	/**
	 * The name of a plugin's own debug constant.
	 *
	 * `{SLUG}_DEBUG`, upper-cased with dashes turned into underscores, so a plugin
	 * slugged `acme-crm` reads `ACME_CRM_DEBUG`. {@see is_plugin_debug()} is what
	 * asks whether it is set.
	 *
	 * Static, and taking the slug rather than reading its own, so tooling can name
	 * the constant for a plugin that is not running -- `wp zt debug` writes this
	 * name into `wp-config.php`, and a second spelling of the rule would be a
	 * command that turns on a constant nothing reads.
	 *
	 * @param string $slug The plugin slug.
	 * @return string
	 */
	public static function get_debug_constant( string $slug ): string {
		return \str_replace( '-', '_', \strtoupper( $slug ) ) . '_DEBUG';
	}
}
