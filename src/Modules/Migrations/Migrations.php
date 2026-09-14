<?php

/**
 * Migrations API: Migrations module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Migrations;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Modules\CLI\CLI;
use Zestry\WPToolkit\Modules\Options;
use Zestry\WPToolkit\Modules\Path;

/**
 * Discovers plugin database migrations and runs each one, at most once, in
 * filename order.
 *
 * > [!IMPORTANT]
 * > **Nothing here runs on its own. You decide when.** Booting this module
 * > only registers the `wp {slug} migrations run`/`migrations list` commands,
 * > and those are invoked by hand. Call {@see run_pending()} from whatever
 * > fits your release process.
 *
 * A hook cannot close the gap between new code and its migration: WordPress
 * swaps the code in first, so a request landing mid-migration runs new code
 * against the old schema. A release process can. Put the site in maintenance
 * mode, migrate, then let requests back in -- or migrate as a deploy step
 * before the new code goes live at all.
 *
 * A migrations directory contains PHP files named
 * `{timestamp}-{description}.php`, e.g. `20260115120000-create-books-table.php`.
 * The leading timestamp is the sort key, so migrations run in the order you
 * authored them -- not alphabetically by description, and not in filesystem
 * order. Each file returns a {@see Migration} instance. The module wires it and
 * calls `up()` exactly once per site, recording the whole filename without its
 * `.php` extension in a dedicated `Options` group so a later run skips it.
 *
 * That recorded name is the migration's identity, description and all, so
 * renaming any part of the file -- not only its timestamp -- makes it a
 * migration your site has never run, and the next run runs it again.
 *
 * That consequence is visible rather than silent. The identifier that ran is
 * still recorded, so a rename leaves an *orphan*: a recorded name with no file.
 * `migrations list` reports one as `orphaned`, next to the new filename's
 * `pending` row and sharing its timestamp prefix, and `run_pending()` refuses
 * the whole batch when it sees that pair rather than running the migration a
 * second time. Both are reporting only -- identity is still the filename, and
 * a migration still cannot name itself.
 *
 * The two registered commands are `wp {slug} migrations run`, which runs every
 * pending migration and takes `--force`, and `wp {slug} migrations list`, which
 * prints each identifier with a `ran`, `pending` or `orphaned` status and takes
 * `--format=<table|csv|json|yaml|count>`, defaulting to `table`.
 *
 * > [!WARNING]
 * > **Keep every migration's timestamp the same width.** Filenames are sorted
 * > as plain strings, with no numeric-aware pass, so mixing widths (some
 * > zero-padded, some not) silently sorts them wrong. `wp zt make migration`
 * > generates a correct `YYYYMMDDHHmmss` prefix -- in UTC, so migrations
 * > authored from different timezones still sort against each other correctly.
 *
 * @example Triggering a run
 * Call `run_pending()` from wherever fits your release process -- here,
 * immediately on activation. Or trigger it from `wp {slug} migrations run` as
 * an explicit deploy step, from a reviewed action on an admin screen, or from
 * anything else that lets you decide exactly when migrations run relative to
 * the new code.
 *
 * A PHP timeout can still cut `run_pending()` off partway through a batch
 * (some migrations recorded as run, some not) -- see
 * {@see maybe_resume_interrupted_run()} for the opt-in recovery mechanism a
 * periodic hook of your own can call to detect and resume that.
 *
 * [`wp zt make activation`](../../commands/make-activation.md) writes the class
 * and declares it. `ActivationHandler` makes both `activate()` and
 * `deactivate()` abstract, so neither can be forgotten -- even when, as here,
 * there is nothing to undo. Its one timing rule is that your entry file must
 * call `run()` as it loads: see
 * [`ActivationHandler`](../activation-handler.md).
 *
 * ```
 * class MyActivation extends ActivationHandler {
 *     public function activate( bool $network_wide ): void {
 *         $this->with( Migrations::class )->run_pending();
 *     }
 *
 *     public function deactivate( bool $network_wide ): void {
 *     }
 * }
 * ```
 */
class Migrations extends Module implements Bootable {

	use WithFolderWalker;

