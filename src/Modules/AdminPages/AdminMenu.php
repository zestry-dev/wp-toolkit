<?php

/**
 * Admin Pages API: AdminMenu enum
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\AdminPages;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Which of WordPress's admin menus a page belongs to.
 *
 * A multisite network has two: the ordinary one each site gets, and the network
 * administrator's at `/wp-admin/network/`. They are built from separate hooks
 * and hold separate menus, so a page appears in one or the other — a plugin
 * activated for the whole network puts its network-wide settings in `Network`
 * and anything a single site configures for itself in `Site`.
 *
 * `Network` is inert on a single-site install: the hook never fires, so the page
 * is simply never registered.
 */
enum AdminMenu: string {

	case Site    = 'site';
	case Network = 'network';

	/**
	 * The menu the current request belongs to.
	 *
	 * @return self
	 *
	 * @internal
	 */
	public static function get_current(): self {
		return \is_network_admin() ? self::Network : self::Site;
	}

	/**
	 * The hook WordPress builds this menu on.
	 *
	 * @return string
	 *
	 * @internal
	 */
	public function get_menu_hook(): string {
		return match ( $this ) {
			self::Site    => 'admin_menu',
			self::Network => 'network_admin_menu',
		};
	}

	/**
	 * An absolute URL to a file in this menu's admin directory.
	 *
	 * @param string $path Path relative to the admin directory.
	 * @return string
	 *
	 * @internal
	 */
	public function get_url( string $path = '' ): string {
		return match ( $this ) {
			self::Site    => \admin_url( $path ),
			self::Network => \network_admin_url( $path ),
		};
	}
}
