<?php

/**
 * Post Types API: PostTypes module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\PostTypes;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Services\Path;

/**
 * Discovers plugin custom post types and taxonomies and registers them with
 * WordPress.
 *
 * A post types directory contains PHP files, one per post type; each file
 * returns a {@see PostType} instance and registers under the file's own name
 * (`post-types/book.php` registers as `book`). A taxonomies directory works
 * the same way with {@see Taxonomy} instances. Neither name is auto-namespaced
 * to the plugin slug, the way a meta key and a block name are not either --
 * see {@see PostType} for why, and prefix them yourself in the filename.
 *
 * Taxonomies are always registered after every post type, regardless of file
 * discovery order, so a taxonomy's {@see Taxonomy::object_types()} can safely
 * name any post type this same plugin discovers.
 *
 * {@see get_discovered_post_types()} and {@see get_discovered_taxonomies()}
 * hand back everything the two directories declare, switched on or off, so a
 * plugin can build a screen over its own post types without keeping a second
 * list of them somewhere. `is_enabled()` is read at registration instead: a
 * file that switches itself off is one you can list and WordPress never hears
 * about.
 *
 * Both roots behave the same way, which is what lets a plugin with post types
 * but no taxonomies skip the `taxonomies/` directory entirely. Name one with
 * `post-types/` or `taxonomies/` and it must
 * exist -- asking for a directory by name and getting nothing is a typo worth
 * hearing about. Leave one at its default and let it be absent, and your
 * plugin simply has none of those files yet.
 *
 */
class PostTypes extends Module {

	use WithFolderWalker;

	/**
	 * Default plugin-relative directory of post type files.
	 */
	const POST_TYPES_ROOT = 'post-types';

	/**
	 * Default plugin-relative directory of taxonomy files.
	 */
	const TAXONOMIES_ROOT = 'taxonomies';

	/**
	 * Path module injected by the plugin to resolve the discovery directories.
	 *
	 * @var Path
	 */
	public Path $path;

	/**
	 * Discovered post type instances, indexed by their registered name.
	 *
	 * Null until the directory has been walked, so discovery runs once and every
	 * caller is handed the same instances -- which is what lets
	 * {@see get_post_type_of()} recognise one it gave out earlier. Requiring a
	 * file again would return an equal instance that is not the same object.
	 *
	 * @var array<string, PostType>|null
	 */
	private ?array $post_types = null;

	/**
	 * Discovered taxonomy instances, indexed by their registered name.
	 *
	 * @var array<string, Taxonomy>|null
	 */
	private ?array $taxonomies = null;

	/**
	 * This post type's registered name.
	 *
	 * @param PostType $post_type The instance to look up.
	 * @return string
	 * @throws \InvalidArgumentException When the instance was not discovered by this module.
	 * @throws DiscoveryException When discovery fails.
	 */
	public function get_post_type_of( PostType $post_type ): string {
		$name = \array_search( $post_type, $this->get_discovered_post_types(), true );

		if ( false === $name ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The given %s instance was not discovered by this PostTypes module.', PostType::class )
			);
		}