	/**
	 * The Options group the list of migrations that have run is stored under.
	 *
	 * Its own group, so the record cannot collide with a setting of yours.
	 */
	const OPTIONS_GROUP_NAME = '_migrations_';

	/**
	 * Default plugin-relative directory of migration files.
	 */
	const MIGRATIONS_ROOT = 'resources/migrations';

	/**
	 * Options key for the array of migration identifiers already run.
	 */
	private const RAN_KEY = 'ran';

	/**
	 * Options key for the in-progress-run timestamp {@see run_pending()} writes.
	 */
	private const RUNNING_SINCE_KEY = 'running_since';

	/**
	 * Every migration identifier -- its filename without the `.php` extension,
	 * e.g. `20260115120000-create-books-table` -- already recorded as run, in
	 * the order they ran.
	 *
	 * @return string[]
	 */
	public function get_ran_migrations(): array {
		return $this->get_migrations_options()->get( self::RAN_KEY, array() );
	}

	/**
	 * Every migration identifier discovered in the migrations directory, in
	 * filename order, regardless of whether it has run.
	 *
	 * Exposed for `wp {slug} migrations list` ({@see ListMigrationsCommand}),
	 * separate from {@see run_pending()} so listing never requires (and
	 * cannot accidentally trigger) requiring or running any migration file.
	 *
	 * @return string[]
	 */
	public function get_discovered_migrations(): array {
		$root_dir = $this->with( Path::class )->get_plugin_path( self::MIGRATIONS_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			return array();
		}

		$files = $this->walk_folder( $root_dir, array( 'php' ), 1 );
		// walk_folder() already returns sorted paths; the timestamp-prefixed
		// filename is what that sort orders by, and therefore the run order.

		return \array_map(
			static function ( string $file ): string {
				return \basename( $file, '.php' );
			},
			$files
		);
	}

	/**
	 * Every discovered migration that has not run on this site yet.
	 *
	 * What the next {@see run_pending()} would execute, in the order it would
	 * execute them, and an empty array when the database is level with the code.
	 *
	 * Worth asking from an {@see \Zestry\WPToolkit\Kernel\Abstracts\UpdateHandler}:
	 * that compares plugin versions, which answers whether the *code* changed
	 * rather than whether the *schema* is behind. The two diverge whenever a
	 * migration is added without a version bump -- routine in development -- and
	 * the migration then waits for a release that may be days away.
	 *
	 * Requires no migration file, like {@see get_discovered_migrations()}: this
	 * is filenames against a recorded list, and nothing here runs anything.
	 *
	 * @return string[] Pending identifiers, in run order.
	 */
	public function get_pending_migrations(): array {
		return \array_values(
			\array_diff( $this->get_discovered_migrations(), $this->get_ran_migrations() )
		);
	}

	/**
	 * Every migration identifier recorded as run for which no file exists.
	 *
	 * An orphan means one of exactly two things, and nothing here tries to tell
	 * them apart: the file was renamed, or it was deleted. The first is
	 * dangerous -- the migration is about to run a second time under its new
	 * name -- and the second is usually deliberate. Both are worth seeing.
	 *
	 * Returned in the order the identifiers ran, since that is the order the
	 * ran-list already holds them in.
	 *
	 * @return string[]
	 */
	public function get_orphaned_migrations(): array {
		return \array_values(
			\array_diff( $this->get_ran_migrations(), $this->get_discovered_migrations() )
		);
	}

	/**
	 * The baseline on disk, if the plugin has one.
	 *
	 * Unlike {@see get_discovered_migrations()}, this **requires every migration
	 * file**: a baseline is one by its class, not by its name, and nothing on
	 * the outside of a file says which it is. That is deliberate -- a filename
	 * convention would be a second source of truth, and the one that drifts.
	 *
	 * Reads no database, so it answers in a build with no MySQL: the question is
	 * about files, and the baseline is committed source like any migration.
	 *
	 * @return string|null The baseline's identifier, or null when there is none.
	 * @throws DiscoveryException When a file returns the wrong value.
	 * @throws ManyBaselinesException When the plugin has more than one baseline.
	 */
	public function get_baseline(): ?string {
		return $this->find_baseline( $this->get_loaded_migrations() );
	}

