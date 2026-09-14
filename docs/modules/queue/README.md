<!--
    Generated from src/Modules/Queue/Queue.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Queue

Discovers `resources/jobs/` &nbsp;·&nbsp; Each file returns [`Job`](job.md) &nbsp;·&nbsp; Dependencies [`path`](../path/), [`db`](../db/), [`cli`](../cli/), [`migrations`](../migrations/)

Runs work outside the request that asked for it: a durable job queue backed by a table of its own.

A jobs directory contains PHP files, one per kind of work. Each file returns a `Job` instance — the *handler*, discovered by filename exactly like a schedule or a route. The *work* is a row: `dispatch()` writes the job's name and a payload to the queue table and returns immediately, and some later process picks the row up and calls `handle()` with that payload.

```php
// Anywhere in the request -- a form handler, a REST route, a webhook.
$this->with( Queue::class )->dispatch( 'send-receipt', array( 'order_id' => 42 ) );
```

> [!IMPORTANT]
> **Nothing here runs on its own. You decide when.** Booting this module only registers the `wp {slug} queue` commands. Call `process()` from a schedule, a real crontab, or a worker command — see *Draining the queue* below.

## Named queues

Every job belongs to a queue, named by its own `Job::queue()` and defaulting to `default`. A queue is not a separate table or a separate anything — it is a label you drain independently, which is the point: receipts that must go out within the minute and a nightly report rebuild want different intervals, and one drained queue must not be held up behind the other's slow work.

```php
// resources/schedules/drain-mail.php  -- every five minutes
$this->with( Queue::class )->process( 'mail' );

// resources/schedules/drain-reports.php  -- nightly
$this->with( Queue::class )->process( 'reports' );
```

`process()` with no argument drains every queue together, which is the right answer until one kind of work starts starving another.

## Draining the queue

`process()` claims and runs jobs until the queue is empty or a budget runs out — `set_batch_size()`, `set_time_limit()` and `set_memory_limit_fraction()` — and returns what it did, so a caller can tell "nothing left" from "ran out of time". It is safe to call from anywhere; the three usual triggers are:

- **A schedule.** `resources/schedules/drain.php` calling `process()`. Easiest,
and inherits WP-Cron's own limitation: an event fires only when a page load notices it is due, so on a quiet site it fires late.
- **A real crontab** running `wp {slug} queue work`, which is the same thing
without the pseudo-cron.
- **Your own code**, immediately after dispatching, when you happen to know the
work is short and the request can carry it.

> [!NOTE]
> **Two workers running at once is fine, and needs no lock.** A job is claimed with a conditional `UPDATE` that only one worker can win, so overlapping runs divide the queue rather than duplicating it. That is the whole reason the claim is a row update rather than a read followed by a write.

## Failure

**`handle()` returns `true` when the work is done, and anything else asks to be retried** — `false`, a `WP_Error`, or a thrown exception, which are all turned into one `WP_Error` by `get_error_for()`. Returning the `WP_Error` WordPress already handed you is usually the whole of a job's error handling.

Nothing propagates: one bad job does not stop the rest of the batch. The row goes back to `pending` with its attempt counted and a delay from `Job::get_retry_delay()`, until `Job::get_max_attempts()` is reached; then it is marked `failed`, the error message is recorded, and `Job::failed()` is called so you can react. Nothing deletes a failed row: it stays for `wp {slug} queue list --status=failed` to show and `wp {slug} queue retry` to put back.

The attempt is counted **as the job is claimed**, not after it returns, which is what makes a job that kills the whole PHP process — a fatal error, a timeout, an OOM — survivable. Nothing can catch that, so the row is left `reserved` with no worker behind it; `release_stale_reservations()` reclaims it on the next run, and because the attempt was already counted it eventually reaches `failed` instead of crashing every worker forever.

## The table

One table, `{$wpdb->prefix}{plugin_prefix}_queue`, created by the migration `wp zt add queue` writes into your `resources/migrations/` directory. Nothing creates it behind your back, so **run your migrations before dispatching anything** — until then every call here throws `MissingQueueTableException`, which names the command to run.

