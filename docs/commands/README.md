<!--
    Generated from the docblocks in commands/.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Command reference

`zt` is short for *zestry toolkit*.

Run these from inside your own plugin's directory, with the plugin active.
Every command except [`wp zt init`](init.md) also needs that plugin to have
been initialized — see [Getting started](../getting-started.md).

## Set the plugin up

- [`wp zt init`](init.md) — Set up a plugin to receive wp-toolkit source.

## Add toolkit code

- [`wp zt add module`](add-module.md) — Copy one or more feature modules into an initialized plugin.
- [`wp zt add service`](add-service.md) — Copy one or more services into an initialized plugin.
- [`wp zt overwrite module`](overwrite-module.md) — Copy one or more feature modules into an initialized plugin, replacing any of them (or their dependencies) already present.
- [`wp zt overwrite service`](overwrite-service.md) — Copy one or more services into an initialized plugin, replacing any of them (or their dependencies) already present.

## Generate your own code

- [`wp zt make ability`](make-ability.md) — Generate an ability.
- [`wp zt make abstract`](make-abstract.md) — Generate an intermediate abstract of your own.
- [`wp zt make action`](make-action.md) — Generate a new AJAX action.
- [`wp zt make activation`](make-activation.md) — Generate an activation handler.
- [`wp zt make block`](make-block.md) — Generate a new editor block.
- [`wp zt make command`](make-command.md) — Generate a new WP-CLI command.
- [`wp zt make debug-section`](make-debug-section.md) — Generate a Site Health debug section.
- [`wp zt make entry`](make-entry.md) — Generate a script entry of this plugin's own.
- [`wp zt make field`](make-field.md) — Generate a post meta field.
- [`wp zt make health-check`](make-health-check.md) — Generate a Site Health check.
- [`wp zt make meta-box`](make-meta-box.md) — Generate a post edit screen meta box.
- [`wp zt make migration`](make-migration.md) — Generate a new database migration.
- [`wp zt make module`](make-module.md) — Generate a new plain Module subclass.
- [`wp zt make page`](make-page.md) — Generate a new admin page.
- [`wp zt make post-type`](make-post-type.md) — Generate a new custom post type.
- [`wp zt make route`](make-route.md) — Generate a new REST route.
- [`wp zt make schedule`](make-schedule.md) — Generate a new cron schedule.
- [`wp zt make service`](make-service.md) — Generate a new Service subclass.
- [`wp zt make shared`](make-shared.md) — Generate a shared JavaScript package.
- [`wp zt make taxonomy`](make-taxonomy.md) — Generate a new custom taxonomy.
- [`wp zt make view`](make-view.md) — Generate a view template.

## Keep it healthy

- [`wp zt doctor`](doctor.md) — Check this plugin's module wiring for silent misconfiguration.
- [`wp zt update`](update.md) — Re-copy the toolkit source this plugin already has.

## Everything else

- [`wp zt describe`](describe.md) — Report what this plugin has, where each module looks, and what it expects.
