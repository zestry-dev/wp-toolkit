<?php

/**
 * DevTools API: MakeCommand base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools\Abstracts;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\DevTools\BootstrapFile;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\DevTools\DeclarationResult;
use Zestry\WPToolkit\DevTools\Formatter;
use Zestry\WPToolkit\DevTools\ParentClass;
use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\DevTools\StubRenderer;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Services\Path;
use Zestry\WPToolkit\DevTools\RuntimePlugin;

/**
 * MakeCommand class.
 *
 * Shared flow behind every `wp zt make <type> <name>` subcommand (see the
 * concrete classes under `commands/make/`): read the project's zestry.json for
 * its namespace, render the type's stub with the name/title plus whatever
 * {@see get_extra_values()} contributes, and write it into the type's
 * conventional directory (or `--dir=` when given), refusing to overwrite an
 * existing file without confirmation.
 *
 * A concrete subcommand supplies only what makes it different from the
 * others: its stub filename, its default destination directory, and,
 * optionally, extra placeholder values gathered from flags or prompts.
 * Unlike `wp zt add`, none of this touches the module registry or copies
 * any toolkit source -- it only writes one new file with the project's own
 * namespace already filled in.
 */
abstract class MakeCommand extends Command {

	use WithFolderWalker;

	/**
	 * @var ConsumerPlugin
	 */
	public ConsumerPlugin $consumer_plugin;

	/**
	 * @var ZestryConfig
	 */
	public ZestryConfig $zestry_config;

	/**
	 * @var StubRenderer
	 */
	public StubRenderer $stub_renderer;

	/**
	 * @var Formatter
	 */
	public Formatter $formatter;

	/**
	 * @var BootstrapFile
	 */
	public BootstrapFile $bootstrap_file;

	/**
	 * @var Path
	 */
	public Path $path;

	/**
	 * @var RuntimePlugin
	 */
	public RuntimePlugin $runtime;

	/**
	 * @var ParentClass
	 */
	public ParentClass $parent_class;

	/**
	 * Parse errors in the files just written, keyed by absolute path.
	 *
	 * @var array<string, string>
	 */
	private array $parse_errors = array();

	/**
	 * Generate a new file from this type's stub.
	 *
	 * Requires `wp zt init` to have already run in this plugin (it reads
	 * zestry.json for the namespace to fill into the generated file).
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local name, e.g. 'send-welcome-email'. Becomes the filename
	 * (`{name}.php`) under the type's conventional directory.
	 *
	 * [--dir=<dir>]
	 * : Write into this plugin-relative directory instead of the type's
	 * default -- use this when the consuming plugin configured a module's
	 * root to something other than its default.
	 *
	 * [--extends=<class>]
	 * : Extend your own intermediate abstract instead of the toolkit's base.
	 * The generated file then stubs the methods *that* class leaves abstract.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		if ( \count( $args ) < 1 ) {
			$this->error(
				'Usage: wp zt make ' . static::get_type() . ' <name>'
					. ( $this->allows_custom_dir() ? ' [--dir=<dir>]' : '' )
			);
			return;
		}

		// Accept a trailing ".php" on the command line without doubling it up.
		$given = (string) \preg_replace( '/\.php$/', '', (string) $args[0] );
		$name  = $this->normalize_name( $given );

		// Said out loud, with the reason, because the file that lands is not the
		// one that was asked for. Only the few types whose destination refuses a
		// name outright reach this -- everywhere else the name is written as given.
		if ( $name !== $given ) {
			$this->warning(
				\sprintf(
					'Writing "%s" rather than "%s": %s',
					$name,
					$given,
					$this->get_name_constraint()
				)
			);
		}

		$plugin_root = $this->consumer_plugin->get_plugin_root();

		try {
			$config = $this->zestry_config->read( $plugin_root );
		} catch ( \RuntimeException $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}

		/*
		 * Two namespaces, and a stub has to pick the right one for every import.
		 * `namespace` is the plugin's own, where generated code lives; `copied`
		 * is the same name plus the `Core/` segment, where toolkit source landed.
		 * Deriving the second from `Copier` rather than spelling `\Core\` into
		 * eleven stubs keeps the segment in the one place that already owns it.
		 */
		$namespace = \rtrim( $config['namespace'], '\\' );