		return $name;
	}

	/**
	 * This taxonomy's registered name.
	 *
	 * @param Taxonomy $taxonomy The instance to look up.
	 * @return string
	 * @throws \InvalidArgumentException When the instance was not discovered by this module.
	 * @throws DiscoveryException When discovery fails.
	 */
	public function get_taxonomy_of( Taxonomy $taxonomy ): string {
		$name = \array_search( $taxonomy, $this->get_discovered_taxonomies(), true );

		if ( false === $name ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The given %s instance was not discovered by this PostTypes module.', Taxonomy::class )
			);
		}

		return $name;
	}

	/**
	 * Discover and register every post type, then every taxonomy.
	 *
	 * Post types are registered first, unconditionally, so a taxonomy
	 * discovered afterward can name any of them in object_types() regardless
	 * of the two directories' relative file discovery order.
	 *
	 * This is the only place `is_enabled()` is consulted. Discovery hands back
	 * every file either way, so a file that switches itself off is still
	 * something you can list; it is registration it does not reach.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function register_all(): void {
		foreach ( $this->get_discovered_post_types() as $name => $post_type ) {
			if ( ! $post_type->is_enabled() ) {
				continue;
			}

			$this->assert_registered( \register_post_type( $name, $post_type->get_args() ), $name, 'post type' );
		}

		foreach ( $this->get_discovered_taxonomies() as $name => $taxonomy ) {
			if ( ! $taxonomy->is_enabled() ) {
				continue;
			}

			$this->assert_registered(
				\register_taxonomy( $name, $taxonomy->object_types(), $taxonomy->get_args() ),
				$name,
				'taxonomy'
			);
		}
	}

	/**
	 * Every post type this plugin declares, by registered name.
	 *
	 * Everything the directory holds, including any file whose `is_enabled()`
	 * returns false — so a screen offering to switch features on can list the
	 * ones currently switched off, which is the only case such a screen exists
	 * for. Ask an instance yourself when you need to tell them apart; only
	 * {@see register_all()} acts on the answer.
	 *
	 * The directory is walked once and the instances kept, so two calls hand
	 * back the same objects and {@see get_post_type_of()} recognises one you
	 * were given earlier.
	 *
	 * @return array<string, PostType> Wired instances keyed by registered name.
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	public function get_discovered_post_types(): array {
		if ( null !== $this->post_types ) {
			return $this->post_types;
		}

		$root_dir = $this->path->get_plugin_path( self::POST_TYPES_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			$this->post_types = array();

			return $this->post_types;
		}

		$this->post_types = array();

		// A post type name is the `post_type` column in wp_posts. The filename is it,
		// verbatim: rewriting one renames every row already stored under it.
		foreach ( $this->walk_folder( $root_dir, array( 'php' ), 1 ) as $file ) {
			$name = \basename( $file, '.php' );

			/** @var PostType $instance */
			$instance = require $root_dir . '/' . $file;

			if ( ! $instance instanceof PostType ) {
				throw new DiscoveryException(
					\sprintf(
						'The file "%s" must return an instance of %s. Got: %s',
						$root_dir . '/' . $file,
						PostType::class,
						\is_object( $instance ) ? $instance::class : \gettype( $instance )
					)
				);
			}

			// Wired here rather than at registration, so that is_enabled() can
			// read an injected service whenever it is asked.
			$this->get_plugin()->wire( $instance );

			$this->post_types[ $name ] = $instance;
		}

		return $this->post_types;
	}

	/**
	 * Every taxonomy this plugin declares, by registered name.
	 *
	 * Everything the directory holds, on the same terms as
	 * {@see get_discovered_post_types()}: a file whose `is_enabled()` returns
	 * false is listed here and registered nowhere.
	 *
	 * @return array<string, Taxonomy> Wired instances keyed by registered name.
	 * @throws DiscoveryException When a named taxonomies directory does not exist, or a file returns the wrong value.
	 */
	public function get_discovered_taxonomies(): array {
		if ( null !== $this->taxonomies ) {
			return $this->taxonomies;
		}

		$root_dir = $this->path->get_plugin_path( self::TAXONOMIES_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			// The default directory, absent and never asked for: this plugin
			// registers no taxonomies. Only a directory named by
			$this->taxonomies = array();

			return $this->taxonomies;
		}

		$this->taxonomies = array();

		// A taxonomy name is stored the same way, and is equally not ours to respell.
		foreach ( $this->walk_folder( $root_dir, array( 'php' ), 1 ) as $file ) {
			$name = \basename( $file, '.php' );

			/** @var Taxonomy $instance */
			$instance = require $root_dir . '/' . $file;

			if ( ! $instance instanceof Taxonomy ) {
				throw new DiscoveryException(
					\sprintf(
						'The file "%s" must return an instance of %s. Got: %s',
						$root_dir . '/' . $file,
						Taxonomy::class,
						\is_object( $instance ) ? $instance::class : \gettype( $instance )
					)
				);
			}

			// Wired here rather than at registration, so that is_enabled() can
			// read an injected service whenever it is asked.
			$this->get_plugin()->wire( $instance );

			$this->taxonomies[ $name ] = $instance;
		}

		return $this->taxonomies;
	}

	/**
	 * Resolve both directories and schedule registration on every request.
	 *
	 * Deferred to `init` at the default priority (10), matching WordPress
	 * core's own recommended timing for `register_post_type()`/
	 * `register_taxonomy()` -- unlike Ajax/RestApi's later, request-type-gated
	 * hooks, a post type or taxonomy must exist before anything else running
	 * on `init` might query it.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function on_boot(): void {
		$this->on_wp_init(
			static function ( self $module ): void {
				$module->register_all();
			}
		);
	}

	/**
	 * Fail loudly when WordPress refuses a registration.
	 *
	 * `register_post_type()` and `register_taxonomy()` return a `WP_Error`
	 * rather than throwing, so an unchecked call leaves the type simply absent:
	 * no menu, no queries, nothing said. The commonest cause is a filename over
	 * the length WordPress allows -- 20 characters for a post type, 32 for a
	 * taxonomy -- which is invisible until something that needed the type does
	 * not work.
	 *
	 * @param mixed  $result What WordPress returned.
	 * @param string $name   The name being registered, from the file.
	 * @param string $kind   `post type` or `taxonomy`, for the message.
	 * @return void
	 * @throws DiscoveryException When WordPress refused the registration.
	 */
	private function assert_registered( mixed $result, string $name, string $kind ): void {
		if ( ! \is_wp_error( $result ) ) {
			return;
		}

		throw DiscoveryException::registration_refused( $kind, $name, $result->get_error_message() );
	}
}
