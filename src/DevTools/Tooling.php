<?php

/**
 * DevTools: linting and formatting scaffold
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\Kernel\Abstracts\Module;

/**
 * Sets a consuming plugin up with the linters the toolkit itself is written to.
 *
 * `wp zt init` offers each of phpcs, ESLint and Prettier, and this writes
 * whatever the consumer accepts: a config file, the dev dependencies it needs,
 * and a script to run it with.
 *
 * Always additive, like {@see GitIgnore}. A config file already on disk is left
 * exactly as it is rather than replaced, a dependency already required keeps
 * whatever constraint it was given, and a script already defined is never
 * rewritten -- so running `init` again in a configured plugin changes nothing.
 *
 * Dependencies are added unversioned (`*` for Composer, `*` for npm) rather
 * than pinned. The pin belongs in the consumer's own lock file, which is
 * written the first time they install; baking a range in here would freeze
 * their tooling to whatever was current when this toolkit was released.
 *
 * One exception, and it is not a pin: `prettier` is installed as an npm alias
 * of `wp-prettier`, and an alias has to name a range. See
 * {@see PRETTIER_PACKAGES} for what plain prettier does instead.
 */
class Tooling extends Module {

	/**
	 * Composer packages the generated `phpcs.xml` needs to run.
	 *
	 * The same three this toolkit requires for its own ruleset -- the copied
	 * source in a consumer's tree is written to that standard, so a thinner
	 * set would flag the code they were just handed.
	 *
	 * @var array<int, string>
	 */
	public const PHPCS_PACKAGES = array(
		'wp-coding-standards/wpcs',
		'sirbrillig/phpcs-variable-analysis',
		'slevomat/coding-standard',
	);

	/**
	 * npm packages the generated `eslint.config.mjs` imports.
	 *
	 * @var array<int, string>
	 */
	public const ESLINT_PACKAGES = array(
		'eslint',
		'@wordpress/eslint-plugin',
	);

	/**
	 * npm packages the generated `.prettierrc` resolves.
	 *
	 * @var array<int|string, string>
	 */
	public const PRETTIER_PACKAGES = array(
		/*
		 * The WordPress fork, under the name everything looks for. Plain
		 * prettier fails two ways, and only the first one says so:
		 * `wp-scripts format` refuses to run against it -- "Incompatible
		 * version of Prettier was found in your project".
		 *
		 * The quiet one is worse. `@wordpress/prettier-config` peers on
		 * `prettier >=3`, so it loads either way -- and then decides what to
		 * configure by reading the installed package's *name*:
		 *
		 *     const isWPPrettier = prettierPackage.name === 'wp-prettier';
		 *     const customOptions = isWPPrettier ? { parenSpacing: true } : {};
		 *
		 * `parenSpacing` exists only in the fork, and it is what puts the
		 * spaces in `( $collection )`. With plain prettier it is dropped, the
		 * config still loads, and every correctly formatted file in the plugin
		 * is reported as wrong by `prettier/prettier` -- "Replace
		 * ` collection: Collection ` with `collection: Collection`".
		 *
		 * Versioned where the rest are not, and the pin is not the point: an
		 * alias has to name a range, and this is the one `@wordpress/scripts`
		 * asks for, so npm resolves the two to a single copy rather than
		 * installing a second beside it.
		 */
		'prettier' => 'npm:wp-prettier@^3.0.3',
		'@wordpress/prettier-config',
	);

