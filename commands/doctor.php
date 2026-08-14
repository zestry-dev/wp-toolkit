<?php

/**
 * Devtool command: `wp zt doctor`.
 *
 * Checks a plugin's own wiring -- zestry.json, bootstrap.php, and the modules
 * declared in it -- for the mistakes that fail silently rather than loudly.
 * Reads only; it never edits a file.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\DevTools\BootstrapFile;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Modules\Path;

return new class() extends Command {

	/**
	 * Problems found so far, in the order they were found.
	 *
	 * @var array<int, array{title: string, detail: string, where: string|null}>
	 */
	private array $problems = array();

	/**
	 * Check this plugin's module wiring for silent misconfiguration.
	 *
	 * Six checks, each targeting a mistake that produces no error at runtime:
	 *
	 * - a `bootstrap.php` that declares modules where nothing built any of them,
	 *   because the entry file never reached `->bootstrap()->run()` -- so every
	 *   module is inert, every hook unbound and every directory unread;
	 * - a module on disk that `bootstrap.php` does not list -- never built, so
	 *   `on_boot()` never runs and the feature is simply absent;
	 * - a declaration whose class file is gone;
	 * - a `zestry.json` naming a root directory that is not there;
	 * - no `Requires at least:` header, so WordPress will activate the plugin on
	 *   any version it likes;
	 * - a module needing a newer WordPress than the plugin promises, which on an
	 *   older site registers against an API that is not there.
	 *
	 * Needs an initialized plugin: with no `zestry.json` in the current directory
	 * it exits non-zero telling you to run `wp zt init` first, and it stops the
	 * same way when `bootstrap.php` does not parse.
	 *
	 * Reads only. Nothing here edits a file, so it is safe to run at any point.
	 *
	 * ## WHAT IS NOT CHECKED
	 *
	 * Whether a module's directory holds anything. Every module reads one fixed
	 * directory, so this command can see them all -- but an empty one is what a
	 * module looks like before its first file is written, which is ordinary and
	 * not worth failing a build over.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format. `report` is the default read by a
	 * person: the two summary lines, then each problem with what it causes and
	 * where. The machine-readable formats print the problems alone, with a
	 * `file` and a `problem` field -- the advice is guidance for a reader, not
	 * data for a consumer, so it is left out. Every format exits non-zero when
	 * there is at least one problem, so any of them can gate a build.
	 * ---
	 * default: report
	 * options:
	 *   - report
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Check the plugin in the current directory.
	 *     $ wp zt doctor
	 *     zestry.json    Acme\Plugin -> lib/
	 *     bootstrap.php  6 classes declared
	 *
	 *     ! The "cron" module is copied in but never declared.
	 *       A module is built because bootstrap.php lists it, so one that is not
	 *       listed is never built: it discovers no files and binds no hooks.
	 *       lib/Core/Modules/Cron/Cron.php
	 *
	 *     Error: 1 problem found.
	 *
	 *     # Nothing wrong.
	 *     $ wp zt doctor
	 *     Success: No problems found.
	 *
	 *     # For tooling. Exits non-zero, so it gates a build on its own.
	 *     $ wp zt doctor --format=json
	 *     [{"file":"lib\/Core\/Modules\/Cron\/Cron.php","problem":"The \"cron\" module is copied in but never declared."}]
	 *
	 *     # Same fields, easier to read over someone's shoulder.
	 *     $ wp zt doctor --format=yaml
	 *     ---
	 *     -
	 *       file: lib/Core/Modules/Cron/Cron.php
	 *       problem: The "cron" module is copied in but never declared.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$format      = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'report' );
		$plugin_root = $this->with( ConsumerPlugin::class )->get_plugin_root();

		if ( ! $this->with( ZestryConfig::class )->exists( $plugin_root ) ) {
			$this->error( 'No zestry.json here. Run `wp zt init` first.' );
			return;
		}

		$config = $this->with( ZestryConfig::class )->read( $plugin_root );

		$this->check_root_directory( $plugin_root, $config );

		try {
			$declarations = $this->with( BootstrapFile::class )->read_declarations( $plugin_root );
		} catch ( \RuntimeException $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}

		// The summary is context for reading the problems below it. A structured
		// format is a list of problems and nothing else, or it would not parse.
		if ( 'report' === $format ) {
			$this->report_summary( $plugin_root, $config, $declarations );
		}

		$this->check_plugin_is_running( $plugin_root, $declarations );
		$this->check_declared_modules( $plugin_root, $config, $declarations );
		$this->check_undeclared_modules( $plugin_root, $config, $declarations );
		$this->check_wordpress_version( $plugin_root, $config );

		$this->report_problems( $format );
	}

	/**
	 * Print the two facts every other check is read against.
	 *
	 * @param string                                 $plugin_root  Absolute path to the plugin root.
	 * @param array{namespace: string, root: string} $config       The project's zestry.json.
	 * @param array<string, array{initialize: bool}> $declarations Declared classes.
	 * @return void
	 */
	private function report_summary( string $plugin_root, array $config, array $declarations ): void {
		$this->log( sprintf( 'zestry.json    %s -> %s/', $config['namespace'], trim( $config['root'], '/\\' ) ) );

		// The name this plugin actually reads, which is `bootstrap.php` unless its
		// entry file pointed `bootstrap()` somewhere else.
		$file = $this->with( BootstrapFile::class )->get_display_path( $plugin_root );

		if ( ! $this->with( BootstrapFile::class )->exists( $plugin_root ) ) {
			$this->log( $file . '  absent (modules declared in the entry file instead)' );
			return;
		}

		$this->log(
			sprintf(
				'%s  %d class%s declared',
				$file,
				count( $declarations ),
				1 === count( $declarations ) ? '' : 'es'
			)
		);
	}

	/**
	 * Flag a plugin that declares modules and never built any of them.
	 *
	 * The largest silent failure there is, and the one thing here that no file
	 * can answer. `bootstrap.php` listing a module means it *should* be built;
	 * whether anything built it is a fact about the request, not about the
	 * repository. An entry file that constructs a `Plugin` and never reaches
	 * `run()` -- or never constructs one at all -- leaves every module
	 * unbuilt, every hook unbound and every directory unread, with no error
	 * anywhere and every other check on this page passing.
	 *
	 * Reaching this command at all proves the plugin is active and its
	 * autoloader ran, so an absent instance is not "not installed": it is a
	 * plugin that loaded and then did nothing.
	 *
	 * Nothing is built to find out. `Plugin::run()` publishes itself where
	 * {@see RuntimePlugin} looks, as its last act, so presence means it
	 * finished and absence means it never ran.
	 *
	 * @param string                                 $plugin_root  Absolute path to the plugin root.
	 * @param array<string, array{initialize: bool}> $declarations Declared classes.
	 * @return void
	 */
	private function check_plugin_is_running( string $plugin_root, array $declarations ): void {
		// With nothing declared there is nothing that should have been built,
		// and a plugin is free not to use this toolkit at run time at all.
		if ( array() === $declarations || null !== $this->with( RuntimePlugin::class )->get( $plugin_root ) ) {
			return;
		}

		$this->add_problem(
			sprintf(
				'bootstrap.php declares %d class%s, and nothing in this plugin built any of them.',
				count( $declarations ),
				1 === count( $declarations ) ? '' : 'es'
			),
			'Declaring a module is only half of it: something has to build the plugin for any of it to happen.'
				. ' Check the entry file constructs a Plugin and calls `->bootstrap()->run()` -- until it does,'
				. ' every module here is inert and nothing reports it.',
			'the entry file'
		);
	}

	/**
	 * Flag a zestry.json naming a root directory that is not there.
	 *
	 * @param string                                 $plugin_root Absolute path to the plugin root.
	 * @param array{namespace: string, root: string} $config      The project's zestry.json.
	 * @return void
	 */
	private function check_root_directory( string $plugin_root, array $config ): void {
		$root = trim( $config['root'], '/\\' );

		if ( is_dir( rtrim( $plugin_root, '/\\' ) . '/' . $root ) ) {
			return;
		}

		$this->add_problem(
			sprintf( 'zestry.json names "%s/" as this project\'s root, but there is no such directory.', $root ),
			'Every `wp zt add` and `wp zt make` writes there, and the PSR-4 entry in composer.json points at it.',
			'zestry.json'
		);
	}

	/**
	 * Flag a declaration whose class file is gone.
	 *
	 * @param string                                 $plugin_root  Absolute path to the plugin root.
	 * @param array{namespace: string, root: string} $config       The project's zestry.json.
	 * @param array<string, array{initialize: bool}> $declarations Declared classes.
	 * @return void
	 */
	private function check_declared_modules( string $plugin_root, array $config, array $declarations ): void {
		foreach ( $declarations as $class_name => $declaration ) {
			$relative = $this->get_relative_class_path( $class_name, $config );

			if ( null === $relative ) {
				continue;
			}

			$file = Str::join_path( $plugin_root, $relative );

			if ( ! is_file( $file ) ) {
				$this->add_problem(
					sprintf( '%s is declared in bootstrap.php but its file does not exist.', $this->get_short_name( $class_name ) ),
					'The plugin will fail to resolve it. Remove the declaration, or restore the file.',
					$relative
				);
				continue;
			}
		}
	}

	/**
	 * Flag a copied-in module that nothing declares.
	 *
	 * The one failure that produces no error at all. Nothing builds an
	 * undeclared module, so one that acts on its own never runs its `on_boot()`
	 * and any other throws the first time something reaches for it.
	 *
	 * @param string                                 $plugin_root  Absolute path to the plugin root.
	 * @param array{namespace: string, root: string} $config       The project's zestry.json.
	 * @param array<string, array{initialize: bool}> $declarations Declared classes.
	 * @return void
	 */
	private function check_undeclared_modules( string $plugin_root, array $config, array $declarations ): void {
		$registry  = Copier::normalize_registry( require $this->with( Path::class )->get_plugin_path( 'src/DevTools/registry.php' ) );
		$namespace = Copier::get_target_namespace( $config['namespace'] );

		foreach ( $registry as $name => $entry ) {
			// Guards the registry rather than the plugin: every entry is a
			// Module, and only a Module is worth reporting as undeclared.
			if ( ! is_a( $entry['source'], Module::class, true ) ) {
				continue;
			}

			// The same class under the project's own copied-source namespace,
			// and the file PSR-4 puts it in -- both from the class name, so
			// neither restates a layout that could drift.
			$class_name = $namespace . '\\' . Copier::get_relative_class( $entry['source'] );
			$relative   = (string) $this->get_relative_class_path( $class_name, $config );

			if ( ! is_file( rtrim( $plugin_root, '/\\' ) . '/' . $relative ) ) {
				continue;
			}

			if ( array_key_exists( $class_name, $declarations ) ) {
				continue;
			}

			$this->add_problem(
				sprintf( 'The "%s" module is copied in but never declared.', $name ),
				'A module is built because bootstrap.php lists it, so one that is not listed is never built: it discovers no files and binds no hooks. Add it to bootstrap.php.',
				$relative
			);
		}
	}

	/**
	 * Flag a copied-in entry this site's WordPress is too old for.
	 *
	 * `wp zt add` refuses one of these outright, so arriving here means the site
	 * moved rather than the plugin: WordPress rolled back, or a copied tree
	 * carried to an older install. The module still loads and is still declared;
	 * it simply has no API to call, and reports that once per boot through
	 * `_doing_it_wrong()` -- a notice on a page nobody is reading, on a site where
	 * the feature is quietly absent.
	 *
	 * Read from the same registry `add` gates on, so the two cannot disagree about
	 * what a module needs.
	 *
	 * @param string                                 $plugin_root Absolute path to the plugin root.
	 * @param array{namespace: string, root: string} $config      The project's zestry.json.
	 * @return void
	 */
	private function check_wordpress_version( string $plugin_root, array $config ): void {
		$registry   = Copier::normalize_registry( require $this->with( Path::class )->get_plugin_path( 'src/DevTools/registry.php' ) );
		$namespace  = Copier::get_target_namespace( $config['namespace'] );
		$declared   = $this->with( ConsumerPlugin::class )->get_required_wordpress( $plugin_root );
		$entry_file = $this->with( ConsumerPlugin::class )->get_entry_file( $plugin_root );

		if ( null === $declared ) {
			$this->add_problem(
				'This plugin does not declare a `Requires at least:` header.',
				'WordPress reads that header to refuse activation on a site too old to run the plugin, so'
					. ' without it there is nothing stopping it from loading anywhere -- and nothing here can'
					. ' tell whether a copied module would have an API to call. Add it beside `Plugin Name:`.',
				null === $entry_file ? null : basename( $entry_file )
			);
		}

		foreach ( $registry as $name => $entry ) {
			if ( null === $entry['requires'] ) {
				continue;
			}

			if ( null !== $declared && version_compare( $declared, $entry['requires'], '>=' ) ) {
				continue;
			}

			$class_name = $namespace . '\\' . Copier::get_relative_class( $entry['source'] );
			$relative   = (string) $this->get_relative_class_path( $class_name, $config );

			if ( ! is_file( rtrim( $plugin_root, '/\\' ) . '/' . $relative ) ) {
				continue;
			}

			$this->add_problem(
				sprintf(
					'The "%s" module needs WordPress %s, which this plugin does not promise: %s.',
					$name,
					$entry['requires'],
					null === $declared
						? 'nothing declares a minimum'
						: sprintf( 'it declares `Requires at least: %s`', $declared )
				),
				'On a site older than that, the API this registers against does not exist: it binds nothing and'
					. ' the feature is silently absent. Raise the header, or remove it.',
				$relative
			);
		}
	}

	/**
	 * The PSR-4 path of a class inside this project, relative to its root.
	 *
	 * @param string                                 $class_name The fully qualified class name.
	 * @param array{namespace: string, root: string} $config     The project's zestry.json.
	 * @return string|null Null when the class is not under the project's own namespace.
	 */
	private function get_relative_class_path( string $class_name, array $config ): ?string {
		$namespace = rtrim( $config['namespace'], '\\' ) . '\\';

		if ( ! str_starts_with( $class_name, $namespace ) ) {
			return null;
		}

		return trim( $config['root'], '/\\' ) . '/'
			. str_replace( '\\', '/', substr( $class_name, strlen( $namespace ) ) ) . '.php';
	}

	/**
	 * A class name without its namespace.
	 *
	 * @param string $class_name The fully qualified class name.
	 * @return string
	 */
	private function get_short_name( string $class_name ): string {
		$position = strrpos( $class_name, '\\' );

		return false === $position ? $class_name : substr( $class_name, $position + 1 );
	}

	/**
	 * Record a problem to be reported once every check has run.
	 *
	 * @param string      $title  One sentence naming what is wrong.
	 * @param string      $detail What it causes, and what to do about it.
	 * @param string|null $where  Plugin-relative path the problem is in.
	 * @return void
	 */
	private function add_problem( string $title, string $detail, ?string $where = null ): void {
		$this->problems[] = array(
			'title'  => $title,
			'detail' => $detail,
			'where'  => $where,
		);
	}

	/**
	 * Print every problem found, and exit non-zero if there were any.
	 *
	 * @param string $format One of the formats the command declares.
	 * @return void
	 */
	private function report_problems( string $format ): void {
		if ( 'report' !== $format ) {
			$items = array_map(
				static function ( array $problem ): array {
					return array(
						'file'    => $problem['where'] ?? '',
						'problem' => $problem['title'],
					);
				},
				$this->problems
			);

			\WP_CLI\Utils\format_items( $format, $items, array( 'file', 'problem' ) );

			// halt() rather than error(): the items have already been printed,
			// and error()'s own message on STDERR would be one more thing for a
			// caller parsing this to have to ignore.
			$this->halt( array() === $this->problems ? 0 : 1 );
			return;
		}

		if ( array() === $this->problems ) {
			$this->success( 'No problems found.' );
			return;
		}

		/*
		 * All three lines of a problem go to STDOUT, including the title. Using
		 * warning() for the title put it on STDERR while its own detail and path
		 * went to STDOUT: `doctor > report.txt` then captured an explanation with
		 * no module name, `doctor 2> report.txt` captured names with no paths,
		 * and on a terminal the unbuffered STDERR writes overtook the STDOUT
		 * ones, printing the final summary in the middle of a problem.
		 */
		foreach ( $this->problems as $problem ) {
			$this->log( '' );
			$this->log( '! ' . $problem['title'] );
			$this->log( '  ' . $problem['detail'] );

			if ( null !== $problem['where'] ) {
				$this->log( '  ' . $problem['where'] );
			}
		}

		$this->log( '' );
		$this->error(
			sprintf(
				'%d problem%s found.',
				count( $this->problems ),
				1 === count( $this->problems ) ? '' : 's'
			)
		);
	}
};
