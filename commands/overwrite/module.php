<?php

/**
 * Devtool command: `wp zestry overwrite module <module>...`.
 *
 * Like `wp zestry add module`, but deliberately replaces a module already present
 * on disk instead of skipping it -- warns once, listing every already-present
 * module the batch would touch, and proceeds only after a single explicit
 * confirmation covering the whole batch. Any local edits to those files are
 * lost; there is no per-file undo.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\AddCommand;

return new class() extends AddCommand {

	/**
	 * Copy one or more feature modules into an initialized plugin, replacing
	 * any of them (or their dependencies) already present.
	 *
	 * Requires `wp zestry init` to have already run in this plugin. Resolves
	 * dependencies exactly like `wp zestry add module`, but warns before
	 * overwriting anything already on disk: local edits to an already-present
	 * module are destroyed by the copy, with no confirmation per file -- only
	 * one confirmation for the whole resolved batch. Answering "no" cancels the
	 * command entirely; nothing is copied, not even modules that were not
	 * already present.
	 *
	 * Dependencies cross the two kinds, so a module's services are re-copied
	 * with it. To replace a service on its own, use
	 * `wp zestry overwrite service <service>`.
	 *
	 * ## OPTIONS
	 *
	 * <module>...
	 * : One or more module names to overwrite (or add, if not already present).
	 * Available modules: log, options, assets, ajax, admin-pages, rest-api, cli, cron, fields, meta-boxes, post-types, blocks, site-health, abilities, migrations.
	 *
	 * [--yes]
	 * : Answer any confirmation prompt affirmatively, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Re-copy cli from the toolkit, discarding any local edits to it.
	 *     $ wp zestry overwrite module cli
	 *     Warning: This will overwrite existing files for: cli
	 *     Any local changes to these files will be lost. Continue? [y/N] y
	 *     Overwrote cli
	 *     Success: Done.
	 *
	 *     # Declining leaves every file untouched, including new deps.
	 *     $ wp zestry overwrite module rest-api
	 *     Also adding required dependencies: path
	 *     Warning: This will overwrite existing files for: rest-api
	 *     Any local changes to these files will be lost. Continue? [y/N] n
	 *     Cancelled.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	protected function filter_existing_modules( array $existing_names, array $destinations, array &$to_copy ): bool {
		$this->warning( 'This will overwrite existing files for: ' . implode( ', ', $existing_names ) );

		// Named individually when the manifest can identify them: "your edits to
		// these three files" is a decision, where "local changes may be lost" is
		// a disclaimer to click through.
		$edited = $this->get_edited_files( array_intersect_key( $destinations, array_flip( $existing_names ) ) );

		foreach ( $edited as $relative ) {
			$this->log( '  edited  ' . $relative );
		}

		$question = array() === $edited
			? 'Any local changes to these files will be lost. Continue?'
			: sprintf( 'Your edits to %d file%s will be lost. Continue?', count( $edited ), 1 === count( $edited ) ? '' : 's' );

		if ( ! $this->confirm( $question, false ) ) {
			$this->log( 'Cancelled.' );
			return true;
		}

		return false;
	}

	protected static function get_word(): string {
		return 'overwrite';
	}

	protected static function get_past_tense(): string {
		return 'Overwrote';
	}

	protected static function get_kind(): string {
		return 'modules';
	}
};
