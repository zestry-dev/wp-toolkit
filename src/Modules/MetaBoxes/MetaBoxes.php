<?php

/**
 * MetaBoxes API: MetaBoxes module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\MetaBoxes;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Modules\Fields\Fields;
use Zestry\WPToolkit\Modules\Fields\MetaType;
use Zestry\WPToolkit\Modules\Path;

/**
 * Puts panels on the post and comment edit screens, and owns the part that is
 * easy to get wrong.
 *
 * A file in `resources/meta-boxes/` returns a {@see MetaBox}. Its filename is the box's
 * identifier, prefixed with your plugin slug: `resources/meta-boxes/details.php` becomes
 * `{plugin-slug}-details`.
 *
 * ## What this exists for
 *
 * The markup is the easy half, and this module does not touch it. The save is
 * the half worth owning, because `save_post` fires far more often than a user
 * pressing Update, and every guard you would write by hand is a bug when
 * omitted:
 *
 * - **An autosave carries none of your fields.** Reading them anyway stores
 *   empty values over what was there.
 * - **A revision is saved as its own post.** Writing meta then attaches it to
 *   the revision rather than the post.
 * - **Without a nonce, any page can submit that form on a user's behalf.**
 * - **Without a capability check, anyone who can reach the screen can write.**
 *
 * All four are checked before your `save()` runs, and the nonce field is
 * printed for you before your `render()` does.
 *
 * ## The two screens that have boxes
 *
 * A box declares an {@see MetaBoxType} — `Post` or `Comment` — and those are
 * the only two WordPress offers. Terms and users take custom fields through
 * action hooks that emit table rows rather than panels, so they are not a
 * meta-box concern at all; register their meta with
 * {@see \Zestry\WPToolkit\Modules\Fields\Fields} and render it on the term or profile
 * form yourself.
 *
 * The comment screen has neither autosaves nor revisions, so a comment box is
 * guarded by its nonce and capability alone.
 *
 * ## The block editor
 *
 * The block editor still shows classic boxes, and a post type that excludes
 * `editor` support has nothing else. But for a type using the block editor, the
 * modern equivalent is a sidebar panel written in JavaScript against meta you
 * registered with {@see \Zestry\WPToolkit\Modules\Fields\Fields} — a box is not the only
 * answer, just the one that needs no build step.
 *
 * @example A box
 * ```
 * // resources/meta-boxes/details.php
 * return new class extends MetaBox {
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
 *         // your markup
 *     }
 *
 *     public function save( object $post ): void {
 *         // reached only on a real save, by a user allowed to make it
 *     }
 * };
 * ```
 *
 */
class MetaBoxes extends Module implements Bootable {

	use WithFolderWalker;

	/**
	 * Where boxes are discovered, relative to the plugin root.
	 */
	const BOXES_ROOT = 'resources/meta-boxes';

	/**
	 * Discovered boxes by screen type, then identifier.
	 *
	 * Nested because an identifier is unique only within a screen: the post edit
	 * screen and the comment edit screen are different pages, and a box called
	 * `details` on each is two boxes.
	 *
	 * @var array<string, array<string, MetaBox>>|null
	 */
	private ?array $discovered = null;

	/**
	 * Every discovered box, by screen type and then by identifier.
	 *
	 * @return array<string, array<string, MetaBox>> Screen type => identifier => instance.
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	public function get_discovered_boxes(): array {
		if ( null !== $this->discovered ) {
			return $this->discovered;
		}

		$root_dir = $this->with( Path::class )->get_plugin_path( self::BOXES_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			$this->discovered = array();

			return $this->discovered;
		}

		$instances = array();

		foreach ( $this->walk_folder( $root_dir, array( 'php' ), 1 ) as $file ) {
			$box = $this->wire_box_file( $root_dir . '/' . $file );

			// Wired first, so is_enabled() can reach a module with `with()`.
			if ( ! $box->is_enabled() ) {
				continue;
			}

			$id   = $this->get_box_id( \basename( $file, '.php' ) );
			$type = $box->object_type()->value;

			// Scoped by object type rather than name-mapped: a post box and a
			// comment box may share a name, and they are two boxes.
			if ( isset( $instances[ $type ][ $id ] ) ) {
				throw new DiscoveryException(
					\sprintf( 'Two %1$s meta boxes resolve to the identifier "%2$s". Rename one of them.', $type, $id )
				);
			}

			$instances[ $type ][ $id ] = $box;
		}

		$this->discovered = $instances;

		return $this->discovered;
	}

	/**
	 * Every box belonging to one kind of screen, by identifier.
	 *
	 * @param MetaBoxType $type The screen type.
	 * @return array<string, MetaBox>
	 * @throws DiscoveryException When discovery fails.
	 */
	public function get_boxes_of( MetaBoxType $type ): array {
		return $this->get_discovered_boxes()[ $type->value ] ?? array();
	}

