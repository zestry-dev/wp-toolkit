<?php

/**
 * DevTools API: AddCommand base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools\Abstracts;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\DevTools\Formatter;
use Zestry\WPToolkit\DevTools\StubRenderer;
use Zestry\WPToolkit\DevTools\Tooling;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\DeclarationResult;
use Zestry\WPToolkit\DevTools\Manifest;
use Zestry\WPToolkit\DevTools\GitIgnore;
use Zestry\WPToolkit\DevTools\BootstrapFile;
use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Modules\Path;
use Zestry\WPToolkit\DevTools\RuntimePlugin;

/**
 * AddCommand class.
 *
 * Shared flow behind `wp zt add <module>...` and `wp zt overwrite
 * <module>...` (see `commands/add/`/`commands/overwrite/`): read the
 * project's zestry.json for its namespace, text domain, and destination root,
 * resolve the requested modules' transitive dependencies against the
 * registry, then copy whichever of them {@see filter_existing_modules()}
 * leaves in the copy set. `zestry.json`'s `text_domain` may be null, which
 * `Copier` treats as "leave text-domain strings untouched" rather than
 * requiring one.
 *
 * The two concrete commands differ only in what happens when a resolved
 * module's destination already exists on disk: `add` removes it from the
 * copy set with a log message (never overwriting a consumer's own edits
 * silently), while `overwrite` warns once, listing every already-present
 * module the batch would touch, and leaves the whole set untouched (so
 * everything still gets copied) only after a single explicit confirmation
 * covering the whole batch -- or empties the set entirely to cancel.
 */
abstract class AddCommand extends Command {

	/**
	 * Resolve, and copy, the requested modules and their dependencies.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		if ( array() === $args ) {
			$this->error(
				\sprintf(
					'Specify at least one module. Run `wp zt %s --help` for the list.',
					static::get_word()
				)
			);
			return;
		}

		$plugin_root = $this->with( ConsumerPlugin::class )->get_plugin_root();

		try {
			$config = $this->with( ZestryConfig::class )->read( $plugin_root );
		} catch ( \RuntimeException $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}

		$registry = require $this->with( Path::class )->get_plugin_path( 'src/DevTools/registry.php' );
		$registry = Copier::normalize_registry( $registry );

		try {
			$resolved = $this->with( Copier::class )->resolve_dependencies( $args, $registry );
		} catch ( \InvalidArgumentException $exception ) {
			$this->error(
				\sprintf(
					'%s Known modules: %s',
					$exception->getMessage(),
					\implode( ', ', \array_keys( $registry ) )
				)
			);
			return;
		}

		if ( ! $this->assert_wordpress_requirement_is_met( $resolved, $registry, $args ) ) {
			return;
		}

		$extra = \array_diff( $resolved, $args );
		if ( array() !== $extra ) {
			$this->log( 'Also adding required dependencies: ' . \implode( ', ', $extra ) );
		}

		/*
		 * Both ends carry the `Core/` segment, and neither carries anything more
		 * specific: each copied file already declares its own `Zestry\WPToolkit\Modules\...`
		 * namespace, which Copier's rewrite preserves as a suffix after stripping
		 * the `Zestry\WPToolkit` prefix. Appending `\Modules` here would duplicate it.
		 */
		$project_root = Copier::get_target_root( Str::join_path( $plugin_root, \trim( $config['root'], '/\\' ) ) );
		$namespace    = Copier::get_target_namespace( $config['namespace'] );

		$destinations = array();
		foreach ( $resolved as $name ) {
			$destinations[ $name ] = $project_root . '/' . Copier::get_relative_source( $registry[ $name ]['source'] );
		}

		$existing  = \array_keys( \array_filter( $destinations, 'file_exists' ) );
		$to_copy   = $resolved;
		$cancelled = false;

		if ( array() !== $existing ) {
			$cancelled = $this->filter_existing_modules( $existing, $destinations, $to_copy );
		}

		if ( $cancelled ) {
			// The subcommand is expected to have already logged why (see
			// commands/overwrite/*.php's declined-confirmation "Cancelled." log).
			return;
		}

		$written = array();

