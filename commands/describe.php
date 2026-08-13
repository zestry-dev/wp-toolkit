<?php

/**
 * Devtool command: `wp zt describe`.
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
	 * The plugin whose directory `wp zt` was run from.
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
	 * this command does not do, for the same reason `wp zt doctor` does not.
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
	 *     $ wp zt describe --installed
	 *     Acme\Plugin -> lib/   text domain: acme-plugin
	 *
	 *     MODULES
	 *       ajax           actions/         AjaxAction    wp zt make action
	 *       cli            commands/        Command       wp zt make command
	 *       cron           schedules/       Schedule      wp zt make schedule   NOT DECLARED
	 *       fields         fields/          Field         wp zt make field
	 *           fields/ 40 files via Acme\Plugin\Abstracts\EntityField
	 *
	 *     SERVICES
	 *       path           —
	 *       views          views/
	 *
	 *     # For a script, or an agent.
	 *     $ wp zt describe --format=json --installed
	 *     [{"name":"ajax","kind":"module","installed":true,"declared":true,
	 *       "configured":false,"reads":"actions/","returns":"AjaxAction",
	 *       "via":"","make":"action","file":"lib/Core/Modules/Ajax/Ajax.php"}]
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
			$this->error( 'No zestry.json here. Run `wp zt init` first.' );
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
			array( 'name', 'kind', 'installed', 'declared', 'configured', 'reads', 'returns', 'via', 'make', 'file' )
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
			$bases = $this->get_returned_bases( $entry['source'] );
			$via   = $this->get_intermediates( $plugin_root, $bases, $roots, $namespace );

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
				'returns'    => implode( ', ', $bases ),
				'via'        => implode(
					', ',
					array_map(
						static function ( array $found, string $root ): string {
							return sprintf(
								'%s/ %d file%s via %s',
								$root,
								$found['files'],
								1 === $found['files'] ? '' : 's',
								$found['parent']
							);
						},
						$via,
						array_keys( $via )
					)
				),
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
						'' === $entry['make'] ? '' : 'wp zt make ' . $entry['make'],
						$entry['declared'] ? '' : '  NOT DECLARED'
					)
				);

				/*
				 * On its own line rather than in the table: it is the answer to a
				 * question the table does not ask, and the one thing someone
				 * opening this repository cold cannot find out any other way.
				 */
				foreach ( '' === $entry['via'] ? array() : explode( ', ', (string) $entry['via'] ) as $via ) {
					$this->log( '      ' . $via );
				}
			}
		}

		$this->log( '' );
		$this->log( '· = installable, not added. `wp zt add <kind> <name>` to add one.' );
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
	 * Each `wp zt make` type, keyed by the directory it writes into.
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
	 * Every discovery module names its own with a `*_ROOT` constant, so
	 * this reads them rather than repeating them -- and a module with two roots
	 * reports both without needing to be a special case.
	 *
	 * @param string $module The module's class name in this package.
	 * @return string[]
	 */
	private function get_default_roots( string $module ): array {
		$roots = array();

		foreach ( ( new \ReflectionClass( $module ) )->getConstants() as $name => $value ) {
			if ( is_string( $value ) && 1 === preg_match( '/^\w+_ROOT$/', $name ) ) {
				$roots[] = trim( $value, '/\\' );
			}
		}

		sort( $roots );

		return $roots;
	}

	/**
	 * The intermediate abstract a directory's files all extend, if they do.
	 *
	 * A plugin past a handful of files of one kind grows one -- a class between
	 * the toolkit's base and each file, holding what every one of them shares.
	 * Discovery has always accepted it, because it checks `instanceof` and a
	 * subclass of a `Field` is a `Field`, so nothing had to be taught about it
	 * and nothing was. Which leaves someone opening the repository cold reading
	 * `extends EntityField` with no path from any command to what that is.
	 *
	 * Reported only when *every* file in the directory shares the parent. A
	 * mixed directory has no such thing to name, and saying "mostly" would be
	 * worse than saying nothing.
	 *
	 * @rationale
	 * Read from the source rather than reflected off the discovered instances,
	 * which is what the modules now hand back publicly and would have been the
	 * shorter route. Reflecting means booting the consumer's module, and booting
	 * one walks its directory and `require`s every file in it -- arbitrary code,
	 * during a command whose entire job is to report. `describe` derives
	 * everything else statically for that reason, and `doctor` has the same rule
	 * written down. Reading the files also answers for a module that is
	 * installed but not declared, which is exactly the plugin most in need of
	 * being described.
	 *
	 * @param string   $plugin_root Absolute path to the consuming plugin's root.
	 * @param string[] $bases       The base class names files here may return.
	 * @param string[] $roots       The module's default directories.
	 * @param string   $copied      The namespace the toolkit's own classes were copied under.
	 * @return array<string, array{parent: string, files: int}> Keyed by directory.
	 */
	private function get_intermediates( string $plugin_root, array $bases, array $roots, string $copied ): array {
		$found = array();

		foreach ( $roots as $root ) {
			$directory = Str::join_path( rtrim( $plugin_root, '/\\' ), $root );

			if ( ! is_dir( $directory ) ) {
				continue;
			}

			$files = glob( $directory . '/*.php' );
			$files = false === $files ? array() : $files;

			if ( array() === $files ) {
				continue;
			}

			$parents = array();

			foreach ( $files as $file ) {
				$parents[] = $this->get_extended_class( $file );
			}

			// Counted before filtering, so a file extending nothing is a file
			// that does not share the parent -- which is the whole condition.
			$named  = array_values( array_filter( $parents ) );
			$unique = array_unique( $named );

			if ( count( $named ) !== count( $files ) || 1 !== count( $unique ) ) {
				continue;
			}

			$parent = (string) reset( $unique );

			/*
			 * The base they would have extended anyway is not an intermediate,
			 * and naming it would say nothing the `returns` column does not.
			 * Matched on the short name *and* where it came from: a class of the
			 * plugin's own that happens to be called `Taxonomy` is an
			 * intermediate, and the copied namespace is what tells them apart.
			 * An unqualified name is read as the toolkit's, which is what a file
			 * with no import of its own means.
			 */
			$separator = strrpos( $parent, '\\' );
			$short     = false === $separator ? $parent : substr( $parent, $separator + 1 );

			if ( in_array( $short, $bases, true )
				&& ( $short === $parent || str_starts_with( $parent, $copied . '\\' ) )
			) {
				continue;
			}

			$found[ $root ] = array(
				'parent' => $parent,
				'files'  => count( $files ),
			);
		}

		return $found;
	}

	/**
	 * What one discovered file extends, qualified where the file says so.
	 *
	 * A discovered file is `return new class() extends X`, and `X` is an import
	 * in the same file -- so the `use` line is what turns the short name into
	 * one worth printing. A name with no matching import is reported as written.
	 *
	 * @param string $file Absolute path to the file.
	 * @return string|null The class extended, or null when the file extends nothing.
	 */
	private function get_extended_class( string $file ): ?string {
		$source = (string) file_get_contents( $file );

		if ( 1 !== preg_match( '/\bextends\s+([A-Za-z_\\\\][\w\\\\]*)/', $source, $match ) ) {
			return null;
		}

		$short = ltrim( $match[1], '\\' );

		if ( 1 === preg_match( '/^use\s+([\w\\\\]*\\\\' . preg_quote( $short, '/' ) . ')\s*;/m', $source, $import ) ) {
			return $import[1];
		}

		return $short;
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