	/**
	 * The file names Prettier looks for, in the order it resolves them.
	 *
	 * Prettier reads the first one it finds and ignores the rest, so writing a
	 * second under a different name does not add a configuration -- it adds a
	 * file that never applies. Asking about the whole set is what keeps two
	 * commands from each writing their own: `wp zt init` writes
	 * `.prettierrc.js`, and anything writing later finds it here.
	 *
	 * The `prettier` key in `package.json` is checked separately, since it is
	 * a key rather than a file, but resolves ahead of all of these.
	 *
	 * @var array<int, string>
	 */
	public const PRETTIER_CONFIG_FILES = array(
		'.prettierrc',
		'.prettierrc.json',
		'.prettierrc.yaml',
		'.prettierrc.yml',
		'.prettierrc.json5',
		'.prettierrc.js',
		'.prettierrc.cjs',
		'.prettierrc.mjs',
		'prettier.config.js',
		'prettier.config.cjs',
		'prettier.config.mjs',
		'.prettierrc.toml',
	);

	/**
	 * npm packages the generated `webpack.config.js` requires.
	 *
	 * The extraction plugin is a direct dependency here rather than one reached
	 * through `@wordpress/scripts`: the config imports it by name to replace the
	 * instance the default configuration installs, and importing a package
	 * nothing declares works only until npm hoists differently.
	 *
	 * `webpack-remove-empty-scripts` deletes the JavaScript webpack generates
	 * for an entry that is only a stylesheet -- a file of pure runtime, and a
	 * `<script>` tag for nothing. It recognises the case by every source file
	 * reachable from the entry being a stylesheet, which is why a style-only
	 * entry is built from its stylesheet rather than from an index that imports
	 * one.
	 *
	 * @var array<int, string>
	 */
	public const WEBPACK_PACKAGES = array(
		'@wordpress/scripts',
		'@wordpress/dependency-extraction-webpack-plugin',
		'webpack-remove-empty-scripts',
	);

	/**
	 * The workspace pattern the generated build configuration reads.
	 *
	 * Under `src/`, with the rest of the plugin's JavaScript: that is what
	 * `@wordpress/scripts` treats as its source directory, and where
	 * `wp zt make block` already writes.
	 */
	public const WORKSPACE_PATTERN = 'src/shared/*';

	/**
	 * The npm scripts that drive the JavaScript build.
	 *
	 * One definition, because two commands write them: `add module assets` when it
	 * sets the build configuration up, and `add module blocks` when it lays down a
	 * block toolchain. A script already defined is never rewritten, so two different
	 * strings would mean whichever ran first silently decided the flags -- and
	 * the flags are each load-bearing:
	 *
	 * - `--webpack-copy-php` so every PHP file under `src/` reaches `build/`,
	 *   not just the one a block.json's `render` names.
	 * - `--experimental-modules` so ES module output is built at all. Without
	 *   it a block's `viewScriptModule` and a `"kind": "module"` package are
	 *   both skipped, silently, since nothing asked for either.
	 * - `--blocks-manifest` so the Blocks module can take its one-call
	 *   registration path.
	 *
	 * All three are harmless in a plugin that has none of what they are for.
	 *
	 * @var array<string, string>
	 */
	public const BUILD_SCRIPTS = array(
		'build' => 'wp-scripts build --webpack-copy-php --experimental-modules --blocks-manifest',
		'start' => 'wp-scripts start --webpack-copy-php --experimental-modules --blocks-manifest',
	);

	/**
	 * Composer plugin phpcs needs allowed to discover installed standards.
	 *
	 * Without this, Composer silently declines to run the installer and
	 * `phpcs --standard=phpcs.xml` then fails with "the WordPress standard is
	 * not installed" despite the package being right there in vendor/.
	 */
	public const PHPCS_COMPOSER_PLUGIN = 'dealerdirect/phpcodesniffer-composer-installer';