	/**
	 * The identifier a box file registers under.
	 *
	 * Prefixed with the plugin slug, since a box's id becomes an element id on a
	 * screen every plugin can add panels to.
	 *
	 * @param string $name The box's local name — its filename without `.php`.
	 * @return string
	 */
	public function get_box_id( string $name ): string {
		return $this->get_plugin()->get_namespaced_name( $name );
	}

	/**
	 * This box's identifier, from the file it was discovered in.
	 *
	 * @param MetaBox $box The instance to look up.
	 * @return string
	 * @throws \InvalidArgumentException When the instance was not discovered by this module.
	 */
	public function get_id_of( MetaBox $box ): string {
		$id = \array_search( $box, $this->get_boxes_of( $box->object_type() ), true );

		if ( false === $id ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The given %s instance was not discovered by this MetaBoxes module.', MetaBox::class )
			);
		}

		return $id;
	}

	/**
	 * Add every discovered box to the screens it names.
	 *
	 * @return void
	 * @throws DiscoveryException When discovery fails.
	 *
	 * @internal
	 */
	public function register_all(): void {
		foreach ( $this->get_discovered_boxes() as $boxes ) {
			foreach ( $boxes as $id => $box ) {
				$this->register_box( $id, $box );
			}
		}
	}

	/**
	 * Hand a save to every box that should act on it.
	 *
	 * The guards are the reason this module exists. `save_post` fires for
	 * autosaves and for revisions, neither of which carries the form, so a
	 * handler without these reads absent fields and stores empty values over
	 * whatever was there.
	 *
	 * @param int      $post_id The post being saved.
	 * @param \WP_Post $post    The post being saved.
	 * @return void
	 * @throws DiscoveryException When discovery fails.
	 *
	 * @internal
	 */
	public function save_all( int $post_id, \WP_Post $post ): void {
		// Two guards a comment save does not need, because that screen has
		// neither: an autosave carries none of the form, and a revision is a
		// post of its own that meta must not be attached to.
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( false !== \wp_is_post_revision( $post_id ) ) {
			return;
		}

		$this->hand_to_boxes( MetaBoxType::Post, $post_id, $post, $post->post_type );
	}

	/**
	 * Hand a comment save to every box that should act on it.
	 *
	 * The comment screen has no autosave and no revisions, so the nonce and the
	 * capability are the whole check here.
	 *
	 * @param int $comment_id The comment being saved.
	 * @return void
	 * @throws DiscoveryException When discovery fails.
	 *
	 * @internal
	 */
	public function save_comment( int $comment_id ): void {
		$comment = \get_comment( $comment_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$this->hand_to_boxes( MetaBoxType::Comment, $comment_id, $comment, 'comment' );
	}

	/**
	 * Register the hooks a box needs, for both screens that render one.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function on_boot(): void {
		\add_action( 'add_meta_boxes', array( $this, 'register_all' ) );
		\add_action( 'save_post', array( $this, 'save_all' ), 10, 2 );
		\add_action( 'edit_comment', array( $this, 'save_comment' ) );
	}

	/**
	 * Add one box to every screen it names.
	 *
	 * @param string  $id  The identifier it registers under.
	 * @param MetaBox $box The box.
	 * @return void
	 */
	private function register_box( string $id, MetaBox $box ): void {
		foreach ( $box->screens() as $screen ) {
			\add_meta_box(
				$id,
				$box->title(),
				function ( object $edited ) use ( $id, $box ): void {
					// Printed here rather than left to the box, so a box cannot
					// be written without one.
					\wp_nonce_field( $id, $id . '-nonce' );

					$box->render( $edited );
				},
				$screen,
				$box->context()->value,
				$box->priority()->value
			);
		}
	}

	/**
	 * Run every box of one type that the request actually submitted.
	 *
	 * @param MetaBoxType $type      The screen type being saved.
	 * @param int         $object_id The post or comment being saved.
	 * @param object      $edited    The object itself.
	 * @param string      $screen    The screen name to match against `screens()`.
	 * @return void
	 * @throws DiscoveryException When discovery fails.
	 */
	private function hand_to_boxes( MetaBoxType $type, int $object_id, object $edited, string $screen ): void {
		foreach ( $this->get_boxes_of( $type ) as $id => $box ) {
			if ( ! \in_array( $screen, $box->screens(), true ) ) {
				continue;
			}

			if ( ! $this->has_valid_nonce( $id ) || ! $box->can_edit( $object_id ) ) {
				continue;
			}

			$this->store_declared_fields( $box, $object_id );

			$box->save( $edited );
		}
	}

	/**
	 * Write every field the box named, from the request.
	 *
	 * A key the form did not submit is skipped rather than written empty: a
	 * checkbox is absent when unchecked, and so is every field of a box the user
	 * never opened.
	 *
	 * @param MetaBox $box       The box being saved.
	 * @param int     $object_id The post or comment being saved.
	 * @return void
	 */
	private function store_declared_fields( MetaBox $box, int $object_id ): void {
		foreach ( $box->fields() as $key ) {
			// The nonce was verified by the caller before this runs.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			// Unslashed, not sanitised: the field's own sanitize() runs inside
			// WordPress's write, and its validate() inside Fields::set().
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$this->fields()->set( $object_id, $key, \wp_unslash( $_POST[ $key ] ), $this->get_meta_type( $box ) );
		}
	}

	/**
	 * The meta table a box's declared fields live in.
	 *
	 * @param MetaBox $box The box.
	 * @return MetaType
	 */
	private function get_meta_type( MetaBox $box ): MetaType {
		return MetaBoxType::Comment === $box->object_type()
			? MetaType::Comment
			: MetaType::Post;
	}

	/**
	 * Whether the request carries this box's nonce.
	 *
	 * A box absent from the submitted form has no nonce, which is why a missing
	 * one skips the box rather than failing the request: a screen showing three
	 * boxes and saving one is ordinary.
	 *
	 * @param string $id The box's identifier.
	 * @return bool
	 */
	private function has_valid_nonce( string $id ): bool {
		// This *is* the nonce check, so the missing-nonce sniff does not apply;
		// the value is sanitised on the next line, before it is used.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = \sanitize_key( \wp_unslash( (string) ( $_POST[ $id . '-nonce' ] ?? '' ) ) );

		return '' !== $nonce && false !== \wp_verify_nonce( $nonce, $id );
	}

	/**
	 * Require a box file and wire the instance it returns.
	 *
	 * @param string $file Absolute path to the box file.
	 * @return MetaBox
	 * @throws DiscoveryException When the file does not return a MetaBox instance.
	 */
	private function wire_box_file( string $file ): MetaBox {
		/** @var MetaBox $instance */
		$instance = require $file;

		if ( ! $instance instanceof MetaBox ) {
			throw new DiscoveryException(
				\sprintf(
					'The file "%s" must return an instance of %s. Got: %s',
					$file,
					MetaBox::class,
					\is_object( $instance ) ? $instance::class : \gettype( $instance )
				)
			);
		}

		$this->get_plugin()->wire( $instance );

		return $instance;
	}

	/**
	 * The fields module, asked for where it is needed.
	 *
	 * Not a property: building a module boots it, and a declaration would hide
	 * that behind a type name. Meta is written through it so a box's value
	 * passes the same guards a field registers.
	 *
	 * @return Fields
	 */
	private function fields(): Fields {
		return $this->get_plugin()->get( Fields::class );
	}
}