		$values = array(
			'namespace'        => $namespace,
			'copied_namespace' => Copier::get_target_namespace( $namespace ),
			'name'             => $name,
			'title'            => $this->stub_renderer->to_title( $name ),
		);

		$values = \array_merge( $values, $this->get_extra_values( $name, $assoc_args ) );

		// Before --extends, which resolves against the base this may install.
		$this->ensure_base_installed( $namespace );

		$extends = $this->takes_shared_extends() ? $this->get_flag( $assoc_args, 'extends', null ) : null;
		$parent  = null;

		if ( null !== $extends ) {
			$parent = $this->get_parent_values( $extends, $namespace );

			// Said and stopped in get_parent_values(): a bad parent is worth
			// refusing before a file exists, since every way it is wrong is one
			// that only shows up at boot, on every request.
			if ( null === $parent ) {
				return;
			}

			$values = \array_merge( $values, $parent );
		}

		if ( ! $this->allows_custom_dir() && null !== $this->get_flag( $assoc_args, 'dir', null ) ) {
			$this->error(
				\sprintf(
					'`%s` does not take --dir: it belongs in "%s" and nowhere else.',
					static::get_type(),
					$this->get_default_dir( $config )
				)
			);

			return;
		}

		$dir = $this->allows_custom_dir()
			? $this->get_flag( $assoc_args, 'dir', $this->get_default_dir( $config ) )
			: $this->get_default_dir( $config );

		/*
		 * One shared stub for every type once a parent is named. The type's own
		 * stub explains the base class it extends -- which methods to override,
		 * what each default means -- and none of that is true of someone else's
		 * abstract, whose whole purpose is to have settled those already.
		 */
		$stub = $this->path->get_plugin_path(
			'src/DevTools/stubs/' . ( null === $parent ? $this->get_stub() : 'extends.php.stub' )
		);

		$relative_path = $this->get_destination_path( $dir, $name );
		$destination   = Str::join_path( $plugin_root, $relative_path );

		try {
			$this->detect_collision( $plugin_root, $relative_path, $destination );
		} catch ( \InvalidArgumentException $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}

		/*
		 * A stub is either one file or a directory of them. The single-file case
		 * is every type but `block`, and resolves to an empty relative path so
		 * the loop below writes exactly the one destination it always did.
		 */
		$files = \is_dir( $stub ) ? $this->get_stub_files( $stub, $assoc_args ) : array( '' );

		foreach ( $files as $relative_stub ) {
			$target = '' === $relative_stub ? $destination : $destination . '/' . $this->get_written_name( $relative_stub );

			if ( \is_file( $target ) && ! $this->confirm( 'File "' . $target . '" already exists. Overwrite it?', false ) ) {
				$this->log( 'Cancelled.' );
				return;
			}
		}

		$written = array();

		foreach ( $files as $relative_stub ) {
			$source = '' === $relative_stub ? $stub : $stub . '/' . $relative_stub;
			$target = '' === $relative_stub ? $destination : $destination . '/' . $this->get_written_name( $relative_stub );

			if ( ! \is_dir( \dirname( $target ) ) ) {
				\wp_mkdir_p( \dirname( $target ) );
			}

			if ( false === \file_put_contents( $target, $this->stub_renderer->render( $source, $values ) ) ) {
				$this->error( 'Failed to write ' . $target );
				return;
			}

			$this->lint( $target );

			$written[] = $target;
		}

		/*
		 * Before after_write(), so a generated module is formatted before it is
		 * declared -- and so declare_generated_module() can format bootstrap.php
		 * knowing nothing else will touch it afterwards.
		 */
		$this->formatter->format( $plugin_root, $written );

		$this->after_write( $name, $plugin_root, $config );