		foreach ( $to_copy as $name ) {
			$source      = $this->with( Path::class )->get_plugin_path( 'src/' . Copier::get_relative_source( $registry[ $name ]['source'] ) );
			$destination = $destinations[ $name ];

			$written += \is_dir( $source )
				? $this->with( Copier::class )->copy_directory( $source, $destination, $namespace, $config['text_domain'] )
				: $this->with( Copier::class )->copy_file( $source, $destination, $namespace, $config['text_domain'] );

			$this->log( static::get_past_tense() . ' ' . $name );
		}

		// Merged into whatever previous runs recorded, since this copies one
		// module at a time and the manifest describes the whole copied tree.
		$this->with( Manifest::class )->record( $plugin_root, $written );

		$this->after_copy( $to_copy, $plugin_root );

		$this->success( 'Done.' );
	}

	/**
	 * Set up anything a copied module needs beyond its own source files.
	 *
	 * Runs once, after every module has been copied, with the names that were
	 * actually copied -- a module skipped as already-present is not in the list,
	 * so its setup does not run again either. Does nothing by default; `blocks`
	 * and `assets` use it to write the npm scripts, TypeScript config and build
	 * configuration those need, which is wiring no amount of PHP copying
	 * provides.
	 *
	 * @param string[] $copied      The module names that were copied.
	 * @param string   $plugin_root Absolute path to the consuming plugin's root.
	 * @return void
	 */
	protected function after_copy( array $copied, string $plugin_root ): void {
		$this->declare_in_bootstrap( $copied, $plugin_root );

		/*
		 * The declarations, not the copied files. Every copied file's hash is
		 * recorded by the manifest as it is written, and `wp zt update` tells
		 * an upstream change from a local edit by comparing against it --
		 * formatting after the fact would report all of them as edited.
		 */
		$this->with( Formatter::class )->format( $plugin_root, array( \rtrim( $plugin_root, '/\\' ) . '/bootstrap.php' ) );

		if ( \in_array( 'blocks', $copied, true ) ) {
			$this->set_up_block_build( $plugin_root );
		}

		if ( \in_array( 'assets', $copied, true ) ) {
			$this->set_up_asset_build( $plugin_root );
		}
	}

	/**
	 * Declare each copied module in the plugin's `bootstrap.php`.
	 *
	 * Copying a module's files is only half of adding it: a module binds no
	 * hooks until the plugin builds it, and being listed here is what builds
	 * it. Declaring it as it is copied makes it active straight away, without
	 * requiring a manual edit.
	 *
	 * Every copied module is declared, whether or not it acts on its own:
	 * `bootstrap.php` is the whole inventory, and nothing outside it is built.
	 *
	 * @param string[] $copied      The module names that were copied.
	 * @param string   $plugin_root Absolute path to the consuming plugin's root.
	 * @return void
	 */
	protected function declare_in_bootstrap( array $copied, string $plugin_root ): void {
		if ( array() === $copied ) {
			return;
		}

		$config    = $this->with( ZestryConfig::class )->read( $plugin_root );
		$namespace = Copier::get_target_namespace( $config['namespace'] );
		$registry  = require $this->with( Path::class )->get_plugin_path( 'src/DevTools/registry.php' );
		$registry  = Copier::normalize_registry( $registry );

		$classes        = array();
		$declared_names = array();

		foreach ( $copied as $name ) {
			$source = $registry[ $name ]['source'];

			/*
			 * Guards the registry rather than the copy: every entry is a Module,
			 * and a declaration is only meaningful for one.
			 */
			if ( ! \is_a( $source, Module::class, true ) ) {
				continue;
			}

			// The same class under the project's own namespace: only the root
			// segment differs, so a directory module keeps its `Ajax\Ajax` tail.
			// The whole root namespace, not up to the first backslash -- the two were
			// the same while the root was one segment, and a copied class landed a
			// segment too deep the moment it became two.
			$class_name = $namespace . '\\' . Copier::get_relative_class( $source );

			$source_path      = $this->with( Path::class )->get_plugin_path( 'src/' . Copier::get_relative_source( $source ) );
			$declared_names[] = $name;

			$classes[ $class_name ] = \array_merge(
				array( 'config' => $this->get_bootstrap_config( $source_path ) ),
				$this->get_boot_timing( $source_path, $source, $plugin_root )
			);
		}

		/*
		 * A plugin may legitimately have no bootstrap.php: it may declare its
		 * modules in the entry file, or have been initialized before this file
		 * existed. Logged rather than warned, since neither is an error, and
		 * the entries are printed so they can be pasted in directly.
		 */
		if ( ! $this->with( BootstrapFile::class )->exists( $plugin_root ) ) {
			$this->log( 'No bootstrap.php found. Declare these modules in your entry file:' );

			foreach ( $classes as $class_name => $entry ) {
				$this->log(
					$this->with( BootstrapFile::class )->get_entry_line( $class_name, $entry['config'], $entry['hook'], $entry['priority'] )
				);
			}

			return;
		}

		$result = $this->with( BootstrapFile::class )->declare_modules( $plugin_root, $classes );

		if ( DeclarationResult::Declared === $result ) {
			$this->log( 'Declared in bootstrap.php: ' . \implode( ', ', $declared_names ) );

			return;
		}

		// Already declared is the ordinary result of re-running the command,
		// and says nothing a consumer needs to act on.
		if ( DeclarationResult::AlreadyDeclared === $result ) {
			return;
		}

		// Anything else means the modules are on disk but inert, which is worth
		// interrupting for: the same paste-ready lines as the no-file branch
		// above, so the recovery is one paste either way.
		$this->warning( 'Could not write to bootstrap.php. Declare these modules by hand:' );

		foreach ( $classes as $class_name => $entry ) {
			$this->log(
				$this->with( BootstrapFile::class )->get_entry_line( $class_name, $entry['config'], $entry['hook'], $entry['priority'] )
			);
		}
	}

	/**
	 * When a module says it has to boot, read from its own docblock.
	 *
	 * `@setup-hook` names the hook and `@setup-hook-priority` the priority. A
	 * module that names neither and acts on its own gets the plugin's own
	 * `{slug}-loaded` action, which is the answer that suits almost all of them:
	 * it fires at the end of `run()`, so the module boots after every other one
	 * the plugin has. The tag is for the few that need something else.
	 *
	 * A module that is not {@see Bootable} gets no hook at all -- it does
	 * nothing when built, so there is nothing to time, and a bare entry says
	 * that plainly.
	 *
	 * Written into `bootstrap.php` either way rather than defaulted at runtime:
	 * the kernel refuses a `Bootable` module whose entry is silent about
	 * timing, so what this writes is what makes the entry complete.
	 *
	 * @param string $source_path Absolute path to the module's own source.
	 * @param string $class_name  The module class, for whether it is Bootable.
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return array{hook: string|null, priority: int}
	 */
	protected function get_boot_timing( string $source_path, string $class_name, string $plugin_root ): array {
		$file = \is_dir( $source_path )
			? $source_path . '/' . \basename( $source_path ) . '.php'
			: $source_path;

		$declared = null;
		$priority = array();

		if ( \is_file( $file ) ) {
			$source = (string) \file_get_contents( $file );

			\preg_match( '/^\s*\*\s*@setup-hook\s+(\S+)/m', $source, $hook );
			\preg_match( '/^\s*\*\s*@setup-hook-priority\s+(\d+)/m', $source, $priority );

			$declared = $hook[1] ?? null;
		}

		if ( null === $declared && \is_a( $class_name, Bootable::class, true ) ) {
			$declared = $this->with( RuntimePlugin::class )->get_loaded_hook( $plugin_root );
		}

		return array(
			'hook'     => $declared,
			'priority' => isset( $priority[1] ) ? (int) $priority[1] : 10,
		);
	}

	/**
	 * Write the JavaScript build wiring a block needs.
	 *
	 * Copying the Blocks module gives a plugin the PHP half of a block; the
	 * other half is a build, and a plugin that has never built JavaScript has
	 * nothing to run one with. No `webpack.config.js` is written here:
	 * `wp-scripts` already finds every block by globbing `src/**\/block.json`,
	 * so blocks alone need no config file. {@see set_up_asset_build()} writes
	 * one, because shared packages are the thing `wp-scripts` has no opinion
	 * about.
	 *
	 * Everything is additive. An existing script, dependency or `.gitignore`
	 * entry is left exactly as it was and reported as skipped, so running this
	 * twice changes nothing the second time.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return void
	 */
	protected function set_up_block_build( string $plugin_root ): void {
		$root = \rtrim( $plugin_root, '/\\' );

		$this->merge_package_json( $root . '/package.json' );
		$this->write_if_absent( $root . '/tsconfig.json', $this->get_tsconfig() );
		$this->write_if_absent( $root . '/src/types.d.ts', $this->get_ambient_types() );
		$this->write_if_absent( $root . '/eslint.config.mjs', $this->get_eslint_config() );
		$this->write_prettier_config( $root );
		foreach ( $this->with( GitIgnore::class )->add_entries( $root, array( 'build/', 'vendor/', 'node_modules/' ) ) as $entry ) {
			$this->log( 'Added ' . $entry . ' to .gitignore' );
		}

		$this->log( 'Run `npm install && npm run build` to build your blocks.' );
	}

	/**
	 * Write the build configuration the Assets module reads.
	 *
	 * Assets registers what the build produced, which means something has to
	 * produce it in a shape Assets understands: a `webpack.config.js` that
	 * compiles `src/shared/*` once each and writes the manifest naming them.
	 * `@wordpress/scripts` has no opinion about either, so this is the one
	 * module whose config file is not optional -- without it, `wp zt make
	 * shared` writes a workspace nothing ever builds.
	 *
	 * Additive like everything else here. An existing `webpack.config.js` is
	 * left exactly as it is, which is what makes the generated one safe to edit.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return void
	 */
	protected function set_up_asset_build( string $plugin_root ): void {
		$root = \rtrim( $plugin_root, '/\\' );
		$slug = $this->with( StubRenderer::class )->to_slug(
			$this->with( RuntimePlugin::class )->get_slug_or_default( $plugin_root )
		);

		$written = $this->write_if_absent(
			$root . '/webpack.config.js',
			$this->with( StubRenderer::class )->render(
				$this->with( Path::class )->get_plugin_path( 'src/DevTools/stubs/webpack.config.js.stub' ),
				array(
					'title'      => $this->with( StubRenderer::class )->to_title( $slug ),
					'slug'       => $slug,
					'slug_camel' => $this->with( StubRenderer::class )->to_camel( $slug ),
				)
			)
		);

		// Deliberately not merge_package_json(): that is the block toolchain,
		// and a plugin sharing a formatter has no use for `@wordpress/blocks`.
		$added = $this->with( Tooling::class )->add_npm_dev_dependencies( $plugin_root, Tooling::WEBPACK_PACKAGES );

		if ( array() !== $added ) {
			$this->log( 'Added to package.json: ' . \implode( ', ', $added ) );
		}

		$scripts = $this->with( Tooling::class )->add_scripts( $plugin_root, 'package.json', Tooling::BUILD_SCRIPTS );

		if ( array() !== $scripts ) {
			$this->log( 'Added npm scripts: ' . \implode( ', ', $scripts ) );
		}

		if ( $this->with( Tooling::class )->add_npm_workspaces( $plugin_root, Tooling::WORKSPACE_PATTERN ) ) {
			$this->log( 'Declared the ' . Tooling::WORKSPACE_PATTERN . ' npm workspace in package.json' );
		}

		foreach ( $this->with( GitIgnore::class )->add_entries( $root, array( 'build/', 'node_modules/' ) ) as $entry ) {
			$this->log( 'Added ' . $entry . ' to .gitignore' );
		}

		if ( $written ) {
			$this->with( Formatter::class )->format( $plugin_root, array( $root . '/webpack.config.js' ) );
			$this->log( 'Write shared code with `wp zt make shared <name>`.' );
		}
	}

	/**
	 * Decide what to do about already-present modules before any copying starts.
	 *
	 * Called once per `handle()` invocation with every resolved module whose
	 * destination already exists on disk -- not once per module -- so a
	 * subcommand that needs confirmation can warn about the whole batch and
	 * ask a single yes/no, rather than nagging once per file. Mutate `$to_copy`
	 * (by reference, initially the full resolved list) to control what
	 * actually gets copied: remove an entry to skip it and leave the rest to
	 * proceed to `$this->success( 'Done.' )` as usual (see `commands/add/`),
	 * or return true to cancel the whole command before that success message,
	 * having logged why first (see `commands/overwrite/`'s declined
	 * confirmation).
	 *
	 * @param string[]              $existing_names Resolved module names whose destination already exists.
	 * @param array<string, string> $destinations   Every resolved module's destination path, keyed by name.
	 * @param string[]              $to_copy        The modules that will actually be copied; mutate in place.
	 * @return bool True to cancel the whole command without reaching the success message.
	 */
	abstract protected function filter_existing_modules( array $existing_names, array $destinations, array &$to_copy ): bool;

	/**
	 * Which files under the given destinations the consumer has edited.
	 *
	 * `overwrite` warns by module name, which says how much is at stake only if
	 * you already know what you changed. This names the files that a re-copy
	 * would actually destroy -- a module copied and never touched has none, and
	 * warning about it says nothing worth reading.
	 *
	 * Filters the manifest rather than walking the destinations: it already
	 * lists every copied file with the hash it was written at, so a file on disk
	 * that no longer matches is one that was edited since. A plugin with no
	 * manifest gets an empty list, and the warning falls back to module names.
	 *
	 * @param array<string, string> $destinations Module name => absolute destination path.
	 * @return string[] Plugin-relative paths, sorted.
	 */
	protected function get_edited_files( array $destinations ): array {
		$plugin_root = $this->with( ConsumerPlugin::class )->get_plugin_root();
		$recorded    = $this->with( Manifest::class )->read( $plugin_root )['files'];
		$root        = \rtrim( \wp_normalize_path( $plugin_root ), '/' ) . '/';
		$edited      = array();

		foreach ( $recorded as $relative => $hash ) {
			$absolute = $root . $relative;

			if ( ! \is_file( $absolute ) || ! $this->is_under_any( $absolute, $destinations ) ) {
				continue;
			}

			if ( \hash_file( 'sha256', $absolute ) !== $hash ) {
				$edited[] = $relative;
			}
		}

		\sort( $edited );

		return $edited;
	}

	/**
	 * Whether a path is one of the given destinations, or inside one.
	 *
	 * @param string   $absolute     An absolute file path.
	 * @param string[] $destinations Absolute destination paths, each a file or a directory.
	 * @return bool
	 */
	protected function is_under_any( string $absolute, array $destinations ): bool {
		foreach ( $destinations as $destination ) {
			$destination = \wp_normalize_path( $destination );

			if ( $absolute === $destination || \str_starts_with( $absolute, \rtrim( $destination, '/' ) . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Refuse anything the plugin does not promise a new enough WordPress for.
	 *
	 * Measured against the plugin's own `Requires at least:` header, never against
	 * the WordPress this developer happens to be running. Those are different
	 * questions: a laptop on trunk says nothing about the oldest site the plugin
	 * will be installed on, and that site is where a module registering against a
	 * missing API does its damage. The header is also the one WordPress enforces
	 * itself, so raising it is what stops the plugin activating where it cannot
	 * work.
	 *
	 * An undeclared minimum is not "old enough by default" -- a plugin promising
	 * nothing is one WordPress will activate on anything at all -- so it fails the
	 * same way, and says which line to add.
	 *
	 * Checked across the whole resolved set rather than the names that were typed,
	 * since a dependency is written into the consumer's tree just as surely as the
	 * thing that asked for it -- and says so, or the message names something they
	 * never mentioned.
	 *
	 * Refused rather than warned about. A copied module becomes the plugin's own
	 * source, and one whose API does not exist compiles perfectly and then reports
	 * at every boot that it registered nothing, so the honest moment to say no is
	 * before it is written. Nothing is copied when any one of them fails: a partial
	 * copy would leave a `depends` half-satisfied, which is a state neither `add`
	 * nor `update` has any way to describe.
	 *
	 * @param string[]                                                                                       $resolved The full resolved set, dependencies included.
	 * @param array<string, array{source: string, depends: string[], requires: string|null}> $registry The flattened registry.
	 * @param string[]                                                                                       $args     The names given on the command line.
	 * @return bool False when one was refused, and the caller should stop.
	 */
	protected function assert_wordpress_requirement_is_met( array $resolved, array $registry, array $args ): bool {
		$declared = $this->with( ConsumerPlugin::class )->get_required_wordpress( $this->with( ConsumerPlugin::class )->get_plugin_root() );
		$unmet    = array();
		$highest  = '0';

		foreach ( $resolved as $name ) {
			$required = $registry[ $name ]['requires'] ?? null;

			if ( null === $required ) {
				continue;
			}

			if ( null !== $declared && \version_compare( $declared, $required, '>=' ) ) {
				continue;
			}

			$unmet[] = \sprintf(
				'%s needs WordPress %s%s',
				$name,
				$required,
				\in_array( $name, $args, true ) ? '' : ', and is required by what you asked for'
			);

			if ( \version_compare( $required, $highest, '>' ) ) {
				$highest = $required;
			}
		}

		if ( array() === $unmet ) {
			// Nothing needed a version, so an absent header blocks nothing today.
			// Still worth one line: it is the same missing fact, and the moment
			// code is being added is when it is cheapest to write down.
			if ( null === $declared ) {
				$this->warning( 'This plugin does not declare a `Requires at least:` header, so WordPress will activate it on any version.' );
			}

			return true;
		}

		$this->error(
			\sprintf(
				'%s Nothing was copied: %s. Set `Requires at least: %s` in the entry file header, or leave these out.',
				null === $declared
					? 'This plugin does not declare which WordPress it needs.'
					: \sprintf( 'This plugin declares `Requires at least: %s`.', $declared ),
				\implode( '; ', $unmet ),
				$highest
			)
		);

		return false;
	}

	/**
	 * Merge the block build's scripts and devDependencies into package.json.
	 *
	 * The build commands come from {@see Tooling::BUILD_SCRIPTS}, which is also
	 * what `wp zt init` writes -- a script already defined is never rewritten,
	 * so two spellings would leave whichever command ran first deciding the
	 * flags.
	 *
	 * @param string $file Absolute path to the consuming plugin's package.json.
	 * @return void
	 */
	private function merge_package_json( string $file ): void {
		$scripts = array(
			'format'          => 'wp-scripts format',
			'lint:css'        => 'wp-scripts lint-style',
			'lint:js'         => 'wp-scripts lint-js',
			'lint:pkg-json'   => 'wp-scripts lint-pkg-json',
			'lint:types'      => 'tsc --noEmit',
			'check-engines'   => 'wp-scripts check-engines',
			'check-licenses'  => 'wp-scripts check-licenses',
			'packages-update' => 'wp-scripts packages-update',
			'plugin-zip'      => 'wp-scripts plugin-zip',
		);

		$scripts = \array_merge( Tooling::BUILD_SCRIPTS, $scripts );

		/*
		 * devDependencies rather than dependencies: wp-scripts externalises
		 * every `@wordpress/*` import to the `wp.*` globals WordPress already
		 * enqueues, so none of these ship in the built bundle.
		 */
		$dev_dependencies = array(
			'@wordpress/scripts',
			'@wordpress/block-editor',
			'@wordpress/blocks',
			'@wordpress/components',
			'@wordpress/compose',
			'@wordpress/core-data',
			'@wordpress/data',
			'@wordpress/data-controls',
			'@wordpress/element',
			'@wordpress/hooks',
			'@wordpress/i18n',
			'@wordpress/icons',
			'@wordpress/interactivity',
			'@wordpress/interactivity-router',
			'@wordpress/a11y',
			'@wordpress/api-fetch',
			'@wordpress/dom-ready',
			'@wordpress/keyboard-shortcuts',
			'@wordpress/keycodes',
			'@wordpress/media-utils',
			'@wordpress/notices',
			'@wordpress/plugins',
			'@wordpress/url',
			'@types/wordpress__blocks',
			// Both, because a generated block imports from each: `registerBlockType`
			// and `BlockEditProps` from the first, `useBlockProps` from the second.
			// Without the second, `npm run lint:types` fails on the block it just
			// wrote with TS7016.
			'@types/wordpress__block-editor',
			'typescript',
		);

		$existed = \is_file( $file );

		$package = $existed
			? (array) \json_decode( (string) \file_get_contents( $file ), true )
			: array( 'private' => true );

		$skipped = array();

		foreach ( $scripts as $name => $command ) {
			if ( isset( $package['scripts'][ $name ] ) ) {
				$skipped[] = $name;
				continue;
			}

			$package['scripts'][ $name ] = $command;
		}

		foreach ( $dev_dependencies as $name ) {
			if ( ! isset( $package['devDependencies'][ $name ] ) ) {
				// "*" rather than a pinned range: npm resolves the newest that
				// works, and this toolkit has no business dictating which
				// version of the editor packages a plugin builds against.
				$package['devDependencies'][ $name ] = '*';
			}
		}

		if ( isset( $package['devDependencies'] ) ) {
			\ksort( $package['devDependencies'] );
		}

		\file_put_contents(
			$file,
			(string) \json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);

		$this->log( ( $existed ? 'Updated ' : 'Created ' ) . \basename( $file ) );

		if ( array() !== $skipped ) {
			$this->log( 'Kept your existing scripts: ' . \implode( ', ', $skipped ) );
		}
	}

	/**
	 * Write a file, unless the plugin already has one.
	 *
	 * @param string $file     Absolute path to write.
	 * @param string $contents What to write.
	 * @return bool True when written, false when one was already there.
	 */
	private function write_if_absent( string $file, string $contents ): bool {
		if ( \is_file( $file ) ) {
			$this->log( 'Kept your existing ' . \basename( $file ) . '.' );
			return false;
		}

		// A plugin adding its first block has no `src/` yet, and this is what puts
		// the ambient type declarations there.
		if ( ! \is_dir( \dirname( $file ) ) ) {
			\wp_mkdir_p( \dirname( $file ) );
		}

		\file_put_contents( $file, $contents );
		$this->log( 'Created ' . \basename( $file ) );

		return true;
	}

	/**
	 * Build the commented lead-in written above one module's entry.
	 *
	 * The entry itself is bare, since being listed is all a module needs. A
	 * module that takes configuration gets its configurator written above it,
	 * commented out and listing the setters it accepts. Configuration is
	 * optional for every module, so an active callback would be a change nobody
	 * asked for -- but leaving no trace means the options are only discoverable
	 * in the documentation, away from the file where they are set.
	 *
	 * @param string $source_path Absolute path to the module's copied source.
	 * @return string The commented configuration, or an empty string when the module takes none.
	 */
	private function get_bootstrap_config( string $source_path ): string {
		$setters = $this->get_module_setters( $source_path );

		// Nothing to configure: the entry is just the class, which is all a
		// module needs to be built.
		if ( array() === $setters ) {
			return '';
		}

		$class    = \basename( $source_path, '.php' );
		$variable = $this->get_module_variable( $source_path, $class );

		/*
		 * Commented above the entry rather than inside it: the commented form is
		 * the whole configured entry, which the consumer uncomments over the bare
		 * line -- and until they do, the module still works.
		 */
		$lines = array(
			"\t// " . $class . '::class => static function ( ' . $class . ' $' . $variable . ' ): void {',
		);

		foreach ( $setters as $setter ) {
			$lines[] = "\t//     $" . $variable . '->' . $setter . "( '' );";
		}

		$lines[] = "\t// },";

		return \implode( "\n", $lines ) . "\n";
	}

	/**
	 * The variable name a module's own documentation uses for itself.
	 *
	 * Every configurable module's `@setup` block shows a `configure()`
	 * call, and the name it gives the parameter there is the one a reader has
	 * already seen -- `$pages` for AdminPages, `$api` for RestApi. Lowercasing
	 * the class instead would produce `$adminpages` and `$restapi`.
	 *
	 * @param string $source_path Absolute path to the module's copied source.
	 * @param string $class_name  The module's short class name.
	 * @return string The variable name, without its `$`.
	 */
	private function get_module_variable( string $source_path, string $class_name ): string {
		$file = \is_dir( $source_path )
			? $source_path . '/' . \basename( $source_path ) . '.php'
			: $source_path;

		if ( \is_file( $file ) && \preg_match(
			'/function \(\s*' . \preg_quote( $class_name, '/' ) . '\s+\$(\w+)/',
			(string) \file_get_contents( $file ),
			$match
		) ) {
			return $match[1];
		}

		return \strtolower( $class_name );
	}

	/**
	 * The `set_*()` methods a module's own source declares.
	 *
	 * Read from the copied file rather than listed in the registry, so a module
	 * gaining or losing an option cannot leave the registry describing it
	 * wrongly.
	 *
	 * @param string $source_path Absolute path to the module's copied source.
	 * @return string[] Setter names, in declaration order.
	 */
	private function get_module_setters( string $source_path ): array {
		// A module with a directory of its own keeps the class in a file of the
		// same name inside it.
		$file = \is_dir( $source_path )
			? $source_path . '/' . \basename( $source_path ) . '.php'
			: $source_path;

		if ( ! \is_file( $file ) ) {
			return array();
		}

		\preg_match_all(
			'/public function (set_\w+)\(/',
			(string) \file_get_contents( $file ),
			$matches
		);

		return $matches[1];
	}

	/**
	 * Ambient declarations for what a generated block imports but TypeScript cannot see.
	 *
	 * A block's `index.tsx` imports its own stylesheets for their side effect, so
	 * webpack picks them up -- and TypeScript has no declaration for a `.css`
	 * module, so it reports `TS2882` twice per block. Declaring them once under
	 * `src/` (which is what `tsconfig.json` includes) is the whole fix.
	 *
	 * @return string
	 */
	private function get_ambient_types(): string {
		return \implode(
			"\n",
			array(
				'// Stylesheets are imported for their side effect, so webpack emits them.',
				'// TypeScript needs telling they are modules at all.',
				"declare module '*.css';",
				"declare module '*.scss';",
				'',
			)
		);
	}

	/**
	 * The TypeScript config a generated block type-checks against.
	 *
	 * `resolveJsonModule` is what lets a block's index.tsx import its own
	 * block.json for the name, so the name is never written twice.
	 *
	 * @return string
	 */
	private function get_tsconfig(): string {
		return (string) \json_encode(
			array(
				'compilerOptions' => array(
					'target'            => 'ES2020',
					'module'            => 'ESNext',
					'moduleResolution'  => 'bundler',
					'jsx'               => 'react-jsx',
					'strict'            => true,
					'noEmit'            => true,
					'resolveJsonModule' => true,
					'esModuleInterop'   => true,
					'skipLibCheck'      => true,
				),
				'include'         => array( 'src' ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";
	}

	/**
	 * An ESLint config extending the one `wp-scripts` already ships.
	 *
	 * `create-block` writes no config at all, because `wp-scripts lint-js`
	 * falls back to its own. That fallback does not cover `.ts`/`.tsx`, which
	 * is what `make block` generates, so the extension list is widened here
	 * rather than left to fail quietly on files it skips.
	 *
	 * @return string
	 */
	private function get_eslint_config(): string {
		return \implode(
			"\n",
			array(
				'// Extends the config @wordpress/scripts ships, widening it to the',
				'// TypeScript files `wp zt make block` generates. Edit freely.',
				"import wordpress from '@wordpress/scripts/config/eslint.config.cjs';",
				'',
				'export default [',
				'	...wordpress,',
				'	{',
				"		files: [ 'src/**/*.{js,jsx,ts,tsx}' ],",
				'		languageOptions: {',
				'			parserOptions: {',
				'				ecmaFeatures: { jsx: true },',
				'			},',
				'		},',
				'	},',
				'	{',
				"		ignores: [ 'build/**', 'vendor/**', 'node_modules/**' ],",
				'	},',
				'];',
				'',
			)
		);
	}

	/**
	 * Point Prettier at WordPress's own formatting rules, if nothing else has.
	 *
	 * `wp-scripts format` uses those rules whether or not a config file exists;
	 * writing one means an editor's format-on-save agrees with what that
	 * command does, rather than reformatting every file the other way on each
	 * save.
	 *
	 * The same file `wp zt init` writes, from the same stub, and skipped when
	 * any of {@see Tooling::PRETTIER_CONFIG_FILES} is already there. Prettier
	 * reads the first name it resolves and ignores the rest, so a second config
	 * under a different name is not a second opinion -- it is a file that never
	 * applies, and one that reads as configuration to everyone but Prettier.
	 *
	 * @param string $root Absolute path to the consuming plugin's root, without a trailing slash.
	 * @return void
	 */
	private function write_prettier_config( string $root ): void {
		if ( $this->with( Tooling::class )->has_prettier_config( $root ) ) {
			$this->log( 'Kept your existing Prettier configuration.' );
			return;
		}

		$this->write_if_absent(
			$root . '/.prettierrc.js',
			$this->with( StubRenderer::class )->render(
				$this->with( Path::class )->get_plugin_path( 'src/DevTools/stubs/prettierrc.js.stub' ),
				array()
			)
		);
	}

	/**
	 * The `wp zt <word>` this subcommand registers under, for usage messages.
	 *
	 * The verb alone -- `add` or `overwrite` -- since the kind is the word after
	 * it.
	 *
	 * @return string
	 */
	abstract protected static function get_word(): string;

	/**
	 * The past-tense verb logged after each module is copied ("Added"/"Overwrote").
	 *
	 * @return string
	 */
	abstract protected static function get_past_tense(): string;
}
