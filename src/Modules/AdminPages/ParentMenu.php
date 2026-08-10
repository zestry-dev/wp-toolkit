<?php

/**
 * Admin Pages API: ParentMenu enum
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\AdminPages;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * The built-in WordPress menus an AdminPage can be nested under.
 *
 * Returning one of these from AdminPage::parent() places the page there;
 * returning a plain slug string nests it under a custom parent (typically
 * another AdminPage), and returning null makes it a top-level menu.
 *
 * The two admin menus hold different sections. `Sites` exists only in the
 * network menu, and `Posts`, `Media`, `Pages`, `Comments` and `Tools` only in a
 * site's — a page that asks for a section its {@see AdminMenu} does not have
 * throws, rather than registering somewhere invisible.
 */
enum ParentMenu {

	case Dashboard;
	case Posts;
	case Media;
	case Pages;
	case Comments;
	case Themes;
	case Plugins;
	case Users;
	case Tools;
	case Settings;
	case Sites;

	/**
	 * The admin file this menu's entries hang off, in the given menu.
	 *
	 * @param AdminMenu $menu The menu the page belongs to.
	 * @return string
	 * @throws \InvalidArgumentException When that menu has no such section.
	 *
	 * @internal
	 */
	public function get_parent_file( AdminMenu $menu ): string {
		$file = match ( $menu ) {
			AdminMenu::Site    => match ( $this ) {
				self::Dashboard => 'index.php',
				self::Posts     => 'edit.php',
				self::Media     => 'upload.php',
				self::Pages     => 'edit.php?post_type=page',
				self::Comments  => 'edit-comments.php',
				self::Themes    => 'themes.php',
				self::Plugins   => 'plugins.php',
				self::Users     => 'users.php',
				self::Tools     => 'tools.php',
				self::Settings  => 'options-general.php',
				self::Sites     => null,
			},
			AdminMenu::Network => match ( $this ) {
				self::Dashboard => 'index.php',
				self::Themes    => 'themes.php',
				self::Plugins   => 'plugins.php',
				self::Users     => 'users.php',
				self::Settings  => 'settings.php',
				self::Sites     => 'sites.php',
				default         => null,
			},
		};

		if ( null === $file ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The %s admin menu has no "%s" section to nest a page under.', $menu->value, $this->name )
			);
		}

		return $file;
	}
}
