<?php

/**
 * Devtool command: `wp zestry init`.
 *
 * One-time setup for a consuming plugin: asks for a target namespace, a text
 * domain, and a destination directory (relative to the plugin's own root),
 * copies the kernel (Plugin, Service, Module, the PluginAware contract, the
 * shared traits) into `{root}/Core/Kernel/` -- with every
 * `namespace Zestry\WPToolkit\...;`/`use Zestry\WPToolkit\...;` rewritten to the chosen namespace, and
 * every `'zestry-toolkit'` text-domain string literal rewritten to the chosen text
 * domain -- then writes `zestry.json` recording those choices, `zestry.lock.json`
 * recording what was written, a `bootstrap.php`, and a `.gitignore` covering
 * what is built rather than authored, and adds a matching PSR-4 autoload entry
 * to the plugin's own composer.json. Run `wp zestry add module <name>` afterward
 * to copy in individual feature modules.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\DevTools\AgentInstructions;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\DevTools\GitIgnore;
use Zestry\WPToolkit\DevTools\Manifest;
use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\DevTools\StubRenderer;
use Zestry\WPToolkit\DevTools\Formatter;
use Zestry\WPToolkit\DevTools\Tooling;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Services\Path;

return new class() extends Command {

	/**
	 * Renderer for the instructions an agent finds in the plugin.
	 *
	 * @var AgentInstructions
	 */
	public AgentInstructions $agent_instructions;

	/**
	 * @var ConsumerPlugin
	 */
	public ConsumerPlugin $consumer_plugin;

	/**
	 * @var ZestryConfig
	 */
	public ZestryConfig $zestry_config;

	/**
	 * @var Copier
	 */
	public Copier $copier;

	/**
	 * @var Path
	 */
	public Path $path;

	/**
	 * @var GitIgnore
	 */
	public GitIgnore $gitignore;

	/**
	 * @var Manifest
	 */
	public Manifest $manifest;

	/**
	 * @var StubRenderer
	 */
	public StubRenderer $stub_renderer;

	/**
	 * @var Tooling
	 */
	public Tooling $tooling;

	/**
	 * @var Formatter
	 */
	public Formatter $formatter;

	/**
	 * Set up a plugin to receive wp-toolkit source.
	 *
	 * One-time, interactive setup for the plugin that required `wp-toolkit` as a
	 * Composer dependency. Prompts for the namespace the copied source should be
	 * rewritten to, the text domain its translation calls should be rewritten
	 * to, and the directory (relative to your plugin's root) to copy it into,
	 * then copies the kernel -- Plugin, Service, Module, ActivationHandler, the
	 * PluginAware contract, and the shared traits every class needs -- into
	 * `{root}/Core/Kernel/`.
	 *
	 * Four files are written around that copy: `zestry.json`, recording the three
	 * choices above; `zestry.lock.json`, recording the hash of every copied file as
	 * it was written, which is what later lets `wp zestry update` tell an edit of
	 * yours from an upstream change; `bootstrap.php`, the file your modules are
	 * declared in; and `.gitignore`, covering the directories that are built
	 * rather than authored. It then adds a matching PSR-4 autoload entry to your
	 * own composer.json and shells out to `composer dump-autoload`, so the
	 * copied classes load without a further step.
	 *
	 * Refuses to run if zestry.json already exists, since that means the
	 * plugin has already been initialized; run `wp zestry add module <name>`
	 * instead to copy in additional feature modules.
	 *
	 * That directory is where the plugin's *own* classes belong too, beside
	 * the copied source rather than in a second root of their own. After this
	 * command there is no "toolkit" half to keep separate from: it is all the
	 * plugin's code, under one namespace and one PSR-4 entry. A second root
	 * would need a second PSR-4 prefix, which every class beneath it then
	 * carries as an extra namespace segment.
	 *
	 * Do not choose `src`: `@wordpress/scripts` treats it as its source path,
	 * and `wp zestry make block` writes into `src/blocks/`.
	 *
	 * ## TOOLING
	 *
	 * After the source is copied, this sets up the things a plugin usually
	 * wants and nothing else provides. Each is written without asking -- pass the
	 * matching `--no-` flag below to skip one.
	 *
	 * Each writes only what is missing: a config file already on disk is left
	 * alone, a dependency already required keeps whatever constraint it has,
	 * and an existing script is never rewritten -- so running this in a
	 * configured plugin changes nothing.
	 *
	 * - `phpcs.xml`, with the same ruleset the copied source is written to, so
	 *   `composer lint` passes on the code you were just handed rather than
	 *   flagging it. Adds the three phpcs packages, allows the standards
	 *   installer to run, and adds `composer lint` / `composer lint:fix`.
	 *
	 * - `eslint.config.mjs`, extending `@wordpress/eslint-plugin`. Flat config,
	 *   because that is the only format the ESLint bundled with current
	 *   `@wordpress/scripts` reads. Adds its npm packages and `npm run lint:js`.
	 *
	 * - `.prettierrc.js`, re-exporting `@wordpress/prettier-config`. It is
	 *   CommonJS, so rename it to `.prettierrc.cjs` if your package.json
	 *   declares `"type": "module"`. Adds its npm packages and `npm run format`,
	 *   plus a `.prettierignore` -- that script is `prettier --write .`, and
	 *   without one it reformats your `composer.json` and your Markdown too.
	 *
	 * - `AGENTS.md`, the invariants an agent working in this plugin needs, and a
	 *   `.claude/CLAUDE.md` pointing at it. Rendered from the toolkit's own rules
	 *   page rather than written twice, and describing no feature of your plugin
	 *   -- `wp zestry describe` answers that from the plugin itself.
	 *
	 * Dependencies are added unversioned. The pin belongs in your lock file,
	 * written the first time you install.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Take the default answer to every prompt, for an unattended run. The
	 * namespace is inferred from composer.json's PSR-4 entry, the text domain
	 * from the entry file's own `Text Domain:` header (or the directory name
	 * when it declares none), and the root is whatever composer.json already
	 * maps that namespace to, falling back to `lib`. Fails with a message
	 * naming what to fix when either inferred value is unusable, rather than
	 * proceeding with a wrong one.
	 *
	 *
	 * A brand-new plugin has no PSR-4 entry yet -- `init` is what writes one --
	 * so there is nothing to infer a namespace from and this stops rather than
	 * guessing. For an unattended first run, declare the entry in composer.json
	 * yourself before calling this; every run after that has one.
 * [--no-phpcs]
	 * : Skip the phpcs.xml and its Composer dev dependencies.
	 *
	 * [--no-eslint]
	 * : Skip the ESLint config and its npm dev dependencies.
	 *
	 * [--no-agents]
	 * : Skip AGENTS.md, the instructions an agent working in this plugin reads.
	 *
	 * [--no-prettier]
	 * : Skip the Prettier config and its npm dev dependencies.
	 *
	 * ## EXAMPLES
	 *
	 *     # A full run. The copied source becomes the plugin's own code, so a
	 *     # plain project namespace is normal here.
	 *     $ wp zestry init
	 *     Namespace (e.g. Vendor\MyPlugin): Vendor\MyPlugin
	 *     Text domain: (default: my-plugin) my-plugin
	 *     Source directory: (default: lib) lib
	 *     Copy the kernel into lib/Core/Kernel/ under Vendor\MyPlugin? [Y/n] y
	 *     Created bootstrap.php. Read it with `$plugin->bootstrap()` in your entry file.
	 *     Added to .gitignore: build/, vendor/, node_modules/, .DS_Store, *.log
	 *     Wrote phpcs.xml
	 *     Added to composer.json: wp-coding-standards/wpcs, ...
	 *     Wrote eslint.config.mjs
	 *     Wrote .prettierrc.js
	 *     Wrote .prettierignore
	 *     Added to package.json: eslint, @wordpress/eslint-plugin, prettier, ...
	 *     Success: Initialized. Run `wp zestry add module <name>` to copy in feature modules.
	 *
	 *     # Unattended, taking every inferred default and setting up all three.
	 *     $ wp zestry init --yes
	 *     Success: Initialized. Run `wp zestry add module <name>` to copy in feature modules.
	 *
	 *     # Unattended, and without the JS tooling.
	 *     $ wp zestry init --yes --no-eslint --no-prettier
	 *     Success: Initialized. Run `wp zestry add module <name>` to copy in feature modules.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$plugin_root = $this->consumer_plugin->get_plugin_root();

		if ( $this->zestry_config->exists( $plugin_root ) ) {
			$this->error( 'zestry.json already exists at ' . $plugin_root . ' -- already initialized.' );
			return;
		}

		$composer_path = $plugin_root . '/composer.json';
		$composer      = $this->read_composer_json( $composer_path );

		$namespace   = $this->ask_for_namespace( $composer );
		$text_domain = $this->ask_for_text_domain( $plugin_root );
		$root        = $this->ask_for_root( $plugin_root, $composer, $namespace );

		/*
		 * Each prompt returns '' when it gave up, having already said why. Under
		 * `--yes` that is the only way out, since re-asking cannot change an
		 * answer nothing is reading. Checked here rather than trusted to
		 * `error()` exiting, so a caller that keeps running -- a test, or WP-CLI
		 * with its own error handler -- does not go on to write a plugin
		 * configured from a rejected answer.
		 */
		if ( '' === $namespace || '' === $text_domain || '' === $root ) {
			return;
		}

		$target_root = Copier::get_target_root( Str::join_path( $plugin_root, trim( $root, '/\\' ) ) );

		if ( ! $this->confirm(
			sprintf( 'Copy the kernel into %s/%s/Kernel/ under %s?', trim( $root, '/\\' ), Copier::COPIED_SEGMENT, $namespace ),
			true
		) ) {
			$this->log( 'Cancelled.' );
			return;
		}

		/*
		 * Only the kernel. Feature modules and services are opt-in through `wp
		 * zestry add`, which writes beside this under the same `Core/` segment --
		 * everything there came from the toolkit and `wp zestry update` may replace
		 * it, everything else under `{root}/` is the consumer's own.
		 */
		$written = $this->copier->copy_directory(
			$this->path->get_plugin_path( 'src/Kernel' ),
			$target_root . '/Kernel',
			Copier::get_target_namespace( $namespace ),
			$text_domain
		);

		$this->zestry_config->write( $plugin_root, $namespace, $root, $text_domain );

		// Recorded as it is written, so `wp zestry update` can later tell a file
		// this wrote from one the consumer has since edited.
		$this->manifest->record( $plugin_root, $written );
		$this->update_composer_autoload( $plugin_root, $composer_path, $composer, $namespace, $root );
		$this->write_bootstrap_file( $plugin_root );
		$this->write_gitignore( $plugin_root );
		$this->offer_tooling( $plugin_root, $root, $text_domain, $assoc_args );

		$this->success( 'Initialized. Run `wp zestry add module <name>` to copy in feature modules.' );
	}

	/**
	 * Read and decode the consuming plugin's composer.json, if it has one.
	 *
	 * @param string $composer_path Absolute path to the plugin's composer.json.
	 * @return array<string, mixed>|null The decoded data, or null if the file is missing or unreadable.
	 */
	private function read_composer_json( string $composer_path ): ?array {
		if ( ! is_file( $composer_path ) ) {
			return null;
		}

		$content = file_get_contents( $composer_path );
		$data    = false === $content ? null : json_decode( $content, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Find the first PSR-4 namespace already declared in composer.json, if any.
	 *
	 * Used to default the namespace prompt to a plugin's existing autoload
	 * root instead of asking cold, since a plugin that already declares one
	 * PSR-4 namespace almost always wants the copied source to live under
	 * that same namespace rather than a second, unrelated one.
	 *
	 * @param array<string, mixed>|null $composer Decoded composer.json data, or null.
	 * @return string|null The first declared namespace prefix (with trailing backslash trimmed), or null.
	 */
	private function get_existing_namespace( ?array $composer ): ?string {
		$psr4 = $composer['autoload']['psr-4'] ?? null;
		if ( ! is_array( $psr4 ) || array() === $psr4 ) {
			return null;
		}

		return rtrim( (string) array_key_first( $psr4 ), '\\' );
	}

	/**
	 * Find the directory a given namespace already maps to in composer.json, if any.
	 *
	 * @param array<string, mixed>|null $composer         Decoded composer.json data, or null.
	 * @param string                    $target_namespace The namespace to look up.
	 * @return string|null The mapped directory (trailing slash trimmed), or null if not declared.
	 */
	private function get_existing_root( ?array $composer, string $target_namespace ): ?string {
		$mapped = $composer['autoload']['psr-4'][ $target_namespace . '\\' ] ?? null;

		return is_string( $mapped ) ? rtrim( $mapped, '/' ) : null;
	}

	/**
	 * Prompt for and validate the target namespace.
	 *
	 * Defaults to a namespace composer.json already declares for PSR-4
	 * autoloading, if one exists, since the copied source becomes the
	 * plugin's own code and most plugins already have one project-wide
	 * namespace they want it to live under.
	 *
	 * @param array<string, mixed>|null $composer Decoded composer.json data, or null.
	 * @return string
	 */
	private function ask_for_namespace( ?array $composer ): string {
		$existing  = $this->get_existing_namespace( $composer );
		$prompt    = 'Namespace (e.g. Vendor\\MyPlugin):';
		$namespace = $this->ask( $prompt, $existing ?? '' );

		if ( '' === $namespace || 1 !== preg_match( '/^[a-zA-Z_][a-zA-Z0-9_\\\\]*$/', $namespace ) ) {
			// Re-asking under --yes would return this same rejected answer
			// forever, since nothing reads STDIN to change it.
			if ( ! empty( $this->get_assoc_args()['yes'] ) ) {
				$this->error( 'No usable namespace: composer.json has no PSR-4 entry to infer one from. Add one, or run without --yes and type it.' );
				return '';
			}

			$this->warning( 'Invalid namespace format.' );
			return $this->ask_for_namespace( $composer );
		}

		return $namespace;
	}

	/**
	 * Prompt for and validate the target text domain.
	 *
	 * Defaults to whatever the plugin's own entry file declares, falling back
	 * to its directory name -- see {@see get_default_text_domain()} for why the
	 * declaration wins.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return string
	 */
	private function ask_for_text_domain( string $plugin_root ): string {
		$default     = $this->get_default_text_domain( $plugin_root );
		$text_domain = $this->ask( 'Text domain:', $default );

		if ( '' === $text_domain || 1 !== preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $text_domain ) ) {
			if ( ! empty( $this->get_assoc_args()['yes'] ) ) {
				$this->error( sprintf( 'Cannot derive a text domain from "%s". Run without --yes and give one.', basename( $plugin_root ) ) );
				return '';
			}

			$this->warning( 'Invalid text domain format -- use lowercase letters, digits, and hyphens only.' );
			return $this->ask_for_text_domain( $plugin_root );
		}

		return $text_domain;
	}

	/**
	 * The text domain to offer, preferring the plugin's own declaration.
	 *
	 * A plugin's entry file header is authoritative: whatever `Text Domain:`
	 * names there is the domain WordPress loads its translations under. Copying
	 * source stamped with a different one would leave every `__()` in it
	 * pointing at a domain nothing loads, and the strings would silently never
	 * translate -- a failure with no error attached to it.
	 *
	 * The directory name is the fallback for a plugin that declares no header,
	 * which is common enough: WordPress itself falls back to the directory name
	 * for an omitted `Text Domain`.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return string A text domain matching the validator's grammar, or '' if neither source yields one.
	 */
	private function get_default_text_domain( string $plugin_root ): string {
		$declared = $this->get_entry_file_header( $plugin_root, 'Text Domain' );

		// Taken verbatim rather than normalised: this is the domain the plugin
		// already ships under, so "fixing" it would be the mismatch this exists
		// to avoid. An invalid one falls through to the directory instead.
		if ( null !== $declared && 1 === preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $declared ) ) {
			return $declared;
		}

		/*
		 * Normalised, not the bare directory name: `AcmePlugin`, `My_Plugin`
		 * and `acme_plugin` are all ordinary plugin directories, and all three
		 * fail the validator in the caller -- so offering the name verbatim
		 * proposes a default that is then rejected.
		 */
		return $this->to_text_domain( basename( $plugin_root ) );
	}

	/**
	 * Read one header from the plugin's own entry file.
	 *
	 * The entry file is whichever top-level PHP file carries a `Plugin Name:`
	 * header, which is how WordPress itself identifies it -- `{dirname}.php` is
	 * the convention rather than the rule, and plenty of plugins do not follow
	 * it.
	 *
	 * Uses `get_file_data()` rather than `get_plugin_data()`, which needs
	 * `wp-admin` loaded and so is unavailable here, matching
	 * {@see \Zestry\WPToolkit\Kernel\Plugin::get_header()} for the same reason.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @param string $header      The header to read, e.g. `Text Domain`.
	 * @return string|null The trimmed value, or null when there is no entry file or no such header.
	 */
	private function get_entry_file_header( string $plugin_root, string $header ): ?string {
		$files = glob( rtrim( $plugin_root, '/\\' ) . '/*.php' );

		foreach ( false === $files ? array() : $files as $file ) {
			$data = get_file_data(
				$file,
				array(
					'name'   => 'Plugin Name',
					'header' => $header,
				)
			);

			if ( '' === trim( $data['name'] ) ) {
				continue;
			}

			$value = trim( $data['header'] );

			return '' === $value ? null : $value;
		}

		return null;
	}

	/**
	 * A directory name as a text domain.
	 *
	 * Splits camel case first, so `AcmePlugin` becomes `acme-plugin` rather
	 * than `acmeplugin`, then reduces anything else outside the grammar to a
	 * single hyphen.
	 *
	 * @param string $name The plugin directory's own name.
	 * @return string A text domain matching the validator's grammar.
	 */
	private function to_text_domain( string $name ): string {
		$split = (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $name );

		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $split ) ), '-' );
	}

	/**
	 * Prompt for and validate the destination directory.
	 *
	 * Defaults to the directory composer.json already maps the chosen
	 * namespace to, if that namespace is already declared there, so
	 * confirming an existing PSR-4 entry needs only pressing enter twice
	 * rather than retyping a path that must match exactly.
	 *
	 * @param string                    $plugin_root      Absolute path to the consuming plugin's root.
	 * @param array<string, mixed>|null $composer         Decoded composer.json data, or null.
	 * @param string                    $target_namespace The namespace chosen in ask_for_namespace().
	 * @return string
	 */
	private function ask_for_root( string $plugin_root, ?array $composer, string $target_namespace ): string {
		$existing_root = $this->get_existing_root( $composer, $target_namespace );
		$root          = $this->ask( 'Source directory:', $existing_root ?? 'lib' );

		/*
		 * Under `--yes` nothing is read from STDIN, so re-asking hands back the
		 * same rejected answer and this recurses until the stack dies. It is
		 * reachable: a plugin whose composer.json already maps the chosen
		 * namespace to `src/` gets `src` as its default, which the next guard
		 * refuses. `ask_for_namespace()` and `ask_for_text_domain()` both stop
		 * here instead; this did not.
		 */
		$unattended = ! empty( $this->get_assoc_args()['yes'] );

		if ( 1 !== preg_match( '#^[a-zA-Z0-9_/-]+$#', $root ) ) {
			if ( $unattended ) {
				$this->error( sprintf( 'Cannot use "%s" as a source directory. Run without --yes and give one.', $root ) );
				return '';
			}

			$this->warning( 'Invalid path format.' );
			return $this->ask_for_root( $plugin_root, $composer, $target_namespace );
		}

		// `src` belongs to `@wordpress/scripts`, which treats it as its source
		// path, and `wp zestry make block` writes into `src/blocks/`. Copying PHP
		// there would put the two in the same tree for no gain.
		if ( 'src' === trim( $root, '/' ) ) {
			if ( $unattended ) {
				$this->error(
					sprintf(
						'composer.json maps %s to src/, which is reserved for the JavaScript build (wp-scripts). Run without --yes and choose another directory, e.g. "lib".',
						$target_namespace
					)
				);

				return '';
			}

			$this->warning( '"src" is reserved for the JavaScript build (wp-scripts). Choose another directory, e.g. "lib".' );
			return $this->ask_for_root( $plugin_root, $composer, $target_namespace );
		}

		$full_path = $plugin_root . '/' . $root;

		// `confirm()` answers yes under --yes, so this branch cannot loop.
		if ( is_dir( $full_path ) && ! $this->confirm( 'Directory "' . $full_path . '" already exists. Use it anyway?', true ) ) {
			return $this->ask_for_root( $plugin_root, $composer, $target_namespace );
		}

		return $root;
	}

	/**
	 * Write a `.prettierignore`, so `npm run format` stays inside your source.
	 *
	 * The script is `prettier --write .`, which is the whole directory: without
	 * this it reformats `composer.json`, a `.wp-env.json`, and any Markdown you
	 * keep at the root. None of that is wrong, and all of it is wider than a
	 * command called `format` reads.
	 *
	 * Written only when absent, like every other file here, so a project with its
	 * own ignore list keeps it.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return void
	 */
	private function write_prettier_ignore( string $plugin_root ): void {
		$file = $plugin_root . '/.prettierignore';

		if ( \is_file( $file ) ) {
			return;
		}

		$written = \file_put_contents(
			$file,
			\implode(
				"\n",
				array(
					'# Built output and dependencies.',
					'build/',
					'vendor/',
					'node_modules/',
					'',
					'# Files whose formatting is decided elsewhere.',
					'composer.json',
					'composer.lock',
					'package-lock.json',
					'.wp-env.json',
					'*.md',
					'',
				)
			)
		);

		if ( false !== $written ) {
			$this->log( 'Wrote .prettierignore' );
		}
	}

	/**
	 * Cover what a generated plugin should not commit.
	 *
	 * `vendor/` and `node_modules/` are installed from a lockfile and `build/`
	 * is compiled output, so all three are reproducible and none belongs in
	 * history -- but a plugin scaffolded by hand often has no `.gitignore` at
	 * all, and the first `git add .` is where that gets noticed.
	 *
	 * Additive, like everything else this command writes: an existing file keeps
	 * whatever is in it and only gains what is missing.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return void
	 */
	private function write_gitignore( string $plugin_root ): void {
		$added = $this->gitignore->add_entries( $plugin_root );

		if ( array() === $added ) {
			return;
		}

		$this->log( 'Added to .gitignore: ' . implode( ', ', $added ) );
	}

	/**
	 * Set up each linter, unless it was declined.
	 *
	 * Every one is opt-out rather than automatic: these write to composer.json
	 * and package.json, which are the consumer's own files, and a plugin may
	 * well already have its own tooling. Pass `--no-phpcs`, `--no-eslint` or
	 * `--no-prettier` to skip one entirely.
	 *
	 * All three lint the source `init` has just copied, which is the whole
	 * reason they are offered here. Nothing sets up a local WordPress: reaching
	 * this command at all took an active plugin in a running install, so there
	 * is one already.
	 *
	 * @param string               $plugin_root Absolute path to the consuming plugin's root.
	 * @param string               $root        The plugin-relative directory the source was copied into.
	 * @param string               $text_domain The text domain the copied source was rewritten to.
	 * @param array<string, mixed> $assoc_args  WP-CLI's named arguments.
	 * @return void
	 */
	private function offer_tooling( string $plugin_root, string $root, string $text_domain, array $assoc_args ): void {
		$slug = basename( rtrim( $plugin_root, '/\\' ) );

		if ( ! $this->is_declined( $assoc_args, 'phpcs' ) ) {
			$this->write_tooling_file(
				$plugin_root,
				'phpcs.xml',
				'phpcs.xml.stub',
				array(
					'title'       => $this->stub_renderer->to_title( $slug ),
					'root'        => trim( $root, '/\\' ),
					'text_domain' => $text_domain,
				)
			);

			$this->report_packages(
				'composer.json',
				$this->tooling->add_composer_dev_requires( $plugin_root, Tooling::PHPCS_PACKAGES )
			);

			// Without this, Composer declines to run the standards installer and
			// phpcs then reports the WordPress standard as missing despite it
			// being installed.
			$this->tooling->allow_composer_plugin( $plugin_root, Tooling::PHPCS_COMPOSER_PLUGIN );

			$this->report_scripts(
				'composer',
				$this->tooling->add_scripts(
					$plugin_root,
					'composer.json',
					array(
						'lint'     => 'phpcs --standard=phpcs.xml',
						'lint:fix' => 'phpcbf --standard=phpcs.xml',
					)
				)
			);
		}

		if ( ! $this->is_declined( $assoc_args, 'eslint' ) ) {
			$this->write_tooling_file(
				$plugin_root,
				'eslint.config.mjs',
				'eslint.config.mjs.stub',
				array( 'text_domain' => $text_domain )
			);
			$this->report_packages(
				'package.json',
				$this->tooling->add_npm_dev_dependencies( $plugin_root, Tooling::ESLINT_PACKAGES )
			);
			$this->report_scripts(
				'npm',
				$this->tooling->add_scripts( $plugin_root, 'package.json', array( 'lint:js' => 'eslint .' ) )
			);
		}

		if ( ! $this->is_declined( $assoc_args, 'prettier' ) ) {
			$this->write_tooling_file( $plugin_root, '.prettierrc.js', 'prettierrc.js.stub', array() );
			$this->report_packages(
				'package.json',
				$this->tooling->add_npm_dev_dependencies( $plugin_root, Tooling::PRETTIER_PACKAGES )
			);
			$this->report_scripts(
				'npm',
				$this->tooling->add_scripts( $plugin_root, 'package.json', array( 'format' => 'prettier --write .' ) )
			);
			$this->write_prettier_ignore( $plugin_root );
		}

		if ( ! $this->is_declined( $assoc_args, 'agents' ) ) {
			$this->write_agent_instructions( $plugin_root );
		}
	}

	/**
	 * Leave the invariants where an agent working in this plugin will find them.
	 *
	 * An agent opening this plugin sees a `Core/` tree it did not write and a
	 * `bootstrap.php` whose entries look optional. Every convention explaining
	 * those is in the toolkit's documentation, which the plugin does not carry
	 * -- so it either infers them or goes looking, and inference gets the
	 * load-bearing ones wrong.
	 *
	 * Rendered from the toolkit's own rules page rather than written twice, and
	 * additive like everything else here: an existing file is left exactly as
	 * it is, so a plugin that has written its own instructions keeps them.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return void
	 */
	private function write_agent_instructions( string $plugin_root ): void {
		try {
			// From zestry.json rather than from this run's variables: it is written
			// before this point and is what every later command reads, so the
			// instructions describe the plugin the same way the tooling does.
			$contents = $this->agent_instructions->render( $this->zestry_config->read( $plugin_root ) );
		} catch ( \RuntimeException $exception ) {
			$this->warning( $exception->getMessage() );
			return;
		}

		try {
			$written = $this->tooling->write_config_file( $plugin_root, 'AGENTS.md', $contents );
		} catch ( \RuntimeException $exception ) {
			$this->warning( $exception->getMessage() );
			return;
		}

		$this->log( $written ? 'Wrote AGENTS.md' : 'AGENTS.md already exists -- left as it is.' );

		// A pointer, not a copy: Claude Code reads CLAUDE.md, most other tools
		// read AGENTS.md, and two files saying the same thing is the drift this
		// whole file exists to remove.
		$claude_dir = \rtrim( $plugin_root, '/\\' ) . '/.claude';

		if ( ! \is_dir( $claude_dir ) && ! \wp_mkdir_p( $claude_dir ) ) {
			return;
		}

		try {
			$this->tooling->write_config_file( $plugin_root, '.claude/CLAUDE.md', $this->agent_instructions->render_pointer() );
		} catch ( \RuntimeException $exception ) {
			$this->warning( $exception->getMessage() );
		}
	}

	/**
	 * Whether `--no-<name>` was passed.
	 *
	 * WP-CLI's own negation of a declared `[--<name>]` flag arrives as `false`,
	 * which is the only way any of this is turned off: the linters are set up
	 * without asking, so opting out is an explicit act rather than an answer to
	 * a prompt nobody wants to read three times.
	 *
	 * @param array<string, mixed> $assoc_args WP-CLI's named arguments.
	 * @param string               $name       The flag's name.
	 * @return bool
	 */
	private function is_declined( array $assoc_args, string $name ): bool {
		return false === ( $assoc_args[ $name ] ?? null );
	}

	/**
	 * Render a stub into the plugin root, unless that file already exists.
	 *
	 * @param string                $plugin_root Absolute path to the consuming plugin's root.
	 * @param string                $name        The file to write, relative to the plugin root.
	 * @param string                $stub        The stub's file name within src/DevTools/stubs/.
	 * @param array<string, string> $values      Placeholder values for the stub.
	 * @return void
	 */
	private function write_tooling_file( string $plugin_root, string $name, string $stub, array $values ): void {
		$contents = $this->stub_renderer->render(
			$this->path->get_plugin_path( 'src/DevTools/stubs/' . $stub ),
			$values
		);

		try {
			$written = $this->tooling->write_config_file( $plugin_root, $name, $contents );
		} catch ( \RuntimeException $exception ) {
			$this->warning( $exception->getMessage() );
			return;
		}

		if ( $written ) {
			$this->formatter->format( $plugin_root, array( Str::join_path( $plugin_root, $name ) ) );
		}

		$this->log( $written ? 'Wrote ' . $name : $name . ' already exists -- left as it is.' );
	}

	/**
	 * Report the packages a manifest gained, if any.
	 *
	 * @param string   $manifest The manifest's file name.
	 * @param string[] $added    Packages actually added.
	 * @return void
	 */
	private function report_packages( string $manifest, array $added ): void {
		if ( array() === $added ) {
			return;
		}

		$this->log( 'Added to ' . $manifest . ': ' . implode( ', ', $added ) );
	}

	/**
	 * Report the scripts a manifest gained, if any.
	 *
	 * @param string   $runner The tool that runs them, for the message.
	 * @param string[] $added  Script names actually added.
	 * @return void
	 */
	private function report_scripts( string $runner, array $added ): void {
		if ( array() === $added ) {
			return;
		}

		$this->log( 'Added scripts: ' . $runner . ' ' . implode( ', ' . $runner . ' ', $added ) );
	}

	/**
	 * Write the `bootstrap.php` that `wp zestry add module` appends to.
	 *
	 * Left alone if one already exists: it is the plugin's own file the moment
	 * it is written, and `init` has no business replacing declarations someone
	 * has since made in it.
	 *
	 * @param string $plugin_root Absolute path to the consuming plugin's root.
	 * @return void
	 */
	private function write_bootstrap_file( string $plugin_root ): void {
		$destination = rtrim( $plugin_root, '/\\' ) . '/bootstrap.php';

		if ( is_file( $destination ) ) {
			$this->log( 'bootstrap.php already exists -- left as it is.' );
			return;
		}

		$stub = $this->path->get_plugin_path( 'src/DevTools/stubs/bootstrap.php.stub' );

		if ( false === file_put_contents( $destination, (string) file_get_contents( $stub ) ) ) {
			$this->warning( 'Failed to write bootstrap.php -- create it yourself, returning an empty array.' );
			return;
		}

		$this->log( 'Created bootstrap.php. Read it with `$plugin->bootstrap()` in your entry file.' );
	}

	/**
	 * Add a PSR-4 autoload entry for the copied namespace to the consuming
	 * plugin's own composer.json, then refresh its autoloader.
	 *
	 * Warns and asks for confirmation before overwriting when the chosen
	 * namespace is already declared there pointing at a *different*
	 * directory, rather than silently repointing an existing autoload
	 * entry a plugin may still rely on elsewhere.
	 *
	 * @param string                    $plugin_root      Absolute path to the consuming plugin's root.
	 * @param string                    $composer_path    Absolute path to the plugin's composer.json.
	 * @param array<string, mixed>|null $composer         Decoded composer.json data, as read before prompting, or null.
	 * @param string                    $target_namespace The namespace the copied source was rewritten to.
	 * @param string                    $root             The plugin-relative directory the source was copied into.
	 * @return void
	 */
	private function update_composer_autoload( string $plugin_root, string $composer_path, ?array $composer, string $target_namespace, string $root ): void {
		if ( null === $composer ) {
			$this->warning( 'No composer.json found at ' . $plugin_root . ' -- add the autoload entry yourself: "' . $target_namespace . '\\\\": "' . $root . '/"' );
			return;
		}

		$existing_root = $this->get_existing_root( $composer, $target_namespace );
		if ( null !== $existing_root && $existing_root !== rtrim( $root, '/' ) ) {
			$overwrite = $this->confirm(
				sprintf(
					'composer.json already maps "%s\\" to "%s/", not "%s/". Overwrite it?',
					$target_namespace,
					$existing_root,
					rtrim( $root, '/' )
				),
				false
			);

			if ( ! $overwrite ) {
				$this->warning( 'Left composer.json untouched -- add the autoload entry yourself: "' . $target_namespace . '\\\\": "' . $root . '/"' );
				return;
			}
		}

		$composer['autoload']['psr-4'][ $target_namespace . '\\' ] = rtrim( $root, '/' ) . '/';

		$encoded = json_encode( $composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded || false === file_put_contents( $composer_path, $encoded ) ) {
			$this->warning( 'Failed to update composer.json at ' . $composer_path );
			return;
		}

		$this->debug( 'Running composer dump-autoload.' );
		exec( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			sprintf( 'composer dump-autoload --working-dir=%s', escapeshellarg( $plugin_root ) ),
			$output,
			$exit_code
		);

		if ( 0 !== $exit_code ) {
			$this->warning( 'composer dump-autoload failed -- run it yourself in ' . $plugin_root );
		}
	}
};
