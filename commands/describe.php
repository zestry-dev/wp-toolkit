<?php

/**
 * Devtool command: `wp zestry describe`.
 *
 * Reports what this plugin is made of -- what is installed, what is declared,
 * where each module looks and what a file there has to return. Reads only.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\DevTools\BootstrapFile;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Services\Path;

return new class() extends Command {

	/**
	 * The plugin whose directory `wp zestry` was run from.
	 *
	 * @var ConsumerPlugin
	 */
	public ConsumerPlugin $consumer_plugin;

	/**
	 * Reader for the project's zestry.json.
	 *
	 * @var ZestryConfig
	 */
	public ZestryConfig $zestry_config;

	/**
	 * Reader for the project's bootstrap.php.
	 *
	 * @var BootstrapFile
	 */
	public BootstrapFile $bootstrap_file;

	/**
	 * Resolver for this toolkit's own paths, used to read the registry.
	 *
	 * @var Path
	 */
	public Path $path;

	/**
	 * The consuming plugin's own running instance, when it has one.
	 *
	 * @var RuntimePlugin
	 */
	public RuntimePlugin $runtime;

	/**
	 * Report what this plugin has, where each module looks, and what it expects.
	 *
	 * Answers the questions someone arriving at an unfamiliar plugin has to
	 * answer before touching anything: which features are installed, which of
	 * them are actually built, which directory each one reads, and what a file
	 * dropped in there has to return. All of it is derived -- from
	 * `registry.php`, your `zestry.json`, your `bootstrap.php` and the classes on
	 * disk -- so it cannot describe a plugin that is not the one you have.
	 *
	 * `--format=json` is the same answer for a script or an agent, which is why
	 * this exists as well as the documentation: a page describes the toolkit,
	 * this describes *your* plugin.
	 *
	 * Reads only. Nothing here edits a file, and no module is ever built.
	 *
	 * ## WHAT IT CANNOT TELL YOU
	 *
	 * The directory reported for a module is its **default**. A
	 * `set_*_root()` call inside an initializer changes it, and finding that out
	 * would mean running your closures against live module instances -- which
	 * this command does not do, for the same reason `wp zestry doctor` does not.
	 * A module whose entry carries an initializer is marked `configured`, so the
	 * report says where to look rather than guessing.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format. `report` is the default read by a
	 * person; the rest are the same rows for a script.
	 * ---
	 * default: report
	 * options:
	 *   - report
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--kind=<kind>]
	 * : Limit to modules or to services.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - modules
	 *   - services
	 * ---
	 *
	 * [--installed]
	 * : Only what is actually in this plugin. Without it, everything installable
	 * is listed, so you can see what you have not added yet.
	 *
	 * ## EXAMPLES
	 *
	 *     # What this plugin has.
	 *     $ wp zestry describe --installed
	 *     Acme\Plugin -> lib/   text domain: acme-plugin
	 *
	 *     MODULES
	 *       ajax           actions/         AjaxAction    wp zestry make action
	 *       cli            commands/        Command       wp zestry make command
	 *       cron           schedules/       Schedule      wp zestry make schedule   NOT DECLARED
	 *
	 *     SERVICES
	 *       path           —
	 *       views          views/
	 *
	 *     # For a script, or an agent.
	 *     $ wp zestry describe --format=json --installed
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$format      = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'report' );
		$kind        = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'kind', 'all' );
		$only_added  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'installed', false );
		$plugin_root = $this->consumer_plugin->get_plugin_root();

		if ( ! $this->zestry_config->exists( $plugin_root ) ) {
			$this->error( 'No zestry.json here. Run `wp zestry init` first.' );
			return;
		}

		$config = $this->zestry_config->read( $plugin_root );

		try {
			$declarations = $this->bootstrap_file->read_declarations( $plugin_root );
		} catch ( \RuntimeException $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}

		$entries = $this->describe_entries( $plugin_root, $config, $declarations );

		if ( 'all' !== $kind ) {
			$wanted  = rtrim( $kind, 's' );
			$entries = array_values(
				array_filter(
					$entries,
					static function ( array $entry ) use ( $wanted ): bool {
						return $entry['kind'] === $wanted;
					}
				)
			);
		}

		if ( $only_added ) {
			$entries = array_values(
				array_filter(
					$entries,
					static function ( array $entry ): bool {
						return (bool) $entry['installed'];
					}
				)
			);
		}

		if ( 'report' === $format ) {
			$this->report( $config, $entries, $this->get_slug( $plugin_root ) );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$entries,
			array( 'name', 'kind', 'installed', 'declared', 'configured', 'reads', 'returns', 'make', 'file' )
		);
	}

	/**
	 * Every registry entry, described against this plugin.
	 *
	 * @param string                                              $plugin_root  Absolute path to the plugin root.
	 * @param array{namespace: string, root: string}              $config       The project's zestry.json.
	 * @param array<string, array{initialize: bool}>              $declarations Declared classes.
	 * @return array<int, array<string, string|bool>>
	 */
	private function describe_entries( string $plugin_root, array $config, array $declarations ): array {
		$registry  = Copier::flatten_registry( require $this->path->get_plugin_path( 'src/DevTools/registry.php' ) );
		$namespace = Copier::get_target_namespace( $config['namespace'] );
		$root      = Str::join_path( $plugin_root, trim( $config['root'], '/\\' ) );
		$makers    = $this->get_make_types( $config );

		$entries = array();

		foreach ( $registry as $name => $entry ) {
			$relative = trim( $config['root'], '/\\' ) . '/' . Copier::COPIED_SEGMENT . '/' . Copier::get_relative_source( $entry['source'] );
			$is_dir   = is_dir( $this->path->get_plugin_path( 'src/' . Copier::get_relative_source( $entry['source'] ) ) );
			$on_disk  = $is_dir ? $relative . '/' . basename( $relative ) . '.php' : $relative . '.php';

			$class = $namespace . '\\' . Copier::get_relative_class( $entry['source'] );
			$roots = $this->get_default_roots( $entry['source'] );

			$entries[] = array(
				'name'       => $name,
				'kind'       => rtrim( $entry['section'], 's' ),
				'installed'  => file_exists( rtrim( $plugin_root, '/\\' ) . '/' . $on_disk ),
				// Only a module is ever declared; a service that is not is doing
				// exactly what it should.
				'declared'   => is_a( $entry['source'], Module::class, true ) ? array_key_exists( $class, $declarations ) : true,
				'configured' => (bool) ( $declarations[ $class ]['initialize'] ?? false ),
				'reads'      => implode(
					', ',
					array_map(
						static function ( string $directory ): string {
							return $directory . '/';
						},
						$roots
					)
				),
				'returns'    => implode( ', ', $this->get_returned_bases( $entry['source'] ) ),
				'make'       => implode( ', ', array_values( array_intersect_key( $makers, array_flip( $roots ) ) ) ),
				'file'       => $on_disk,
			);

			unset( $root );
		}

		return $entries;
	}

	/**
	 * Print the human-readable form.
	 *
	 * @param array{namespace: string, root: string, text_domain?: string|null} $config  The project's zestry.json.
	 * @param array<int, array<string, string|bool>>                            $entries Described entries.
	 * @param string                                                            $slug    What the plugin registers names under.
	 * @return void
	 */
	private function report( array $config, array $entries, string $slug ): void {
		$this->log(
			sprintf(
				'%s -> %s/   text domain: %s   slug: %s',
				$config['namespace'],
				trim( $config['root'], '/\\' ),
				$config['text_domain'] ?? '(none)',
				$slug
			)
		);
		$this->log(
			sprintf(
				'Upstream under %1$s/%2$s/, yours under %1$s/',
				trim( $config['root'], '/\\' ),
				Copier::COPIED_SEGMENT
			)
		);

		foreach ( array( 'module', 'service' ) as $kind ) {
			$of_kind = array_values(
				array_filter(
					$entries,
					static function ( array $entry ) use ( $kind ): bool {
						return $entry['kind'] === $kind;
					}
				)
			);

			if ( array() === $of_kind ) {
				continue;
			}

			$this->log( '' );
			$this->log( strtoupper( $kind ) . 'S' );

			foreach ( $of_kind as $entry ) {
				$this->log(
					sprintf(
						'  %s %-14s %-18s %-22s %-24s%s',
						$entry['installed'] ? ' ' : '·',
						$entry['name'],
						'' === $entry['reads'] ? '—' : $entry['reads'],
						'' === $entry['returns'] ? '—' : $entry['returns'],
						'' === $entry['make'] ? '' : 'wp zestry make ' . $entry['make'],
						$entry['declared'] ? '' : '  NOT DECLARED'
					)
				);
			}
		}

		$this->log( '' );
		$this->log( '· = installable, not added. `wp zestry add <kind> <name>` to add one.' );
		$this->log( 'A directory shown is the default; an initializer may point the module elsewhere.' );
	}

	/**
	 * What this plugin registers its names under.
	 *
	 * Asked of the running instance first, because nothing on disk holds it: the
	 * slug is the entry file's second constructor argument, and falls back to
	 * the directory name only when that argument is omitted. Every namespaced
	 * name carries it -- `wp {slug} greet`, `?page={slug}-settings`,
	 * `{slug}-sync` -- so reporting the directory as though it were the slug is
	 * wrong exactly when someone passed one deliberately.
	 *
	 * The fallback is the same default `Plugin` itself applies, and is marked as
	 * a guess, since a plugin that is not running cannot be asked.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return string
	 */
	private function get_slug( string $plugin_root ): string {
		$slug = $this->runtime->get_slug( $plugin_root );

		return null === $slug
			? basename( rtrim( $plugin_root, '/\\' ) ) . ' (assumed; the plugin is not running)'
			: $slug;
	}

	/**
	 * Each `wp zestry make` type, keyed by the directory it writes into.
	 *
	 * Read off the generator itself rather than listed here: every type is one
	 * file in `commands/make/`, and each knows its own word and its own
	 * destination. A list would be a second place to update when a generator is
	 * added, and the drift would be silent.
	 *
	 * The files are required here rather than taken from whatever the CLI
	 * module happened to have loaded. Each returns `new class() extends
	 * MakeCommand`, and requiring one twice makes a second distinct instance
	 * rather than a redeclaration, so this costs an extra object and depends on
	 * nothing having run first.
	 *
	 * @param array{namespace: string, root: string} $config The project's zestry.json.
	 * @return array<string, string> Directory => the `make` word writing into it.
	 */
	private function get_make_types( array $config ): array {
		$files = glob( $this->path->get_plugin_path( 'commands/make' ) . '/*.php' );
		$types = array();

		foreach ( false === $files ? array() : $files as $file ) {
			$command = require $file;

			if ( ! $command instanceof MakeCommand ) {
				continue;
			}

			$reflection = new \ReflectionObject( $command );

			$word = $reflection->getMethod( 'get_type' );
			$dir  = $reflection->getMethod( 'get_default_dir' );

			$word->setAccessible( true );
			$dir->setAccessible( true );

			$types[ trim( (string) $dir->invoke( $command, $config ), '/\\' ) ] = (string) $word->invoke( null );
		}

		return $types;
	}

	/**
	 * The directories a module reads by default.
	 *
	 * Every discovery module names its own with a `DEFAULT_*_ROOT` constant, so
	 * this reads them rather than repeating them -- and a module with two roots
	 * reports both without needing to be a special case.
	 *
	 * @param string $module The module's class name in this package.
	 * @return string[]
	 */
	private function get_default_roots( string $module ): array {
		$roots = array();

		foreach ( ( new \ReflectionClass( $module ) )->getConstants() as $name => $value ) {
			if ( is_string( $value ) && 1 === preg_match( '/^DEFAULT_\w+_ROOT$/', $name ) ) {
				$roots[] = trim( $value, '/\\' );
			}
		}

		sort( $roots );

		return $roots;
	}

	/**
	 * The base classes a file discovered by this module has to return.
	 *
	 * The abstracts living beside it: `Modules/Ajax/` holds `Ajax` and
	 * `AjaxAction`, and the second is the one a discovered file returns. Read
	 * from the directory so a base added later is reported by existing.
	 *
	 * @param string $module The module's class name in this package.
	 * @return string[]
	 */
	private function get_returned_bases( string $module ): array {
		$relative = Copier::get_relative_source( $module );
		$dir      = $this->path->get_plugin_path( 'src/' . $relative );

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$bases = array();

		$files = glob( $dir . '/*.php' );

		foreach ( false === $files ? array() : $files as $file ) {
			if ( 1 === preg_match( '/^abstract class (\w+)/m', (string) file_get_contents( $file ), $match ) ) {
				$bases[] = $match[1];
			}
		}

		sort( $bases );

		return $bases;
	}
};
