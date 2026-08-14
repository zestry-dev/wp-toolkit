<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\Blocks\Blocks;
use Zestry\WPToolkit\Tests\Support\TestCase;
use WP_Block_Type_Registry;

/**
 * Discovery, registration, render-callback binding, static blocks, categories
 * and failure reporting of the Blocks module.
 *
 * @covers \Zestry\WPToolkit\Modules\Blocks\Blocks
 * @covers \Zestry\WPToolkit\Modules\Blocks\Block
 */
final class BlocksTest extends TestCase {

	public function tear_down(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( 'zestry-test/hero', 'zestry-test/notice', 'zestry-test/echoing' ) as $name ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}
		}

		parent::tear_down();
	}

	/**
	 * Register Blocks pointed at $root (running $configure, if given), then
	 * resolve it. Resolution wires, initializes and boots the module, so
	 * on_boot()'s deferred-to-init registration is queued by this call.
	 *
	 * @param string        $root      Plugin-relative blocks directory.
	 * @param callable|null $configure Optional extra configuration, e.g. add_categories().
	 * @return Blocks The resolved module.
	 */
	private function boot_blocks_with_root( string $root, ?callable $configure = null ): Blocks {
		$this->plugin->configure(
			Blocks::class,
			static function ( Blocks $blocks ) use ( $root, $configure ): void {
				if ( null !== $configure ) {
					$configure( $blocks );
				}
			}
		);

		$blocks = $this->plugin->get( Blocks::class );
		do_action( 'init' );

		return $blocks;
	}

	/**
	 * Write a built block directory: its block.json, plus a render.php when one
	 * is given.
	 *
	 * @param string      $dir      Directory name under the blocks root.
	 * @param string      $name     The block's namespaced name.
	 * @param string|null $render   Body of a render.php, or null for a static block.
	 * @param array       $extra    Extra block.json fields.
	 * @return void
	 */
	private function write_block( string $dir, string $name, ?string $render = null, array $extra = array() ): void {
		$metadata = array_merge(
			array(
				'apiVersion' => 3,
				'name'       => $name,
				'title'      => ucfirst( $dir ),
				'category'   => 'widgets',
			),
			$extra
		);

		if ( null !== $render ) {
			// Under `supports`, which is the one object the official block schema
			// declares `additionalProperties: true` on.
			$metadata['supports']['zestry-test-php'] = 'file:./block.php';
		}

		$this->write_plugin_file(
			'build/blocks/' . $dir . '/block.json',
			(string) wp_json_encode( $metadata )
		);

		if ( null !== $render ) {
			$this->write_plugin_file( 'build/blocks/' . $dir . '/block.php', $render );
		}
	}

	public function test_registers_a_static_block_from_its_metadata(): void {
		$this->write_block( 'notice', 'zestry-test/notice' );
		$this->boot_blocks_with_root( 'build/blocks' );

		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( 'zestry-test/notice' ),
			'A block directory with only a block.json should still be registered.'
		);
	}

	public function test_binds_the_render_of_a_block_whose_file_returns_an_instance(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return 'rendered:' . ( \$attributes['title'] ?? '' );\n"
				. "}\n};\n",
			array( 'attributes' => array( 'title' => array( 'type' => 'string' ) ) )
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'zestry-test/hero' );

		$this->assertNotNull( $block_type, 'The dynamic block should be registered.' );
		$this->assertTrue( $block_type->is_dynamic(), 'A bound render callback makes the block dynamic.' );
	}

	public function test_render_receives_the_attributes_it_was_given(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return 'rendered:' . ( \$attributes['title'] ?? 'none' );\n"
				. "}\n};\n",
			array( 'attributes' => array( 'title' => array( 'type' => 'string', 'default' => '' ) ) )
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$rendered = render_block(
			array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array( 'title' => 'Greetings' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		$this->assertSame( 'rendered:Greetings', $rendered );
	}

	/**
	 * A property a block sets during render() belongs to that occurrence alone.
	 *
	 * render() takes its data as arguments, so attributes cannot be corrupted
	 * whatever the instance model. What per-occurrence instances protect is a
	 * block's *own* state: set a property, render another block, read it back.
	 * A shared instance would return the inner occurrence's value.
	 */
	public function test_a_property_set_during_render_survives_rendering_another_block(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "private string \$mine = '';\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "\$this->mine = \$attributes['title'] ?? '?';\n"
				. "\$nested = \$this->mine === 'outer'\n"
				. "? render_block( array( 'blockName' => 'zestry-test/hero', 'attrs' => array( 'title' => 'inner' ),\n"
				. "'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) )\n"
				. ": '';\n"
				. "return \$nested . '|' . \$this->mine;\n"
				. "}\n};\n",
			array( 'attributes' => array( 'title' => array( 'type' => 'string', 'default' => '' ) ) )
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$rendered = render_block(
			array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array( 'title' => 'outer' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		// The inner occurrence renders "|inner"; the outer must still read back
		// its own "outer" rather than the inner one's value.
		$this->assertSame(
			'|inner|outer',
			$rendered,
			'A property set before rendering another block must survive that render.'
		);
	}

	/**
	 * By default WordPress renders the children and $content carries them, so
	 * a wrapper block just returns it.
	 */
	public function test_children_arrive_rendered_in_content_by_default(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return '[' . ( \$attributes['title'] ?? '?' ) . \$content . ']';\n"
				. "}\n};\n",
			array( 'attributes' => array( 'title' => array( 'type' => 'string', 'default' => '' ) ) )
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$inner = array(
			'blockName'    => 'zestry-test/hero',
			'attrs'        => array( 'title' => 'inner' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		$rendered = render_block(
			array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array( 'title' => 'outer' ),
				'innerBlocks'  => array( $inner ),
				'innerHTML'    => '',
				'innerContent' => array( null ),
			)
		);

		$this->assertSame( '[outer[inner]]', $rendered );
	}

	/**
	 * Rendering children must give the `render_block*` filters their turn --
	 * calling `$inner_block->render()` directly is only the last of four steps
	 * core takes, and skipping the rest breaks any plugin filtering blocks as
	 * they render.
	 */
	public function test_rendering_a_child_applies_the_filters_core_would(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "public static function skips_inner_blocks(): bool { return true; }\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return '[' . ( \$attributes['title'] ?? '?' ) . \$this->render_inner_blocks( \$block ) . ']';\n"
				. "}\n};\n",
			array( 'attributes' => array( 'title' => array( 'type' => 'string', 'default' => '' ) ) )
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$seen_children = array();

		add_filter(
			'render_block_data',
			static function ( $parsed_block, $source_block, $parent_block ) use ( &$seen_children ) {
				if ( null !== $parent_block ) {
					$seen_children[] = $parsed_block['attrs']['title'] ?? '?';
				}

				return $parsed_block;
			},
			10,
			3
		);

		render_block(
			array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array( 'title' => 'outer' ),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'zestry-test/hero',
						'attrs'        => array( 'title' => 'inner' ),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					),
				),
				'innerHTML'    => '',
				'innerContent' => array( null ),
			)
		);

		$this->assertSame(
			array( 'inner' ),
			$seen_children,
			'render_block_data must fire for the child, as a child of the block being rendered.'
		);
	}

	/**
	 * A block overriding skips_inner_blocks() renders its children itself, and
	 * render_inner_blocks() means the same thing either way -- so a block
	 * reading it does not have to know which mode it is in.
	 */
	public function test_a_skipping_block_renders_the_same_as_a_default_one(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "public static function skips_inner_blocks(): bool { return true; }\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return '[' . ( \$attributes['title'] ?? '?' ) . \$this->render_inner_blocks( \$block ) . ']';\n"
				. "}\n};\n",
			array( 'attributes' => array( 'title' => array( 'type' => 'string', 'default' => '' ) ) )
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$rendered = render_block(
			array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array( 'title' => 'outer' ),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'zestry-test/hero',
						'attrs'        => array( 'title' => 'inner' ),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					),
				),
				'innerHTML'    => '',
				'innerContent' => array( null ),
			)
		);

		$this->assertSame( '[outer[inner]]', $rendered );
	}

	/**
	 * A loop block renders its children once per item, with something different
	 * in context each pass -- so calling render_inner_blocks() repeatedly has to
	 * build the children afresh every time rather than reusing the first result.
	 */
	public function test_a_block_can_render_its_children_more_than_once(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "public static function skips_inner_blocks(): bool { return true; }\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "\$out = '';\n"
				. "foreach ( array( 'a', 'b' ) as \$item ) {\n"
				. "\$filter = static function ( \$context ) use ( \$item ) { \$context['zestryTestItem'] = \$item; return \$context; };\n"
				. "add_filter( 'render_block_context', \$filter, 1 );\n"
				. "\$out .= \$this->render_inner_blocks( \$block );\n"
				. "remove_filter( 'render_block_context', \$filter, 1 );\n"
				. "}\n"
				. "return '[' . \$out . ']';\n"
				. "}\n};\n",
			array( 'attributes' => array( 'title' => array( 'type' => 'string', 'default' => '' ) ) )
		);

		$this->write_block(
			'item',
			'zestry-test/item',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return '(' . ( \$block->context['zestryTestItem'] ?? '?' ) . ')';\n"
				. "}\n};\n",
			array( 'usesContext' => array( 'zestryTestItem' ) )
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$rendered = render_block(
			array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array( 'title' => 'outer' ),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'zestry-test/item',
						'attrs'        => array(),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					),
				),
				'innerHTML'    => '',
				'innerContent' => array( null ),
			)
		);

		$this->assertSame( '[(a)(b)]', $rendered );
	}

	/**
	 * A block's saved markup interleaves literal chunks with its children, and
	 * render_inner_blocks() has to put each child back where the editor placed
	 * it -- iterating inner_blocks alone would concatenate the children and drop
	 * everything saved around them.
	 */
	public function test_rendering_children_keeps_the_markup_saved_between_them(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "public static function skips_inner_blocks(): bool { return true; }\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return '[' . ( \$attributes['title'] ?? '?' ) . \$this->render_inner_blocks( \$block ) . ']';\n"
				. "}\n};\n",
			array( 'attributes' => array( 'title' => array( 'type' => 'string', 'default' => '' ) ) )
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$child = static function ( string $title ): array {
			return array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array( 'title' => $title ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		};

		$rendered = render_block(
			array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array( 'title' => 'outer' ),
				'innerBlocks'  => array( $child( 'one' ), $child( 'two' ) ),
				'innerHTML'    => '<before><between><after>',
				'innerContent' => array( '<before>', null, '<between>', null, '<after>' ),
			)
		);

		$this->assertSame( '[outer<before>[one]<between>[two]<after>]', $rendered );
	}

	/**
	 * A block that overrides skips_inner_blocks() renders its own children, so
	 * one that then ignores them renders without them.
	 */
	public function test_a_skipping_block_that_ignores_its_children_renders_without_them(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "public static function skips_inner_blocks(): bool { return true; }\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return 'only-me';\n"
				. "}\n};\n"
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$inner = array(
			'blockName'    => 'zestry-test/hero',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		$rendered = render_block(
			array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array(),
				'innerBlocks'  => array( $inner ),
				'innerHTML'    => '',
				'innerContent' => array( null ),
			)
		);

		$this->assertSame( 'only-me', $rendered );
	}

	public function test_repeated_occurrences_of_one_block_keep_their_own_attributes(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "return new class extends Block {\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return '<' . ( \$attributes['title'] ?? '?' ) . '>';\n"
				. "}\n};\n",
			array( 'attributes' => array( 'title' => array( 'type' => 'string', 'default' => '' ) ) )
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$render = static function ( string $title ): string {
			return render_block(
				array(
					'blockName'    => 'zestry-test/hero',
					'attrs'        => array( 'title' => $title ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
		};

		$this->assertSame( '<first>', $render( 'first' ) );
		$this->assertSame( '<second>', $render( 'second' ) );
	}

	public function test_a_renderer_can_reach_a_module(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\nuse Zestry\\WPToolkit\\Modules\\Globals;\n"
				. "return new class extends Block {\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return 'from-module:' . get_class( \$this->with( Globals::class ) );\n"
				. "}\n};\n"
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$rendered = render_block(
			array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		$this->assertStringContainsString(
			'Zestry\\WPToolkit\\Modules\\Globals',
			$rendered,
			'A typed module property must be injected before render() runs.'
		);
	}

	/**
	 * A render file that echoes rather than returning an instance is reported
	 * the same way every other module reports a wrong-typed discovery, rather
	 * than being silently tolerated.
	 */
	public function test_an_echoing_render_file_throws(): void {
		$this->write_block(
			'echoing',
			'zestry-test/echoing',
			"<?php\necho 'echoed-markup';\n"
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		render_block(
			array(
				'blockName'    => 'zestry-test/echoing',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * Registration must not load a block's PHP, even for a block that overrides
	 * skips_inner_blocks() -- that answer is supplied at `pre_render_block`
	 * instead, which is why it costs nothing for a page rendering no blocks.
	 */
	public function test_registration_does_not_load_a_blocks_php(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "\$GLOBALS['zestry-test-block-loads'] = ( \$GLOBALS['zestry-test-block-loads'] ?? 0 ) + 1;\n"
				. "return new class extends Block {\n"
				. "public static function skips_inner_blocks(): bool { return true; }\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return 'x';\n"
				. "}\n};\n"
		);

		$GLOBALS['zestry-test-block-loads'] = 0;

		$this->boot_blocks_with_root( 'build/blocks' );

		$this->assertSame(
			0,
			$GLOBALS['zestry-test-block-loads'],
			'No block PHP should run before a block is actually about to render.'
		);
	}

	/**
	 * The settings filter is global -- around 115 blocks reach it on a bare
	 * install -- so a block in somebody else's namespace must be left alone,
	 * even one declaring a `render` file of its own. Without that, this module
	 * would load a foreign render.php and reject it for not returning a Block.
	 */
	public function test_a_block_in_another_namespace_is_left_alone(): void {
		$this->write_block( 'notice', 'zestry-test/notice' );
		$this->boot_blocks_with_root( 'build/blocks' );

		$foreign = $this->plugin_dir . '/foreign';
		mkdir( $foreign, 0777, true );
		file_put_contents(
			$foreign . '/block.json',
			(string) wp_json_encode(
				array(
					'apiVersion' => 3,
					'name'       => 'other-plugin/widget',
					'title'      => 'Widget',
					'render'     => 'file:./render.php',
				)
			)
		);
		file_put_contents( $foreign . '/render.php', "<?php\necho 'THEIRS';\n" );

		$type = register_block_type( $foreign );

		$rendered = render_block(
			array(
				'blockName'    => 'other-plugin/widget',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		if ( $type instanceof \WP_Block_Type ) {
			WP_Block_Type_Registry::get_instance()->unregister( 'other-plugin/widget' );
		}

		$this->assertSame(
			'THEIRS',
			$rendered,
			"Another plugin's block must render through WordPress, untouched by this module."
		);
	}

	/**
	 * A block may declare both fields, and `render` wins: it is WordPress's own,
	 * so the block is WordPress's to render and this module leaves it alone --
	 * `block.php` is never loaded, even though the field naming it is present.
	 */
	public function test_a_block_declaring_render_is_left_to_wordpress(): void {
		$this->write_plugin_file(
			'build/blocks/notice/block.json',
			(string) wp_json_encode(
				array(
					'apiVersion'   => 3,
					'name'         => 'zestry-test/notice',
					'title'        => 'Notice',
					'category'     => 'widgets',
					'render'       => 'file:./render.php',
					'supports'     => array( 'zestry-test-php' => 'file:./block.php' ),
				)
			)
		);
		$this->write_plugin_file( 'build/blocks/notice/render.php', "<?php\necho 'WORDPRESS';\n" );
		$this->write_plugin_file(
			'build/blocks/notice/block.php',
			"<?php\nthrow new \\RuntimeException( 'block.php must not be loaded when render is declared.' );\n"
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$rendered = render_block(
			array(
				'blockName'    => 'zestry-test/notice',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		$this->assertSame(
			'WORDPRESS',
			$rendered,
			'A block declaring `render` must render through WordPress, untouched by this module.'
		);
	}

	/**
	 * The render file is loaded lazily, on the block's first render rather than
	 * at registration, so this is where a wrong-typed return surfaces.
	 */
	public function test_a_render_file_returning_the_wrong_type_throws(): void {
		$this->write_block( 'hero', 'zestry-test/hero', "<?php\nreturn new \\stdClass();\n" );

		$this->boot_blocks_with_root( 'build/blocks' );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		render_block(
			array(
				'blockName'    => 'zestry-test/hero',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	public function test_a_render_file_is_loaded_only_once_across_renders(): void {
		$this->write_block(
			'hero',
			'zestry-test/hero',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Blocks\\Block;\n"
				. "\$GLOBALS['zestry-test-block-loads'] = ( \$GLOBALS['zestry-test-block-loads'] ?? 0 ) + 1;\n"
				. "return new class extends Block {\n"
				. "public function render( array \$attributes, string \$content, \\WP_Block \$block ): string {\n"
				. "return 'x';\n"
				. "}\n};\n"
		);

		$GLOBALS['zestry-test-block-loads'] = 0;
		$this->boot_blocks_with_root( 'build/blocks' );

		$parsed = array(
			'blockName'    => 'zestry-test/hero',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		render_block( $parsed );
		render_block( $parsed );

		$this->assertSame(
			1,
			$GLOBALS['zestry-test-block-loads'],
			'The render file is required once; later occurrences instantiate its class instead.'
		);
	}

	public function test_declared_categories_reach_the_editor(): void {
		$this->write_block( 'notice', 'zestry-test/notice' );

		$this->boot_blocks_with_root(
			'build/blocks',
			static function ( Blocks $blocks ): void {
				$blocks->add_categories( array( 'reports' => 'Reports' ) );
			}
		);

		$categories = apply_filters( 'block_categories_all', array(), null );
		$slugs      = wp_list_pluck( $categories, 'slug' );

		$this->assertContains( 'reports', $slugs );
	}

	/**
	 * A title is a title, whatever else in PHP answers to that name.
	 *
	 * A title used to be resolved through `is_callable()`, which is true for any
	 * string naming a defined function and matches case-insensitively -- so
	 * `Time` reached the editor as a unix timestamp, and `Log` or `Date` raised
	 * an `ArgumentCountError` inside the filter. A title is a plain string now,
	 * and translating one is what
	 * {@see \Zestry\WPToolkit\Kernel\Abstracts\Module::on_wp_init()} is for.
	 */
	public function test_a_title_naming_a_function_is_not_called(): void {
		$this->assertTrue( \is_callable( 'time' ), 'The premise: PHP has a one-word function by this name.' );

		$this->write_block( 'notice', 'zestry-test/notice' );

		$this->boot_blocks_with_root(
			'build/blocks',
			static function ( Blocks $blocks ): void {
				$blocks->add_categories(
					array(
						'clock' => 'Time',
						'diary' => 'Date',
					)
				);
			}
		);

		$titles = wp_list_pluck( apply_filters( 'block_categories_all', array(), null ), 'title', 'slug' );

		$this->assertSame( 'Time', $titles['clock'] );
		$this->assertSame( 'Date', $titles['diary'] );
	}

	/**
	 * A category title is user-visible, so a plugin with a non-English audience
	 * translates it -- but the initializer this is called from runs at plugin
	 * load, where `__()` triggers `_load_textdomain_just_in_time`. Declaring the
	 * categories from `on_wp_init()` moves the whole call past `init`, which is
	 * why a title is a plain string and nothing about it is lazy.
	 */
	public function test_categories_declared_from_on_wp_init_reach_the_editor(): void {
		$this->write_block( 'notice', 'zestry-test/notice' );

		$declared = 0;

		$this->boot_blocks_with_root(
			'build/blocks',
			static function ( Blocks $blocks ) use ( &$declared ): void {
				$blocks->on_wp_init(
					static function ( Blocks $module ) use ( &$declared ): void {
						++$declared;

						$module->add_categories( array( 'reports' => 'Rapports' ) );
					}
				);
			}
		);

		$this->assertSame( 1, $declared, 'init has already fired in the suite, so it runs at once.' );

		$titles = wp_list_pluck( apply_filters( 'block_categories_all', array(), null ), 'title', 'slug' );

		$this->assertSame( 'Rapports', $titles['reports'] );
	}

	/**
	 * Keyed by slug, so a plain string is the title and an array carries an
	 * icon beside it -- the same shape bootstrap.php uses for modules.
	 */
	public function test_categories_can_be_declared_in_one_call(): void {
		$this->write_block( 'notice', 'zestry-test/notice' );

		$this->boot_blocks_with_root(
			'build/blocks',
			static function ( Blocks $blocks ): void {
				$blocks->add_categories(
					array(
						'reports' => 'Reports',
						'charts'  => array(
							'title' => 'Charts',
							'icon'  => 'chart-bar',
						),
					)
				);
			}
		);

		$categories = apply_filters( 'block_categories_all', array(), null );
		$by_slug    = array_column( $categories, null, 'slug' );

		$this->assertSame( 'Reports', $by_slug['reports']['title'] );
		$this->assertNull( $by_slug['reports']['icon'] );

		// The array form carries the icon alongside the title.
		$this->assertSame( 'Charts', $by_slug['charts']['title'] );
		$this->assertSame( 'chart-bar', $by_slug['charts']['icon'] );
	}

	public function test_a_category_array_without_a_title_is_refused(): void {
		$this->write_block( 'notice', 'zestry-test/notice' );

		$blocks = $this->boot_blocks_with_root( 'build/blocks' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Block category "reports" needs a title.' );

		$blocks->add_categories( array( 'reports' => array( 'icon' => 'chart-bar' ) ) );
	}

	public function test_discovered_blocks_are_keyed_by_directory_name(): void {
		$this->write_block( 'notice', 'zestry-test/notice' );
		$this->write_block( 'hero', 'zestry-test/hero' );

		$blocks = $this->boot_blocks_with_root( 'build/blocks' );

		$this->assertSame( array( 'hero', 'notice' ), array_keys( $blocks->get_discovered_blocks() ) );
	}

	/**
	 * `wp-scripts build --blocks-manifest` writes the manifest *beside* the
	 * blocks directory, not inside it, while core resolves each manifest key
	 * against the collection path as `{path}/{key}/block.json`. Getting either
	 * half wrong loses the fast path silently -- registration still works, just
	 * by walking the filesystem -- so this pins the layout the real build emits.
	 */
	public function test_registers_from_a_manifest_beside_the_blocks_directory(): void {
		$this->write_block( 'notice', 'zestry-test/notice' );

		$this->write_plugin_file(
			'build/blocks-manifest.php',
			"<?php\nreturn array(\n'notice' => array(\n'name' => 'zestry-test/notice',\n"
				. "'title' => 'Notice',\n'category' => 'widgets',\n'apiVersion' => 3,\n),\n);\n"
		);

		$this->boot_blocks_with_root( 'build/blocks' );

		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( 'zestry-test/notice' ),
			'A block listed in the manifest must be registered from it.'
		);
	}

	public function test_a_private_directory_is_not_discovered(): void {
		$this->write_block( '-wip', 'zestry-test/wip' );
		$this->write_block( 'notice', 'zestry-test/notice' );

		$blocks = $this->boot_blocks_with_root( 'build/blocks' );

		$this->assertSame(
			array( 'notice' ),
			array_keys( $blocks->get_discovered_blocks() ),
			'A hyphen-prefixed directory must be pruned, as it is in every other module.'
		);
	}
}
