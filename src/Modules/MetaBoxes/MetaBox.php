<?php

/**
 * MetaBoxes API: MetaBox base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\MetaBoxes;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * One panel on the post edit screen.
 *
 * A file in `meta-boxes/` returns one of these. You write the markup and decide
 * what to store; everything between the two is handled — the nonce, and the
 * guards that decide whether a `save_post` is even a save worth acting on.
 *
 * @stub meta-box.php.stub
 *
 * @example A box
 * `render()` writes the form. A nonce field is already printed, so the only
 * escaping to think about is your own.
 *
 * ```
 * namespace Acme\Plugin\MetaBoxes;
 *
 * use Acme\Plugin\Core\Modules\Fields\Fields;
 * use Acme\Plugin\Core\Modules\MetaBoxes\MetaBox;
 * use WP_Post;
 *
 * return new class extends MetaBox {
 *
 *     public Fields $fields;
 *
 *     public function title(): string {
 *         return __( 'Book details', 'acme-plugin' );
 *     }
 *
 *     public function screens(): array {
 *         return array( 'book' );
 *     }
 *
 *     public function render( object $post ): void {
 *         printf(
 *             '<label for="acme_rating">%s</label>
 *              <input type="number" id="acme_rating" name="acme_rating" value="%s" min="1" max="5">',
 *             esc_html__( 'Rating', 'acme-plugin' ),
 *             esc_attr( (string) $this->fields->get( $post->ID, 'acme_rating', '' ) )
 *         );
 *     }
 *
 *     public function fields(): array {
 *         return array( 'acme_rating' );
 *     }
 *
 *     public function save( object $post ): void {
 *         // The field above is already stored. Nothing else to do here.
 *     }
 * };
 * ```
 */
abstract class MetaBox implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * The heading shown on the panel.
	 *
	 * @return string A short, translated title.
	 */
	abstract public function title(): string;

	/**
	 * The screens this box appears on.
	 *
	 * Post type names for a post box — override this for a custom type, since
	 * the default is the built-in `post`.
	 *
	 * A comment box needs nothing here. WordPress renders one comment edit
	 * screen and offers no way to target a single comment type, so `comment` is
	 * both the default and the only value that draws anything.
	 *
	 * @return string[]
	 */
	public function screens(): array {
		return array( $this->object_type()->value );
	}

	/**
	 * Which kind of screen this box belongs to.
	 *
	 * @return MetaBoxType
	 */
	public function object_type(): MetaBoxType {
		return MetaBoxType::Post;
	}

	/**
	 * Write the panel's markup.
	 *
	 * Everything you print is your own to escape — `esc_attr()` around a value
	 * in an attribute, `esc_html()` around one in text. The nonce field is
	 * printed for you before this runs.
	 *
	 * @param \WP_Post|\WP_Comment $edited The object being edited.
	 * @return void
	 */
	abstract public function render( object $edited ): void;

	/**
	 * Store what the form submitted.
	 *
	 * **Reached only when this is a real save by a permitted user.** For a post,
	 * `save_post` fires for autosaves and for revisions as well, and an autosave
	 * carries none of your fields — so a handler that does not check would read
	 * them as empty and wipe what was stored. A comment has neither, and arrives
	 * on `edit_comment`. Whichever applies, plus the nonce and
	 * {@see can_edit()}, is checked before this runs.
	 *
	 * `$edited` is the `WP_Post` or `WP_Comment` being saved, matching
	 * {@see object_type()}.
	 *
	 * Runs after every key named by {@see fields()} has been stored, so this is
	 * for whatever that list cannot express. Leave it empty when the list covers
	 * everything.
	 *
	 * What arrives in `$_POST` is raw and unslashed by nothing. Writing through
	 * {@see \Zestry\WPToolkit\Modules\Fields\Fields::set()} applies the field's
	 * `validate()`; WordPress applies its `sanitize()` on any write, including a
	 * bare `update_post_meta()`.
	 *
	 * @param \WP_Post|\WP_Comment $edited The object being saved.
	 * @return void
	 */
	abstract public function save( object $edited ): void;

	/**
	 * The meta keys this box's form submits.
	 *
	 * Name them and the module does the reading: for each key present in the
	 * request it unslashes the value and writes it through
	 * {@see \Zestry\WPToolkit\Modules\Fields\Fields::set()}, so the field's `validate()`
	 * and `sanitize()` both apply. A key the form did not submit is left alone
	 * rather than written empty.
	 *
	 * ```
	 * public function fields(): array {
	 *     return array( 'acme_rating', 'acme_blurb' );
	 * }
	 * ```
	 *
	 * This covers a form whose inputs are named after the fields they edit.
	 * {@see save()} runs afterwards for anything else — a value assembled from
	 * several inputs, a taxonomy term, something that is not meta at all.
	 *
	 * @return string[] Meta keys declared by your `fields/` files.
	 */
	public function fields(): array {
		return array();
	}

	/**
	 * Which column of the edit screen the panel sits in.
	 *
	 * @return Context
	 */
	public function context(): Context {
		return Context::Advanced;
	}

	/**
	 * Where the panel sits among the others in its column.
	 *
	 * @return Priority
	 */
	public function priority(): Priority {
		return Priority::Default;
	}

	/**
	 * Whether the current user may save this box on a given post.
	 *
	 * Checked before {@see save()}, on top of the nonce. The default asks
	 * whether they can edit the object, which is what the screen itself required
	 * to show them the box.
	 *
	 * @param int $object_id The post or comment being saved.
	 * @return bool
	 */
	public function can_edit( int $object_id ): bool {
		return \current_user_can( 'edit_' . $this->object_type()->value, $object_id );
	}

	/**
	 * The identifier this box is registered under.
	 *
	 * Your filename with the plugin slug prefixed, since a box's id is an
	 * element id on a screen every plugin can add to. `meta-boxes/details.php`
	 * gives `{plugin-slug}-details`.
	 *
	 * @return string
	 */
	final public function get_id(): string {
		return $this->meta_boxes()->get_id_of( $this );
	}

	/**
	 * The module that discovered this box.
	 *
	 * @return MetaBoxes
	 */
	final protected function meta_boxes(): MetaBoxes {
		return $this->get_plugin()->get( MetaBoxes::class );
	}
}
