<?php

/**
 * Devtool command: `wp zt make module <name>`.
 *
 * Generates a new, plain consumer Module -- one you write yourself (in the
 * spirit of Options/Log/Assets), not one of the file-based discovery
 * conventions (action/page/command/schedule/route/post-type/taxonomy) the
 * other `make` types generate.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\DevTools\ZestryConfig;

return new class() extends MakeCommand {

	/**
	 * Whether `--bootable` was given, read by {@see after_write()}.
	 *
	 * @var bool
	 */
	private bool $bootable = false;

	/**
	 * Generate a new plain Module subclass.
	 *
	 * **This is where your own WordPress hooks go.** Every other `make` type
	 * writes into a directory some module already walks, which covers the hooks
	 * this toolkit has a convention for -- a post type, a route, a cron event. For
	 * anything it does not, a module of your own is the thing that acts without
	 * being called, so `on_boot()` is where an `add_filter()` or an `add_action()`
	 * belongs:
	 *
	 *     final class Editor extends Module implements Bootable {
	 *
	 *         public function on_boot(): void {
	 *             // Restrict which blocks a form post can hold.
	 *             \add_filter( 'allowed_block_types_all', array( $this, 'filter_allowed' ), 10, 2 );
	 *         }
	 *     }
	 *
	 * The `implements Bootable` is what makes `on_boot()` run. A module without
	 * it works only when something calls it, and is listed just the same.
	 *
	 * Anything that has to wait for `init` goes through
	 * {@see \Zestry\WPToolkit\Kernel\Abstracts\Module::on_wp_init()} instead, since a module
	 * can be built on either side of it.
	 *
	 * Requires `wp zt init` to have already run in this plugin. Unlike every
	 * other `make` type, there is no fixed conventional directory to default
	 * to -- a plain module is not discovered by anything -- so its home is your
	 * own `{zestry.json root}/Modules/` directory, beside the copied `Core/` tree
	 * rather than inside it, which `wp zt update` never touches.
	 *
	 * Because nothing discovers it, this is also the one `make` type that writes
	 * to your `bootstrap.php`: the new class is appended there, and being listed
	 * is the only thing that builds a module.
	 *
	 * A generated file that does not yet parse is not declared at all. The
	 * command says so, and declaring it is one edit away once the file parses.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The class name, e.g. 'RequestLog'. Becomes both the filename
	 * (`{name}.php`) and the class name itself -- unlike every other `make`
	 * type, this is NOT a kebab-case local name; give it exactly as it
	 * should appear after `class`.
	 * Group related modules by qualifying the name: `Services/Mailer` writes
	 * `Modules/Services/Mailer.php` declaring `{namespace}\Modules\Services`.
	 * The destination is fixed, since PSR-4 ties a namespace to one directory and
	 * the name decides both.
	 *
	 * [--bootable]
	 * : Give the module an `on_boot()` that runs without being called, and
	 * declare it in `bootstrap.php` against the plugin's own `{slug}_loaded`
	 * action -- so it boots after every other module the plugin has, rather
	 * than in the middle of the list. Leave it off for a module that only
	 * works when something calls it.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate a new module at lib/Modules/RequestLog.php (given a
	 *     # project initialized with root "lib").
	 *     $ wp zt make module RequestLog
	 *     Success: Created lib/Modules/RequestLog.php
	 *
	 *     # Grouped: the directory and the namespace come from the same name.
	 *     $ wp zt make module Services/Mailer
	 *     Success: Created lib/Modules/Services/Mailer.php
	 *
	 *     # One that acts on its own, booting after every other module.
	 *     $ wp zt make module Shortcodes --bootable
	 *     Declared in bootstrap.php, booting on `acme_plugin_loaded`.
	 *     Success: Created lib/Modules/Shortcodes.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * Supply the class name placeholder in place of the usual kebab-case name/title.
	 *
	 * @param string $name       The class name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array{class_name: string, class_namespace: string}
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$segments = $this->get_name_segments( $name );
		$bootable = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'bootable', false );

		// Remembered for after_write(), which decides the bootstrap.php entry
		// and is handed no flags of its own.
		$this->bootable = $bootable;

		return array(
			'class_name'      => (string) array_pop( $segments ),
			'class_namespace' => $this->get_generated_namespace( $segments ),
			'bootable_import' => $bootable ? 'use ' . $this->get_copied_namespace() . "\\Kernel\\Contracts\\Bootable;\n" : '',
			'bootable_clause' => $bootable ? ' implements Bootable' : '',
			'bootable_body'   => $bootable ? $this->get_boot_method() : '',
		);
	}

	protected function get_stub(): string {
		return 'module.php.stub';
	}

	/**
	 * The project's own `Modules` directory, read from zestry.json.
	 *
	 * Unlike every other type, there is no type-specific literal default: a
	 * plain module is not a file-discovery convention with a folder of its own,
	 * so its home has to be read from the project's own root --
	 * {@see \Zestry\WPToolkit\DevTools\Abstracts\MakeCommand::get_default_dir()}'s `$config`
	 * parameter exists specifically for this case. Beside the copied `Core/`
	 * tree, never inside it: that tree is what `wp zt update` may replace.
	 *
	 * @param array{namespace: string, root: string} $config The project's zestry.json.
	 * @return string
	 */
	protected function get_default_dir( array $config ): string {
		return trim( $config['root'], '/\\' ) . '/Modules';
	}

	/**
	 * Declare the new module in the plugin's `bootstrap.php`.
	 *
	 * The only `make` type that requires this. Every other type writes a file
	 * that its module discovers, and so takes effect immediately; a plain
	 * module is discovered by nothing, and is built only once declared.
	 *
	 * Written bare. The entry's value would be a configuration array, and there
	 * is nothing to configure on a class generated a moment ago -- being listed
	 * is the whole of what it needs, and a `Bootable` one runs its `on_boot()`
	 * as soon as the plugin builds it.
	 *
	 * @param string                                                          $name        The class name given on the command line.
	 * @param string                                                          $plugin_root Absolute path to the consuming plugin's root.
	 * @param array{namespace: string, root: string, text_domain: string|null} $config      The project's zestry.json.
	 * @return void
	 */
	protected function after_write( string $name, string $plugin_root, array $config ): void {
		// A Bootable module has to say when it boots, and the plugin's own
		// loaded action is the answer that suits a module you just wrote: it
		// fires at the end of `run()`, so this boots after everything else the
		// plugin has. One that only works when called has nothing to time.
		$hook = $this->bootable
			? $this->with( RuntimePlugin::class )->get_loaded_hook( $plugin_root )
			: null;

		$this->declare_generated_module( $name, $plugin_root, $hook );
	}

	/**
	 * The `Core\` namespace the copied kernel landed under.
	 *
	 * Read here rather than taken from the shared values, since
	 * {@see get_extra_values()} is handed the name and the flags and nothing
	 * else -- and {@see Copier} is the one place that knows the segment.
	 *
	 * @return string
	 */
	private function get_copied_namespace(): string {
		$config = $this->with( ZestryConfig::class )->read( $this->with( ConsumerPlugin::class )->get_plugin_root() );

		return Copier::get_target_namespace( rtrim( $config['namespace'], '\\' ) );
	}

	/**
	 * The `on_boot()` a `--bootable` module is generated with.
	 *
	 * @return string
	 */
	private function get_boot_method(): string {
		return implode(
			"\n",
			array(
				"\t/**",
				"\t * What this module does on its own.",
				"\t *",
				"\t * Runs once, when the plugin builds this module -- which its",
				"\t * bootstrap.php entry decides. Bind hooks, register things, walk a",
				"\t * directory: anything that has to happen without being called.",
				"\t *",
				"\t * @return void",
				"\t */",
				"\tpublic function on_boot(): void {",
				"\t\t// add_shortcode( 'example', array( \$this, 'render' ) );",
				"\t}",
			)
		);
	}

	protected static function get_type(): string {
		return 'module';
	}
};