	/**
	 * Every migration a baseline does not already stand in for.
	 *
	 * What a fresh install still runs one by one after the baseline has done its
	 * work -- everything on disk that {@see Baseline::subsumes()} does not name.
	 * An empty return means a squash is current, and a long one means it is worth
	 * running again. Reads no database, which is what lets a build check it.
	 *
	 * Every migration when the plugin has no baseline at all: with nothing
	 * standing in for anything, a fresh install runs all of them.
	 *
	 * @return string[] Identifiers the baseline does not cover, in run order.
	 * @throws DiscoveryException When a file returns the wrong value.
	 * @throws ManyBaselinesException When the plugin has more than one baseline.
	 */
	public function get_migrations_after_baseline(): array {
		$loaded   = $this->get_loaded_migrations();
		$baseline = $this->find_baseline( $loaded );

		if ( null === $baseline ) {
			return \array_keys( $loaded );
		}

		return \array_values(
			\array_diff( \array_keys( $loaded ), $loaded[ $baseline ]->subsumes(), array( $baseline ) )
		);
	}

	/**
	 * Pending migrations that look like a rename of one that has already run.
	 *
	 * A pending migration is a probable rename when an orphan
	 * ({@see get_orphaned_migrations()}) shares its timestamp prefix and
	 * differs in the rest. That is precise because the prefix is the one part
	 * of a filename this module documents as never safe to change: a rename in
	 * practice keeps it and edits the description, which is exactly this shape.
	 *
	 * A heuristic all the same. Two migrations authored in the same second
	 * would match, which is why {@see run_pending()} refuses rather than
	 * repairs, and why it takes a `$force` to go ahead anyway.
	 *
	 * An identifier with no leading digits has no timestamp to compare and
	 * never matches -- a plugin naming migrations some other way gets no
	 * guesses rather than wrong ones.
	 *
	 * @return array<string, string> Each pending identifier, mapped to the orphan it probably renames.
	 */
	public function get_probable_renames(): array {
		$discovered = $this->get_discovered_migrations();
		$ran        = $this->get_ran_migrations();
		$orphans    = \array_diff( $ran, $discovered );

		if ( array() === $orphans ) {
			return array();
		}

		$renames = array();

		foreach ( $discovered as $identifier ) {
			if ( \in_array( $identifier, $ran, true ) ) {
				continue;
			}

			$timestamp = $this->get_timestamp( $identifier );

			if ( '' === $timestamp ) {
				continue;
			}

			foreach ( $orphans as $orphan ) {
				if ( $timestamp === $this->get_timestamp( $orphan ) ) {
					$renames[ $identifier ] = $orphan;
					break;
				}
			}
		}

		return $renames;
	}

