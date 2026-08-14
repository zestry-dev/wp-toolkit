<?php

/**
 * Devtool command: `wp zt update`.
 *
 * Brings a plugin's copied source up to the toolkit currently installed --
 * the kernel `init` wrote, plus every module and service `add` has copied
 * since -- reporting, before it writes anything, which files it would replace
 * and which of those the consumer has edited.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\DevTools\Manifest;
use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Modules\Path;

return new class() extends Command {

	/**
	 * Re-copy the toolkit source this plugin already has.
	 *
	 * Copying is one-way: a later release of the toolkit does not reach a
	 * plugin that has already run `wp zt init`. This is how you go and get
	 * one. It looks at everything under your `Core/` directory -- the kernel,
	 * and each module or service you have added -- and replaces it with what
	 * the currently installed `zestry-dev/wp-toolkit` would write.
	 *
	 * Nothing outside `Core/` is touched. Your own modules and services live
	 * beside it, and this command cannot see them.
	 *
	 * ## WHAT IT REPORTS
	 *
	 * Every file is one of five things, and the distinction is the point:
	 *
	 * - **unchanged** -- matches what was copied, and upstream has not moved.
	 *   Nothing to do.
	 * - **upstream** -- upstream changed it and you have not. This is the
	 *   update; taking it loses nothing.
	 * - **missing** -- recorded as copied, and no longer on disk. Written back
	 *   along with the upstream changes, without needing `--force`. A file whose
	 *   whole module you deleted is reported as removed instead, and named with
	 *   the command that puts it back: this copies what the plugin has, so it
	 *   never reintroduces a module you took out.
	 * - **edited** -- you changed it and upstream has not. Replacing it would
	 *   discard your work for no gain, so it is **kept as it is**.
	 * - **conflict** -- both. Only these need a decision.
	 *
	 * Edited and conflicted files are the ones named individually, since they
	 * are the ones something of yours is at stake in. The upstream and missing
	 * ones are only counted.
	 *
	 * Telling the two apart needs `zestry.lock.json`, written by `init` and every
	 * `add` since. A plugin that never committed it has none: the
	 * command says so and falls back to reporting only that a file differs,
	 * which cannot say whether you or upstream changed it.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report and stop. Writes nothing, and exits zero whatever it finds.
	 *
	 * [--force]
	 * : Replace edited and conflicted files too, discarding those changes.
	 * Without this they are kept and named in the summary.
	 *
	 * [--yes]
	 * : Answer the confirmation prompt affirmatively, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what a later release would change, before changing anything.
	 *     $ wp zt update --dry-run
	 *     Copied from wp-toolkit 1.2.0; 1.4.0 is installed.
	 *     3 files to update, 1 you have edited, 1 conflicted.
	 *       conflict  lib/Core/Kernel/Abstracts/Module.php
	 *       edited    lib/Core/Modules/Ajax/Ajax.php
	 *     Success: Dry run; nothing written.
	 *
	 *     # A module deleted from the plugin. This copies what you have, so it
	 *     # says so rather than offering to put back what you took out.
	 *     $ wp zt update
	 *     2 files removed with the "ajax" module. `wp zt add module ajax` puts it back.
	 *     Success: Already up to date.
	 *
	 *     # Take it. Your edited files are kept.
	 *     $ wp zt update
	 *     3 files to update, 1 you have edited, 1 conflicted.
	 *     Replace 3 files? [y/N] y
	 *     Success: Updated 3 files. 2 kept as they are.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$plugin_root = $this->with( ConsumerPlugin::class )->get_plugin_root();

		try {
			$config = $this->with( ZestryConfig::class )->read( $plugin_root );
		} catch ( \RuntimeException $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}

		$sources = $this->get_installed_sources( $plugin_root, $config );

		if ( array() === $sources ) {
			$this->error( 'Nothing to update: no copied source found under ' . trim( $config['root'], '/\\' ) . '/' . Copier::COPIED_SEGMENT . '/.' );
			return;
		}

		if ( ! $this->with( Manifest::class )->exists( $plugin_root ) ) {
			$this->warning( 'No zestry.lock.json, so an edited file cannot be told from an upstream change. Every difference is reported as "upstream".' );
		}

		$rendered = $this->render_current( $plugin_root, $config, $sources );
		$statuses = $this->with( Manifest::class )->compare( $plugin_root, $rendered );
		$grouped  = $this->group_by_status( $statuses );

		/*
		 * A recorded file whose whole module is gone is not missing, it is
		 * removed: an update copies what the plugin already has, so nothing here
		 * can put a deleted module back, and reporting it as pending promises a
		 * write that never happens.
		 */
		$removed                      = $this->get_removed_modules( $plugin_root, $config, $grouped[ Manifest::MISSING ] ?? array() );
		$gone                         = array_merge( array(), ...array_values( $removed ) );
		$grouped[ Manifest::MISSING ] = array_values( array_diff( $grouped[ Manifest::MISSING ] ?? array(), $gone ) );

		$this->report_version( $plugin_root );
		$this->report_counts( $grouped );
		$this->report_files( $grouped );
		$this->report_removed( $removed );

		// The lock records what was copied here, and these no longer are --
		// left in, they are reported again on every run for as long as the
		// plugin exists.
		$this->with( Manifest::class )->forget( $plugin_root, $gone );

		if ( $this->is_flag( $assoc_args, 'dry-run' ) ) {
			$this->success( 'Dry run; nothing written.' );
			return;
		}

		$this->apply( $plugin_root, $config, $sources, $grouped, $assoc_args );
	}

	/**
	 * Copy every installed source over, and re-record what was written.
	 *
	 * @param array<string, string> $config     The project's zestry.json.
	 * @param array<string, string> $sources    Source path => destination path, as get_installed_sources() returns.
	 * @param array<string, string[]> $grouped  Status => plugin-relative paths.
	 * @param array<string, mixed>  $assoc_args The command's flags.
	 * @param string                $plugin_root Absolute path to the plugin root.
	 * @return void
	 */
	private function apply( string $plugin_root, array $config, array $sources, array $grouped, array $assoc_args ): void {
		$force = $this->is_flag( $assoc_args, 'force' );

		// The files carrying local work, which --force is the decision to
		// discard. Kept separate from $keep: emptying that to force them would
		// also empty what gets replaced, which is the opposite of forcing.
		$risky  = array_merge( $grouped[ Manifest::EDITED ] ?? array(), $grouped[ Manifest::CONFLICT ] ?? array() );
		$keep   = $force ? array() : $risky;
		$change = array_merge( $grouped[ Manifest::UPSTREAM ] ?? array(), $grouped[ Manifest::MISSING ] ?? array() );

		if ( $force ) {
			$change = array_merge( $change, $risky );
		}

		if ( array() === $change ) {
			$this->success( 'Already up to date.' );
			return;
		}

		/*
		 * One count answers the prompt and the result, because it is one piece of
		 * work: every file left in $change belongs to a module that is installed,
		 * so the copy below writes it. What that copy *returns* is every file in
		 * every tree it walked -- the right input for the manifest a few lines
		 * down, and never a count of what changed.
		 */
		if ( ! $this->confirm( sprintf( 'Replace %d file%s?', count( $change ), 1 === count( $change ) ? '' : 's' ), false ) ) {
			$this->log( 'Cancelled.' );
			return;
		}

		/*
		 * Copy whole trees, then restore the files being kept. Copying only the
		 * changed files would mean re-deriving each one's source path from its
		 * destination, which is the mapping `Copier` already owns -- and getting
		 * it subtly wrong is how a file ends up written from the wrong module.
		 */
		$kept    = $this->read_kept( $plugin_root, $keep );
		$written = array();

		foreach ( $sources as $source => $destination ) {
			$written += is_dir( $source )
				? $this->with( Copier::class )->copy_directory( $source, $destination, Copier::get_target_namespace( $config['namespace'] ), $config['text_domain'] )
				: $this->with( Copier::class )->copy_file( $source, $destination, Copier::get_target_namespace( $config['namespace'] ), $config['text_domain'] );
		}

		$this->restore_kept( $plugin_root, $kept );

		// Recorded from what the copy wrote, then corrected for the restored
		// files: the manifest has to describe the tree as it now stands, or the
		// next run reports every kept file as freshly edited.
		$this->with( Manifest::class )->record( $plugin_root, $written );
		$this->record_kept( $plugin_root, $kept );

		$this->success(
			sprintf(
				'Updated %d file%s.%s',
				count( $change ),
				1 === count( $change ) ? '' : 's',
				array() === $keep ? '' : sprintf( ' %d kept as %s.', count( $keep ), 1 === count( $keep ) ? 'it is' : 'they are' )
			)
		);
	}

	/**
	 * The recorded files whose module is no longer installed, by module name.
	 *
	 * An update copies what the plugin already has, so a module deleted from
	 * disk is left alone rather than reintroduced. Its recorded files therefore
	 * cannot be written back, however missing they look.
	 *
	 * @param string                $plugin_root Absolute path to the plugin root.
	 * @param array<string, string> $config      The project's zestry.json.
	 * @param string[]              $missing     Plugin-relative paths recorded but not on disk.
	 * @return array<string, string[]> Module name => the paths it accounts for.
	 */
	private function get_removed_modules( string $plugin_root, array $config, array $missing ): array {
		if ( array() === $missing ) {
			return array();
		}

		$target_root = Copier::get_target_root( trim( $config['root'], '/\\' ) );
		$registry    = Copier::normalize_registry( require $this->with( Path::class )->get_plugin_path( 'src/DevTools/registry.php' ) );
		$removed     = array();

		foreach ( $registry as $name => $entry ) {
			$source   = Copier::get_relative_source( $entry['source'] );
			$relative = $target_root . '/' . $source;

			if ( file_exists( rtrim( $plugin_root, '/\\' ) . '/' . $relative ) ) {
				continue;
			}

			// A directory module accounts for everything beneath it; a
			// single-file service accounts for itself.
			$paths = array_values(
				array_filter(
					$missing,
					static function ( string $path ) use ( $relative ) {
						return $path === $relative || str_starts_with( $path, $relative . '/' );
					}
				)
			);

			if ( array() !== $paths ) {
				$removed[ $name ] = $paths;
			}
		}

		return $removed;
	}

	/**
	 * Say what was removed, and the one command that puts it back.
	 *
	 * @param array<string, string[]> $removed Module name => the paths it accounts for.
	 * @return void
	 */
	private function report_removed( array $removed ): void {
		foreach ( $removed as $name => $paths ) {
			$this->log(
				sprintf(
					'%d file%s removed with the "%s" module. `wp zt add module %s` puts it back.',
					count( $paths ),
					1 === count( $paths ) ? '' : 's',
					$name,
					$name
				)
			);
		}
	}

	/**
	 * Which of this package's directories this plugin has copied in.
	 *
	 * The kernel is always there, since `init` wrote it. Everything else is a
	 * registry entry whose destination exists -- so what gets updated is exactly
	 * what was added, and a module the consumer never wanted is never introduced
	 * by an update.
	 *
	 * @param string                $plugin_root Absolute path to the plugin root.
	 * @param array<string, string> $config      The project's zestry.json.
	 * @return array<string, string> Source path => destination path.
	 */
	private function get_installed_sources( string $plugin_root, array $config ): array {
		$target_root = Copier::get_target_root( Str::join_path( $plugin_root, trim( $config['root'], '/\\' ) ) );
		$sources     = array();

		if ( is_dir( $target_root . '/Kernel' ) ) {
			$sources[ $this->with( Path::class )->get_plugin_path( 'src/Kernel' ) ] = $target_root . '/Kernel';
		}

		$registry = Copier::normalize_registry( require $this->with( Path::class )->get_plugin_path( 'src/DevTools/registry.php' ) );

		foreach ( $registry as $entry ) {
			$relative    = Copier::get_relative_source( $entry['source'] );
			$destination = $target_root . '/' . $relative;

			if ( file_exists( $destination ) ) {
				$sources[ $this->with( Path::class )->get_plugin_path( 'src/' . $relative ) ] = $destination;
			}
		}

		return $sources;
	}

	/**
	 * Hash what the installed toolkit would write, keyed as the manifest is.
	 *
	 * @param string                $plugin_root Absolute path to the plugin root.
	 * @param array<string, string> $config      The project's zestry.json.
	 * @param array<string, string> $sources     Source path => destination path.
	 * @return array<string, string> Plugin-relative path => sha256.
	 */
	private function render_current( string $plugin_root, array $config, array $sources ): array {
		$namespace = Copier::get_target_namespace( $config['namespace'] );
		$rendered  = array();

		foreach ( $sources as $source => $destination ) {
			$rendered += is_dir( $source )
				? $this->with( Copier::class )->render_directory( $source, $destination, $namespace, $config['text_domain'] )
				: array( $destination => hash( 'sha256', $this->with( Copier::class )->render( $source, $namespace, $config['text_domain'] ) ) );
		}

		$relative = array();
		$root     = rtrim( wp_normalize_path( $plugin_root ), '/' ) . '/';

		foreach ( $rendered as $absolute => $hash ) {
			$absolute = wp_normalize_path( $absolute );
			$key      = str_starts_with( $absolute, $root ) ? substr( $absolute, strlen( $root ) ) : $absolute;

			$relative[ $key ] = $hash;
		}

		return $relative;
	}

	/**
	 * Invert the per-file statuses into lists per status.
	 *
	 * @param array<string, string> $statuses Plugin-relative path => status.
	 * @return array<string, string[]> Status => plugin-relative paths.
	 */
	private function group_by_status( array $statuses ): array {
		$grouped = array();

		foreach ( $statuses as $relative => $status ) {
			$grouped[ $status ][] = $relative;
		}

		return $grouped;
	}

	/**
	 * Report which release the copy came from, and which is installed now.
	 *
	 * @param string $plugin_root Absolute path to the plugin root.
	 * @return void
	 */
	private function report_version( string $plugin_root ): void {
		$was = $this->with( Manifest::class )->read( $plugin_root )['version'];
		$now = $this->with( Manifest::class )->get_toolkit_version();

		if ( null === $was && null === $now ) {
			return;
		}

		$this->log(
			sprintf(
				'Copied from wp-toolkit %s; %s is installed.',
				$was ?? 'an unrecorded version',
				$now ?? 'an unnamed version'
			)
		);
	}

	/**
	 * Report how many files fall into each status.
	 *
	 * @param array<string, string[]> $grouped Status => plugin-relative paths.
	 * @return void
	 */
	private function report_counts( array $grouped ): void {
		$parts = array();

		foreach ( array(
			Manifest::UPSTREAM => '%d file%s to update',
			Manifest::MISSING  => '%d missing',
			Manifest::EDITED   => '%d you have edited',
			Manifest::CONFLICT => '%d conflicted',
		) as $status => $format ) {
			$count = count( $grouped[ $status ] ?? array() );

			if ( 0 === $count ) {
				continue;
			}

			$parts[] = str_contains( $format, '%s' )
				? sprintf( $format, $count, 1 === $count ? '' : 's' )
				: sprintf( $format, $count );
		}

		$this->log( array() === $parts ? 'Everything matches the installed toolkit.' : ucfirst( implode( ', ', $parts ) ) . '.' );
	}

	/**
	 * Name the files that need a decision.
	 *
	 * Only edited and conflicted ones. A list of every file an update touches
	 * is noise -- taking them is the point of running this -- while these are
	 * the ones where something of the consumer's is at stake.
	 *
	 * @param array<string, string[]> $grouped Status => plugin-relative paths.
	 * @return void
	 */
	private function report_files( array $grouped ): void {
		foreach ( array( Manifest::CONFLICT, Manifest::EDITED ) as $status ) {
			foreach ( $grouped[ $status ] ?? array() as $relative ) {
				$this->log( sprintf( '  %-9s %s', $status, $relative ) );
			}
		}
	}

	/**
	 * Read the files an update is keeping, before it overwrites them.
	 *
	 * @param string   $plugin_root Absolute path to the plugin root.
	 * @param string[] $keep        Plugin-relative paths to preserve.
	 * @return array<string, string> Plugin-relative path => its current contents.
	 */
	private function read_kept( string $plugin_root, array $keep ): array {
		$root = rtrim( $plugin_root, '/\\' ) . '/';
		$kept = array();

		foreach ( $keep as $relative ) {
			if ( is_file( $root . $relative ) ) {
				$kept[ $relative ] = (string) file_get_contents( $root . $relative );
			}
		}

		return $kept;
	}

	/**
	 * Put the kept files back after the copy has run over them.
	 *
	 * @param string                $plugin_root Absolute path to the plugin root.
	 * @param array<string, string> $kept        Plugin-relative path => contents.
	 * @return void
	 */
	private function restore_kept( string $plugin_root, array $kept ): void {
		$root = rtrim( $plugin_root, '/\\' ) . '/';

		foreach ( $kept as $relative => $contents ) {
			file_put_contents( $root . $relative, $contents );
		}
	}

	/**
	 * Record the kept files under their own hashes, not the copy's.
	 *
	 * @param string                $plugin_root Absolute path to the plugin root.
	 * @param array<string, string> $kept        Plugin-relative path => contents.
	 * @return void
	 */
	private function record_kept( string $plugin_root, array $kept ): void {
		if ( array() === $kept ) {
			return;
		}

		$manifest = $this->with( Manifest::class )->read( $plugin_root );

		foreach ( $kept as $relative => $contents ) {
			$manifest['files'][ $relative ] = hash( 'sha256', $contents );
		}

		$this->with( Manifest::class )->write( $plugin_root, $this->with( Manifest::class )->get_toolkit_version(), $manifest['files'] );
	}

	/**
	 * Whether a boolean flag was passed.
	 *
	 * @param array<string, mixed> $assoc_args The command's flags.
	 * @param string               $name       The flag's name, without `--`.
	 * @return bool
	 */
	private function is_flag( array $assoc_args, string $name ): bool {
		return isset( $assoc_args[ $name ] ) && false !== $assoc_args[ $name ];
	}
};
