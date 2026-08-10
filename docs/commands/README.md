<!--
    Generated from the docblocks in commands/.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Command reference

Run these from inside your own plugin's directory, with the plugin active.
Every command except [`wp zestry init`](init.md) also needs that plugin to have
been initialized — see [Getting started](../getting-started.md).

## Set the plugin up

- [`wp zestry init`](init.md) — Set up a plugin to receive wp-toolkit source.

## Add toolkit code

- [`wp zestry add module`](add-module.md) — Copy one or more feature modules into an initialized plugin.
- [`wp zestry add service`](add-service.md) — Copy one or more services into an initialized plugin.
- [`wp zestry overwrite module`](overwrite-module.md) — Copy one or more feature modules into an initialized plugin, replacing any of them (or their dependencies) already present.
- [`wp zestry overwrite service`](overwrite-service.md) — Copy one or more services into an initialized plugin, replacing any of them (or their dependencies) already present.

## Generate your own code

- [`wp zestry make ability`](make-ability.md) — Generate an ability.
- [`wp zestry make action`](make-action.md) — Generate a new AJAX action.
- [`wp zestry make activation`](make-activation.md) — Generate an activation handler.
- [`wp zestry make block`](make-block.md) — Generate a new editor block.
- [`wp zestry make command`](make-command.md) — Generate a new WP-CLI command.
- [`wp zestry make debug-section`](make-debug-section.md) — Generate a Site Health debug section.
- [`wp zestry make entry`](make-entry.md) — Generate a script entry of this plugin's own.
- [`wp zestry make field`](make-field.md) — Generate a post meta field.
- [`wp zestry make health-check`](make-health-check.md) — Generate a Site Health check.
- [`wp zestry make meta-box`](make-meta-box.md) — Generate a post edit screen meta box.
- [`wp zestry make migration`](make-migration.md) — Generate a new database migration.
- [`wp zestry make module`](make-module.md) — Generate a new plain Module subclass.
- [`wp zestry make page`](make-page.md) — Generate a new admin page.
- [`wp zestry make post-type`](make-post-type.md) — Generate a new custom post type.
- [`wp zestry make route`](make-route.md) — Generate a new REST route.
- [`wp zestry make schedule`](make-schedule.md) — Generate a new cron schedule.
- [`wp zestry make service`](make-service.md) — Generate a new Service subclass.
- [`wp zestry make shared`](make-shared.md) — Generate a shared JavaScript package.
- [`wp zestry make taxonomy`](make-taxonomy.md) — Generate a new custom taxonomy.
- [`wp zestry make view`](make-view.md) — Generate a view template.

## Keep it healthy

- [`wp zestry doctor`](doctor.md) — Check this plugin's module wiring for silent misconfiguration.
- [`wp zestry update`](update.md) — Re-copy the toolkit source this plugin already has.

## Everything else

- [`wp zestry describe`](describe.md) — Report what this plugin has, where each module looks, and what it expects.