		$this->success(
			\count( $written ) > 1
				? \sprintf( 'Created %s (%d files)', $relative_path, \count( $written ) )
				: 'Created ' . $relative_path
		);
	}

	/**
	 * The toolkit base a file of this type has to extend, or null for a type
	 * that extends nothing.
	 *
	 * Given relative to the copied namespace, the way a stub's own import
	 * writes it -- `Modules\Fields\Field` -- since the plugin's copy is what a
	 * generated file extends and what its own abstract extends in turn.
	 *
	 * **Returning null is what makes `--extends` unavailable**, and three types
	 * mean it: a `route` is built by `Route::get()` rather than by extending
	 * anything, and a `view` and a `page-view` are templates.
	 *
	 * @return string|null
	 */
	public function get_base_class(): ?string {
		return null;
	}

	/**
	 * Do anything the generated file needs beyond existing.
	 *
	 * Runs once, after every file is written and before the success message.
	 * Does nothing by default: a discovered file -- an action, a route, a
	 * schedule -- is found by its module the moment it exists, so nothing more
	 * is needed. Only `module` overrides this, since a plain module is
	 * discovered by nothing and has to be declared to be reachable.
	 *
	 * @param string                                                          $name        The local name given on the command line.
	 * @param string                                                          $plugin_root Absolute path to the consuming plugin's root.
	 * @param array{namespace: string, root: string, text_domain: string|null} $config      The project's zestry.json.
	 * @return void
	 */
	protected function after_write( string $name, string $plugin_root, array $config ): void {
	}

	/**
	 * Declare a generated class in the consumer's bootstrap.php.
	 *
	 * Shared by the two `make` types that produce a Module: being listed is the
	 * only thing that builds one, so generating the file without declaring it
	 * would leave a class that never runs.
	 *
	 * @param string $name        The name given on the command line.
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return void
	 */
	protected function declare_generated_module( string $name, string $plugin_root ): void {
		$segments   = $this->get_name_segments( $name );
		$class_name = (string) \array_pop( $segments );

		// Built from the same split the file was stamped with, so a nested
		// module is declared under the namespace it actually declares.
		$class = $this->get_generated_namespace( $segments ) . '\\' . $class_name;

		if ( ! $this->bootstrap_file->exists( $plugin_root ) ) {
			$this->log( 'No bootstrap.php found. Declare it in bootstrap.php to have the plugin build it.' );

			return;
		}

		/*
		 * A file that does not compile must not be built on every request:
		 * doing so would take the site down until it is fixed. So nothing is
		 * written to bootstrap.php at all, and the reason is logged where the
		 * consumer will look -- declaring it is one edit away once it parses.
		 */
		if ( array() !== $this->get_parse_errors() ) {
			$this->log( 'Not declared: the generated file does not parse yet. Fix it, then add it in bootstrap.php.' );

			return;
		}

		if ( DeclarationResult::Declared === $this->bootstrap_file->declare_module( $plugin_root, $class ) ) {
			// An edited file is worth formatting for the same reason a generated
			// one is: the appended entry lands in someone else's file, and should
			// not be the line that makes their lint fail.
			$this->formatter->format( $plugin_root, array( \rtrim( $plugin_root, '/\\' ) . '/bootstrap.php' ) );

			$this->log( 'Declared in bootstrap.php, so the plugin builds it and on_boot() runs.' );
		}
	}

	/**
	 * Split a name into its namespace segments and class name.
	 *
	 * Either separator is accepted: a path is what gets typed, a namespace is
	 * what it means, and both name the same thing here.
	 *
	 * @param string $name The name given on the command line.
	 * @return string[] Namespace segments, the class name last.
	 */
	protected function get_name_segments( string $name ): array {
		$segments = \preg_split( '#[/\\\\]+#', \trim( $name, '/\\\\' ) );

		return false === $segments ? array() : \array_values( \array_filter( $segments, 'strlen' ) );
	}

	/**
	 * The namespace a generated module belongs to, given its leading segments.
	 *
	 * Always under `{namespace}\Modules`, since PSR-4 ties a namespace to one
	 * directory and every module lives in one. `make module Services/Mailer`
	 * writes `{root}/Modules/Services/Mailer.php` declaring
	 * `{namespace}\Modules\Services` -- the directory and the namespace derived
	 * from the same name, so the two cannot disagree.
	 *
	 * @param string[] $segments Leading segments of the given name, without the class name.
	 * @return string
	 */
	protected function get_generated_namespace( array $segments ): string {
		$config    = $this->zestry_config->read( $this->consumer_plugin->get_plugin_root() );
		$namespace = \rtrim( $config['namespace'], '\\' ) . '\\Modules';

		foreach ( $segments as $segment ) {
			if ( 1 !== \preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $segment ) ) {
				$this->error(
					\sprintf( '"%s" is not a valid namespace segment, so PSR-4 cannot map it to a directory.', $segment )
				);
			}

			$namespace .= '\\' . $segment;
		}

		return $namespace;
	}

	/**
	 * Parse errors in the files this command just wrote, keyed by path.
	 *
	 * Consulted by {@see after_write()}, which runs once every file is on disk:
	 * a type that registers what it generated can then decline to, rather than
	 * wiring up something the next request will die on.
	 *
	 * @return array<string, string>
	 */
	protected function get_parse_errors(): array {
		return $this->parse_errors;
	}

	/**
	 * The stub filename, relative to this package's own `src/DevTools/stubs/`.
	 *
	 * @return string
	 */

	abstract protected function get_stub(): string;

	/**
	 * Whether this type accepts `--dir` at all.
	 *
	 * True for every discovery type: their root is configurable at runtime with
	 * a `set_*_root()` call, so where the file lives is genuinely the project's
	 * choice and zestry.json does not track it.
	 *
	 * The `module` type is the exception. A plain module is not a discovery
	 * convention with a root of its own -- it is found by its namespace, and
	 * PSR-4 ties that to one directory. A file written elsewhere would need a
	 * namespace to match, so honouring `--dir` there meant either stamping a
	 * namespace nothing autoloads or deriving one per invocation. One home is
	 * simpler than either, and it is the one the docs already name.
	 *
	 * @return bool
	 */
	protected function allows_custom_dir(): bool {
		return true;
	}

	/**
	 * The plugin-relative directory this type writes into by default.
	 *
	 * A default only for a type that {@see allows_custom_dir()}, since zestry.json
	 * does not track per-module discovery roots; for one that does not, it is
	 * the only destination. `$config` is the already-read zestry.json, used by the
	 * `module` type alone (its destination is `{$config['root']}/Modules`);
	 * every other type ignores it.
	 *
	 * @param array{namespace: string, root: string} $config The project's zestry.json.
	 * @return string
	 */
	abstract protected function get_default_dir( array $config ): string;

	/**
	 * The name this type will actually write, given the one asked for.
	 *
	 * Defaults to the name as typed, because for most types the filename *is* the
	 * identifier and respelling it would register something other than what was
	 * asked for -- a `post_type`, a taxonomy and a `meta_key` are database columns
	 * and are left alone.
	 *
	 * Overridden only by a type whose destination refuses a name outright: an
	 * ability and a block name are matched against `^[a-z0-9-]+$` by WordPress, an
	 * admin page slug has to survive a URL, and a shared package is an npm
	 * workspace. Writing the name as given there produces a file that fails at
	 * boot or at install, so those four canonicalise it and say so.
	 *
	 * @param string $name The local name given on the command line.
	 * @return string The name to write.
	 */
	protected function normalize_name( string $name ): string {
		return $name;
	}

	/**
	 * Why this type respelled the name, for the warning that says it did.
	 *
	 * Only reached when {@see normalize_name()} changed something, so only a type
	 * that overrides that needs one.
	 *
	 * @return string One clause, naming what refuses the original.
	 */
	protected function get_name_constraint(): string {
		return '';
	}

	/**
	 * Build the plugin-relative destination path for the generated file.
	 *
	 * Defaults to `{$dir}/{$name}.php`. Only `migration` overrides it, to prefix
	 * the filename with a timestamp so migrations run in authoring order; the
	 * stub placeholders still use the plain `$name`. A type whose stub is a
	 * directory returns a directory here instead, since that is what it writes.
	 *
	 * @param string $dir  The resolved destination directory (already `--dir=`-overridden if given).
	 * @param string $name The local name given on the command line.
	 * @return string The plugin-relative path, e.g. `schedules/cleanup.php`.
	 */
	protected function get_destination_path( string $dir, string $name ): string {
		return \trim( $dir, '/\\' ) . '/' . $name . '.php';
	}

	/**
	 * Whether one file of a directory stub should be written.
	 *
	 * Called once per file, with its path relative to the stub directory. True
	 * for everything by default; an override skips the files its flags did not
	 * ask for -- `block` leaves out `render.php.stub` unless `--dynamic` was
	 * given, so a block never declares a file that was not written.
	 *
	 * @param string $relative_stub The stub file's path relative to the stub directory, e.g. `render.php.stub`.
	 * @param array  $assoc_args    WP-CLI's named arguments.
	 * @return bool
	 */
	protected function should_write( string $relative_stub, array $assoc_args ): bool {
		return true;
	}

	/**
	 * The filename a stub is written under, with its `.stub` suffix removed.
	 *
	 * Stubs carry the extension so they stay out of the consuming toolchain: a
	 * bare `index.tsx` under `stubs/` would be type-checked and linted by
	 * anything walking this package, and `{{placeholder}}` tokens are not valid
	 * in most of the languages a stub is written in.
	 *
	 * @param string $relative_stub The stub file's path relative to the stub directory.
	 * @return string The path to write it to, relative to the destination.
	 */
	protected function get_written_name( string $relative_stub ): string {
		return (string) \preg_replace( '/\.stub$/', '', $relative_stub );
	}

	/**
	 * Gather any placeholder values beyond `namespace`/`name`/`title`.
	 *
	 * Runs after the destination is resolved and before the stub is rendered, so
	 * an override can prompt via `$this->ask()` only when the matching flag was
	 * not given. Contributes nothing by default; see `commands/make/route.php`.
	 *
	 * @param string $name       The local name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array<string, string>
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		return array();
	}

	/**
	 * Read a WP-CLI `--flag=value` argument, or a fallback when it was not given.
	 *
	 * @param array       $assoc_args WP-CLI's named arguments.
	 * @param string      $flag       The flag name, without the leading `--`.
	 * @param string|null $fallback   The value to use when the flag was not given.
	 * @return string|null
	 */
	protected function get_flag( array $assoc_args, string $flag, ?string $fallback ): ?string {
		return isset( $assoc_args[ $flag ] ) ? (string) $assoc_args[ $flag ] : $fallback;
	}

	/**
	 * Check the destination for a conflict that would prevent writing to it.
	 *
	 * Covers filesystem conflicts only: the destination exists as a directory, or
	 * an ancestor exists as a file. An existing destination *file* is not a
	 * collision -- the overwrite prompt handles that.
	 *
	 * A subcommand whose destination directory has its own naming rules beyond
	 * plain filesystem conflicts overrides this to check for those too before
	 * calling the parent implementation (see commands/make/command.php, which
	 * adds the leaf/namespace rule {@see \Zestry\WPToolkit\Modules\CLI\CLI} enforces for
	 * command names).
	 *
	 * @param string $plugin_root   Absolute path to the consuming plugin's root.
	 * @param string $relative_path The plugin-relative destination path, for error messages.
	 * @param string $destination   Absolute path the new file would be written to.
	 * @return void
	 * @throws \InvalidArgumentException When the destination conflicts with an existing directory or file.
	 */
	protected function detect_collision( string $plugin_root, string $relative_path, string $destination ): void {
		if ( \is_dir( $destination ) ) {
			throw new \InvalidArgumentException( 'Cannot create "' . $relative_path . '" -- a directory already exists at that path.' );
		}

		$directory = \dirname( $destination );

		while ( ! \is_dir( $directory ) ) {
			if ( \is_file( $directory ) ) {
				throw new \InvalidArgumentException( 'Cannot create "' . $relative_path . '" -- "' . $directory . '" already exists as a file, not a directory.' );
			}

			$parent = \dirname( $directory );
			if ( $parent === $directory ) {
				break;
			}

			$directory = $parent;
		}
	}

	/**
	 * Whether `--extends` is this command's to interpret, or the base's.
	 *
	 * True everywhere but `abstract`, which takes the same flag to mean
	 * something of its own: a discovered file extends its type's base or a
	 * subclass of it, while an abstract may extend anything and writes a named
	 * class rather than the shared anonymous one. Answering false is what leaves
	 * the flag alone for {@see get_extra_values()} to read.
	 *
	 * @return bool
	 */
	protected function takes_shared_extends(): bool {
		return true;
	}

	/**
	 * Make sure the module whose base class this file will extend is here.
	 *
	 * A generated file names its base in a `use` line, and nothing before this
	 * checked that the class had ever been copied in. `make page` in a plugin
	 * that never ran `add module admin-pages` therefore wrote a file importing
	 * three classes that do not exist -- and said nothing, because PHP imports
	 * are lazy, so it parses, lints clean, and lands in a directory no module is
	 * walking. It does nothing at all until someone happens to add the module.
	 *
	 * Offered rather than done: `--yes` answers it, which is how the same flag
	 * already answers the overwrite prompt, so an unattended run installs what
	 * the file needs instead of writing something inert.
	 *
	 * @param string $root The plugin's namespace root.
	 * @return void
	 */
	private function ensure_base_installed( string $root ): void {
		$base = $this->get_base_class();

		if ( null === $base ) {
			return;
		}

		$class = Copier::get_target_namespace( $root ) . '\\' . $base;

		if ( \class_exists( $class ) ) {
			return;
		}

		$module = $this->get_module_providing( $base );

		if ( null === $module ) {
			$this->warning(
				\sprintf( 'This plugin has no %s, so nothing will discover the file about to be written.', $class )
			);

			return;
		}

		$confirmed = $this->confirm(
			\sprintf(
				'This plugin has no %s, so nothing would discover the file about to be written. Add the "%s" module first?',
				$class,
				$module
			),
			false
		);

		if ( ! $confirmed ) {
			// The class is named again rather than left in the prompt above: a
			// prompt is answered and scrolls away, and this is the line someone
			// comes back to when the file turns out to do nothing.
			$this->warning(
				\sprintf(
					'Writing it anyway, but this plugin has no %s. Run `wp zt add module %s` before it can do anything.',
					$class,
					$module
				)
			);

			return;
		}

		$this->add_module( $module );
	}

	/**
	 * The registry name of the module that owns a base class.
	 *
	 * Matched on the namespace the two share -- `Modules\Fields\Field` is the
	 * base and `Modules\Fields\Fields` is the module, so `Modules\Fields`
	 * answers for both. Asking the registry rather than keeping a second map
	 * means a module that moves takes its answer with it.
	 *
	 * @param string $base The base class, relative to the copied namespace.
	 * @return string|null The module's registry name, or null when nothing owns it.
	 */
	private function get_module_providing( string $base ): ?string {
		$registry = Copier::flatten_registry(
			require $this->path->get_plugin_path( 'src/DevTools/registry.php' )
		);

		$wanted = $this->get_declaring_namespace( $base );

		foreach ( $registry as $name => $entry ) {
			if ( 'modules' !== ( $entry['section'] ?? '' ) ) {
				continue;
			}

			if ( $this->get_declaring_namespace( Copier::get_relative_class( $entry['source'] ) ) === $wanted ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Everything before a class name's last segment.
	 *
	 * @param string $class_name The class name.
	 * @return string
	 */
	private function get_declaring_namespace( string $class_name ): string {
		$position = \strrpos( $class_name, '\\' );

		return false === $position ? '' : \substr( $class_name, 0, $position );
	}

	/**
	 * Run `wp zt add module <name>` from inside this command.
	 *
	 * The real command rather than a copy of what it does: it resolves the
	 * module's dependencies, rewrites every namespace, declares it in
	 * bootstrap.php and records the hashes `update` later reads. Reimplementing
	 * any of that here would be a second thing to keep true.
	 *
	 * @param string $module The module's registry name.
	 * @return void
	 */
	private function add_module( string $module ): void {
		$command = require $this->path->get_plugin_path( 'commands/add/module.php' );

		$this->get_plugin()->wire( $command );

		// Answered rather than asked: the question that got us here has already
		// been answered, and asking it again per dependency is not a second
		// decision.
		$command->set_arguments( array( $module ), array( 'yes' => true ) );
		$command->handle( array( $module ), array( 'yes' => true ) );
	}

	/**
	 * Resolve `--extends`, refuse it if it cannot work, and describe it to the stub.
	 *
	 * Everything is settled before a file is written, because each way this can
	 * be wrong is one that surfaces at boot rather than here: the wrong base
	 * throws a DiscoveryException on every request, and a final class is a fatal
	 * as soon as the file is read.
	 *
	 * @param string $requested The value given for --extends.
	 * @param string $root      The plugin's namespace root.
	 * @return array<string, string>|null The stub's parent values, or null once the reason has been reported.
	 */
	private function get_parent_values( string $requested, string $root ): ?array {
		$base = $this->get_base_class();

		if ( null === $base ) {
			$this->error(
				\sprintf( '`%s` does not take --extends: it does not generate a class that extends anything.', static::get_type() )
			);

			return null;
		}

		try {
			$parent = $this->parent_class->resolve( $requested, $root );

			$this->parent_class->assert_usable( $parent, Copier::get_target_namespace( $root ) . '\\' . $base );
		} catch ( \InvalidArgumentException $exception ) {
			$this->error( $exception->getMessage() );

			return null;
		}

		$methods = $this->parent_class->get_abstract_methods( $parent );

		if ( '' === $methods ) {
			// Not a failure, and worth saying: a parent leaving nothing abstract
			// generates an empty class, which is a correct file and rarely what
			// someone expected to see.
			$this->log( \sprintf( '"%s" leaves no methods abstract, so the generated file overrides nothing.', $parent ) );
		}

		return array(
			'parent_fqn'       => $parent,
			'parent'           => ( new \ReflectionClass( $parent ) )->getShortName(),
			'abstract_methods' => $methods,
			'type'             => static::get_type(),
		);
	}

	/**
	 * Check a generated file parses, and say so loudly when it does not.
	 *
	 * A stub is a template, and a template can be edited into something PHP
	 * refuses to compile -- which is exactly what happened when the module stub
	 * grew an `ABSPATH` guard above its `namespace`. Every test asserting on the
	 * file's *contents* passed, and the fatal only surfaced when a consumer
	 * loaded it.
	 *
	 * Shelled out to `php -l` rather than tokenized in-process:
	 * `token_get_all( ..., TOKEN_PARSE )` raises only on a parse error, and a
	 * misplaced `namespace` is a compile error it accepts happily. The check
	 * that would have caught this is the one that compiles the file.
	 *
	 * @param string $file Absolute path to a just-written file.
	 * @return void
	 */
	private function lint( string $file ): void {
		if ( 'php' !== \strtolower( (string) \pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			return;
		}

		// Disabled on plenty of shared hosts. Nothing else here can compile a
		// file, so the check is skipped rather than faked.
		if ( ! \function_exists( 'exec' ) || \in_array( 'exec', \array_map( 'trim', \explode( ',', (string) \ini_get( 'disable_functions' ) ) ), true ) ) {
			return;
		}

		$output = array();
		$status = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		\exec( \escapeshellcmd( PHP_BINARY ) . ' -l ' . \escapeshellarg( $file ) . ' 2>&1', $output, $status );

		if ( 0 === $status ) {
			return;
		}

		$message = \trim( \implode( ' ', $output ) );

		$this->parse_errors[ $file ] = $message;

		$this->warning( 'Generated file does not parse: ' . $file );
		$this->warning( $message );
	}

	/**
	 * Every `.stub` file in a stub directory, in a deterministic order.
	 *
	 * Walked rather than listed in PHP, so adding a file to a stub directory is
	 * all it takes for that file to be generated. Nesting is preserved, and the
	 * walker's own pruning applies -- a `_notes.stub` can sit beside real ones
	 * without being written.
	 *
	 * @param string $stub_dir   Absolute path to the stub directory.
	 * @param array  $assoc_args WP-CLI's named arguments, for should_write().
	 * @return string[] Stub paths relative to $stub_dir.
	 */
	private function get_stub_files( string $stub_dir, array $assoc_args ): array {
		$files = array();

		foreach ( $this->walk_folder( $stub_dir, array( 'stub' ), 0 ) as $relative_stub ) {
			if ( $this->should_write( $relative_stub, $assoc_args ) ) {
				$files[] = $relative_stub;
			}
		}

		return $files;
	}

	/**
	 * The `wp zt make <type>` word this subcommand registers under.
	 *
	 * Used only to compose the usage message when no name is given; the word
	 * itself comes from the subcommand's own filename under `commands/make/`
	 * (see {@see \Zestry\WPToolkit\Modules\CLI\CLI}), not from this method.
	 *
	 * @return string
	 */
	abstract protected static function get_type(): string;
}
