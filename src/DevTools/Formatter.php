<?php

/**
 * DevTools: generated-file formatting
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Service;

/**
 * Runs a consuming plugin's own formatters over the files a command wrote.
 *
 * A stub is a template with holes in it, and what fills the holes is not known
 * when the stub is written: a block named `send-welcome-email` pushes a type
 * annotation past the line limit that the same stub keeps under it for a block
 * named `hero`. No amount of care in the stub can settle that, because the
 * answer depends on the name. So the generated file is formatted after it is
 * written, by the same tools the plugin lints itself with -- which is also what
 * makes a generated file look like the code around it rather than like a
 * template.
 *
 * Every tool is the consumer's own, found in their own project and never
 * installed by this. A missing one is not an error: a plugin that declined the
 * tooling scaffolds, or has simply not run `composer install` yet, gets its
 * file written and left alone.
 *
 * | File | Tool |
 * | --- | --- |
 * | `.php` | `vendor/bin/phpcbf` |
 * | `.ts`, `.tsx`, `.js`, `.jsx`, `.mjs`, `.cjs` | `node_modules/.bin/eslint --fix` |
 * | `.json`, `.css`, `.scss`, `.sass` | `node_modules/.bin/prettier --write` |
 *
 * ESLint takes the scripts because in a WordPress project it is the superset:
 * `@wordpress/eslint-plugin`'s recommended config loads `eslint-plugin-prettier`
 * whenever Prettier is installed, so `--fix` formats *and* applies every lint
 * rule that can autofix -- including the `@wordpress/i18n-text-domain` rule the
 * generated config pins, which corrects a wrong text domain rather than only
 * reporting it. Prettier takes them instead when ESLint is not installed or has
 * no configuration.
 *
 * > [!IMPORTANT]
 * > **Never format a copied file.** `wp zt add` records a hash of every file
 * > it writes, and `wp zt update` compares against it to tell an upstream
 * > change from a local edit. Formatting after that hash is taken would report
 * > every copied file as edited, which is the one thing the manifest exists to
 * > get right. This is for generated and edited files only.
 */
class Formatter extends Service {

	/**
	 * Extensions `phpcbf` is asked to fix.
	 *
	 * @var array<int, string>
	 */
	public const PHP_EXTENSIONS = array( 'php' );

	/**
	 * Extensions `eslint --fix` is asked to fix, when it is installed.
	 *
	 * Prettier handles them instead when ESLint is not installed, or has no
	 * configuration to apply.
	 *
	 * @var array<int, string>
	 */
	public const ESLINT_EXTENSIONS = array( 'ts', 'tsx', 'js', 'jsx', 'mjs', 'cjs' );

	/**
	 * Extensions `prettier` is asked to fix.
	 *
	 * Everything a stub directory can hold that is not PHP, since Prettier is
	 * also the fallback for anything ESLint would have taken. ESLint has no
	 * opinion on JSON or a stylesheet, so those are only ever Prettier's.
	 *
	 * @var array<int, string>
	 */
	public const PRETTIER_EXTENSIONS = array(
		'ts',
		'tsx',
		'js',
		'jsx',
		'mjs',
		'cjs',
		'json',
		'css',
		'scss',
		'sass',
	);

	/**
	 * Format the files a command has just written or edited.
	 *
	 * Grouped by tool and run once per tool rather than once per file, since
	 * each is a process start measured in hundreds of milliseconds.
	 *
	 * Quiet by design. This is a convenience on top of a file that is already
	 * written and already correct, so a formatter that is absent, disabled or
	 * unhappy must not turn a successful command into a failed one.
	 *
	 * @param string        $plugin_root Absolute path to the consuming plugin's root.
	 * @param array<string> $files       Absolute paths to files just written.
	 * @return string[] The files handed to a formatter, empty when none could run.
	 */
	public function format( string $plugin_root, array $files ): array {
		if ( array() === $files || ! $this->can_run() ) {
			return array();
		}

		$root      = \rtrim( $plugin_root, '/\\' );
		$formatted = array();

		$php = $this->filter_by_extension( $files, self::PHP_EXTENSIONS );

		if ( array() !== $php && $this->run( $root, $this->get_phpcbf_command( $root ), $php ) ) {
			$formatted = \array_merge( $formatted, $php );
		}

		$eslint  = $this->filter_by_extension( $files, self::ESLINT_EXTENSIONS );
		$command = array() === $eslint ? null : $this->get_eslint_command( $root );

		if ( null !== $command && $this->run( $root, $command, $eslint ) ) {
			$formatted = \array_merge( $formatted, $eslint );
			$files     = \array_values( \array_diff( $files, $eslint ) );
		}

		// Whatever ESLint did not take: JSON and stylesheets always, and the
		// scripts too when there was no ESLint to take them.
		$prettier = $this->filter_by_extension( $files, self::PRETTIER_EXTENSIONS );

		if ( array() !== $prettier && $this->run( $root, $this->get_prettier_command( $root ), $prettier ) ) {
			$formatted = \array_merge( $formatted, $prettier );
		}

		return $formatted;
	}

