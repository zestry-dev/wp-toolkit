<?php

/**
 * Migrations API: Baseline base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Migrations;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * A migration that stands in for every migration before it, on a site that has
 * never run any of them.
 *
 * A plugin two years old has forty migrations, and a fresh install runs all
 * forty in a row -- each one introspecting and diffing the same tables through
 * `dbDelta()` to arrive at a schema the last one could have created outright.
 * On an activation hook that is slow enough to hit a timeout, and none of the
 * work was ever needed: there was no old schema to migrate.
 *
 * A baseline is that last step, written down. `wp {slug} migrations squash`
 * generates one from the schema your migrations have actually produced, so what
 * it creates and what they build cannot disagree.
 *
 * ## When it runs, and when it does not
 *
 * **Only on a site where no migration has ever run.** That is the whole rule,
 * and it is what makes a baseline safe: a site with a history has some of these
 * migrations behind it and the rest genuinely pending, and only running them
 * one by one gets it to the same place.
 *
 * - **Fresh install.** The baseline runs. Every migration sorting before it is
 *   recorded as run without being executed, and anything after it runs as
 *   normal.
 * - **Existing install.** The baseline is recorded as run *without* being
 *   executed -- the schema it describes is already here -- and the migrations
 *   it would have subsumed run individually, exactly as they did before it
 *   existed.
 *
 * Nothing changes at the call site either way: {@see Migrations::run_pending()}
 * is still the one entry point, and your activation handler does not learn that
 * baselines exist.
 *
 * > [!WARNING]
 * > **A baseline carries schema, and only schema.** If a migration it subsumes
 * > also seeded a default option, inserted a row or registered a term, a fresh
 * > install now never does that -- the migration is recorded as run without
 * > running. `migrations squash` lists everything it is about to subsume for
 * > exactly this reason: read that list, and carry anything non-schema into the
 * > baseline's own `up()` by hand.
 *
 * ## What it stands in for
 *
 * {@see subsumes()} names them, one identifier per migration, and nothing else
 * decides. Every other file here takes its place in the run order from its
 * filename; a baseline is not a step in that order but a replacement for a
 * stretch of it, so what it covers is a list. Which is why the file is simply
 * `baseline.php`, with no timestamp to carry: one file, rewritten in place each
 * time you squash, so a squash reads as a diff.
 *
 * The list being explicit is what makes it exact. A migration added later with a
 * backdated filename is not in it, so it still runs; a subsumed migration that
 * has since been deleted is simply skipped. Positional subsumption -- everything
 * sorting before this file -- got both of those wrong, quietly.
 *
 * There is at most one baseline. Squashing again rewrites it; two on disk is a
 * {@see ManyBaselinesException}, since nothing could say which of them a fresh
 * install should believe.
 */
abstract class Baseline extends Migration {

	/**
	 * The name `wp {slug} migrations squash` writes this under, without `.php`.
	 *
	 * Only ever used to decide where that command writes. Nothing reads it to
	 * find a baseline -- discovery does that by class, so renaming the file
	 * changes nothing except where the next squash lands.
	 */
	const FILENAME = 'baseline';

	/**
	 * Every migration this baseline stands in for.
	 *
	 * Identifiers, exactly as `migrations list` prints them: a filename without
	 * its `.php`. Each is recorded as run, without running, on the fresh install
	 * this baseline serves.
	 *
	 * Abstract rather than defaulting to none, because a baseline that stands in
	 * for nothing is not a smaller baseline -- it is one that runs and then lets
	 * every migration run as well, which is the slow path it was written to
	 * replace. Saying so has to be deliberate.
	 *
	 * An identifier here with no file on disk is skipped rather than recorded,
	 * so deleting an old migration does not leave a phantom in the ledger.
	 *
	 * @return string[]
	 */
	abstract public function subsumes(): array;
}
