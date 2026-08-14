<?php

/**
 * Post Types API: PostType base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\PostTypes;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * Base class for a file-based custom post type registration.
 *
 * A post type file returns a subclass instance; the PostTypes module wires it
 * (assigning the shared plugin, so `with()` reaches every module) and
 * calls {@see get_args()} to build the array passed to WordPress core's own
 * `register_post_type()`. The post type's name is not derived from this class
 * at all -- it comes from the file's own name within the post types
 * directory (`post-types/book.php` registers as `book`), matching the
 * `slug()`-from-filename convention every other file-based module in this
 * toolkit uses.
 *
 * Only `singular_name()`/`plural_name()` are required: WordPress core itself
 * only derives `name`/`singular_name`/`menu_name`/`all_items`/`archives` from
 * those two, and otherwise falls back to generic, literally-worded defaults
 * ('Add Post', 'Search Posts', ...) meant for the built-in Post/Page types --
 * wrong for any custom post type. `get_args()` below builds the
 * commonly-needed label set by interpolating `singular_name()`/`plural_name()`
 * into the label keys most "register custom post type" tutorials hand-write,
 * so a post type gets correctly-worded labels everywhere without repeating
 * that boilerplate. Keys outside that set -- `name_admin_bar`,
 * `parent_item_colon`, `items_list`, the `item_*` status strings -- keep
 * WordPress's own defaults unless `labels()` supplies them. Override
 * `labels()` to replace or add specific labels beyond that.
 *
 * A post type name is not auto-namespaced to the plugin slug, for the same
 * reason a taxonomy, a meta key and a block name are not: WordPress caps a
 * post type name at 20 characters (enforced by the `wp_posts.post_type`
 * column and `register_post_type()` itself), which leaves little to no room
 * once a realistic plugin slug prefix is added. Community convention (core
 * itself, WooCommerce's `product`) is to pick a short, plain, globally unique
 * name -- the same responsibility you already have when naming a database
 * table or an option key directly.
 *
 * A file at `post-types/book.php` registers as `book`.
 * `wp zt make post-type <name>` generates a starting point.
 *
 * @stub post-type.php.stub
 *
 * > [!IMPORTANT]
 * > **This name is not prefixed with your plugin slug, so choose it as though
 * > every plugin on the site can see it — because they can.** WordPress caps a post type name at 20 characters, which is why the slug is not added for you.
 * >
 * > Two plugins registering `book` are the same post type, and whichever registers
 * > second loses. Put your own prefix in the filename: `post-types/acme-book.php`.
 *
 */