	/**
	 * Discover every migration file, in filename order, and run each one not
	 * already recorded as run.
	 *
	 * Public because nothing calls it automatically. Call it from wherever the
	 * plugin decides migrations should run: an `ActivationHandler::activate()`, a
	 * reviewed action on an admin screen, a deploy script, `wp {slug}
	 * migrations run`, or a hook of the plugin's own.
	 *
	 * > [!IMPORTANT]
	 * > **A failing migration stops the batch, and its exception propagates.**
	 * > The schema is now not what the plugin assumes, and later migrations
	 * > likely build on the one that just failed -- continuing would compound
	 * > the damage silently. Cron's dispatch catches and logs instead, because
	 * > one failed schedule must not stop the others.
	 *
	 * A probable rename ({@see get_probable_renames()}) stops the batch before
	 * anything runs at all, since running a renamed migration a second time is
	 * the damage rather than a symptom of it. Pass `$force` to go ahead: that
	 * runs the rename as the new migration it now looks like, and leaves the
	 * old identifier recorded.
	 *
	 * A {@see Baseline} among the pending migrations changes what happens here,
	 * and only on a site where nothing has ever run: it runs first, and every
	 * migration sorting before it is recorded without being executed. On a site
	 * with a history the baseline is recorded without being executed instead,
	 * and everything else runs one by one as it always did. Nothing about the
	 * call changes either way.
	 *
	 * @param bool $force Run even when a pending migration looks like a rename of one that already ran.
	 * @return void
	 * @throws DiscoveryException When a file returns the wrong value.
	 * @throws RenamedMigrationException When a pending migration looks like a rename and $force is false.
	 */
	public function run_pending( bool $force = false ): void {
		$root_dir    = $this->with( Path::class )->get_plugin_path( self::MIGRATIONS_ROOT );
		$identifiers = $this->get_discovered_migrations();

		if ( ! $force ) {
			$this->refuse_probable_renames();
		}

		$options = $this->get_migrations_options();
		$ran     = $options->get( self::RAN_KEY, array() );

		// Written and saved immediately (not deferred to shutdown) so a run
		// that never reaches the end of this method -- PHP's own
		// max_execution_time, an OOM kill -- leaves a durable trace of an
		// incomplete run for maybe_resume_interrupted_run() to find, rather
		// than depending on a shutdown callback that a hard kill would skip.
		$options->set( self::RUNNING_SINCE_KEY, \time() );
		$options->save();

		/*
		 * Every pending file is loaded before any of them runs, for two reasons.
		 * A baseline has to be found before the migrations it subsumes are run
		 * one by one, which is the whole point of having one. And loading is
		 * `require`, not `require_once`, so reading a file to see what it is and
		 * then reading it again to run it would be fatal for a migration that
		 * declares a named class instead of an anonymous one.
		 */
		$pending = array();

		foreach ( $identifiers as $identifier ) {
			if ( \in_array( $identifier, $ran, true ) ) {
				continue;
			}

			$pending[ $identifier ] = $this->load_migration( $root_dir . '/' . $identifier . '.php' );
		}

		$ran = $this->apply_baseline( $pending, $identifiers, $ran, $options );

		foreach ( $pending as $identifier => $instance ) {
			if ( \in_array( $identifier, $ran, true ) ) {
				continue;
			}

			$instance->up();

			$ran[] = $identifier;
			$options->set( self::RAN_KEY, $ran );
			$options->save();
		}

		// Only cleared on normal completion -- an exception above leaves this
		// set, so a failing migration keeps being retried (and keeps failing
		// loudly) the next time the consumer's own trigger calls run_pending()
		// or maybe_resume_interrupted_run() again.
		$options->set( self::RUNNING_SINCE_KEY, null );
		$options->save();
	}

