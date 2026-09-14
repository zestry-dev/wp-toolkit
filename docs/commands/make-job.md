<!--
    Generated from resources/commands/make/job.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make job

Generate a new background job.

The Queue module discovers it. It walks your `resources/jobs/` directory, requires every file in it, and keeps the `Job` each one returns as the handler for work queued under that filename. Writing the file is the whole registration; nothing has to be declared anywhere.

The file is the handler, not the work: queue the work with `$this->with( Queue::class )->dispatch( '<name>', $payload )`, as often as you like, and this one instance runs all of it.

Needs the `queue` module, so run `wp zt add queue` first if you have not already — and run the migration it writes, or there is no table to queue anything into.

## Options

- **`<name>`**  
  The local name, e.g. 'send-receipt'. Becomes the filename (`{name}.php`) under `resources/jobs/`, and the name `dispatch()` takes.

- **`[--queue=<queue>]`**  
  The queue this job's work goes on.  
  Defaults to 'default' without prompting. Name one of your own when this work wants a different drain interval from the rest.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

## Examples

```bash
# Generate a job at resources/jobs/send-receipt.php.
$ wp zt make job send-receipt
Success: Created resources/jobs/send-receipt.php

# Generate one on its own queue, to drain on its own schedule.
$ wp zt make job rebuild-report --queue=reports
Success: Created resources/jobs/rebuild-report.php
```