abstract class PostType implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * The singular display name, e.g. 'Book'.
	 *
	 * Used both to derive every label WordPress core generates by default
	 * (`Add New Book`, `Edit Book`, ...) and, lowercased, as the default
	 * `labels()['singular_name']` and `menu_name` inputs.
	 *
	 * @return string
	 */
	abstract public function singular_name(): string;

	/**
	 * The plural display name, e.g. 'Books'.
	 *
	 * @return string
	 */
	abstract public function plural_name(): string;

	/**
	 * Additional or overriding labels beyond {@see get_args()}'s defaults.
	 *
	 * The base label set built from `singular_name()`/`plural_name()` covers
	 * every commonly-needed key already; override this only to replace a
	 * specific one (for example, a `parent_item_colon` on a hierarchical post
	 * type, or a domain-specific `search_items` string) or add a key this
	 * base does not set.
	 *
	 * @return array<string, string>
	 */
	public function labels(): array {
		return array();
	}

	/**
	 * The features this post type supports in the editor.
	 *
	 * @return string[] Any of WordPress's post-type `supports` keys, e.g.
	 *                   'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'.
	 */
	public function supports(): array {
		return array( 'title', 'editor' );
	}

	/**
	 * Whether the post type is intended for public display on the front end.
	 *
	 * This is a bundle: WordPress derives `publicly_queryable`, `show_ui`,
	 * `show_in_menu`, `show_in_nav_menus`, `show_in_admin_bar` and
	 * `exclude_from_search` from it. Each of those has its own method below to
	 * break out of the bundle one argument at a time.
	 *
	 * @return bool
	 */
	public function is_public(): bool {
		return true;
	}

	/**
	 * Whether the post type gets a management UI in the admin.
	 *
	 * Null (the default) lets WordPress derive it from {@see is_public()}, as
	 * it does for the five methods below. Return a bool for a combination
	 * `public` alone cannot express -- the common one being a post type kept
	 * off the front end but still editable in the admin: `is_public()` false
	 * and this true.
	 *
	 * @return bool|null
	 */
	public function is_shown_in_ui(): ?bool {
		return null;
	}

	/**
	 * Where the post type appears in the admin menu.
	 *
	 * Requires {@see is_shown_in_ui()} to be on, and defaults to it.
	 *
	 * @return bool|string|null True for a top-level menu of its own, false for
	 *                           none at all, or the slug of an existing
	 *                           top-level menu (`'tools.php'`,
	 *                           `'edit.php?post_type=book'`) to nest it there as
	 *                           a submenu.
	 */
	public function is_shown_in_menu(): bool|string|null {
		return null;
	}

	/**
	 * Whether the post type is offered when building a nav menu.
	 *
	 * Null (the default) derives it from {@see is_public()}.
	 *
	 * @return bool|null
	 */
	public function is_shown_in_nav_menus(): ?bool {
		return null;
	}

	/**
	 * Whether the post type appears in the admin bar's "+ New" menu.
	 *
	 * Null (the default) derives it from {@see is_shown_in_menu()}.
	 *
	 * @return bool|null
	 */
	public function is_shown_in_admin_bar(): ?bool {
		return null;
	}

	/**
	 * Whether front-end queries may request this post type.
	 *
	 * Null (the default) derives it from {@see is_public()}. Turning this off
	 * while leaving {@see is_shown_in_ui()} on is how a post type becomes
	 * admin-only without also losing its editing screens.
	 *
	 * @return bool|null
	 */
	public function is_publicly_queryable(): ?bool {
		return null;
	}

	/**
	 * Whether the post type is kept out of front-end search results.
	 *
	 * Null (the default) derives it from the *inverse* of {@see is_public()} --
	 * a public post type is searchable, a non-public one is not.
	 *
	 * @return bool|null
	 */
	public function is_excluded_from_search(): ?bool {
		return null;
	}

	/**
	 * Whether this post type has an archive page.
	 *
	 * @return bool|string True for the default archive slug (the post type
	 *                      name), a string for a custom archive slug, or false
	 *                      to disable the archive entirely.
	 */
	public function has_archive(): bool|string {
		return true;
	}

	/**
	 * Whether child pages can be created (a hierarchical post type, like Pages).
	 *
	 * @return bool
	 */
	public function is_hierarchical(): bool {
		return false;
	}

	/**
	 * Whether this post type is exposed through the REST API and block editor.
	 *
	 * @return bool
	 */
	public function is_shown_in_rest(): bool {
		return true;
	}

	/**
	 * The base capability name WordPress derives this post type's full
	 * capability set from (`edit_{type}`, `delete_{type}s`, ...).
	 *
	 * Return `'post'` to reuse the built-in Post capabilities, which leaves
	 * nothing extra to manage. Return a distinct name to give this post type a
	 * capability set of its own.
	 *
	 * > [!CAUTION]
	 * > **A custom capability type has to be granted before anyone can use it.**
	 * > WordPress derives the capabilities but assigns them to no role, so until
	 * > a role is granted them, only an administrator can manage the post type.
	 *
	 * @return string
	 */
	public function capability_type(): string {
		return 'post';
	}

	/**
	 * The permalink structure, or false to disable pretty permalinks for this
	 * post type entirely.
	 *
	 * Becomes WordPress's own `rewrite` argument, so it takes the same keys:
	 * `slug`, `with_front`, `feeds`, `pages` and `ep_mask`.
	 *
	 * @return array<string, mixed>|false
	 */
	public function rewrite(): array|false {
		return array( 'slug' => $this->get_post_type() );
	}

	/**
	 * The dashicon or custom icon shown in the admin menu.
	 *
	 * @return string
	 */
	public function menu_icon(): string {
		return 'dashicons-admin-post';
	}

	/**
	 * The admin menu position, or null for the default ordering.
	 *
	 * @return int|null
	 */
	public function menu_position(): ?int {
		return null;
	}

	/**
	 * This post type's own name, as registered.
	 *
	 * Resolved from the PostTypes module's registry, which derives it from
	 * the file's own name within the post types directory. The post type
	 * itself stores no name state.
	 *
	 * @return string
	 */
	final public function get_post_type(): string {
		return $this->post_types()->get_post_type_of( $this );
	}

	/**
	 * Build the full argument array passed to `register_post_type()`.
	 *
	 * Merges {@see get_default_labels()} with this post type's own `labels()`
	 * overrides, then every other declared option. Override this directly
	 * only when a `register_post_type()` argument has no dedicated method
	 * above.
	 *
	 * @return array<string, mixed>
	 */
	public function get_args(): array {
		return array(
			'labels'              => \array_merge( $this->get_default_labels(), $this->labels() ),
			'public'              => $this->is_public(),
			// A null here is exactly equivalent to omitting the key:
			// WP_Post_Type::set_props() derives each of these from `public`
			// only when it is still null after wp_parse_args(). Passing them
			// unconditionally therefore costs nothing.
			'publicly_queryable'  => $this->is_publicly_queryable(),
			'exclude_from_search' => $this->is_excluded_from_search(),
			'show_ui'             => $this->is_shown_in_ui(),
			'show_in_menu'        => $this->is_shown_in_menu(),
			'show_in_nav_menus'   => $this->is_shown_in_nav_menus(),
			'show_in_admin_bar'   => $this->is_shown_in_admin_bar(),
			'has_archive'         => $this->has_archive(),
			'hierarchical'        => $this->is_hierarchical(),
			'show_in_rest'        => $this->is_shown_in_rest(),
			'supports'            => $this->supports(),
			'capability_type'     => $this->capability_type(),
			'rewrite'             => $this->rewrite(),
			'menu_icon'           => $this->menu_icon(),
			'menu_position'       => $this->menu_position(),
		);
	}

	/**
	 * The PostTypes module that manages this post type.
	 *
	 * @return PostTypes
	 */
	final protected function post_types(): PostTypes {
		return $this->get_plugin()->get( PostTypes::class );
	}

	/**
	 * Build the full default label set from singular_name()/plural_name().
	 *
	 * Interpolates both into every label key WordPress's own
	 * `register_post_type()` documents, following the exact wording pattern
	 * used across the WordPress ecosystem's own "register custom post type"
	 * tutorials -- this is the boilerplate `get_args()` exists to eliminate.
	 * `labels()` overrides are merged on top by the caller, not here.
	 *
	 * `$singular` and `$plural` are not translated here -- they are your own
	 * `singular_name()`/`plural_name()` return values, so wrap those in `__()`
	 * yourself if you want them translated. This is the pattern WordPress
	 * documents for custom post type labels: translate the surrounding phrase,
	 * and `sprintf()` the caller-supplied name into it.
	 *
	 * @return array<string, string>
	 */
	private function get_default_labels(): array {
		$singular = $this->singular_name();
		$plural   = $this->plural_name();

		return array(
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => $plural,
			'add_new'               => \__( 'Add New', 'zestry-toolkit' ),
			/* translators: %s: Singular post type name. */
			'add_new_item'          => \sprintf( \__( 'Add New %s', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Singular post type name. */
			'edit_item'             => \sprintf( \__( 'Edit %s', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Singular post type name. */
			'new_item'              => \sprintf( \__( 'New %s', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Singular post type name. */
			'view_item'             => \sprintf( \__( 'View %s', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Plural post type name. */
			'view_items'            => \sprintf( \__( 'View %s', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural post type name. */
			'search_items'          => \sprintf( \__( 'Search %s', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural post type name. */
			'not_found'             => \sprintf( \__( 'No %s found.', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural post type name. */
			'not_found_in_trash'    => \sprintf( \__( 'No %s found in Trash.', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural post type name. */
			'all_items'             => \sprintf( \__( 'All %s', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Singular post type name. */
			'archives'              => \sprintf( \__( '%s Archives', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Singular post type name. */
			'attributes'            => \sprintf( \__( '%s Attributes', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Singular post type name. */
			'insert_into_item'      => \sprintf( \__( 'Insert into %s', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Singular post type name. */
			'uploaded_to_this_item' => \sprintf( \__( 'Uploaded to this %s', 'zestry-toolkit' ), $singular ),
			'featured_image'        => \__( 'Featured Image', 'zestry-toolkit' ),
			'set_featured_image'    => \__( 'Set featured image', 'zestry-toolkit' ),
			'remove_featured_image' => \__( 'Remove featured image', 'zestry-toolkit' ),
			'use_featured_image'    => \__( 'Use as featured image', 'zestry-toolkit' ),
		);
	}
}
