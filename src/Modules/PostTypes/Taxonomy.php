<?php

/**
 * Post Types API: Taxonomy base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\PostTypes;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * Base class for a file-based custom taxonomy registration.
 *
 * A taxonomy file returns a subclass instance. The PostTypes module wires it
 * and calls {@see get_args()} to build the array it passes to WordPress core's
 * `register_taxonomy()`.
 *
 * Only `singular_name()`, `plural_name()` and `object_types()` are required --
 * unlike a {@see PostType}, a taxonomy also has to say what it attaches to.
 * `get_args()` builds a full label set from the first two, rather than leaving
 * core's own defaults to fall back on generic 'Tags'/'Categories' wording
 * meant for the built-in taxonomies.
 *
 * > [!NOTE]
 * > **The taxonomy's name comes from its filename, not from this class.**
 * > `taxonomies/genre.php` registers as `genre`. Like post type names, it is
 * > *not* namespaced to the plugin slug: WordPress caps a taxonomy name at 32
 * > characters, and the convention is a short, plain, globally unique name.
 *
 * Taxonomies are registered after every post type has been (see
 * {@see PostTypes}), so {@see object_types()} can safely name any post type
 * this same plugin discovers, in either directory, regardless of file
 * ordering.
 *
 * A file at `taxonomies/genre.php` attaches to a `book` post type discovered
 * from `post-types/book.php`. `wp zestry make taxonomy <name>` generates a
 * starting point.
 *
 * @stub taxonomy.php.stub
 *
 * > [!IMPORTANT]
 * > **This name is not prefixed with your plugin slug, so choose it as though
 * > every plugin on the site can see it — because they can.** WordPress caps a taxonomy name at 32 characters, which is why the slug is not added for you.
 * >
 * > Two plugins registering `genre` are the same taxonomy, and whichever registers
 * > second loses. Put your own prefix in the filename: `taxonomies/acme-genre.php`.
 *
 */
