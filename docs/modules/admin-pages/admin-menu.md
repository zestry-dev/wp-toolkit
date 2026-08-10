<!--
    Generated from src/Modules/AdminPages/AdminMenu.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# AdminMenu

Which of WordPress's admin menus a page belongs to.

A multisite network has two: the ordinary one each site gets, and the network administrator's at `/wp-admin/network/`. They are built from separate hooks and hold separate menus, so a page appears in one or the other — a plugin activated for the whole network puts its network-wide settings in `Network` and anything a single site configures for itself in `Site`.

`Network` is inert on a single-site install: the hook never fires, so the page is simply never registered.