	/**
	 * The `phpcbf` invocation for a plugin, or null when it cannot be run.
	 *
	 * No `--standard` is passed: phpcbf discovers `phpcs.xml`/`phpcs.xml.dist`
	 * from the working directory, which is the plugin root, and a consumer who
	 * moved or renamed their ruleset has told phpcbf where it is by other means
	 * already. Without any ruleset at all phpcbf has nothing to apply, so the
	 * presence of one is what decides whether this runs.
	 *
	 * @param string $root Absolute path to the consuming plugin's root, without a trailing slash.
	 * @return string|null
	 */
	private function get_phpcbf_command( string $root ): ?string {
		$binary = $root . '/vendor/bin/phpcbf';

		if ( ! \is_file( $binary ) ) {
			return null;
		}

		foreach ( array( 'phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml', '.phpcs.xml.dist' ) as $ruleset ) {
			if ( \is_file( $root . '/' . $ruleset ) ) {
				return \escapeshellarg( $binary ) . ' -q';
			}
		}

		return null;
	}

	/**
	 * The `eslint --fix` invocation for a plugin, or null when it cannot be run.
	 *
	 * ESLint 9 reads flat config only, and refuses to start without one rather
	 * than falling back to defaults -- so a configuration file being present is
	 * what decides whether this runs, the same way a ruleset decides for phpcbf.
	 *
	 * @param string $root Absolute path to the consuming plugin's root, without a trailing slash.
	 * @return string|null
	 */
	private function get_eslint_command( string $root ): ?string {
		$binary = $root . '/node_modules/.bin/eslint';

		if ( ! \is_file( $binary ) ) {
			return null;
		}

		foreach ( array( 'eslint.config.mjs', 'eslint.config.js', 'eslint.config.cjs' ) as $config ) {
			if ( \is_file( $root . '/' . $config ) ) {
				return \escapeshellarg( $binary ) . ' --fix --no-error-on-unmatched-pattern';
			}
		}

		return null;
	}

	/**
	 * The `prettier` invocation for a plugin, or null when it cannot be run.
	 *
	 * Prettier applies its own defaults when a project has no configuration, so
	 * unlike phpcbf it is run on the binary's presence alone -- `wp zt init`
	 * writes a `.prettierrc.js` re-exporting WordPress's own, and a consumer
	 * who declined it still gets consistent files rather than none.
	 *
	 * @param string $root Absolute path to the consuming plugin's root, without a trailing slash.
	 * @return string|null
	 */
	private function get_prettier_command( string $root ): ?string {
		$binary = $root . '/node_modules/.bin/prettier';

		return \is_file( $binary ) ? \escapeshellarg( $binary ) . ' --write --log-level=warn' : null;
	}

	/**
	 * Run one formatter over a set of files, from the plugin root.
	 *
	 * The exit status is deliberately ignored. `phpcbf` reports 1 when it fixed
	 * something, which is the successful case and not distinguishable from a
	 * real failure without parsing its output; the file is already written and
	 * already correct either way.
	 *
	 * @param string        $root    Absolute path to the consuming plugin's root, without a trailing slash.
	 * @param string|null   $command The formatter invocation, or null when it cannot be run.
	 * @param array<string> $files   Absolute paths to pass to it.
	 * @return bool True when the formatter was actually run.
	 */
	private function run( string $root, ?string $command, array $files ): bool {
		if ( null === $command ) {
			return false;
		}

		$arguments = \implode( ' ', \array_map( '\escapeshellarg', $files ) );
		$output    = array();
		$status    = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		\exec(
			\sprintf( 'cd %s && %s %s 2>&1', \escapeshellarg( $root ), $command, $arguments ),
			$output,
			$status
		);

		return true;
	}

	/**
	 * The files among $files whose extension is one of $extensions.
	 *
	 * @param array<string> $files      Absolute paths.
	 * @param array<string> $extensions Lowercase extensions, without a dot.
	 * @return string[]
	 */
	private function filter_by_extension( array $files, array $extensions ): array {
		return \array_values(
			\array_filter(
				$files,
				static function ( string $file ) use ( $extensions ): bool {
					return \in_array(
						\strtolower( (string) \pathinfo( $file, PATHINFO_EXTENSION ) ),
						$extensions,
						true
					);
				}
			)
		);
	}

	/**
	 * Whether this environment can start a process at all.
	 *
	 * `exec()` is disabled on plenty of shared hosts, and there is no in-process
	 * substitute for either formatter. Nothing is faked: the file stays as the
	 * stub rendered it.
	 *
	 * @return bool
	 */
	private function can_run(): bool {
		if ( ! \function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = \array_map( 'trim', \explode( ',', (string) \ini_get( 'disable_functions' ) ) );

		return ! \in_array( 'exec', $disabled, true );
	}
}