	/**
	 * Write a config file, unless the plugin already has one.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $name        The file's name, relative to the plugin root.
	 * @param string $contents    What to write.
	 * @return bool True when written, false when a file of that name was already there.
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function write_config_file( string $plugin_root, string $name, string $contents ): bool {
		$path = Str::join_path( $plugin_root, $name );

		if ( \file_exists( $path ) ) {
			return false;
		}

		if ( false === \file_put_contents( $path, $contents ) ) {
			throw new \RuntimeException( 'Failed to write ' . $path );
		}

		return true;
	}

	/**
	 * Whether the plugin already configures Prettier, under any of its names.
	 *
	 * Prettier resolves {@see PRETTIER_CONFIG_FILES} in order and stops at the
	 * first hit, so "does a config exist" is a question about the whole set
	 * rather than about one file name -- and a command that asks about only its
	 * own name writes a file Prettier will never read.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return bool
	 */
	public function has_prettier_config( string $plugin_root ): bool {
		$root = \rtrim( $plugin_root, '/\\' );

		foreach ( self::PRETTIER_CONFIG_FILES as $name ) {
			if ( \file_exists( $root . '/' . $name ) ) {
				return true;
			}
		}

		$package = $this->read_json( $plugin_root, 'package.json' );

		return isset( $package['prettier'] );
	}

	/**
	 * Add packages to composer.json's `require-dev`, unversioned.
	 *
	 * @param string        $plugin_root Absolute path to the consuming plugin's root.
	 * @param array<string> $packages    Package names.
	 * @return string[] The packages actually added, empty when all were already required.
	 * @throws \RuntimeException When composer.json cannot be written.
	 */
	public function add_composer_dev_requires( string $plugin_root, array $packages ): array {
		$composer = $this->read_json( $plugin_root, 'composer.json' );

		if ( null === $composer ) {
			return array();
		}

		$added = array();

		foreach ( $packages as $package ) {
			// A package already in `require` is satisfied; moving it to
			// require-dev would change what ships, which is not this command's
			// call to make.
			if ( isset( $composer['require'][ $package ] ) || isset( $composer['require-dev'][ $package ] ) ) {
				continue;
			}

			$composer['require-dev'][ $package ] = '*';
			$added[]                             = $package;
		}

		if ( array() === $added ) {
			return array();
		}

		$this->write_json( $plugin_root, 'composer.json', $composer );

		return $added;
	}

	/**
	 * Allow a Composer plugin to run, leaving any existing decision alone.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $package     The Composer plugin's package name.
	 * @return bool True when the allowance was added.
	 * @throws \RuntimeException When composer.json cannot be written.
	 */
	public function allow_composer_plugin( string $plugin_root, string $package ): bool {
		$composer = $this->read_json( $plugin_root, 'composer.json' );

		// An explicit `false` is a decision someone made; only an absent key is
		// this command's to fill in.
		if ( null === $composer || isset( $composer['config']['allow-plugins'][ $package ] ) ) {
			return false;
		}

		$composer['config']['allow-plugins'][ $package ] = true;

		$this->write_json( $plugin_root, 'composer.json', $composer );

		return true;
	}

	/**
	 * Add scripts to a manifest, skipping any name already defined.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param string                $manifest    Either `composer.json` or `package.json`.
	 * @param array<string, string> $scripts     Command keyed by script name.
	 * @return string[] The script names actually added.
	 * @throws \RuntimeException When the manifest cannot be written.
	 */
	public function add_scripts( string $plugin_root, string $manifest, array $scripts ): array {
		$data = $this->read_json( $plugin_root, $manifest );

		if ( null === $data ) {
			return array();
		}

		$added = array();

		foreach ( $scripts as $name => $command ) {
			if ( isset( $data['scripts'][ $name ] ) ) {
				continue;
			}

			$data['scripts'][ $name ] = $command;
			$added[]                  = $name;
		}

		if ( array() === $added ) {
			return array();
		}

		$this->write_json( $plugin_root, $manifest, $data );

		return $added;
	}

