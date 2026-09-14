<?php

/**
 * Queue API: Job base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Queue;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * Base class for a file-based background job.
 *
 * A job file returns a subclass instance, and that instance is the *handler* --
 * one per kind of work, discovered by filename. The work itself is queued
 * separately and repeatedly: `Queue::dispatch( 'send-receipt', array( 'order_id'
 * => 42 ) )` writes a row, and whatever drains the queue later calls
 * {@see handle()} with that payload.
 *
 * That split is the thing to get used to. A file at
 * `resources/jobs/send-receipt.php` is not a receipt waiting to be sent; it is
 * the code that sends one, and it sits there being asked to send thousands.
 * Keep it stateless -- a property set in `handle()` is visible to the next job
 * in the same batch.
 *
 * `wp zt make job <name>` generates a starting point.
 *
 * @example A job, and dispatching it
 * ```
 * // resources/jobs/send-receipt.php
 * return new class() extends Job {
 *
 *     public function queue(): string {
 *         return 'mail';
 *     }
 *
 *     public function handle( array $payload ): bool|WP_Error {
 *         $order = get_post( $payload['order_id'] );
 *
 *         // Anything but true asks to be retried, and says why.
 *         if ( ! $order instanceof WP_Post ) {
 *             return new WP_Error( 'order_gone', 'Order ' . $payload['order_id'] . ' is gone.' );
 *         }
 *
 *         return wp_mail( get_post_meta( $order->ID, 'email', true ), 'Your receipt', '...' );
 *     }
 * };
 * ```
 *
 * ```
 * // Anywhere at all -- the request returns without waiting for the mail.
 * $this->with( Queue::class )->dispatch( 'send-receipt', array( 'order_id' => $order->ID ) );
 * ```
 *
 * @stub job.php.stub
 */
abstract class Job implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * Do the work, and say whether it worked.
	 *
	 * The payload is what was passed to `dispatch()`, decoded from the JSON it
	 * was stored as -- so it holds scalars and arrays, and never the objects you
	 * might have put in an argument list. Load what you need from the ids in it.
	 *
	 * **`true` is the only thing that means done.** Return `false` or a
	 * `WP_Error` and the job is retried; a `WP_Error` is the better of the two,
	 * since its message is what `queue list` shows and its code is what
	 * {@see failed()} can branch on. Much of WordPress already hands you one --
	 * `wp_remote_post()`, `wp_insert_post()` -- so returning it straight through
	 * is usually the whole of your error handling.
	 *
	 * **Throwing works too**, and means the same thing: the exception is caught
	 * and becomes a `WP_Error` coded {@see Queue::ERROR_EXCEPTION}, carrying the
	 * original in its error data. Use whichever the code in front of you
	 * produces; there is no need to translate one into the other.
	 *
	 * Either way the attempt is counted and the job goes back on the queue after
	 * {@see get_retry_delay()}, until {@see get_max_attempts()} is spent -- then
	 * the row is marked `failed` and {@see failed()} is called. Nothing reaches
	 * whatever drained the queue, so one bad job never stops the rest of the
	 * batch.
	 *
	 * Expect to run more than once. A retry re-runs the whole method, so a job
	 * that half-succeeded runs its first half again: make it safe to repeat, or
	 * check before acting.
	 *
	 * @param array<string, mixed> $payload What `dispatch()` was given.
	 * @return bool|\WP_Error True when the work is done; false or a WP_Error to be retried.
	 */
	abstract public function handle( array $payload ): bool|\WP_Error;

	/**
	 * The queue this job's work goes on.
	 *
	 * A queue is a label you drain independently, so it is worth its own name
	 * whenever a kind of work wants a different interval from the rest --
	 * receipts every five minutes, a report rebuild nightly. Work sharing a
	 * queue is drained in the order it was dispatched, so slow work also holds
	 * up whatever is behind it.
	 *
	 * @return string
	 */
	public function queue(): string {
		return Queue::DEFAULT_QUEUE;
	}

	/**
	 * How many times this job may run before it is given up on.
	 *
	 * Counting starts at the first run, so `3` means one attempt and two
	 * retries. `1` never retries.
	 *
	 * An attempt is spent as the job is claimed rather than when it finishes,
	 * which is deliberate: work that kills the whole PHP process leaves nothing
	 * to catch, and without that a job which crashes its worker would crash
	 * every worker forever.
	 *
	 * @return int
	 */
	public function get_max_attempts(): int {
		return 3;
	}

	/**
	 * How long to wait before the next attempt.
	 *
	 * Doubles each time by default -- one minute, two, four -- so a job failing
	 * because something else is down stops hammering it. Return 0 to retry at
	 * the next drain.
	 *
	 * @param int $attempt How many attempts have been made, including the one that just failed.
	 * @return int Seconds to wait.
	 */
	public function get_retry_delay( int $attempt ): int {
		return \MINUTE_IN_SECONDS * ( 2 ** \max( 0, $attempt - 1 ) );
	}

	/**
	 * React to this job being given up on.
	 *
	 * Called once, after the last attempt has failed and the row has been marked
	 * `failed` -- so the record is already written, and this is for telling
	 * somebody rather than for cleaning up. Notify an admin, mark the order, put
	 * a notice somewhere a human will see it.
	 *
	 * Throwing here is caught and logged too, and changes nothing about the row.
	 *
	 * The error is always a `WP_Error`, whichever way the job reported failure.
	 * One from a thrown exception is coded {@see Queue::ERROR_EXCEPTION} and
	 * carries the original under the `exception` key of its error data; one from
	 * a bare `false` is coded {@see Queue::ERROR_FAILED}; anything else is the
	 * `WP_Error` the job itself returned, code and all.
	 *
	 * @param array<string, mixed> $payload What `dispatch()` was given.
	 * @param \WP_Error            $error   The last failure.
	 * @return void
	 */
	public function failed( array $payload, \WP_Error $error ): void {}

	/**
	 * This job's name, as `dispatch()` takes it.
	 *
	 * Your filename without `.php`: `resources/jobs/send-receipt.php` is
	 * `send-receipt`. Unlike most names this toolkit registers, it carries no
	 * plugin slug -- nothing outside your own plugin ever sees it, since the
	 * queue table is already yours.
	 *
	 * @return string
	 */
	final public function get_name(): string {
		return $this->with( Queue::class )->get_name_of( $this );
	}
}
