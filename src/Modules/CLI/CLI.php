<?php

/**
 * CLI API: CLI module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\CLI;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Closure;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Services\Path;

/**
 * Discovers plugin WP-CLI commands and registers them with WP-CLI.
 *
 * A commands directory contains PHP files, one per command, each returning a
 * {@see Command} instance. The module registers every one it finds under the
 * plugin slug, so adding a command means dropping a file in place rather than
 * writing another `WP_CLI::add_command()` call.
 *
 * Command files can be organized in subdirectories. A file at
 * `commands/cache/clear.php`, for example, is registered as
 * `plugin-slug cache clear`, where `plugin-slug` is the plugin slug.
 *
 * > [!IMPORTANT]
 * > **A name can be a command or a namespace, never both.** WP-CLI does not
 * > allow a command to have subcommands, so `commands/cache.php` cannot sit
 * > alongside a `commands/cache/` directory. Pick one: either `cache` is a
 * > command, or it is the namespace holding `cache clear`. Discovery checks
 * > this before loading anything and throws `\InvalidArgumentException`
 * > naming both files if they collide, which aborts the `wp` invocation.
 *
 * A discovered file that returns anything other than a {@see Command} throws
 * `DiscoveryException`, as does a commands directory you named yourself with
 * {@see set_commands_root()} that does not exist.
 *
 * @setup
 * Register an initializer only to point the module at a non-default directory.
 *
 * ```
 * // bootstrap.php
 * return array(
 *     CLI::class => static function ( CLI $cli ): void {
 *         $cli->set_commands_root( 'cli/commands' );
 *     },
 * );
 * ```
 */
class CLI extends Module {

	use WithFolderWalker;

	/**
	 * Default plugin-relative directory of command files.
	 */
	const DEFAULT_COMMANDS_ROOT = 'commands';

	/**
	 * Path module injected by the plugin to resolve the command directory.
	 *
	 * @var Path
	 */
	public Path $path;

	/**
	 * Plugin-relative directory of command files.
	 *
	 * @var string
	 */
	private string $commands_root = self::DEFAULT_COMMANDS_ROOT;

	/**
	 * Whether the directory above was named deliberately.
	 *
	 * A missing directory means two different things. Named by
	 * {@see set_commands_root()} and absent: a typo, and registering nothing
	 * silently would hide it. Never named, and the default is absent: this
	 * plugin has none of these yet, which is ordinary -- adding the module
	 * before writing the first file should not take the site down.
	 *
	 * @var bool
	 */
	private bool $commands_root_was_set = false;

	/**
	 * Set the plugin-relative directory that contains command files.
	 *
	 * Call this from the module initializer before the plugin boots the module
	 * to override the default `commands` directory.
	 *
	 * Naming a directory is what makes its absence fatal. Discovery runs at
	 * `init`, and if the directory you name here is not there it throws a
	 * `DiscoveryException` then -- so a typo in your initializer fails loud on
	 * every `wp` invocation rather than registering nothing and leaving you to
	 * wonder why your commands are missing. The *default* `commands` directory
	 * being absent is deliberately not an error: a plugin that has not written
	 * its first command yet should still boot.
	 *
	 * @param string $commands_root Plugin-relative directory of command files.
	 * @return void
	 * @throws DiscoveryException When the directory named here does not exist at boot, or a file beneath it returns something other than a Command instance.
	 */
	public function set_commands_root( string $commands_root ): void {
		$this->commands_root         = $commands_root;
		$this->commands_root_was_set = true;
	}

	/**
	 * Wire (if applicable) and register an already-built command instance
	 * under a WP-CLI command name, namespaced under the plugin slug.
	 *
	 * Use this when a module of your own builds its command instances in PHP
	 * instead of shipping a file in `commands/`. The command is wired and
	 * namespaced exactly as a discovered one is, and needs no file at all.
	 * `Migrations` works this way: it registers `migrations run` and
	 * `migrations list` here, so `wp {slug} migrations run` exists the moment
	 * the module is added, with nothing to generate or maintain.
	 *
	 * `$name` is plugin-relative, matching {@see walk_and_load()}'s own
	 * behavior of prefixing every discovered command with the slug -- pass
	 * only the command's own name/namespace, never the slug itself.
	 *
	 * Only instances of {@see Command} are passed to
	 * {@see \Zestry\WPToolkit\Kernel\Plugin::wire()}, since the plugin-assignment and
	 * property injection it performs is meaningful only for that base class;
	 * an instance of some other type is registered as-is, on the assumption
	 * that it exposes a compatible `handle()` method without needing plugin
	 * services.
	 *
	 * This is a thin instance-side wrapper over {@see register_command_for()},
	 * which is `static` precisely so a module can register a command without
	 * holding -- and therefore without booting -- a CLI instance. Prefer the
	 * static form from another module; see `Migrations`.
	 *
	 * @param string $name     The command name relative to the plugin slug, e.g. `'migrations run'`.
	 * @param object $instance The command instance; wired automatically if it is a {@see Command}.
	 * @return void
	 */
	public function register_command( string $name, object $instance ): void {
		self::register_command_for( $this->get_plugin(), $name, $instance );
	}