	/**
	 * Re-run `run_pending()` if a previous run never reached its own end.
	 *
	 * `run_pending()` records a `running_since` timestamp before it starts and
	 * clears it only once every pending migration has run (or thrown). If PHP
	 * is killed mid-run -- `max_execution_time`, an OOM kill -- neither of
	 * which a `try`/`finally` can trap, that timestamp is left behind: proof
	 * migrations 1-3 of 5 ran but 4 and 5 did not.
	 *
	 * This module never calls it for you, the same way it never calls
	 * `run_pending()` for you. For
	 * automatic recovery, hook something periodic of your own -- `admin_init`,
	 * a cron schedule, whatever fits -- and call this from there. Without that,
	 * an interrupted run stays incomplete until your own trigger calls
	 * `run_pending()` again.
	 *
	 * A `running_since` younger than five minutes is left alone. It reads as a
	 * slow run still going on another request, not an interrupted one --
	 * without that, two requests arriving mid-run would both try to resume it
	 * at once. Override {@see get_stale_run_threshold()} in a subclass to move
	 * that line.
	 *
	 * Exempt from the probable-rename refusal, which is why this forces the run
	 * rather than repeating it. This exists to finish a batch a timeout cut in
	 * half, and that batch's pending set was already vetted when it started;
	 * blocking a resume on a heuristic would strand a half-migrated site, which
	 * is the one state worse than either outcome the heuristic guards against.
	 *
	 * @return void
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	public function maybe_resume_interrupted_run(): void {
		$running_since = $this->get_migrations_options()->get( self::RUNNING_SINCE_KEY );

		if ( null === $running_since ) {
			return;
		}

		if ( \time() - $running_since < $this->get_stale_run_threshold() ) {
			return;
		}

		$this->run_pending( true );
	}

	/**
	 * Register the `wp {slug} migrations run`/`list`/`squash` WP-CLI commands,
	 * under WP-CLI only.
	 *
	 * Deliberately the only thing this method does: this module never decides
	 * on its own when migrations should run -- the
	 * CLI commands are themselves consumer-invoked, not automatic, so they are
	 * the one thing safe to register unconditionally.
	 *
	 * Registration goes through CLI's `static` entry point, which needs no CLI
	 * instance -- so a plugin using migrations without file-based commands never
	 * builds the CLI module, while the command names stay namespaced the same
	 * way a discovered command's would be.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function on_boot(): void {
		if ( $this->get_plugin()->is_wp_cli() ) {
			CLI::register_command_for( $this->get_plugin(), 'migrations run', new RunMigrationsCommand() );
			CLI::register_command_for( $this->get_plugin(), 'migrations list', new ListMigrationsCommand() );
			CLI::register_command_for( $this->get_plugin(), 'migrations squash', new SquashMigrationsCommand() );
		}
	}

	/**
	 * Seconds a `running_since` timestamp is trusted to reflect a run still
	 * genuinely in progress, before `maybe_resume_interrupted_run()` treats it
	 * as abandoned and safe to resume.
	 *
	 * Deliberately generous relative to typical PHP execution limits, since
	 * treating a merely slow (but still running) migration as interrupted
	 * would resume it concurrently with itself.
	 *
	 * @return int Seconds; five minutes unless a subclass overrides it.
	 */
	protected function get_stale_run_threshold(): int {
		return 5 * MINUTE_IN_SECONDS;
	}

	/**
	 * Throw if any pending migration looks like a rename of one that has run.
	 *
	 * Reports every match rather than the first, so an operator who renamed
	 * three files fixes three things in one pass instead of three runs.
	 *
	 * @return void
	 * @throws RenamedMigrationException When a pending migration looks like a rename.
	 */
	private function refuse_probable_renames(): void {
		$renames = $this->get_probable_renames();

		if ( array() === $renames ) {
			return;
		}

		$lines = array();

		foreach ( $renames as $pending => $orphan ) {
			$lines[] = \sprintf(
				'%s looks like a rename of %s, which has already run. Running it would execute it a second time.',
				$pending,
				$orphan
			);
		}

		$lines[] = '';
		$lines[] = 'Rename the file back, or force the run to execute it as a new migration'
			. ' (--force on the command, run_pending( true ) in PHP).';

		throw new RenamedMigrationException( \implode( "\n", $lines ) );
	}

	/**
	 * The leading timestamp of an identifier: its digits up to the first `-`.
	 *
	 * Empty when it does not start with one, which is what keeps a plugin
	 * naming its migrations some other way out of the rename heuristic.
	 *
	 * @param string $identifier A migration identifier.
	 * @return string
	 */
	private function get_timestamp( string $identifier ): string {
		\preg_match( '/^\d+/', $identifier, $matches );

		return $matches[0] ?? '';
	}

	/**
	 * Take the baseline into account, and say what is now recorded as run.
	 *
	 * Does nothing at all when there is no baseline pending, which is every
	 * plugin that has never squashed and every site that has already taken one.
	 *
	 * The two cases are opposite instructions, and the thing that tells them
	 * apart is whether this site has any history: nothing recorded means a fresh
	 * install, where the baseline is the fast way to the same schema and the
	 * migrations before it are work that was never needed. Anything recorded
	 * means the site got here the long way, so the baseline's schema is already
	 * present and the migrations it would have subsumed are either behind us or
	 * genuinely pending.
	 *
	 * @param array<string, Migration> $pending     Pending instances, keyed by identifier, in run order.
	 * @param string[]                 $identifiers Every discovered identifier, in run order.
	 * @param string[]                 $ran         Identifiers already recorded as run.
	 * @param Options                  $options     The group the record is written to.
	 * @return string[] The recorded identifiers, with whatever this added.
	 * @throws ManyBaselinesException When more than one baseline is pending.
	 */
	private function apply_baseline( array $pending, array $identifiers, array $ran, Options $options ): array {
		$baseline = $this->find_baseline( $pending );

		if ( null === $baseline ) {
			return $ran;
		}

		$subsumed = array();

		if ( array() === $ran ) {
			$pending[ $baseline ]->up();

			/*
			 * Exactly what the baseline names, and only what is still on disk:
			 * the schema those migrations build up to step by step is what up()
			 * just created in one. Nothing checks that they *would* have produced
			 * it -- the generated baseline is dumped from a database they made,
			 * which is the only check worth having.
			 */
			$subsumed = \array_values(
				\array_diff( \array_intersect( $identifiers, $pending[ $baseline ]->subsumes() ), array( $baseline ) )
			);
		}

		$ran = \array_merge( $ran, $subsumed, array( $baseline ) );

		$options->set( self::RAN_KEY, $ran );
		$options->save();

		return $ran;
	}