A payload is stored as JSON, so it holds what JSON holds: scalars, arrays and nested arrays. Objects are deliberately not serialized into it — a queue row can outlive the code that wrote it, and a `serialize()`d object whose class has since changed comes back as something that fails far away from here. Put an id in the payload and load the thing in `handle()`.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Job](#writing-a-job) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add queue
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `Queue` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'acme_plugin_loaded' => array(
        Queue::class,
    ),
);
```

`acme_plugin_loaded` is your plugin's own action, fired at the end of `run()` once every module is built — `{slug}_loaded`, so a plugin slugged `acme-crm` spells it `acme_crm_loaded`. It is the earliest heading that still has the whole plugin behind it.

## Changing the defaults

Every budget `process()` runs under. All four are optional; the defaults suit a five-minute schedule on ordinary hosting.

```php
// bootstrap.php
return array(
    'acme_plugin_loaded' => array(
        Queue::class => static function ( Queue $queue ): void {
            $queue->set_batch_size( 50 );
            $queue->set_time_limit( 20 );
        },
    ),
);
```

## Writing a Job

A file in `resources/jobs/` returns a [`Job`](job.md) instance, which [`wp zt make job <name>`](../../commands/make-job.md) generates.

## Constants

### `JOBS_ROOT`

```php
const JOBS_ROOT = 'resources/jobs';
```

Default plugin-relative directory of job files.

### `TABLE_NAME`

```php
const TABLE_NAME = 'queue';
```

The local name of this module's table, as `DB::get_table()` takes it.

### `DEFAULT_QUEUE`

```php
const DEFAULT_QUEUE = 'default';
```

The queue a job belongs to when `Job::queue()` says nothing.

### `STATUS_PENDING`

```php
const STATUS_PENDING = 'pending';
```

Waiting to be claimed, once `available_at` has passed.

### `STATUS_RESERVED`

```php
const STATUS_RESERVED = 'reserved';
```

Claimed by a worker that has not yet finished with it.

### `STATUS_FAILED`

```php
const STATUS_FAILED = 'failed';
```

Out of attempts. Nothing will run it again until you retry it.

### `ERROR_EXCEPTION`

```php
const ERROR_EXCEPTION = 'job_exception';
```

The `WP_Error` code for a job that threw rather than returning.

### `ERROR_FAILED`

```php
const ERROR_FAILED = 'job_failed';
```

The `WP_Error` code for a job that returned a bare `false`.

### `ERROR_INTERRUPTED`

```php
const ERROR_INTERRUPTED = 'job_interrupted';
```

The `WP_Error` code for a job whose worker died mid-run.

## Methods

### `set_batch_size( $jobs )`

How many jobs one `process()` call runs before stopping.

```php
public function set_batch_size( int $jobs ): void
```

|  | Details |
|---|---|
| **Parameters** | `$jobs` — Jobs per run; 0 for no limit |
| **Return** | — |
| **Throws** | — |

The batch exists so a run ends predictably rather than when the work does: a queue that is filled faster than it drains would otherwise keep one worker busy indefinitely. Pass 0 to drain until the queue is empty or another budget stops it.

<br>

### `set_time_limit( $seconds )`

How long one `process()` call may spend before stopping.

```php
public function set_time_limit( int $seconds ): void
```

|  | Details |
|---|---|
| **Parameters** | `$seconds` — Seconds per run; 0 for no limit |
| **Return** | — |
| **Throws** | — |

Checked between jobs, never during one — a single job that runs longer than this is not interrupted, because there is no safe point to interrupt it at. The default is deliberately well under a typical PHP time limit, so the run ends by choice rather than by being killed halfway through a job.

Capped at 80% of `max_execution_time` when PHP has one, which is why a generous value here is harmless on a web request and still useful under WP-CLI, where there is no limit to cap against.

<br>

### `set_memory_limit_fraction( $fraction )`

The share of PHP's memory limit a run may reach before stopping.

```php
public function set_memory_limit_fraction( float $fraction ): void
```

|  | Details |
|---|---|
| **Parameters** | `$fraction` — Between 0 and 1; 0.9 unless you change it |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the fraction is not between 0 and 1 |

Checked between jobs, like the time limit. A worker that exhausts memory is killed mid-job, which costs an attempt and leaves a reserved row for `release_stale_reservations()` to clean up — stopping short is cheaper.

<br>

### `set_stale_reservation_timeout( $seconds )`

How long a claimed job is trusted to still be running.

```php
public function set_stale_reservation_timeout( int $seconds ): void
```

|  | Details |
|---|---|
| **Parameters** | `$seconds` — Seconds; five minutes unless you change it |
| **Return** | — |
| **Throws** | — |

A worker that dies without catching anything — a fatal error, a timeout, a killed process — leaves its row `reserved` forever. After this long, `release_stale_reservations()` takes it back.

Make it comfortably longer than your slowest job: reclaiming a job that is genuinely still running lets a second worker run it concurrently with the first.

<br>

### `dispatch( $job, $payload, $delay )`

Put a job on its queue, to run the next time that queue is drained.

```php
public function dispatch( string $job, array $payload = array(), int $delay = 0 ): int
```

|  | Details |
|---|---|
| **Parameters** | `$job` — The job's local name<br>`$payload` — What `handle()` is given. Must be JSON-encodable<br>`$delay` — Seconds to wait before it may run |
| **Return** | The queued row's id |
| **Throws** | `InvalidArgumentException` — When no job file matches, or the payload will not encode<br>`MissingQueueTableException` — When the queue table does not exist<br>`DiscoveryException` — When the job file returns something other than a Job |

The name is the job file's own, without `.php` — `resources/jobs/send-receipt.php` is `send-receipt`. It is resolved here rather than when the row is claimed, so a name with a typo in it fails at the call that made the mistake instead of sitting in the table unclaimed.

Returns the new row's id, which `cancel()` takes.

<br>

### `dispatch_at( $job, $payload, $timestamp )`

Put a job on its queue, to run no earlier than a given moment.

```php
public function dispatch_at( string $job, array $payload, int $timestamp ): int
```

|  | Details |
|---|---|
| **Parameters** | `$job` — The job's local name<br>`$payload` — What `handle()` is given. Must be JSON-encodable<br>`$timestamp` — Unix timestamp before which it will not run |
| **Return** | The queued row's id |
| **Throws** | `InvalidArgumentException` — When no job file matches, or the payload will not encode<br>`MissingQueueTableException` — When the queue table does not exist<br>`DiscoveryException` — When the job file returns something other than a Job |

The same as `dispatch()` with a delay, for when you have the time rather than the interval. A timestamp already past means "as soon as the queue is next drained", not "now" — nothing here runs a job itself.

<br>

### `process( $queue )`

Claim and run queued jobs until the queue is empty or a budget runs out.

```php
public function process( ?string $queue = null ): array
```

|  | Details |
|---|---|
| **Parameters** | `$queue` — The queue to drain, or null for every queue |
| **Return** | What the run did |
| **Throws** | `MissingQueueTableException` — When the queue table does not exist<br>`DiscoveryException` — When a job file returns something other than a Job |

Pass a queue name to drain only that one, which is how two kinds of work get two intervals; pass nothing to drain them all together.

Reclaims abandoned reservations first, so a worker that died takes at most one drain to recover from rather than needing anything called by hand.

Never throws on account of a job: a `handle()` that throws is caught, retried or failed, and the run continues. What it does throw is a problem with the queue itself — a missing table, a job file returning the wrong type.

The three counts are of *attempts*, not of rows: a job that throws and is put back counts once under `retried` and counts again when it next runs. `failed` is only the terminal kind — a job that has run out of attempts and will not be tried again.

The `stopped` key says why the run ended: `empty` (nothing left to claim), `batch`, `time` or `memory`. Anything but `empty` means there is more waiting, so a worker loop knows to come straight back.

<br>

### `release_stale_reservations( $queue )`

Take back every job claimed by a worker that never finished with it.

```php
public function release_stale_reservations( ?string $queue = null ): int
```

|  | Details |
|---|---|
| **Parameters** | `$queue` — The queue to reclaim within, or null for every queue |
| **Return** | How many reservations were taken back |
| **Throws** | `MissingQueueTableException` — When the queue table does not exist<br>`DiscoveryException` — When a job file returns something other than a Job |

Called by `process()` before it claims anything, so this is rarely worth calling yourself — it is public because a row stuck `reserved` is the one state you cannot see your way out of from the outside.

A reclaimed job goes back to `pending` and runs again. Its attempt was already counted when it was claimed, so a job that kills the process every time it runs reaches `failed` after `Job::get_max_attempts()` rather than being retried forever.

<br>

### `cancel( $id )`

Remove a queued job that has not been claimed yet.

```php
public function cancel( int $id ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$id` — The row id `dispatch()` returned |
| **Return** | True when a pending row was found and deleted |
| **Throws** | `MissingQueueTableException` — When the queue table does not exist |

Only a `pending` row can be cancelled: one already claimed is running, and deleting it from under its worker would not stop it.

<br>

### `retry( $id, $queue )`

Put failed jobs back on their queue.

```php
public function retry( ?int $id = null, ?string $queue = null ): int
```

|  | Details |
|---|---|
| **Parameters** | `$id` — One row to retry, or null for every failure<br>`$queue` — Restrict to one queue. Ignored when $id is given |
| **Return** | How many rows went back to pending |
| **Throws** | `MissingQueueTableException` — When the queue table does not exist |

Their attempt count is reset, so a retried job gets the same number of attempts it started with. Pass an id to retry one, a queue to retry that queue's failures, or neither to retry all of them.

<br>

### `purge( $status, $queue )`

Delete every row with a given status.

```php
public function purge( string $status, ?string $queue = null ): int
```

|  | Details |
|---|---|
| **Parameters** | `$status` — One of the `STATUS_*` constants<br>`$queue` — Restrict to one queue, or null for every queue |
| **Return** | How many rows were deleted |
| **Throws** | `InvalidArgumentException` — When the status is not one this module uses<br>`MissingQueueTableException` — When the queue table does not exist |

For clearing out failures you have read and dealt with, or a queue of pending work a release made meaningless. There is no "delete everything": a status is always named, so this cannot quietly take the running ones with it.

<br>

### `get_counts( $queue )`

How many rows a queue holds, by status.

```php
public function get_counts( ?string $queue = null ): array
```

|  | Details |
|---|---|
| **Parameters** | `$queue` — Restrict to one queue, or null for every queue |
| **Return** | Status => row count |
| **Throws** | `MissingQueueTableException` — When the queue table does not exist |

Every status this module uses is present in the return, zeroes included, so a caller never has to check a key before reading it.

<br>

### `get_rows( $status, $queue, $limit )`

Read queued rows, newest first.

```php
public function get_rows( ?string $status = null, ?string $queue = null, int $limit = 100 ): array
```

|  | Details |
|---|---|
| **Parameters** | `$status` — Restrict to one status, or null for every status<br>`$queue` — Restrict to one queue, or null for every queue<br>`$limit` — How many rows at most |
| **Return** | Status: string, attempts: int, available_at: string, reserved_at: string\|null, created_at: string, last_error: string\|null}> |
| **Throws** | `InvalidArgumentException` — When the status is not one this module uses<br>`MissingQueueTableException` — When the queue table does not exist |

The payload comes back decoded, so a row reads the way it was dispatched. For inspection — an admin screen, `wp {slug} queue list`, a Site Health check counting failures — rather than for running anything.

<br>

### `get_queues()`

Every queue name the discovered jobs put work on.

```php
public function get_queues(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Queue names, in alphabetical order |
| **Throws** | `DiscoveryException` — When a job file returns something other than a Job |

Read off the job files rather than off the table, so a queue that is currently empty is still listed — which is what makes this the right thing to write a schedule against.

<br>

### `get_orphaned_jobs()`

Every job name with work waiting that nothing on disk can run.

```php
public function get_orphaned_jobs(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Job name => rows waiting, most first |
| **Throws** | `MissingQueueTableException` — When the queue table does not exist<br>`DiscoveryException` — When a job file returns something other than a Job |

A job's identity is its filename, so deleting or renaming a job file abandons every row already dispatched under the old name. Those rows are never claimed — a worker only claims work it has a handler for, which is what stops a missing handler from failing rows a deploy is about to restore — so they simply wait, and this is what says so.

A job switched off with `is_enabled()` is reported here too, for the same reason: its rows are waiting on something that is not going to happen until you change your mind.

This reports; nothing acts on it. Restore the file, or `purge()` the rows.

<br>

### `get_discovered_jobs()`

Every job file, wired and ready to run, keyed by its local name.

```php
public function get_discovered_jobs(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Wired instances keyed by local job name |
| **Throws** | `DiscoveryException` — When a job file returns something other than a Job |

A job whose `is_enabled()` returns false is not here, which is what keeps its rows unclaimed.

<br>

### `get_name_of( $job )`

This job's name, from the file it was discovered in.

```php
public function get_name_of( Job $job ): string
```

|  | Details |
|---|---|
| **Parameters** | `$job` — The instance to look up |
| **Return** | The local job name `dispatch()` takes |
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module<br>`DiscoveryException` — When a job file returns something other than a Job |

<br>

### `get_table()`

This module's table, named the way everything else names one.

```php
public function get_table(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The full `{$wpdb->prefix}{plugin_prefix}_queue` table name |
| **Throws** | `MissingQueueTableException` — When the table does not exist |

<br>

### `get_error_for( $failure )`

However a job reported failure, as one `WP_Error`.

```php
public static function get_error_for( \Throwable|\WP_Error|bool $failure ): \WP_Error
```

|  | Details |
|---|---|
| **Parameters** | `$failure` — What the job threw or returned. Only `false` ever reaches here |
| **Return** | `\WP_Error` |
| **Throws** | — |

Three things can arrive here and they are all the same request — try again — so they are made one type before anything acts on them: your `Job::failed()` branches on a code rather than on whichever of the three the job happened to use, and `last_error` is filled the same way either way.

A thrown exception keeps everything: it goes under the `exception` key of the error's data, so the stack trace is still there for whoever wants it.

<br>

### `get_statuses()`

Every status a queued row can hold.

```php
public static function get_statuses(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

<br>

### `on_wp_init( $callback, $priority )`

*Inherited from [`Module`](../module.md).*

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function on_wp_init( callable $callback, int $priority = 10 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run<br>`$priority` — WordPress hook priority, honoured only while `init` is still ahead |
| **Return** | — |
| **Throws** | — |

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

## See also

- [`Job`](job.md) — what a file in `resources/jobs/` returns
- [`path`](../path/) — copied in alongside this one
- [`db`](../db/) — copied in alongside this one
- [`cli`](../cli/) — copied in alongside this one
- [`migrations`](../migrations/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add queue`](../../commands/add.md) — the command that copies it
