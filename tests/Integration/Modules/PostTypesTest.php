<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\PostTypes\PostType;
use Zestry\WPToolkit\Modules\PostTypes\PostTypes;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Discovery and registration of post types and taxonomies, label derivation,
 * registration ordering, and the name-lookup helpers.
 *
 * @covers \Zestry\WPToolkit\Modules\PostTypes\PostTypes
 * @covers \Zestry\WPToolkit\Modules\PostTypes\PostType
 * @covers \Zestry\WPToolkit\Modules\PostTypes\Taxonomy
 */
final class PostTypesTest extends TestCase {

	public function tear_down(): void {
		foreach ( array( 'book', 'movie', 'bad-type' ) as $post_type ) {
			unregister_post_type( $post_type );
		}
		foreach ( array( 'genre', 'bad-taxonomy' ) as $taxonomy ) {
			unregister_taxonomy( $taxonomy );
		}
		parent::tear_down();
	}

	public function test_a_discovered_post_type_is_registered_with_wordpress(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }"
		);

		$this->boot_post_types_with_roots();

		$this->assertTrue( post_type_exists( 'book' ) );
	}

	public function test_labels_are_derived_from_singular_and_plural_name(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }"
		);

		$this->boot_post_types_with_roots();

		$object = get_post_type_object( 'book' );
		$this->assertSame( 'Books', $object->labels->name );
		$this->assertSame( 'Book', $object->labels->singular_name );
		// WordPress core derives every other label from just those two.
		$this->assertSame( 'Add New Book', $object->labels->add_new_item );
		$this->assertSame( 'Search Books', $object->labels->search_items );
	}

	public function test_labels_override_replaces_a_specific_derived_label(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }\n"
				. "public function labels(): array { return array( 'search_items' => 'Find a Book' ); }"
		);

		$this->boot_post_types_with_roots();

		$object = get_post_type_object( 'book' );
		$this->assertSame( 'Find a Book', $object->labels->search_items );
		// Everything else is still derived normally.
		$this->assertSame( 'Add New Book', $object->labels->add_new_item );
	}

	public function test_supports_is_passed_through(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }\n"
				. "public function supports(): array { return array( 'title', 'thumbnail' ); }"
		);

		$this->boot_post_types_with_roots();

		$this->assertTrue( post_type_supports( 'book', 'title' ) );
		$this->assertTrue( post_type_supports( 'book', 'thumbnail' ) );
		$this->assertFalse( post_type_supports( 'book', 'editor' ) );
	}

	/**
	 * The visibility methods all default to null, and get_args() passes those
	 * nulls through unconditionally. WordPress must still derive each from
	 * `public`, exactly as if the keys had been left out.
	 */
	public function test_visibility_args_left_null_are_still_derived_from_is_public(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }\n"
				. 'public function is_public(): bool { return false; }'
		);

		$this->boot_post_types_with_roots();

		$object = get_post_type_object( 'book' );
		$this->assertFalse( $object->show_ui );
		$this->assertFalse( $object->publicly_queryable );
		$this->assertFalse( $object->show_in_nav_menus );
		$this->assertFalse( $object->show_in_admin_bar );
		// Derived from the inverse of public, not from public itself.
		$this->assertTrue( $object->exclude_from_search );
	}

	public function test_show_ui_can_be_turned_on_for_a_non_public_post_type(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }\n"
				. "public function is_public(): bool { return false; }\n"
				. 'public function is_shown_in_ui(): ?bool { return true; }'
		);

		$this->boot_post_types_with_roots();

		$object = get_post_type_object( 'book' );
		$this->assertFalse( $object->public );
		$this->assertTrue( $object->show_ui );
		// show_in_menu is left null, so it follows show_ui rather than public.
		$this->assertTrue( $object->show_in_menu );
		$this->assertFalse( $object->publicly_queryable );
	}

	public function test_show_in_menu_accepts_a_parent_menu_slug(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }\n"
				. "public function is_shown_in_menu(): bool|string|null { return 'tools.php'; }"
		);

		$this->boot_post_types_with_roots();

		$this->assertSame( 'tools.php', get_post_type_object( 'book' )->show_in_menu );
	}

	public function test_taxonomy_visibility_args_left_null_are_still_derived_from_is_public(): void {
		$this->write_taxonomy(
			'genre',
			"public function singular_name(): string { return 'Genre'; }\n"
				. "public function plural_name(): string { return 'Genres'; }\n"
				. "public function object_types(): array { return array( 'post' ); }\n"
				. 'public function is_public(): bool { return false; }'
		);

		$this->boot_post_types_with_roots();

		$object = get_taxonomy( 'genre' );
		$this->assertFalse( $object->show_ui );
		$this->assertFalse( $object->publicly_queryable );
		$this->assertFalse( $object->show_tagcloud );
		$this->assertFalse( $object->show_in_quick_edit );
	}

	public function test_taxonomy_show_ui_can_be_turned_on_for_a_non_public_taxonomy(): void {
		$this->write_taxonomy(
			'genre',
			"public function singular_name(): string { return 'Genre'; }\n"
				. "public function plural_name(): string { return 'Genres'; }\n"
				. "public function object_types(): array { return array( 'post' ); }\n"
				. "public function is_public(): bool { return false; }\n"
				. "public function is_shown_in_ui(): ?bool { return true; }\n"
				. 'public function is_shown_in_quick_edit(): ?bool { return false; }'
		);

		$this->boot_post_types_with_roots();

		$object = get_taxonomy( 'genre' );
		$this->assertFalse( $object->public );
		$this->assertTrue( $object->show_ui );
		// Derived from show_ui, which is now on despite public being off.
		$this->assertTrue( $object->show_tagcloud );
		// ...except where the taxonomy overrode it explicitly.
		$this->assertFalse( $object->show_in_quick_edit );
	}

	public function test_post_type_name_is_not_namespaced_to_the_plugin_slug(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }"
		);

		$this->boot_post_types_with_roots();

		// zestry-test is this TestCase's plugin slug; the registered name must be
		// the bare filename, never prefixed with it.
		$this->assertTrue( post_type_exists( 'book' ) );
		$this->assertFalse( post_type_exists( 'zestry-test_book' ) );
	}

	public function test_a_discovered_taxonomy_is_registered_and_attached_to_its_object_type(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }"
		);
		$this->write_taxonomy(
			'genre',
			"public function singular_name(): string { return 'Genre'; }\n"
				. "public function plural_name(): string { return 'Genres'; }\n"
				. "public function object_types(): array { return array( 'book' ); }"
		);

		$this->boot_post_types_with_roots();

		$this->assertTrue( taxonomy_exists( 'genre' ) );
		$this->assertTrue( is_object_in_taxonomy( 'book', 'genre' ) );
	}

	public function test_taxonomy_labels_are_derived_from_singular_and_plural_name(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }"
		);
		$this->write_taxonomy(
			'genre',
			"public function singular_name(): string { return 'Genre'; }\n"
				. "public function plural_name(): string { return 'Genres'; }\n"
				. "public function object_types(): array { return array( 'book' ); }"
		);

		$this->boot_post_types_with_roots();

		$object = get_taxonomy( 'genre' );
		$this->assertSame( 'Genres', $object->labels->name );
		$this->assertSame( 'Genre', $object->labels->singular_name );
	}

	public function test_taxonomy_can_attach_to_a_post_type_discovered_in_the_same_batch_regardless_of_file_order(): void {
		// Written before its post type on disk; registration order must still
		// register post types first so object_types() names something real.
		$this->write_taxonomy(
			'genre',
			"public function singular_name(): string { return 'Genre'; }\n"
				. "public function plural_name(): string { return 'Genres'; }\n"
				. "public function object_types(): array { return array( 'book' ); }"
		);
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }"
		);

		$this->boot_post_types_with_roots();

		$this->assertTrue( is_object_in_taxonomy( 'book', 'genre' ) );
	}

	public function test_taxonomy_can_attach_to_a_built_in_post_type(): void {
		$this->write_taxonomy(
			'genre',
			"public function singular_name(): string { return 'Genre'; }\n"
				. "public function plural_name(): string { return 'Genres'; }\n"
				. "public function object_types(): array { return array( 'post' ); }"
		);

		$this->boot_post_types_with_roots();

		$this->assertTrue( is_object_in_taxonomy( 'post', 'genre' ) );
	}

	public function test_get_post_type_of_resolves_the_registered_name(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }\n"
				. "public function rewrite(): array|false { return array( 'slug' => \$this->get_post_type() ); }"
		);

		$this->boot_post_types_with_roots();

		$object = get_post_type_object( 'book' );
		$this->assertSame( 'book', $object->rewrite['slug'] );
	}

	public function test_get_post_type_of_throws_for_an_instance_it_did_not_discover(): void {
		$post_types = $this->boot_post_types_with_roots();
		$instance   = $this->orphan_post_type();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'was not discovered' );

		$post_types->get_post_type_of( $instance );
	}

	/**
	 * A plugin registering post types but no taxonomies is ordinary, and this
	 * is the only module with a second root -- so the default `taxonomies/`
	 * being absent must not force an empty directory into every such plugin.
	 */
	public function test_an_absent_default_taxonomies_directory_is_not_an_error(): void {
		$this->write_plugin_file(
			'post-types/book.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\PostTypes\\PostType;\n"
				. "return new class extends PostType {\n"
				. "public function singular_name(): string { return 'Book'; }\n"
				. "public function plural_name(): string { return 'Books'; }\n};\n"
		);

		$this->plugin->configure(
			PostTypes::class,
			static function ( PostTypes $post_types ): void {
			}
		);

		$this->plugin->get( PostTypes::class );
		do_action( 'init' );

		$this->assertTrue(
			post_type_exists( 'book' ),
			'The post type registers even though taxonomies/ was never created.'
		);
	}

	/**
	 * Discovery is public, so an instance can be handed out and then handed back.
	 * That only works if the directory is walked once: `require` runs the file
	 * again every time, returning an equal object that is not the same one, and
	 * get_post_type_of() compares by identity.
	 *
	 * @return void
	 */
	public function test_discovery_hands_back_the_same_instances(): void {
		$this->write_post_type(
			'book',
			"public function singular_name(): string { return 'Book'; }\n"
				. 'public function plural_name(): string { return \'Books\'; }'
		);

		$module = $this->boot_post_types_with_roots();

		$first  = $module->get_discovered_post_types();
		$second = $module->get_discovered_post_types();

		$this->assertSame( $first['book'], $second['book'], 'Walked once, so the same object comes back.' );
		$this->assertSame( 'book', $module->get_post_type_of( $first['book'] ), 'And the reverse lookup recognises it.' );
	}

	public function test_a_post_type_file_returning_the_wrong_type_throws(): void {
		mkdir( $this->plugin_dir . '/taxonomies', 0777, true );
		$this->write_plugin_file( 'post-types/bad-type.php', "<?php\nreturn 42;\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		$this->boot_post_types_with_roots();
	}

	public function test_a_taxonomy_file_returning_the_wrong_type_throws(): void {
		mkdir( $this->plugin_dir . '/post-types', 0777, true );
		$this->write_plugin_file( 'taxonomies/bad-taxonomy.php', "<?php\nreturn 42;\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		$this->boot_post_types_with_roots();
	}

	/**
	 * Register PostTypes with an initializer pointing it at both roots (each
	 * defaulting to a directory that always exists, even if empty), then
	 * resolve it. Resolution wires the module, runs the initializer, and
	 * boots it -- the WP test suite has already fired `init` globally before
	 * any test method runs, so on_boot() takes its immediate-registration
	 * branch (see CronTest for the same nuance with the deferred branch).
	 *
	 * @param string $post_types_root Plugin-relative post types directory.
	 * @param string $taxonomies_root Plugin-relative taxonomies directory.
	 * @return PostTypes The resolved module.
	 */
	private function boot_post_types_with_roots( string $post_types_root = 'post-types', string $taxonomies_root = 'taxonomies' ): PostTypes {
		// A test that already wrote a file into one of these (write_post_type()/
		// write_taxonomy() create the parent directory themselves) has nothing
		// left to create here; mkdir() only needs to run for the other, empty one.
		if ( ! is_dir( $this->plugin_dir . '/' . $post_types_root ) ) {
			mkdir( $this->plugin_dir . '/' . $post_types_root, 0777, true );
		}
		if ( ! is_dir( $this->plugin_dir . '/' . $taxonomies_root ) ) {
			mkdir( $this->plugin_dir . '/' . $taxonomies_root, 0777, true );
		}

		$this->plugin->configure(
			PostTypes::class,
			static function ( PostTypes $post_types ) use ( $post_types_root, $taxonomies_root ): void {
			}
		);

		return $this->plugin->get( PostTypes::class );
	}

	private function write_post_type( string $name, string $body ): void {
		$this->write_plugin_file(
			'post-types/' . $name . '.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\PostTypes\\PostType;\nreturn new class extends PostType {\n{$body}\n};\n"
		);
	}

	private function write_taxonomy( string $name, string $body ): void {
		$this->write_plugin_file(
			'taxonomies/' . $name . '.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\PostTypes\\Taxonomy;\nreturn new class extends Taxonomy {\n{$body}\n};\n"
		);
	}

	/**
	 * Build a PostType instance never passed through PostTypes discovery, so
	 * get_post_type_of() has no record of it.
	 *
	 * @return PostType
	 */
	private function orphan_post_type(): PostType {
		return new class() extends PostType {
			public function singular_name(): string {
				return 'Orphan';
			}

			public function plural_name(): string {
				return 'Orphans';
			}
		};
	}
	/**
	 * WordPress caps a post type name at 20 characters and returns a WP_Error
	 * past it, rather than throwing -- so an unchecked call leaves the type
	 * simply absent: no menu, no queries, nothing said. The filename is the
	 * name, so this is a rename away and worth saying out loud.
	 */
	public function test_a_name_too_long_for_wordpress_throws_rather_than_registering_nothing(): void {
		$this->write_plugin_file(
			'post-types/customer-testimonials.php',
			"<?php\nreturn new class extends \\Zestry\\WPToolkit\\Modules\\PostTypes\\PostType {\n"
				. "public function singular_name(): string { return 'Testimonial'; }\n"
				. "public function plural_name(): string { return 'Testimonials'; }\n"
				. "};\n"
		);

		$this->setExpectedIncorrectUsage( 'register_post_type' );

		$this->expectException( \Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException::class );
		$this->expectExceptionMessage( 'refused to register the post type' );

		$this->plugin->get( PostTypes::class )->register_all();
	}

}
