<!--
    Generated from src/Modules/Queue/Job.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Job

[A job, and dispatching it](#a-job-and-dispatching-it) &nbsp;·&nbsp; [Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for a file-based background job.

A job file returns a subclass instance, and that instance is the *handler* — one per kind of work, discovered by filename. The work itself is queued separately and repeatedly: `Queue::dispatch( 'send-receipt', array( 'order_id' => 42 ) )` writes a row, and whatever drains the queue later calls `handle()` with that payload.

That split is the thing to get used to. A file at `resources/jobs/send-receipt.php` is not a receipt waiting to be sent; it is the code that sends one, and it sits there being asked to send thousands. Keep it stateless — a property set in `handle()` is visible to the next job in the same batch.

`wp zt make job <name>` generates a starting point.

## A job, and dispatching it

```php
// resources/jobs/send-receipt.php
return new class() extends Job {

    public function queue(): string {
        return 'mail';
    }

    public function handle( array $payload ): bool|WP_Error {
        $order = get_post( $payload['order_id'] );

        // Anything but true asks to be retried, and says why.
        if ( ! $order instanceof WP_Post ) {
            return new WP_Error( 'order_gone', 'Order ' . $payload['order_id'] . ' is gone.' );
        }

        return wp_mail( get_post_meta( $order->ID, 'email', true ), 'Your receipt', '...' );
    }
};

// Anywhere at all -- the request returns without waiting for the mail.
$this->with( Queue::class )->dispatch( 'send-receipt', array( 'order_id' => $order->ID ) );
```

@stub job.php.stub

## Generated starting point

[`wp zt make job <name>`](../../commands/make-job.md) writes this file:

```php
<?php
/**
 * example background job.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\Queue\Job;

return new class() extends Job {

	// This file is the handler, not the work. Its name -- example -- is what
	// dispatch() takes, and the same instance runs every job queued under it:
	//
	//     $this->with( Queue::class )->dispatch( 'example', array( 'id' => 42 ) );
	//
	// So keep it stateless. A property set here is still set for the next job
	// in the same batch.

	// The queue this job's work goes on. A queue is just a label you drain
	// independently -- worth its own name when this work wants a different
	// interval from the rest, since everything on one queue waits its turn.
	public function queue(): string {
		return 'default';
	}

	// How many times this may run before it is given up on. Counting starts at
	// the first run, so 3 is one attempt and two retries; 1 never retries. The
	// attempt is spent when the job is claimed, not when it finishes, so work
	// that kills the whole PHP process still runs out eventually.
	public function get_max_attempts(): int {
		return 3;
	}

	// How long to wait before the next attempt -- doubling by default, so a job
	// failing because something else is down stops hammering it. Return 0 to
	// retry at the next drain.
	public function get_retry_delay( int $attempt ): int {
		return \MINUTE_IN_SECONDS * ( 2 ** \max( 0, $attempt - 1 ) );
	}

	// The work. $payload is what dispatch() was given, decoded from the JSON it
	// was stored as -- scalars and arrays, never objects, so load what you need
	// from the ids in it.
	//
	// TRUE IS THE ONLY THING THAT MEANS DONE. Return false or a WP_Error and
	// this goes back on the queue until the attempts run out -- a WP_Error is
	// the better of the two, since its message is what `queue list` shows.
	// Most of WordPress hands you one already:
	//
	//     $response = \wp_remote_post( $url, $args );
	//
	//     if ( \is_wp_error( $response ) ) {
	//         return $response;
	//     }
	//
	// Throwing works too and means the same thing; the exception is caught and
	// becomes a WP_Error. Either way nothing reaches whatever drained the
	// queue, so one bad job never stops the batch.
	//
	// Expect to run more than once: a retry re-runs the whole method, so make
	// it safe to repeat, or check before acting.
	public function handle( array $payload ): bool|\WP_Error {
		return true;
	}

	// Called once, after the last attempt has failed and the row has been
	// marked `failed`. The record is already written, so this is for telling
	// somebody rather than for cleaning up. $error is a WP_Error however the
	// job reported failure -- branch on $error->get_error_code().
	//
	// public function failed( array $payload, \WP_Error $error ): void {
	// }
};
```

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

### `handle( $payload )`

Do the work, and say whether it worked.

```php
abstract public function handle( array $payload ): bool|\WP_Error
```

|  | Details |
|---|---|
| **Parameters** | `$payload` — What `dispatch()` was given |
| **Return** | True when the work is done; false or a WP_Error to be retried |
| **Throws** | — |

The payload is what was passed to `dispatch()`, decoded from the JSON it was stored as — so it holds scalars and arrays, and never the objects you might have put in an argument list. Load what you need from the ids in it.

**`true` is the only thing that means done.** Return `false` or a `WP_Error` and the job is retried; a `WP_Error` is the better of the two, since its message is what `queue list` shows and its code is what `failed()` can branch on. Much of WordPress already hands you one — `wp_remote_post()`, `wp_insert_post()` — so returning it straight through is usually the whole of your error handling.

**Throwing works too**, and means the same thing: the exception is caught and becomes a `WP_Error` coded `Queue::ERROR_EXCEPTION`, carrying the original in its error data. Use whichever the code in front of you produces; there is no need to translate one into the other.

Either way the attempt is counted and the job goes back on the queue after `get_retry_delay()`, until `get_max_attempts()` is spent — then the row is marked `failed` and `failed()` is called. Nothing reaches whatever drained the queue, so one bad job never stops the rest of the batch.

Expect to run more than once. A retry re-runs the whole method, so a job that half-succeeded runs its first half again: make it safe to repeat, or check before acting.

## Methods you can use

### `queue()`

The queue this job's work goes on.

```php
public function queue(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

A queue is a label you drain independently, so it is worth its own name whenever a kind of work wants a different interval from the rest — receipts every five minutes, a report rebuild nightly. Work sharing a queue is drained in the order it was dispatched, so slow work also holds up whatever is behind it.

<br>

### `get_max_attempts()`

How many times this job may run before it is given up on.

```php
public function get_max_attempts(): int
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `int` |
| **Throws** | — |

Counting starts at the first run, so `3` means one attempt and two retries. `1` never retries.

An attempt is spent as the job is claimed rather than when it finishes, which is deliberate: work that kills the whole PHP process leaves nothing to catch, and without that a job which crashes its worker would crash every worker forever.

<br>

### `get_retry_delay( $attempt )`

How long to wait before the next attempt.

```php
public function get_retry_delay( int $attempt ): int
```

|  | Details |
|---|---|
| **Parameters** | `$attempt` — How many attempts have been made, including the one that just failed |
| **Return** | Seconds to wait |
| **Throws** | — |

Doubles each time by default — one minute, two, four — so a job failing because something else is down stops hammering it. Return 0 to retry at the next drain.

<br>

### `failed( $payload, $error )`

React to this job being given up on.

```php
public function failed( array $payload, \WP_Error $error ): void
```

|  | Details |
|---|---|
| **Parameters** | `$payload` — What `dispatch()` was given<br>`$error` — The last failure |
| **Return** | — |
| **Throws** | — |

Called once, after the last attempt has failed and the row has been marked `failed` — so the record is already written, and this is for telling somebody rather than for cleaning up. Notify an admin, mark the order, put a notice somewhere a human will see it.

Throwing here is caught and logged too, and changes nothing about the row.

The error is always a `WP_Error`, whichever way the job reported failure. One from a thrown exception is coded `Queue::ERROR_EXCEPTION` and carries the original under the `exception` key of its error data; one from a bare `false` is coded `Queue::ERROR_FAILED`; anything else is the `WP_Error` the job itself returned, code and all.

<br>

### `get_name()`

This job's name, as `dispatch()` takes it.

```php
final public function get_name(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Your filename without `.php`: `resources/jobs/send-receipt.php` is `send-receipt`. Unlike most names this toolkit registers, it carries no plugin slug — nothing outside your own plugin ever sees it, since the queue table is already yours.

<br>

### `get_plugin()`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

<br>

### `with( $name )`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Reach another module.

```php
final public function with( string $name ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The module class to reach |
| **Return** | The shared instance |
| **Throws** | `ModuleException` — If it is not declared, or has not booted yet |

<br>

### `is_enabled()`

*Inherited from [`WithEnablement`](../../kernel/with-enablement.md).*

Whether this should be registered at all.

```php
public function is_enabled(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |
