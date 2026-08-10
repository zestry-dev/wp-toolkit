<!--
    Generated from commands/make/schedule.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# wp zestry make schedule

Generate a new cron schedule.

The Cron module discovers it. At boot it walks your `schedules/` directory, requires every file in it, binds the `Schedule` each one returns to its own hook, and calls `wp_schedule_event()` for it when the event is not already on the calendar. Writing the file is the whole registration; nothing has to be declared anywhere.

Needs the `cron` module, so run `wp zestry add module cron` first if you have not already.

## Options

- **`<name>`**  
  The local name, e.g. 'cleanup'. Becomes the filename (`{name}.php`) under `schedules/`.

- **`[--dir=<dir>]`**  
  Write into this plugin-relative directory instead of `schedules` — pass it when you have pointed Cron's schedules root somewhere other than its default.

- **`[--recurrence=<recurrence>]`**  
  The WP-Cron recurrence, e.g. 'daily'.  
  Defaults to 'daily' without prompting.

- **`[--yes]`**  
  Overwrite an existing file without asking, for an unattended run.

## Examples

```bash
# Generate a daily cron schedule at schedules/cleanup.php.
$ wp zestry make schedule cleanup
Success: Created schedules/cleanup.php

# Generate a schedule with an explicit recurrence.
$ wp zestry make schedule cleanup --recurrence=hourly
Success: Created schedules/cleanup.php
```