	/**
	 * The pending baseline, if there is one.
	 *
	 * @param array<string, Migration> $pending Pending instances, keyed by identifier.
	 * @return string|null The baseline's identifier.
	 * @throws ManyBaselinesException When more than one is pending.
	 */
	private function find_baseline( array $pending ): ?string {
		$found = array();

		foreach ( $pending as $identifier => $instance ) {
			if ( $instance instanceof Baseline ) {
				$found[] = (string) $identifier;
			}
		}

		if ( 1 < \count( $found ) ) {
			throw ManyBaselinesException::for_identifiers( $found );
		}

		return $found[0] ?? null;
	}

	/**
	 * Every discovered migration, loaded and wired, keyed by identifier.
	 *
	 * Requires every file, unlike {@see get_discovered_migrations()}, because the
	 * questions this serves are about what a file *is* rather than what it is
	 * called. Reads no database.
	 *
	 * @return array<string, Migration> Wired instances, in run order.
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	private function get_loaded_migrations(): array {
		$root_dir  = $this->with( Path::class )->get_plugin_path( self::MIGRATIONS_ROOT );
		$instances = array();

		foreach ( $this->get_discovered_migrations() as $identifier ) {
			$instances[ $identifier ] = $this->load_migration( $root_dir . '/' . $identifier . '.php' );
		}

		return $instances;
	}

	/**
	 * Require and wire a single migration file, without running it.
	 *
	 * Running is the caller's, so that run_pending() can read what a file is
	 * before deciding whether it should run at all.
	 *
	 * @param string $file Absolute path to the migration file.
	 * @return Migration The wired instance.
	 * @throws DiscoveryException When the file does not return a Migration instance.
	 */
	private function load_migration( string $file ): Migration {
		/** @var Migration $instance */
		$instance = require $file;

		if ( ! $instance instanceof Migration ) {
			throw new DiscoveryException(
				\sprintf(
					'The file "%s" must return an instance of %s. Got: %s',
					$file,
					Migration::class,
					\is_object( $instance ) ? $instance::class : \gettype( $instance )
				)
			);
		}

		$this->get_plugin()->wire( $instance );

		// Recorded by the caller, not here: run_pending() writes $ran (with
		// this identifier appended) via save() immediately after up() returns,
		// so a migration recorded as run and its up() having actually completed
		// always stay in sync.

		return $instance;
	}

	/**
	 * The dedicated Options group tracking which migrations have run.
	 *
	 * A dedicated group (not the plugin's default, ungrouped Options
	 * instance) so a plugin using Options for its own settings never risks a
	 * key collision with this module's own bookkeeping. Not autoloaded by
	 * default, like any other `Options` group -- a consumer that hooks its own
	 * frequent trigger (e.g. `admin_init`) to `maybe_resume_interrupted_run()`
	 * and wants to save that request the extra query can call
	 * `$plugin->get( Options::class )->add_autoloaded_groups( array( Migrations::OPTIONS_GROUP_NAME ) )`
	 * itself.
	 *
	 * @return Options
	 */
	private function get_migrations_options(): Options {
		return $this->with( Options::class )->group( self::OPTIONS_GROUP_NAME );
	}
}