	/**
	 * Add packages to package.json's `devDependencies`, unversioned.
	 *
	 * Creates a minimal `package.json` when the plugin has none, since a
	 * JavaScript linter is unusable without one and refusing to write it would
	 * leave the consumer with a config file nothing can run.
	 *
	 * A bare entry is added unversioned, which is the rule: the pin belongs in
	 * the consumer's lock file. An entry keyed by name carries its own
	 * specifier, for the one thing a range is not optional for -- an npm alias,
	 * where the specifier says which package the name resolves to rather than
	 * which version of it. Integer key means "no specifier", the same shape
	 * `bootstrap.php` uses for an entry with no configuration.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param array<int|string, string> $packages Package names, or name => specifier.
	 * @return string[] The packages actually added.
	 * @throws \RuntimeException When package.json cannot be written.
	 */
	public function add_npm_dev_dependencies( string $plugin_root, array $packages ): array {
		$package_json = $this->read_package_json( $plugin_root );

		$added = array();

		foreach ( $packages as $key => $value ) {
			$package   = \is_int( $key ) ? $value : $key;
			$specifier = \is_int( $key ) ? '*' : $value;

			if ( isset( $package_json['dependencies'][ $package ] ) || isset( $package_json['devDependencies'][ $package ] ) ) {
				continue;
			}

			$package_json['devDependencies'][ $package ] = $specifier;
			$added[]                                     = $package;
		}

		if ( array() === $added ) {
			return array();
		}

		$this->write_json( $plugin_root, 'package.json', $package_json );

		return $added;
	}

	/**
	 * Declare a workspace pattern in package.json, leaving any existing one alone.
	 *
	 * This is what makes `import { thing } from '@acme-plugin/shared'` resolve --
	 * npm links each directory matching the pattern into `node_modules/` on the
	 * next install, so webpack, TypeScript and ESLint all find the package by
	 * the name it gave itself, with no path aliases to keep in step.
	 *
	 * A package.json already declaring workspaces is left exactly as it is,
	 * even when the pattern is absent: the field is a list the consumer curates,
	 * and appending to it could pull directories into the install that were
	 * deliberately left out.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $pattern     The glob to declare, e.g. `packages/*`.
	 * @return bool True when the declaration was added.
	 * @throws \RuntimeException When package.json cannot be written.
	 */
	public function add_npm_workspaces( string $plugin_root, string $pattern ): bool {
		$package_json = $this->read_package_json( $plugin_root );

		if ( isset( $package_json['workspaces'] ) ) {
			return false;
		}

		$package_json['workspaces'] = array( $pattern );

		$this->write_json( $plugin_root, 'package.json', $package_json );

		return true;
	}

	/**
	 * Read package.json, falling back to the minimal one a plugin would need.
	 *
	 * A JavaScript toolchain is unusable without the file, and refusing to
	 * create it would leave the consumer with a config nothing can run.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return array<string, mixed>
	 */
	private function read_package_json( string $plugin_root ): array {
		return $this->read_json( $plugin_root, 'package.json' ) ?? array(
			'name'    => \basename( \rtrim( $plugin_root, '/\\' ) ),
			'private' => true,
		);
	}

	/**
	 * Read and decode a JSON manifest from the plugin root.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $name        The manifest's file name.
	 * @return array<string, mixed>|null Null when absent or not a JSON object.
	 */
	private function read_json( string $plugin_root, string $name ): ?array {
		$path = Str::join_path( $plugin_root, $name );

		if ( ! \is_file( $path ) ) {
			return null;
		}

		$contents = \file_get_contents( $path );
		$data     = false === $contents ? null : \json_decode( $contents, true );

		return \is_array( $data ) ? $data : null;
	}

	/**
	 * Encode and write a JSON manifest back to the plugin root.
	 *
	 * @param string               $plugin_root Absolute path to the consuming plugin's root.
	 * @param string               $name        The manifest's file name.
	 * @param array<string, mixed> $data        The data to encode.
	 * @return void
	 * @throws \RuntimeException When encoding or writing fails.
	 */
	private function write_json( string $plugin_root, string $name, array $data ): void {
		$path    = Str::join_path( $plugin_root, $name );
		$encoded = \json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $encoded || false === \file_put_contents( $path, $encoded . "\n" ) ) {
			throw new \RuntimeException( 'Failed to update ' . $path );
		}
	}
}