	/**
	 * Resolve the commands root and register every command file beneath it.
	 *
	 * The configured directory is resolved through the Path module rather than
	 * used as a raw relative path, so discovery finds the plugin's commands
	 * regardless of the directory WP-CLI itself was invoked from.
	 *
	 * @return void
	 * @throws DiscoveryException When a commands directory named by set_commands_root() does not exist.
	 *
	 * @internal
	 */
	public function register_commands(): void {
		// Resolve relative to the plugin root so discovery does not depend on the CWD.
		$root_dir = $this->path->get_plugin_path( $this->commands_root );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			if ( ! $this->commands_root_was_set ) {
				return;
			}

			throw DiscoveryException::missing_root( 'Commands', $root_dir, 'set_commands_root()' );
		}

		$command_prefix = $this->get_plugin()->get_slug();
		$this->walk_and_load( $root_dir, $command_prefix );
	}

	/**
	 * Discover and register WP-CLI commands from the configured directory.
	 *
	 * Only runs under WP-CLI; on a normal request this is a no-op, since nothing
	 * needs command registration outside of a CLI invocation.
	 *
	 * Discovery is deferred to `init` rather than run inline, matching every
	 * sibling discovery module (Ajax, Cron and PostTypes defer to `init`,
	 * AdminPages to `admin_menu`, RestApi to `rest_api_init`). Walking the
	 * filesystem at boot would `require` every command file in a materially
	 * earlier environment than an action/page/route/schedule file sees, and
	 * WP-CLI does not need it that early -- dispatch happens well after `init`.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function on_boot(): void {
		if ( ! $this->get_plugin()->is_wp_cli() ) {
			return;
		}

		$this->on_wp_init(
			static function ( self $module ): void {
				$module->register_commands();
			}
		);
	}

	/**
	 * Discover all command files and translate their directories into namespaces.
	 *
	 * Walks `$root_dir` recursively so nested directories become nested
	 * command namespaces (see the class docblock for the `cache/clear.php` example).
	 * A file's directory is derived with `dirname()`, which returns `'.'` for a file
	 * sitting directly in the command root; that value is not itself a namespace
	 * segment, so it is special-cased to an empty prefix list rather than being
	 * exploded into one.
	 *
	 * Every command's word path is computed and checked for namespace/leaf
	 * collisions (see {@see assert_no_namespace_collisions()}) before any file is
	 * required, so a colliding layout fails loud with every command file still
	 * left un-required rather than partially registered.
	 *
	 * @param string $root_dir       Absolute command directory.
	 * @param string $command_prefix First word of each registered command.
	 * @return void
	 * @throws DiscoveryException When two files resolve to one command name.
	 * @throws \InvalidArgumentException When a command name is also used as a subdirectory,
	 *                                    since WP-CLI cannot attach subcommands to a leaf command.
	 */
	private function walk_and_load( string $root_dir, string $command_prefix ): void {
		$files = $this->walk_folder( $root_dir, array( 'php' ), 0 );

		$commands = array();

		$seen = array();

		foreach ( $files as $file ) {
			// The path's own segments: a directory is a namespace and the filename
			// is the command. Lowercased only, because WP-CLI dispatches on a
			// lowercase name -- not respelled, so the file still says what it is.
			$relative = \dirname( $file );
			$prefixes = '.' === $relative ? array() : \explode( '/', $relative );
			$words    = array( $command_prefix, ...$prefixes, \strtolower( \pathinfo( $file, PATHINFO_FILENAME ) ) );
			$name     = \implode( ' ', $words );

			if ( isset( $seen[ $name ] ) ) {
				throw DiscoveryException::name_collision( 'commands', $name, $seen[ $name ], $file );
			}

			$seen[ $name ] = $file;
			$commands[]    = array(
				'file'  => $file,
				'words' => $words,
			);
		}

		$this->assert_no_namespace_collisions( $commands );

		foreach ( $commands as $command ) {
			// Drop the leading plugin-slug word: register_command() (which
			// load_command() delegates to) re-adds it itself, the same as
			// every other caller of that method.
			$this->load_command(
				$root_dir . '/' . $command['file'],
				\array_slice( $command['words'], 1 )
			);
		}
	}

	/**
	 * Fail loud when one command's full name is also a namespace prefix of another.
	 *
	 * WP-CLI registers each command file as either a leaf `Subcommand` (which can
	 * never have children, per WP_CLI\Dispatcher\Subcommand::can_have_subcommands())
	 * or a `CompositeCommand` namespace (which can nest to any depth). A file at
	 * `commands/test-1.php` alongside `commands/test-1/test-2.php` asks WP-CLI to
	 * use the name `test-1` as both at once, which throws a bare
	 * "'wp {slug} test-1' can't have subcommands." exception with no indication of
	 * which two files are responsible. Detecting the collision here up front, across
	 * every discovered command rather than one at a time, produces a message that
	 * names both files while nothing has been `require`d yet.
	 *
	 * @param array<int, array{file: string, words: string[]}> $commands Discovered commands.
	 * @return void
	 * @throws \InvalidArgumentException When a command's word path is a strict prefix of another's.
	 */
	private function assert_no_namespace_collisions( array $commands ): void {
		foreach ( $commands as $command ) {
			foreach ( $commands as $other ) {
				if ( $command === $other ) {
					continue;
				}

				$words       = $command['words'];
				$other_words = $other['words'];

				if ( \count( $words ) >= \count( $other_words ) ) {
					continue;
				}

				if ( \array_slice( $other_words, 0, \count( $words ) ) === $words ) {
					throw new \InvalidArgumentException(
						\sprintf(
							'Command name collision: "%1$s" (%2$s) is also used as a subdirectory by "%3$s" (%4$s). '
								. 'A command file cannot share its name with a folder of commands beneath it.',
							\implode( ' ', $words ),
							$command['file'],
							\implode( ' ', $other_words ),
							$other['file']
						)
					);
				}
			}
		}
	}

	/**
	 * Require a command file, initialize supported command objects, and register it.
	 *
	 * A command file is expected to `return` an object rather than declare a class
	 * that this method instantiates itself, so a command file is free to build its
	 * instance however it needs to.
	 *
	 * A discovered file must return a {@see Command}, matching every sibling
	 * discovery module -- a near-miss (a class that forgot `extends Command`)
	 * would otherwise register unwired and fail later inside `handle()` as an
	 * uninitialized-typed-property fatal, far from the file that caused it.
	 * This is deliberately stricter than {@see register_command()}, which stays
	 * lenient because it is the documented PHP-side escape hatch for a module
	 * registering a command object it built itself.
	 *
	 * @param string   $class_path Full path to the command file.
	 * @param string[] $words      The command's full word path, already normalized, without the plugin slug.
	 * @return void
	 * @throws DiscoveryException When the file does not return a Command instance.
	 */
	private function load_command( string $class_path, array $words ): void {
		$instance = require $class_path;

		if ( ! $instance instanceof Command ) {
			throw new DiscoveryException(
				\sprintf(
					'The file "%s" must return an instance of %s. Got: %s',
					$class_path,
					Command::class,
					\is_object( $instance ) ? $instance::class : \gettype( $instance )
				)
			);
		}

		// The name is not re-derived here. Discovery already computed every word
		// of it, and computing it twice is what let the two spellings disagree.
		$this->register_command( \implode( ' ', $words ), $instance );
	}

	/**
	 * Wire and register a command instance without needing a CLI instance.
	 *
	 * `static`, and taking the plugin as its first argument, so you never have
	 * to resolve the CLI module to reach it. That matters: resolving a module
	 * boots it, and CLI's boot walks `commands/` and throws when that directory
	 * is absent -- so a plugin that added `migrations` but wanted no file-based
	 * commands would fail on every `wp` invocation.
	 *
	 * If you already hold a CLI instance, {@see register_command()} is the same
	 * thing without the first argument.
	 *
	 * The plugin slug is prepended here rather than by the caller, so every
	 * command name is namespaced identically whether it came from file
	 * discovery or from a module registering its own -- no caller can forget
	 * the prefix, and `$name` stays plugin-relative in both paths.
	 *
	 * @param Plugin $plugin   The plugin the command belongs to; supplies wiring and the slug.
	 * @param string $name     The command name relative to the plugin slug, e.g. `'migrations run'`.
	 * @param object $instance The command instance; wired automatically if it is a {@see Command}.
	 * @return void
	 */
	public static function register_command_for( Plugin $plugin, string $name, object $instance ): void {
		if ( ! \is_subclass_of( $instance, Command::class ) ) {
			\WP_CLI::add_command( $plugin->get_namespaced_name( $name, ' ' ), array( $instance, 'handle' ) );

			return;
		}

		// Wire the command so it behaves like a module: plugin assigned and
		// declared module properties injected before WP-CLI invokes handle().
		$plugin->wire( $instance );

		// Wired first, so is_enabled() can read an injected service. A command
		// switched off is never added, so `wp help` does not list it either.
		if ( ! $instance->is_enabled() ) {
			return;
		}

		/*
		 * Registered as a closure rather than the bare `handle` callable so the
		 * invocation's arguments reach the instance before it runs: prompt
		 * helpers such as confirm() read `--yes` off them, and a helper several
		 * calls deep would otherwise need every caller in between to thread the
		 * arguments through purely to pass them on.
		 *
		 * Bound to the command so the instance stays reachable from the
		 * registered callable -- `( new ReflectionFunction( $callable ) )
		 * ->getClosureThis()` -- which a bare `use` capture would hide.
		 */
		$callable = Closure::bind(
			function ( array $args, array $assoc_args ): void {
				$this->set_arguments( $args, $assoc_args );
				$this->handle( $args, $assoc_args );
			},
			$instance,
			$instance
		);

		\WP_CLI::add_command( $plugin->get_namespaced_name( $name, ' ' ), $callable );
	}
}
