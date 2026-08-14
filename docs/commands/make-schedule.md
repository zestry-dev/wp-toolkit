<!--
    Generated from commands/make/schedule.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zt make schedule

Generate a new cron schedule.

The Cron module discovers it. At boot it walks your `schedules/` directory, requires every file in it, binds the `Schedule` each one returns to its own hook, and calls `wp_schedule_event()` for it when the event is not already on the calendar. Writing the file is the whole registration; nothing has to be declared anywhere.

Needs the `cron` module, so run `wp zt add cron` first if you have not already.

## Options

- **`<name>`**  
  The local name, e.g. 'cleanup'. Becomes the filename (`{name}.php`) under `schedules/`.

- **`[--recurrence=<recurrence>]`**  
  The WP-Cron recurrence, e.g. 'daily'.  
  Defaults to 'daily' without prompting.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

- **`[--extends=<class>]`**  
  Extend one of your own abstracts instead of the toolkit base. A bare name is looked for under your Abstracts\ namespace; the generated file stubs the methods that class leaves abstract, and nothing it has already settled.

## Examples

```bash
# Generate a daily cron schedule at schedules/cleanup.php.
$ wp zt make schedule cleanup
Success: Created schedules/cleanup.php

# Generate a schedule with an explicit recurrence.
$ wp zt make schedule cleanup --recurrence=hourly
Success: Created schedules/cleanup.php
```
