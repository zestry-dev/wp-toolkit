<?php

/**
 * Devtool command: `wp zt overwrite service <service>...`.
 *
 * Like `wp zt add service`, but deliberately replaces a service already
 * present on disk instead of skipping it -- warns once, listing every
 * already-present entry the batch would touch, and proceeds only after a single
 * explicit confirmation covering the whole batch. Any local edits to those
 * files are lost; there is no per-file undo.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\AddCommand;

return new class() extends AddCommand {

	/**
	 * Copy one or more services into an initialized plugin, replacing any of
	 * them (or their dependencies) already present.
	 *
	 * Requires `wp zt init` to have already run in this plugin. Warns before
	 * overwriting anything already on disk: local edits to an already-present
	 * service are destroyed by the copy, with no confirmation per file -- only
	 * one confirmation for the whole resolved batch. Answering "no" cancels the
	 * command entirely; nothing is copied, not even services that were not
	 * already present.
	 *
	 * Reach for this to force one named service back to the source the toolkit
	 * currently ships. To bring the whole copied tree up to date instead, run
	 * `wp zt update`: it re-copies everything under `Core/` -- the kernel and
	 * every module and service you have added -- and keeps the files you have
	 * edited rather than discarding them.
	 *
	 * ## OPTIONS
	 *
	 * <service>...
	 * : One or more service names to overwrite (or add, if not already present).
	 * Available services: path, request, cookie, globals, transients, db, views.
	 *
	 * [--yes]
	 * : Answer any confirmation prompt affirmatively, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Re-copy path from the toolkit, discarding any local edits to it.
	 *     $ wp zt overwrite service path
	 *     Warning: This will overwrite existing files for: path
	 *     Any local changes to these files will be lost. Continue? [y/N] y
	 *     Overwrote path
	 *     Success: Done.
	 *
	 *     # Declining leaves every file untouched, including new deps.
	 *     $ wp zt overwrite service views
	 *     Also adding required dependencies: path
	 *     Warning: This will overwrite existing files for: views
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
		return 'services';
	}
};