abstract class Taxonomy implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * The singular display name, e.g. 'Genre'.
	 *
	 * @return string
	 */
	abstract public function singular_name(): string;

	/**
	 * The plural display name, e.g. 'Genres'.
	 *
	 * @return string
	 */
	abstract public function plural_name(): string;

	/**
	 * The post type name(s) this taxonomy attaches to.
	 *
	 * Each entry may be a post type discovered from this same plugin's
	 * `post-types/` directory (see {@see PostType}) or the name of any other
	 * already-registered post type, including WordPress's own built-in `post`.
	 *
	 * @return string[]
	 */
	abstract public function object_types(): array;

	/**
	 * Additional or overriding labels beyond {@see get_args()}'s defaults.
	 *
	 * See {@see PostType::labels()} for the same reasoning: the base label
	 * set already covers every commonly-needed key, so override this only to
	 * replace a specific one (for example, `parent_item`/`parent_item_colon`
	 * on a hierarchical taxonomy) or add a key this base does not set.
	 *
	 * @return array<string, string>
	 */
	public function labels(): array {
		return array();
	}

	/**
	 * Whether this taxonomy behaves like categories (true) or tags (false).
	 *
	 * A hierarchical taxonomy supports parent/child terms and is edited with
	 * a checkbox tree in the admin, matching WordPress's own Category
	 * taxonomy. A non-hierarchical taxonomy is flat and edited with a
	 * comma-separated tag input, matching WordPress's own Tag taxonomy.
	 *
	 * @return bool
	 */
	public function is_hierarchical(): bool {
		return false;
	}

	/**
	 * Whether this taxonomy is exposed through the REST API and block editor.
	 *
	 * @return bool
	 */
	public function is_shown_in_rest(): bool {
		return true;
	}

	/**
	 * Whether this taxonomy is intended for public display on the front end.
	 *
	 * This is a bundle: WordPress derives `publicly_queryable`, `show_ui`,
	 * `show_in_menu`, `show_in_nav_menus`, `show_tagcloud` and
	 * `show_in_quick_edit` from it. Each of those has its own method below to
	 * break out of the bundle one argument at a time.
	 *
	 * @return bool
	 */
	public function is_public(): bool {
		return true;
	}

	/**
	 * Whether the taxonomy gets a management UI in the admin.
	 *
	 * Null (the default) lets WordPress derive it from {@see is_public()}, as
	 * it does for the four methods below. Return a bool for a combination
	 * `public` alone cannot express -- the common one being a taxonomy kept off
	 * the front end but still editable in the admin: `is_public()` false and
	 * this true.
	 *
	 * @return bool|null
	 */
	public function is_shown_in_ui(): ?bool {
		return null;
	}

	/**
	 * Whether the taxonomy gets a submenu entry under its post type's menu.
	 *
	 * Requires {@see is_shown_in_ui()} to be on, and defaults to it.
	 *
	 * @return bool|null
	 */
	public function is_shown_in_menu(): ?bool {
		return null;
	}

	/**
	 * Whether the taxonomy's terms are offered when building a nav menu.
	 *
	 * Null (the default) derives it from {@see is_public()}.
	 *
	 * @return bool|null
	 */
	public function is_shown_in_nav_menus(): ?bool {
		return null;
	}

	/**
	 * Whether front-end queries may request this taxonomy's term archives.
	 *
	 * Null (the default) derives it from {@see is_public()}.
	 *
	 * @return bool|null
	 */
	public function is_publicly_queryable(): ?bool {
		return null;
	}

	/**
	 * Whether the taxonomy is offered to the Tag Cloud widget.
	 *
	 * Null (the default) derives it from {@see is_shown_in_ui()}.
	 *
	 * @return bool|null
	 */
	public function is_shown_in_tagcloud(): ?bool {
		return null;
	}

	/**
	 * Whether the taxonomy is editable from the post list's Quick Edit.
	 *
	 * Null (the default) derives it from {@see is_shown_in_ui()}.
	 *
	 * @return bool|null
	 */
	public function is_shown_in_quick_edit(): ?bool {
		return null;
	}

	/**
	 * The permalink structure, or false to disable pretty permalinks for this
	 * taxonomy entirely.
	 *
	 * Becomes WordPress's own `rewrite` argument, so it takes the same keys:
	 * `slug`, `with_front`, `hierarchical` and `ep_mask`.
	 *
	 * @return array<string, mixed>|false
	 */
	public function rewrite(): array|false {
		return array( 'slug' => $this->get_taxonomy() );
	}

	/**
	 * This taxonomy's own name, as registered.
	 *
	 * Resolved from the PostTypes module's registry, which derives it from
	 * the file's own name within the taxonomies directory. The taxonomy
	 * itself stores no name state.
	 *
	 * @return string
	 */
	final public function get_taxonomy(): string {
		return $this->post_types()->get_taxonomy_of( $this );
	}

	/**
	 * Build the full argument array passed to `register_taxonomy()`.
	 *
	 * Merges {@see get_default_labels()} with this taxonomy's own `labels()`
	 * overrides, then every other declared option. Override this directly
	 * only when a `register_taxonomy()` argument has no dedicated method
	 * above.
	 *
	 * @return array<string, mixed>
	 */
	public function get_args(): array {
		return array(
			'labels'             => \array_merge( $this->get_default_labels(), $this->labels() ),
			'hierarchical'       => $this->is_hierarchical(),
			'public'             => $this->is_public(),
			// A null here is exactly equivalent to omitting the key:
			// WP_Taxonomy::set_props() derives each of these from `public`
			// only when it is still null after wp_parse_args(). Passing them
			// unconditionally therefore costs nothing.
			'publicly_queryable' => $this->is_publicly_queryable(),
			'show_ui'            => $this->is_shown_in_ui(),
			'show_in_menu'       => $this->is_shown_in_menu(),
			'show_in_nav_menus'  => $this->is_shown_in_nav_menus(),
			'show_tagcloud'      => $this->is_shown_in_tagcloud(),
			'show_in_quick_edit' => $this->is_shown_in_quick_edit(),
			'show_in_rest'       => $this->is_shown_in_rest(),
			'rewrite'            => $this->rewrite(),
		);
	}

	/**
	 * The PostTypes module that manages this taxonomy.
	 *
	 * @return PostTypes
	 */
	final protected function post_types(): PostTypes {
		return $this->get_plugin()->get( PostTypes::class );
	}

	/**
	 * Build the full default label set from singular_name()/plural_name().
	 *
	 * See {@see PostType::get_default_labels()} for the same reasoning,
	 * including why every format string is wrapped in `__()` (so this file is
	 * picked up by `wp i18n make-pot`) while `$singular`/`$plural` themselves
	 * are left untranslated here -- they are runtime values from the
	 * consumer's own `singular_name()`/`plural_name()`, not strings this base
	 * class owns. A few keys here are meaningful only for a hierarchical
	 * taxonomy (`parent_item`, `parent_item_colon`) and are left for
	 * `labels()` to add explicitly, matching WordPress core's own behavior of
	 * leaving them null for a non-hierarchical (tag-like) taxonomy.
	 *
	 * @return array<string, string>
	 */
	private function get_default_labels(): array {
		$singular = $this->singular_name();
		$plural   = $this->plural_name();

		return array(
			'name'                       => $plural,
			'singular_name'              => $singular,
			'menu_name'                  => $plural,
			/* translators: %s: Plural taxonomy name. */
			'search_items'               => \sprintf( \__( 'Search %s', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural taxonomy name. */
			'all_items'                  => \sprintf( \__( 'All %s', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Singular taxonomy name. */
			'edit_item'                  => \sprintf( \__( 'Edit %s', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Singular taxonomy name. */
			'view_item'                  => \sprintf( \__( 'View %s', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Singular taxonomy name. */
			'update_item'                => \sprintf( \__( 'Update %s', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Singular taxonomy name. */
			'add_new_item'               => \sprintf( \__( 'Add New %s', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Singular taxonomy name. */
			'new_item_name'              => \sprintf( \__( 'New %s Name', 'zestry-toolkit' ), $singular ),
			/* translators: %s: Plural taxonomy name. */
			'not_found'                  => \sprintf( \__( 'No %s found.', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural taxonomy name. */
			'no_terms'                   => \sprintf( \__( 'No %s', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural taxonomy name. */
			'items_list_navigation'      => \sprintf( \__( '%s list navigation', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural taxonomy name. */
			'items_list'                 => \sprintf( \__( '%s list', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural taxonomy name. */
			'back_to_items'              => \sprintf( \__( '&larr; Go to %s', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural taxonomy name. */
			'separate_items_with_commas' => \sprintf( \__( 'Separate %s with commas', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural taxonomy name. */
			'add_or_remove_items'        => \sprintf( \__( 'Add or remove %s', 'zestry-toolkit' ), $plural ),
			/* translators: %s: Plural taxonomy name. */
			'choose_from_most_used'      => \sprintf( \__( 'Choose from the most used %s', 'zestry-toolkit' ), $plural ),
		);
	}
}
