<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Shared `wp zt make <type>` flow: name normalization, generic filesystem
 * collision guards, and the overwrite prompt.
 *
 * Exercised through a concrete anonymous MakeCommand subclass rather than one
 * of the real commands/make/*.php files, so these tests do not depend on
 * their get_extra_values() prompts. Path resolves stub files against this
 * package's own real src/DevTools/stubs/ (this repository doubles as the
 * zestry-dev/wp-toolkit package root), while ConsumerPlugin's target plugin root
 * is a throwaway directory under WP_PLUGIN_DIR, matching how
 * ConsumerPluginTest exercises the same real-CWD requirement.
 *
 * @covers \Zestry\WPToolkit\DevTools\Abstracts\MakeCommand
 */
final class MakeCommandTest extends TestCase {

	/**
	 * The namespace the `--extends` fixtures really live under.
	 */
	private const FIXTURE_NAMESPACE = 'Zestry\\WPToolkit\\Tests\\Support\\ExtendsFixture';

	private string $target_plugin_dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->target_plugin_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/zestry-make-test-' . uniqid();
		mkdir( $this->target_plugin_dir, 0777, true );

		file_put_contents(
			$this->target_plugin_dir . '/zestry.json',
			json_encode(
				array(
					'namespace'   => 'Acme\\Plugin',
					'root'        => 'lib',
					'text_domain' => 'acme-plugin',
				),
				JSON_PRETTY_PRINT
			)
		);

		/*
		 * Reaching `wp zt make` at all means WordPress loaded this plugin and
		 * its entry file ran. Some generators need the slug, which nothing on
		 * disk records -- it is the entry file's second constructor argument --
		 * so a fixture without a running plugin is a plugin that has none.
		 */
		$this->publish_running_plugin( 'acme-plugin' );
	}

	public function tear_down(): void {
		unset( $GLOBALS[ RuntimePlugin::REGISTRY ] );
		$this->remove_dir( $this->target_plugin_dir );
		parent::tear_down();
	}




	/**
	 * A destination that refuses a name outright gets the name it accepts, and the
	 * command says so -- an ability name is matched against `^[a-z0-9-]+$`, so the
	 * file as typed would throw at boot instead of registering.
	 *
	 * @return void
	 */
	public function test_a_constrained_type_writes_the_name_its_destination_accepts(): void {
		$this->run_make( array( 'create_order' ), array(), 'ability' );

		$this->assertFileExists( $this->target_plugin_dir . '/resources/abilities/create-order.php' );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/resources/abilities/create_order.php' );

		$warnings = $this->warnings();

		$this->assertStringContainsString( 'create-order', $warnings, 'Respelling the name is said out loud.' );
		$this->assertStringContainsString( 'create_order', $warnings );
		$this->assertStringContainsString( 'a-z0-9-', $warnings, 'And the reason is given.' );
	}

	/**
	 * A page slug lands in `?page=`, so a name a URL would have to encode is
	 * canonicalised rather than written and refused at discovery.
	 *
	 * @return void
	 */
	public function test_a_page_name_a_url_could_not_carry_is_canonicalised(): void {
		$this->run_make( array( 'Monthly Report' ), array(), 'page' );

		$this->assertFileExists( $this->target_plugin_dir . '/resources/admin-pages/monthly-report.php' );
		$this->assertNotNull( \WP_CLI::last( 'warning' ) );
	}

	/**
	 * The other side of the line. A `post_type`, a taxonomy and a `meta_key` are
	 * database columns that appear in REST responses, so all three are written
	 * exactly as given and nothing is said -- an underscore is the WordPress
	 * spelling for these, and hyphenating one would rename the column.
	 *
	 * All three are asserted because all three are the rule: the type that keeps
	 * its name is the type with no `normalize_name()`, which is a thing a later
	 * change adds by being helpful.
	 *
	 * @dataProvider data_shaped_names
	 *
	 * @param string $type      The `make` type.
	 * @param string $stub      Its key in run_make()'s stub map.
	 * @param string $name      The name as typed.
	 * @param string $directory Where the file lands.
	 * @return void
	 */
	public function test_a_data_shaped_name_is_written_exactly_as_given( string $type, string $stub, string $name, string $directory ): void {
		$this->run_make( array( $name ), array( 'singular' => 'Thing', 'plural' => 'Things' ), $stub );

		$this->assertFileExists(
			$this->target_plugin_dir . '/' . $directory . '/' . $name . '.php',
			\sprintf( '`make %s %s` kept the name it was given.', $type, $name )
		);
		// Narrowed to respelling. The fixture has no copied source, so the
		// missing-base warning fires here too and is a different subject.
		$this->assertStringNotContainsString(
			'Writing "',
			$this->warnings(),
			'Nothing was respelled, so nothing is warned about that.'
		);
	}

	/**
	 * The three `make` types whose name is a database column.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public function data_shaped_names(): array {
		return array(
			'a meta key'   => array( 'field', 'field', 'acme_rating', 'resources/fields/post/post' ),
			'a post type'  => array( 'post-type', 'post-type.php.stub', 'acme_book', 'resources/post-types' ),
			'a taxonomy'   => array( 'taxonomy', 'taxonomy.php.stub', 'acme_genre', 'resources/taxonomies' ),
		);
	}

	/**
	 * A class name is not a thing to hyphenate, and PSR-4 maps it to the file
	 * verbatim -- so `RequestLog` has to stay `RequestLog`.
	 */
	public function test_does_not_normalize_a_class_name(): void {
		$this->run_make( array( 'RequestLog' ), array(), 'module.php.stub' );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Modules/RequestLog.php' );
		$this->assertStringContainsString(
			'class RequestLog extends Module',
			(string) file_get_contents( $this->target_plugin_dir . '/lib/Modules/RequestLog.php' )
		);
	}


	/**
	 * A block is the plugin's own only if its namespace matches the one the
	 * module derives -- and the module derives it from the plugin **slug**.
	 *
	 * This took the text domain, which is a different thing that every example
	 * in the docs happens to make equal. Where they differ the block registers,
	 * the editor works, and the front end renders nothing: `Blocks::owns()` does
	 * not recognise it, and the field naming its PHP is looked for under a key
	 * nothing wrote.
	 */
	public function test_block_namespace_comes_from_the_slug_not_the_text_domain(): void {
		$this->write_text_domain( 'something-else' );

		$this->run_make( array( 'card' ), array( 'dynamic' => true, 'view' => 'none' ), 'block' );

		$json = (string) file_get_contents( $this->target_plugin_dir . '/src/blocks/card/block.json' );

		$this->assertStringContainsString( '"acme-plugin/card"', $json );
		$this->assertStringNotContainsString( 'something-else/card', $json );
		$this->assertStringContainsString( '"acme-plugin-php"', $json );
	}

	/**
	 * `zestry.json` records no slug, so a plugin that is not running falls back to
	 * its directory name -- which is what `Plugin` itself defaults to.
	 */
	public function test_block_namespace_falls_back_to_the_directory_name(): void {
		$this->write_text_domain( 'something-else' );
		unset( $GLOBALS[ RuntimePlugin::REGISTRY ] );

		$this->run_make( array( 'card' ), array( 'dynamic' => false, 'view' => 'none' ), 'block' );

		// Normalised the same way the module normalises a slug: WordPress
		// validates a block name against /^[a-z0-9-]+\/[a-z0-9-]+$/, and this
		// directory's name carries a dot.
		$expected = (string) preg_replace( '/[^a-z0-9-]/', '', str_replace( '_', '-', strtolower( basename( $this->target_plugin_dir ) ) ) );

		$this->assertStringContainsString(
			'"' . $expected . '/card"',
			(string) file_get_contents( $this->target_plugin_dir . '/src/blocks/card/block.json' )
		);
	}

	/**
	 * Stand in for a plugin WordPress has loaded, which is the only thing that
	 * knows the slug: zestry.json does not record one.
	 *
	 * @param string $slug The slug its entry file passed.
	 * @return void
	 */
	private function publish_running_plugin( string $slug ): void {
		$GLOBALS[ RuntimePlugin::REGISTRY ][ $this->target_plugin_dir ] = new Plugin(
			$this->target_plugin_dir . '/acme-plugin.php',
			$slug
		);
	}

	/**
	 * @param string $text_domain What zestry.json should record.
	 * @return void
	 */
	private function write_text_domain( string $text_domain ): void {
		file_put_contents(
			$this->target_plugin_dir . '/zestry.json',
			(string) json_encode(
				array(
					'namespace'   => 'Acme\\Plugin',
					'root'        => 'lib',
					'text_domain' => $text_domain,
				)
			)
		);
	}

	public function test_creates_a_file_from_the_stub(): void {
		$this->run_make( array( 'send-welcome-email' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/resources/actions/send-welcome-email.php' );
		$this->assertStringContainsString(
			'use Acme\\Plugin\\Core\\Modules\\Ajax\\AjaxAction;',
			(string) file_get_contents( $this->target_plugin_dir . '/resources/actions/send-welcome-email.php' )
		);
	}

	public function test_strips_a_trailing_php_extension_from_the_name(): void {
		$this->run_make( array( 'send-welcome-email.php' ) );

		$this->assertFileExists( $this->target_plugin_dir . '/resources/actions/send-welcome-email.php' );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/resources/actions/send-welcome-email.php.php' );
	}

	public function test_refuses_to_write_when_the_destination_is_already_a_directory(): void {
		mkdir( $this->target_plugin_dir . '/resources/actions/send-welcome-email.php', 0777, true );

		$this->run_make( array( 'send-welcome-email' ) );

		$this->assertNotNull( \WP_CLI::last( 'error' ) );
		$this->assertStringContainsString( 'directory already exists', $this->last_error() );
	}

	public function test_refuses_to_write_when_a_parent_path_segment_is_already_a_file(): void {
		// A real filesystem conflict shared by every type: wp_mkdir_p() cannot
		// create "resources/actions/leaf" as a directory when "resources/actions/leaf" already
		// exists as a plain file.
		mkdir( $this->target_plugin_dir . '/resources/actions', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/resources/actions/leaf', '<?php' );

		$this->run_make( array( 'leaf/send-welcome-email' ) );

		$this->assertNotNull( \WP_CLI::last( 'error' ) );
		$this->assertStringContainsString( 'already exists as a file', $this->last_error() );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/resources/actions/leaf/send-welcome-email.php' );
	}

	public function test_command_type_refuses_a_name_already_claimed_as_a_leaf_command(): void {
		// commands/test-1.php already registers a leaf command; "test-1/test-2"
		// would ask CLI to also treat "test-1" as a namespace, which WP-CLI's
		// own Subcommand::can_have_subcommands() refuses (see CliTest).
		mkdir( $this->target_plugin_dir . '/resources/commands', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/resources/commands/test-1.php', '<?php' );

		$this->run_make( array( 'test-1/test-2' ), array(), 'command.php.stub' );

		$this->assertNotNull( \WP_CLI::last( 'error' ) );
		$this->assertStringContainsString( 'already registered as a command', $this->last_error() );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/resources/commands/test-1/test-2.php' );
	}

	public function test_command_type_refuses_a_name_already_claimed_as_a_namespace(): void {
		// commands/test-1/ already holds command files under that namespace;
		// "test-1" alone would ask CLI to also register it as a leaf command.
		mkdir( $this->target_plugin_dir . '/resources/commands/test-1', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/resources/commands/test-1/test-2.php', '<?php' );

		$this->run_make( array( 'test-1' ), array(), 'command.php.stub' );

		$this->assertNotNull( \WP_CLI::last( 'error' ) );
		$this->assertStringContainsString( 'already exists as a command namespace', $this->last_error() );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/resources/commands/test-1.php' );
	}

	public function test_post_type_type_writes_a_post_type_file_with_singular_and_plural_names(): void {
		$this->run_make( array( 'book' ), array( 'singular' => 'Book', 'plural' => 'Books' ), 'post-type.php.stub' );

		$this->assertFileExists( $this->target_plugin_dir . '/resources/post-types/book.php' );

		$contents = (string) file_get_contents( $this->target_plugin_dir . '/resources/post-types/book.php' );
		$this->assertStringContainsString( "return 'Book';", $contents );
		$this->assertStringContainsString( "return 'Books';", $contents );
		$this->assertStringContainsString( 'use Acme\\Plugin\\Core\\Modules\\PostTypes\\PostType;', $contents );
	}

	public function test_post_type_type_defaults_singular_to_the_title_cased_name(): void {
		$this->run_make( array( 'book' ), array( 'plural' => 'Books' ), 'post-type.php.stub' );

		$contents = (string) file_get_contents( $this->target_plugin_dir . '/resources/post-types/book.php' );
		$this->assertStringContainsString( "return 'Book';", $contents );
	}

	public function test_taxonomy_type_writes_a_taxonomy_file_with_names_and_object_type(): void {
		$this->run_make(
			array( 'genre' ),
			array( 'singular' => 'Genre', 'plural' => 'Genres', 'object-type' => 'book' ),
			'taxonomy.php.stub'
		);

		$this->assertFileExists( $this->target_plugin_dir . '/resources/taxonomies/genre.php' );

		$contents = (string) file_get_contents( $this->target_plugin_dir . '/resources/taxonomies/genre.php' );
		$this->assertStringContainsString( "return 'Genre';", $contents );
		$this->assertStringContainsString( "return 'Genres';", $contents );
		$this->assertStringContainsString( "array( 'book' )", $contents );
		$this->assertStringContainsString( 'use Acme\\Plugin\\Core\\Modules\\PostTypes\\Taxonomy;', $contents );
	}

	public function test_module_type_writes_into_the_zestry_config_root_modules_directory(): void {
		$this->run_make( array( 'RequestLog' ), array(), 'module.php.stub' );

		$this->assertFileExists( $this->target_plugin_dir . '/lib/Modules/RequestLog.php' );

		$contents = (string) file_get_contents( $this->target_plugin_dir . '/lib/Modules/RequestLog.php' );
		$this->assertStringContainsString( 'namespace Acme\\Plugin\\Modules;', $contents );
		$this->assertStringContainsString( 'class RequestLog extends Module', $contents );
	}

	/**
	 * A plain module is discovered by nothing, so generating one has to declare
	 * it -- otherwise the file exists and is reachable by nothing.
	 */
	public function test_module_type_declares_itself_in_bootstrap(): void {
		file_put_contents( $this->target_plugin_dir . '/bootstrap.php', "<?php\n\nreturn array(\n);\n" );

		$this->run_make( array( 'RequestLog' ), array(), 'module.php.stub' );

		$bootstrap = (string) file_get_contents( $this->target_plugin_dir . '/bootstrap.php' );

		$this->assertStringContainsString(
			'RequestLog::class,',
			$bootstrap,
			'Declared, but not autoloaded: the generated class binds no hooks.'
		);
	}

	/**
	 * A stub can be edited into something PHP refuses to compile, and every
	 * assertion about the file's text still passes. The command compiles what
	 * it wrote, so whoever ran it hears about it rather than the next request.
	 *
	 * The stub is swapped for a broken one and put back, since the defect being
	 * guarded against is precisely a stub that stops parsing -- a fixture would
	 * test the checker against something the command never renders.
	 */
	public function test_a_generated_file_that_does_not_parse_is_reported(): void {
		$this->with_broken_module_stub(
			function (): void {
				$this->run_make( array( 'RequestLog' ), array(), 'module.php.stub' );

				// $calls is a flat list of [ method, ...args ] tuples.
				$warnings = implode(
					' ',
					array_map(
						static function ( array $call ): string {
							return (string) ( $call[1] ?? '' );
						},
						array_filter(
							\WP_CLI::$calls,
							static function ( array $call ): bool {
								return 'warning' === $call[0];
							}
						)
					)
				);

				$this->assertStringContainsString( 'does not parse', $warnings );
				$this->assertStringContainsString( 'Namespace declaration', $warnings );
			}
		);
	}

	/**
	 * Autoloading a file that will not compile takes the site down on the next
	 * request, so the entry records why it is off rather than just being off.
	 */
	public function test_an_unparseable_module_is_not_declared_at_all(): void {
		file_put_contents( $this->target_plugin_dir . '/bootstrap.php', "<?php\n\nreturn array(\n);\n" );

		$this->with_broken_module_stub(
			function (): void {
				$this->run_make( array( 'RequestLog' ), array(), 'module.php.stub' );

				$bootstrap = (string) file_get_contents( $this->target_plugin_dir . '/bootstrap.php' );

				// Not declared at all: a file that does not compile must not be
				// built on every request, and there is no longer a flag to
				// declare-but-not-build it with. The message says what to do.
				$this->assertStringNotContainsString( 'RequestLog', $bootstrap );
				$logged = array_map(
					static function ( array $call ): string {
						return (string) ( $call[1] ?? '' );
					},
					array_filter(
						\WP_CLI::$calls,
						static function ( array $call ): bool {
							return 'log' === $call[0];
						}
					)
				);

				$this->assertStringContainsString( 'does not parse yet', implode( "\n", $logged ) );
			}
		);
	}

	/**
	 * Run a callback with the module stub temporarily unparseable, restoring it
	 * however the callback ends.
	 *
	 * The breakage is the real one: an `ABSPATH` guard above the `namespace`,
	 * which PHP accepts as tokens and rejects as a program.
	 *
	 * @param callable(): void $callback What to run while the stub is broken.
	 * @return void
	 */
	private function with_broken_module_stub( callable $callback ): void {
		$stub     = dirname( __DIR__, 3 ) . '/src/DevTools/stubs/module.php.stub';
		$original = (string) file_get_contents( $stub );

		file_put_contents(
			$stub,
			str_replace(
				"namespace {{class_namespace}};\n\n// Loaded by WordPress, never requested directly.\n\\defined( 'ABSPATH' ) || exit;",
				"// Loaded by WordPress, never requested directly.\n\\defined( 'ABSPATH' ) || exit;\n\nnamespace {{class_namespace}};",
				$original
			)
		);

		try {
			$callback();
		} finally {
			file_put_contents( $stub, $original );
		}
	}

	/**
	 * The generated file has to be loadable, not merely written.
	 *
	 * Asserting on its text cannot catch a file PHP refuses to parse: the stub
	 * once put the ABSPATH guard above the namespace, which is a hard fatal on
	 * every generated module, and every assertion about its contents still
	 * passed.
	 */
	public function test_module_type_generates_parseable_php(): void {
		$this->run_make( array( 'RequestLog' ), array(), 'module.php.stub' );

		$file = $this->target_plugin_dir . '/lib/Modules/RequestLog.php';

		$this->assertFileExists( $file );

		$output = array();
		$status = 0;
		exec( 'php -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
	}

	/**
	 * PHP requires the namespace to be the first statement after `declare`, so
	 * the guard has to follow it rather than precede it.
	 */
	public function test_module_type_declares_its_namespace_before_the_guard(): void {
		$this->run_make( array( 'RequestLog' ), array(), 'module.php.stub' );

		$source = (string) file_get_contents( $this->target_plugin_dir . '/lib/Modules/RequestLog.php' );

		$this->assertLessThan(
			strpos( $source, "defined( 'ABSPATH' )" ),
			strpos( $source, 'namespace Acme\\Plugin\\Modules;' )
		);
	}

	/**
	 * The directory and the namespace come from the same name, so the two
	 * cannot disagree the way a separate destination flag let them.
	 */
	public function test_module_type_nests_by_qualifying_the_name(): void {
		file_put_contents( $this->target_plugin_dir . '/bootstrap.php', "<?php\n\nreturn array(\n);\n" );

		$this->run_make( array( 'Services/Mailer' ), array(), 'module.php.stub' );

		$file = $this->target_plugin_dir . '/lib/Modules/Services/Mailer.php';

		$this->assertFileExists( $file );

		$source = (string) file_get_contents( $file );

		$this->assertStringContainsString( 'namespace Acme\\Plugin\\Modules\\Services;', $source );
		// No --bootable, so it is a module that works when something calls it.
		$this->assertStringContainsString( 'class Mailer extends Module {', $source );
		$this->assertStringNotContainsString( 'on_boot', $source );

		$output = array();
		$status = 0;
		exec( 'php -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		// Declared under the namespace it actually declares, not a flattened one.
		$this->assertStringContainsString(
			'use Acme\\Plugin\\Modules\\Services\\Mailer;',
			(string) file_get_contents( $this->target_plugin_dir . '/bootstrap.php' )
		);
	}

	/**
	 * `@wordpress/scripts` decides entry points three mutually exclusive ways,
	 * so a plugin with a block has no supported way to build a script of its
	 * own. This is the convention the generated build config adds.
	 */
	public function test_entry_type_writes_a_script_and_its_stylesheet(): void {
		$this->run_make( array( 'settings' ), array(), 'entry' );

		$dir = $this->target_plugin_dir . '/src/entries/settings';

		$this->assertFileExists( $dir . '/index.ts' );
		$this->assertFileExists( $dir . '/style.scss' );
	}

	/**
	 * The stylesheet is built because the script imports it, and registered
	 * under the same handle -- so dropping the import loses it silently.
	 */
	public function test_entry_type_imports_its_own_stylesheet(): void {
		$this->run_make( array( 'settings' ), array(), 'entry' );

		$source = (string) file_get_contents( $this->target_plugin_dir . '/src/entries/settings/index.ts' );

		$this->assertStringContainsString( "import './style.scss';", $source );
		$this->assertStringContainsString( "enqueue_entry( 'settings' )", $source );
	}

	public function test_entry_type_declares_nothing_for_a_classic_script(): void {
		$this->run_make( array( 'settings' ), array(), 'entry' );

		// A `package.json` is how an entry says it is a module; a classic script
		// is the default, and a file saying so would be noise in every plugin.
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/src/entries/settings/package.json' );
	}

	public function test_entry_type_module_declares_its_kind(): void {
		$this->run_make( array( 'cart' ), array( 'kind' => 'module' ), 'entry' );

		$manifest = (array) json_decode(
			(string) file_get_contents( $this->target_plugin_dir . '/src/entries/cart/package.json' ),
			true
		);

		$this->assertSame( 'module', $manifest['wordpress']['kind'] );
	}

	/**
	 * A shared package is the other multi-file type: a stub directory rather
	 * than a single stub file, written into `src/` alongside the rest of the
	 * plugin's JavaScript.
	 */
	public function test_shared_type_writes_a_workspace_directory(): void {
		$this->run_make( array( 'formatting' ), array( 'kind' => 'script' ), 'shared' );

		$dir = $this->target_plugin_dir . '/src/shared/formatting';

		$this->assertFileExists( $dir . '/package.json' );
		$this->assertFileExists( $dir . '/index.ts' );
	}

	/**
	 * The scope is the text domain, not the directory the plugin happens to be
	 * installed in. Unlike a block namespace, which has to match what the module
	 * derives from the plugin slug, nothing compares this against anything: the
	 * build writes the handle into every importer's own `.asset.php`.
	 */
	public function test_shared_type_scopes_the_name_to_the_text_domain(): void {
		$this->run_make( array( 'formatting' ), array( 'kind' => 'script' ), 'shared' );

		$manifest = $this->read_shared_manifest( 'formatting' );

		$this->assertSame( '@acme-plugin/formatting', $manifest['name'] );
		$this->assertSame( 'index.ts', $manifest['main'] );
	}

	/**
	 * `kind` and nothing else. The handle a script package registers under and
	 * the global it publishes are composed by the generated `webpack.config.js`,
	 * which is also what writes that handle into every importer's `.asset.php` --
	 * so a copy here could only ever be a second opinion about a name that has
	 * already been decided.
	 */
	public function test_shared_type_declares_only_how_wordpress_loads_it(): void {
		$this->run_make( array( 'formatting' ), array( 'kind' => 'script' ), 'shared' );

		$manifest = $this->read_shared_manifest( 'formatting' );

		$this->assertSame( array( 'kind' => 'script' ), $manifest['wordpress'] );
	}

	public function test_shared_type_module_declares_only_its_kind(): void {
		$this->run_make( array( 'runtime' ), array( 'kind' => 'module' ), 'shared' );

		$manifest = $this->read_shared_manifest( 'runtime' );

		$this->assertSame( array( 'kind' => 'module' ), $manifest['wordpress'] );
	}

	/**
	 * `--yes` takes the documented default for an omitted `--kind`, rather than
	 * answering the prompt affirmatively.
	 *
	 * The prompt asks "load it as an ES module?", so answering yes would make an
	 * unattended run produce the kind nobody asked for -- and a module only
	 * imports other modules, so the package would be unusable from the classic
	 * scripts a plugin normally has.
	 */
	public function test_shared_type_yes_takes_the_documented_kind_default(): void {
		$this->run_make( array( 'formatting' ), array( 'yes' => true ), 'shared' );

		$manifest = $this->read_shared_manifest( 'formatting' );

		$this->assertSame( array( 'kind' => 'script' ), $manifest['wordpress'] );
	}

	/**
	 * The generated source is what the consumer edits first, so it has to import
	 * under the same name the manifest publishes.
	 */
	public function test_shared_type_source_imports_under_the_published_name(): void {
		$this->run_make( array( 'formatting' ), array( 'kind' => 'script' ), 'shared' );

		$source = (string) file_get_contents( $this->target_plugin_dir . '/src/shared/formatting/index.ts' );

		$this->assertStringContainsString( "import { greet } from '@acme-plugin/formatting';", $source );
		$this->assertStringContainsString( 'export function greet(): string', $source );
	}

	public function test_view_type_writes_a_template(): void {
		$this->run_make( array( 'receipt' ), array(), 'view' );

		$this->assertFileExists( $this->target_plugin_dir . '/resources/views/receipt.php' );
	}

	/**
	 * A view name is what the caller asks for, and those nest -- so a slash in
	 * the name is a directory, created on the way.
	 */
	public function test_view_type_nests_a_name_with_a_slash(): void {
		$this->run_make( array( 'emails/receipt' ), array(), 'view' );

		$this->assertFileExists( $this->target_plugin_dir . '/resources/views/emails/receipt.php' );
	}

	/**
	 * A field is filed under the table it lives in and the subtype it attaches
	 * to, so the fields root reads as an index of what is stored where.
	 */
	public function test_field_type_files_by_object_type_and_subtype(): void {
		$this->run_make( array( 'rating' ), array( 'subtypes' => 'book', 'yes' => true ), 'field' );

		$path = $this->target_plugin_dir . '/resources/fields/post/book/rating.php';

		$this->assertFileExists( $path );

		$field = (string) file_get_contents( $path );

		$this->assertStringContainsString( "return array( 'book' );", $field );
		$this->assertStringContainsString( 'return MetaType::Post;', $field );
	}

	/**
	 * No one folder is right for a field on several post types, so it is filed
	 * under the table alone rather than under an arbitrary one of them.
	 */
	public function test_field_type_on_several_subtypes_is_filed_under_the_table(): void {
		$this->run_make( array( 'rating' ), array( 'subtypes' => 'book,film', 'yes' => true ), 'field' );

		$path = $this->target_plugin_dir . '/resources/fields/post/rating.php';

		$this->assertFileExists( $path );
		$this->assertStringContainsString( "return array( 'book', 'film' );", (string) file_get_contents( $path ) );
	}

	/**
	 * A subtype on a table that has none registers meta nothing ever matches, so
	 * it is refused here rather than left for discovery to throw over later.
	 */
	public function test_field_type_refuses_a_subtype_the_table_does_not_have(): void {
		$this->run_make(
			array( 'tier' ),
			array( 'object-type' => 'user', 'subtypes' => 'administrator', 'yes' => true ),
			'field'
		);

		$this->assertStringContainsString( 'user meta has no subtypes', $this->last_error() );
	}

	/**
	 * User meta has no subtypes, so there is no second level to file under.
	 */
	public function test_field_type_for_user_meta_has_no_subtype_folder(): void {
		$this->run_make( array( 'tier' ), array( 'object-type' => 'user', 'yes' => true ), 'field' );

		$path = $this->target_plugin_dir . '/resources/fields/user/tier.php';

		$this->assertFileExists( $path );

		$field = (string) file_get_contents( $path );

		$this->assertStringContainsString( 'return array();', $field );
		$this->assertStringContainsString( 'return MetaType::User;', $field );
	}

	public function test_view_type_documents_the_scope_it_renders_in(): void {
		$this->run_make( array( 'receipt' ), array(), 'view' );

		$view = (string) file_get_contents( $this->target_plugin_dir . '/resources/views/receipt.php' );

		$this->assertStringContainsString( '@var \Acme\Plugin\Core\Modules\Views $this', $view );
	}

	/**
	 * An admin page is mostly a form, and markup assembled by concatenation
	 * stops being reviewable long before it stops growing -- so the template is
	 * generated alongside the class rather than left to be noticed later.
	 */
	public function test_page_type_writes_the_template_it_renders(): void {
		$this->run_make( array( 'settings' ), array(), 'page' );

		$this->assertFileExists( $this->target_plugin_dir . '/resources/admin-pages/settings.php' );
		$this->assertFileExists( $this->target_plugin_dir . '/resources/views/admin-pages/settings.php' );
	}

	public function test_page_type_renders_through_the_generated_template(): void {
		$this->run_make( array( 'settings' ), array(), 'page' );

		$page = (string) file_get_contents( $this->target_plugin_dir . '/resources/admin-pages/settings.php' );

		$this->assertStringContainsString( "\$this->view(\n\t\t\t'admin-pages/settings',", $page );
		$this->assertStringNotContainsString( "echo '<div class=\"wrap\">", $page );
	}

	/**
	 * A form needs the page's nonce and its own URL, and the template gets them
	 * as strings the `render()` call names -- not by being handed the page.
	 */
	public function test_page_type_template_can_render_a_form(): void {
		$this->run_make( array( 'settings' ), array(), 'page' );

		$view = (string) file_get_contents( $this->target_plugin_dir . '/resources/views/admin-pages/settings.php' );
		$page = (string) file_get_contents( $this->target_plugin_dir . '/resources/admin-pages/settings.php' );

		$this->assertStringContainsString( 'wp_nonce_field( $nonce )', $view );
		$this->assertStringContainsString( 'esc_url( $action )', $view );
		$this->assertStringNotContainsString( '$page', $view, 'The page itself is not in template scope.' );

		// Named at the call site, which is what makes the template's inputs
		// readable without opening it. Matched without the alignment padding,
		// which shifts whenever a longer key joins the array.
		$this->assertMatchesRegularExpression( "/'nonce'\s+=> \\\$this->get_nonce_action\(\)/", $page );
		$this->assertMatchesRegularExpression( "/'action'\s+=> \\\$this->get_page_url\(\)/", $page );
	}

	/**
	 * The commonest task in WordPress admin, given a shape.
	 *
	 * Without the redirect the browser's current request is still the POST, so
	 * a refresh resubmits and saves twice. Whatever the first person writes
	 * here becomes the plugin's pattern, so the generated default has to be the
	 * one worth copying.
	 */
	public function test_page_type_redirects_after_a_save(): void {
		$this->run_make( array( 'settings' ), array(), 'page' );

		$page = (string) file_get_contents( $this->target_plugin_dir . '/resources/admin-pages/settings.php' );

		$this->assertStringContainsString( 'wp_safe_redirect(', $page );
		$this->assertStringContainsString( 'exit;', $page );

		// The notice survives the redirect in the flash rather than in the URL,
		// which is what keeps it off a refresh and out of a bookmark.
		$this->assertStringContainsString( '$this->set_flash(', $page );
		$this->assertStringNotContainsString( "'updated' => '1'", $page );

		// A page is reached by two methods and declares no arguments, so the stub
		// must not teach an attribute that binds nothing -- code teaches louder
		// than the comment above it, and this is the shape a consumer copies.
		$this->assertStringNotContainsString( 'RequestArgument', $page );
		$this->assertStringContainsString(
			'\sanitize_text_field( \wp_unslash( $_POST',
			$page,
			'The page reads its own values, so the stub shows the unslash and the sanitise.'
		);
	}

	/**
	 * The other half of it. A redirect with nothing to show for it reads as the
	 * save having been lost.
	 */
	public function test_page_type_template_shows_the_saved_notice(): void {
		$this->run_make( array( 'settings' ), array(), 'page' );

		$view = (string) file_get_contents( $this->target_plugin_dir . '/resources/views/admin-pages/settings.php' );
		$page = (string) file_get_contents( $this->target_plugin_dir . '/resources/admin-pages/settings.php' );

		$this->assertMatchesRegularExpression( "/'notice'\s+=> \\\$this->get_flash\( '' \)/", $page );
		$this->assertStringContainsString( "if ( '' !== \$notice )", $view );
		$this->assertStringContainsString( 'notice-success', $view );
		$this->assertStringContainsString( '@var string $notice', $view );

		// Reading a query argument to decide what to show is what the flash
		// replaces, so neither file should be left doing it.
		$this->assertStringNotContainsString( '$_GET', $page );
	}

	/**
	 * A template is included rather than called, so nothing tells an editor what
	 * is in scope unless the template says so.
	 */
	public function test_page_type_template_documents_its_scope(): void {
		$this->run_make( array( 'settings' ), array(), 'page' );

		$view = (string) file_get_contents( $this->target_plugin_dir . '/resources/views/admin-pages/settings.php' );

		$this->assertStringContainsString( '@var \Acme\Plugin\Core\Modules\Views $this', $view );
		$this->assertStringContainsString( '@var string $title', $view );
	}

	public function test_page_type_no_view_writes_only_the_class(): void {
		$this->run_make( array( 'ping' ), array( 'view' => false ), 'page' );

		$this->assertFileExists( $this->target_plugin_dir . '/resources/admin-pages/ping.php' );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/resources/views/admin-pages/ping.php' );
	}

	/**
	 * `--no-view` has to reach `render()`, not only the template writer.
	 *
	 * Skipping the template while still generating a `render()` that calls one
	 * writes a page that throws the first time anyone opens it -- the template
	 * it renders is the one that was deliberately not written.
	 */
	public function test_page_type_no_view_renders_without_a_template(): void {
		$this->run_make( array( 'ping' ), array( 'view' => false ), 'page' );

		$page = (string) file_get_contents( $this->target_plugin_dir . '/resources/admin-pages/ping.php' );

		// The indent is what distinguishes the call from the commented mention
		// of it, which the generated file carries deliberately: it is how you
		// move to a template later.
		$this->assertStringNotContainsString( "\n\t\t\$this->view(", $page );
		$this->assertStringContainsString( 'esc_html( $this->title() )', $page );
	}

	/**
	 * A page and its template are both translated under the plugin's own text
	 * domain -- the notice is set by the page now, and shown by the template.
	 */
	public function test_page_type_template_uses_the_projects_text_domain(): void {
		$this->run_make( array( 'settings' ), array(), 'page' );

		$view = (string) file_get_contents( $this->target_plugin_dir . '/resources/views/admin-pages/settings.php' );
		$page = (string) file_get_contents( $this->target_plugin_dir . '/resources/admin-pages/settings.php' );

		$this->assertStringContainsString( "esc_html_e( 'Example', 'acme-plugin' )", $view );
		$this->assertStringContainsString( "__( 'Saved.', 'acme-plugin' )", $page );
	}

	/**
	 * With no text domain recorded, the plugin's directory name stands in.
	 *
	 * Never the page's own name, which was the old fallback: a page called
	 * `settings` got a template translated under a `settings` domain, which
	 * nothing loads, so every string in it stayed untranslated.
	 */
	public function test_page_type_template_falls_back_to_the_plugin_directory(): void {
		file_put_contents(
			$this->target_plugin_dir . '/zestry.json',
			(string) json_encode(
				array(
					'namespace' => 'Acme\\Plugin',
					'root'      => 'lib',
				)
			)
		);

		$this->run_make( array( 'settings' ), array(), 'page' );

		$view = (string) file_get_contents( $this->target_plugin_dir . '/resources/views/admin-pages/settings.php' );

		$this->assertStringContainsString( basename( $this->target_plugin_dir ), $view );
		$this->assertStringNotContainsString( "'settings' )", $view );
	}

	/**
	 * The default still renders the template written beside it.
	 */
	public function test_page_type_renders_the_template_it_writes(): void {
		$this->run_make( array( 'settings' ), array(), 'page' );

		$page = (string) file_get_contents( $this->target_plugin_dir . '/resources/admin-pages/settings.php' );

		$this->assertStringContainsString( "\n\t\t\$this->view(", $page );
		$this->assertStringContainsString( "'admin-pages/settings'", $page );
	}

	public function test_page_type_honours_a_custom_views_root(): void {
		$this->run_make( array( 'settings' ), array( 'views-dir' => 'templates' ), 'page' );

		$this->assertFileExists( $this->target_plugin_dir . '/templates/admin-pages/settings.php' );
	}

	/**
	 * Regenerating a page must not discard markup someone has written.
	 */
	public function test_page_type_leaves_an_existing_template_alone(): void {
		mkdir( $this->target_plugin_dir . '/resources/views/admin-pages', 0777, true );
		file_put_contents( $this->target_plugin_dir . '/resources/views/admin-pages/settings.php', '<?php // mine' );

		$this->run_make( array( 'settings' ), array( 'yes' => true ), 'page' );

		$this->assertSame(
			'<?php // mine',
			(string) file_get_contents( $this->target_plugin_dir . '/resources/views/admin-pages/settings.php' )
		);
	}

	/**
	 * The stub for `block` is a directory rather than a single file, so this
	 * covers the whole multi-file path: what a static block writes, what each
	 * flag adds, and that no block.json field ever names a file that is absent.
	 */
	public function test_block_type_writes_a_static_block_directory(): void {
		$this->run_make( array( 'hero' ), array( 'dynamic' => false, 'view' => 'none' ), 'block' );

		$dir = $this->target_plugin_dir . '/src/blocks/hero';

		$this->assertFileExists( $dir . '/block.json' );
		$this->assertFileExists( $dir . '/index.tsx' );
		$this->assertFileExists( $dir . '/edit.tsx' );
		$this->assertFileExists( $dir . '/style.css' );
		$this->assertFileExists( $dir . '/editor.css' );

		$this->assertFileDoesNotExist( $dir . '/block.php', 'A static block has no PHP.' );
		$this->assertFileDoesNotExist( $dir . '/view.ts', 'No front-end script was asked for.' );
	}

	/**
	 * A static block has no PHP, so its `save` is the front end: what it returns
	 * is written into the post content and served as-is.
	 */
	public function test_block_type_static_saves_its_own_markup(): void {
		$this->run_make( array( 'hero' ), array( 'dynamic' => false, 'view' => 'none' ), 'block' );

		$index = (string) file_get_contents( $this->target_plugin_dir . '/src/blocks/hero/index.tsx' );

		$this->assertStringContainsString( 'save:', $index, 'A block registered without a save loses its markup.' );
		$this->assertStringContainsString( 'useBlockProps.save()', $index );
		$this->assertStringContainsString( "import { useBlockProps } from '@wordpress/block-editor';", $index );
		$this->assertStringNotContainsString( 'InnerBlocks', $index, 'A static block does not save inner blocks.' );
	}

	/**
	 * A dynamic block's markup comes from PHP, so its `save` persists only the
	 * inner blocks -- which is what reaches render() as $content.
	 */
	public function test_block_type_dynamic_saves_only_its_inner_blocks(): void {
		$this->run_make( array( 'hero' ), array( 'dynamic' => true, 'view' => 'none' ), 'block' );

		$index = (string) file_get_contents( $this->target_plugin_dir . '/src/blocks/hero/index.tsx' );

		$this->assertStringContainsString( 'save: () => <InnerBlocks.Content />', $index );
		$this->assertStringContainsString( "import { InnerBlocks } from '@wordpress/block-editor';", $index );
	}

	public function test_block_type_names_the_block_after_the_text_domain(): void {
		$this->run_make( array( 'hero' ), array( 'dynamic' => false, 'view' => 'none' ), 'block' );

		$metadata = json_decode(
			(string) file_get_contents( $this->target_plugin_dir . '/src/blocks/hero/block.json' ),
			true
		);

		$this->assertSame( 'acme-plugin/hero', $metadata['name'] );
		$this->assertSame( 'Hero', $metadata['title'] );
		$this->assertSame( 'file:./index.tsx', $metadata['editorScript'] );
	}

	/**
	 * WordPress validates a block name against /^[a-z0-9-]+\/[a-z0-9-]+$/, which
	 * a text domain need not satisfy -- and the Blocks module decides a block is
	 * the plugin's own by that namespace, so the two have to agree.
	 */
	public function test_block_type_normalizes_a_text_domain_into_a_valid_namespace(): void {
		file_put_contents(
			$this->target_plugin_dir . '/zestry.json',
			(string) json_encode(
				array(
					'namespace'   => 'Acme\\Plugin',
					'root'        => 'lib',
					'text_domain' => 'Acme_Plugin',
				)
			)
		);

		$this->run_make( array( 'hero' ), array( 'dynamic' => false, 'view' => 'none' ), 'block' );

		$metadata = json_decode(
			(string) file_get_contents( $this->target_plugin_dir . '/src/blocks/hero/block.json' ),
			true
		);

		$this->assertSame( 'acme-plugin/hero', $metadata['name'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $metadata['name'] );
	}

	public function test_block_type_dynamic_flag_adds_the_render_file_and_its_field(): void {
		$this->run_make( array( 'hero' ), array( 'dynamic' => true, 'view' => 'none' ), 'block' );

		$dir = $this->target_plugin_dir . '/src/blocks/hero';

		$this->assertFileExists( $dir . '/block.php' );

		$metadata = json_decode( (string) file_get_contents( $dir . '/block.json' ), true );
		$this->assertSame( 'file:./block.php', $metadata['supports']['acme-plugin-php'] );
		$this->assertArrayNotHasKey(
			'acme-plugin-php',
			$metadata,
			'Not at the root: the official schema sets additionalProperties:false there.'
		);

		$contents = (string) file_get_contents( $dir . '/block.php' );
		$this->assertStringContainsString( 'use Acme\\Plugin\\Core\\Modules\\Blocks\\Block;', $contents );
	}

	/**
	 * The two view modes are distinguishable by one grep, and getting them
	 * crossed is silent at every point a developer checks: the command reports
	 * success, the build compiles, the block registers and renders, and the
	 * front-end script is simply never on the page. `wp-interactivity` is a
	 * script module id, so a classic script naming it as a dependency is one
	 * WordPress refuses to enqueue at all.
	 */
	public function test_block_type_view_script_imports_no_script_modules(): void {
		$this->run_make( array( 'toggle' ), array( 'dynamic' => false, 'view' => 'script' ), 'block' );

		$view = (string) file_get_contents( $this->target_plugin_dir . '/src/blocks/toggle/view.ts' );

		// The import, not the name: the stub explains in prose why it does not
		// import this, and what breaks a build is the statement.
		$this->assertStringNotContainsString( "from '@wordpress/interactivity'", $view );
		$this->assertStringNotContainsString( 'getContext', $view );
		$this->assertStringNotContainsString( 'store(', $view );
		$this->assertStringContainsString( '.wp-block-acme-plugin-toggle', $view );
	}

	public function test_block_type_view_module_adds_an_interactivity_script(): void {
		$this->run_make( array( 'toggle' ), array( 'dynamic' => false, 'view' => 'module' ), 'block' );

		$dir = $this->target_plugin_dir . '/src/blocks/toggle';

		$this->assertFileExists( $dir . '/view.ts' );

		$metadata = json_decode( (string) file_get_contents( $dir . '/block.json' ), true );
		$this->assertSame( 'file:./view.ts', $metadata['viewScriptModule'] );
		$this->assertTrue( $metadata['supports']['interactivity'] );
		$this->assertArrayNotHasKey( 'viewScript', $metadata );

		$this->assertStringContainsString(
			"store( 'acme-plugin/toggle'",
			(string) file_get_contents( $dir . '/view.ts' ),
			'The store namespace must match the block name.'
		);
	}

	public function test_block_type_view_script_uses_the_classic_script_field(): void {
		$this->run_make( array( 'toggle' ), array( 'dynamic' => false, 'view' => 'script' ), 'block' );

		$metadata = json_decode(
			(string) file_get_contents( $this->target_plugin_dir . '/src/blocks/toggle/block.json' ),
			true
		);

		$this->assertSame( 'file:./view.ts', $metadata['viewScript'] );
		$this->assertArrayNotHasKey( 'viewScriptModule', $metadata );

		// A classic script gets no `supports.interactivity`: that capability is
		// what makes WordPress load the module runtime, which this cannot use.
		$this->assertArrayNotHasKey( 'interactivity', $metadata['supports'] );
	}

	public function test_block_type_js_flag_generates_javascript(): void {
		$this->run_make( array( 'hero' ), array( 'dynamic' => false, 'view' => 'module', 'js' => true ), 'block' );

		$dir = $this->target_plugin_dir . '/src/blocks/hero';

		$this->assertFileExists( $dir . '/index.js' );
		$this->assertFileExists( $dir . '/edit.js' );
		$this->assertFileExists( $dir . '/view.js' );
		$this->assertFileDoesNotExist( $dir . '/index.tsx' );

		$metadata = json_decode( (string) file_get_contents( $dir . '/block.json' ), true );
		$this->assertSame( 'file:./index.js', $metadata['editorScript'] );
		$this->assertSame( 'file:./view.js', $metadata['viewScriptModule'] );
	}

	/**
	 * Every file block.json points at must exist, whichever flags were given:
	 * WordPress registers a handle for a missing one without any warning, so a
	 * mismatch here would be invisible until someone looked for the asset.
	 */
	public function test_block_type_never_declares_a_file_it_did_not_write(): void {
		foreach ( array( 'none', 'script', 'module' ) as $view ) {
			foreach ( array( true, false ) as $dynamic ) {
				$name = 'b-' . $view . '-' . ( $dynamic ? 'dyn' : 'static' );

				$this->run_make( array( $name ), array( 'dynamic' => $dynamic, 'view' => $view ), 'block' );

				$dir      = $this->target_plugin_dir . '/src/blocks/' . $name;
				$metadata = json_decode( (string) file_get_contents( $dir . '/block.json' ), true );

				foreach ( array( 'editorScript', 'viewScript', 'viewScriptModule', 'acme-plugin-php' ) as $field ) {
					if ( ! isset( $metadata[ $field ] ) ) {
						continue;
					}

					$this->assertFileExists(
						$dir . '/' . str_replace( 'file:./', '', $metadata[ $field ] ),
						sprintf( '%s names %s, which must exist.', $name, $field )
					);
				}
			}
		}
	}

	/**
	 * The point of the flag: a generated file that knows what *your* abstract
	 * still owes, which no stub shipped here could contain.
	 *
	 * @return void
	 */
	public function test_extends_stubs_the_methods_the_named_abstract_leaves_abstract(): void {
		$this->use_fixture_namespace();

		$this->run_make( array( 'acme-rating' ), array( 'extends' => 'EntityField' ), 'field' );

		$written = (string) file_get_contents( $this->target_plugin_dir . '/resources/fields/post/acme-rating.php' );

		$this->assertStringContainsString( 'use ' . self::FIXTURE_NAMESPACE . '\\Abstracts\\EntityField;', $written );
		$this->assertStringContainsString( 'extends EntityField', $written );

		// Everything EntityField still leaves abstract, and its signature intact.
		$this->assertStringContainsString( 'public function attaches_to(): array {', $written );
		$this->assertStringContainsString( 'public function label(): string {', $written );
		$this->assertStringContainsString( 'public function format( mixed $value, bool $escape = true ): ?string {', $written );
		$this->assertStringContainsString( 'protected function get_store(): \ArrayObject {', $written );

		// ...and nothing it has already answered.
		$this->assertStringNotContainsString(
			'function subtypes(',
			$written,
			'EntityField implements subtypes(), so the generated file must not override it.'
		);
	}

	/**
	 * A generated file that does not compile is worse than no file, and the
	 * bodies are chosen by return type, so this is where a wrong choice shows.
	 *
	 * @return void
	 */
	public function test_the_generated_file_compiles_and_its_bodies_match_the_return_types(): void {
		$this->use_fixture_namespace();

		$this->run_make( array( 'acme-rating' ), array( 'extends' => 'EntityField' ), 'field' );

		$written = (string) file_get_contents( $this->target_plugin_dir . '/resources/fields/post/acme-rating.php' );

		// Throws a ParseError if what was written is not PHP.
		token_get_all( $written, TOKEN_PARSE );

		$this->assertStringContainsString( 'return array();', $written, 'An array return gets an empty array.' );
		$this->assertStringContainsString( "return '';", $written, 'A string return gets an empty string.' );
		$this->assertStringContainsString( 'return null;', $written, 'A nullable return gets null.' );
		$this->assertStringContainsString(
			'throw new \RuntimeException(',
			$written,
			'An object return has no obviously-unfinished value, so it stops instead of guessing one.'
		);
	}

	/**
	 * The whole docblock travels with the method, so the generated file says
	 * what each one owes without opening the parent -- including the tags, which
	 * describe a signature this file carries verbatim and are therefore as true
	 * here as they were there.
	 *
	 * @return void
	 */
	public function test_each_stubbed_method_carries_its_whole_docblock(): void {
		$this->use_fixture_namespace();

		$this->run_make( array( 'acme-rating' ), array( 'extends' => 'EntityField' ), 'field' );

		$written = (string) file_get_contents( $this->target_plugin_dir . '/resources/fields/post/acme-rating.php' );

		$this->assertStringContainsString(
			"\t/**\n\t * Render the stored value for display.\n"
				. "\t *\n"
				. "\t * @param mixed \$value  The stored value.\n"
				. "\t * @param bool  \$escape Whether to escape it.\n"
				. "\t * @return string|null\n"
				. "\t */\n"
				. "\tpublic function format( mixed \$value, bool \$escape = true ): ?string {",
			$written,
			'Copied entire, and re-indented for where it landed rather than where it came from.'
		);
	}

	/**
	 * @return void
	 */
	public function test_extends_accepts_a_fully_qualified_name(): void {
		$this->use_fixture_namespace();

		$this->run_make(
			array( 'acme-rating' ),
			array( 'extends' => self::FIXTURE_NAMESPACE . '\\Abstracts\\EntityField' ),
			'field'
		);

		$this->assertFileExists( $this->target_plugin_dir . '/resources/fields/post/acme-rating.php' );
	}

	/**
	 * A `DiscoveryException` waiting to happen, refused while it is still cheap:
	 * the wrong base is only found at boot, and then on every request.
	 *
	 * @return void
	 */
	public function test_extends_refuses_a_class_that_is_not_the_types_own_base(): void {
		$this->use_fixture_namespace();

		$this->run_make( array( 'acme-rating' ), array( 'extends' => 'EntityPostType' ), 'field' );

		$this->assertStringContainsString( 'does not extend', $this->last_error() );
		$this->assertFileDoesNotExist(
			$this->target_plugin_dir . '/resources/fields/post/acme-rating.php',
			'Refused before anything was written.'
		);
	}

	/**
	 * @return void
	 */
	public function test_extends_refuses_a_final_class(): void {
		$this->use_fixture_namespace();

		$this->run_make( array( 'acme-rating' ), array( 'extends' => 'SealedField' ), 'field' );

		$this->assertStringContainsString( 'is final', $this->last_error() );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/resources/fields/post/acme-rating.php' );
	}

	/**
	 * The message lists what was looked for, since the commonest cause is a
	 * class that exists but has not been dumped into the autoloader yet.
	 *
	 * @return void
	 */
	public function test_extends_refuses_a_class_that_does_not_load(): void {
		$this->use_fixture_namespace();

		$this->run_make( array( 'acme-rating' ), array( 'extends' => 'NoSuchField' ), 'field' );

		$error = $this->last_error();

		$this->assertStringContainsString( 'could be loaded', $error );
		$this->assertStringContainsString( 'Abstracts\\NoSuchField', $error, 'It says where it looked.' );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/resources/fields/post/acme-rating.php' );
	}

	/**
	 * The file a generated one imports has to be here, and nothing checked.
	 *
	 * `make page` before `add admin-pages` wrote a file importing three
	 * classes that do not exist and said nothing: PHP imports are lazy, so it
	 * parses, lints clean, lands in a directory no module is walking, and does
	 * nothing at all until someone happens to add the module.
	 *
	 * @return void
	 */
	public function test_a_missing_base_module_is_offered_and_warned_about(): void {
		$this->run_make( array( 'reports' ), array(), 'page' );

		$warnings = $this->warnings();

		$this->assertStringContainsString( 'Acme\\Plugin\\Core\\Modules\\AdminPages\\AdminPage', $warnings );
		$this->assertStringContainsString( 'wp zt add admin-pages', $warnings, 'And what to run.' );
		$this->assertFileExists(
			$this->target_plugin_dir . '/resources/admin-pages/reports.php',
			'Declining writes the file anyway -- the warning is the point, not a refusal.'
		);
	}

	/**
	 * `--yes` is an answer, not a default, so an unattended run installs what the
	 * file needs rather than writing something inert.
	 *
	 * @return void
	 */
	public function test_yes_installs_the_missing_base_module(): void {
		$this->run_make( array( 'reports' ), array( 'yes' => true ), 'page' );

		$this->assertFileExists(
			$this->target_plugin_dir . '/lib/Core/Modules/AdminPages/AdminPage.php',
			'The module was copied in, by the real `add` command.'
		);
		$this->assertFileExists(
			$this->target_plugin_dir . '/lib/Core/Modules/Views.php',
			'Along with the dependencies the registry resolves for it.'
		);
		$this->assertFileExists( $this->target_plugin_dir . '/resources/admin-pages/reports.php' );
	}

	/**
	 * A plugin that never added the module has no base class for the generated
	 * file to extend, and saying "does not extend" would be true and useless --
	 * the class it does not extend is one this plugin has never had.
	 *
	 * Left at the default `Acme\Plugin` namespace, where no copied source
	 * exists, which is exactly the state a plugin is in before `add`.
	 *
	 * @return void
	 */
	public function test_extends_says_so_when_the_plugin_has_no_base_class_at_all(): void {
		$this->run_make(
			array( 'acme-rating' ),
			array( 'extends' => self::FIXTURE_NAMESPACE . '\\Abstracts\\EntityField' ),
			'field'
		);

		$error = $this->last_error();

		$this->assertStringContainsString( 'This plugin has no', $error );
		$this->assertStringContainsString( 'Acme\\Plugin\\Core\\Modules\\Fields\\Field', $error );
		$this->assertStringContainsString( 'wp zt add <name>', $error, 'And what to do about it.' );
	}

	/**
	 * A type that generates no class to extend says so, rather than writing a
	 * file that ignores the flag.
	 *
	 * @return void
	 */
	public function test_a_type_that_extends_nothing_refuses_the_flag(): void {
		$this->use_fixture_namespace();

		// The default anonymous subclass declares no base class, which is what
		// `route`, `view` and `page-view` do.
		$this->run_make( array( 'anything' ), array( 'extends' => 'EntityField' ) );

		$this->assertStringContainsString( 'does not take --extends', $this->last_error() );
	}

	/**
	 * Without the flag nothing changes: the type's own stub, with all of its
	 * commentary about the base class it extends.
	 *
	 * @return void
	 */
	public function test_without_the_flag_the_types_own_stub_is_still_used(): void {
		$this->use_fixture_namespace();

		$this->run_make( array( 'acme-rating' ), array( 'yes' => true ), 'field' );

		$written = (string) file_get_contents( $this->target_plugin_dir . '/resources/fields/post/post/acme-rating.php' );

		$this->assertStringContainsString( 'extends Field', $written );
		$this->assertStringContainsString( 'public function subtypes(): array {', $written );
	}

	/**
	 * `--for` names a make type, so nobody has to type the `Core` segment --
	 * which the toolkit deliberately keeps in one place of its own.
	 *
	 * @return void
	 */
	public function test_make_abstract_for_a_type_extends_that_types_base(): void {
		$this->run_make( array( 'EntityField' ), array( 'for' => 'field' ), 'abstract' );

		$written = (string) file_get_contents( $this->target_plugin_dir . '/lib/Abstracts/EntityField.php' );

		$this->assertStringContainsString( 'namespace Acme\\Plugin\\Abstracts;', $written );
		$this->assertStringContainsString( 'use Acme\\Plugin\\Core\\Modules\\Fields\\Field;', $written );
		$this->assertStringContainsString( 'abstract class EntityField extends Field {', $written );
	}

	/**
	 * Neither flag is a plain abstract class -- useful for something shared that
	 * is not a discovered file at all.
	 *
	 * @return void
	 */
	public function test_make_abstract_without_a_parent_extends_nothing(): void {
		$this->run_make( array( 'Importer' ), array(), 'abstract' );

		$written = (string) file_get_contents( $this->target_plugin_dir . '/lib/Abstracts/Importer.php' );

		$this->assertStringContainsString( 'abstract class Importer {', $written );
		$this->assertStringNotContainsString( ' extends ', $written );
		$this->assertStringNotContainsString( "\nuse ", $written, 'Nothing to import either.' );
	}

	/**
	 * The second abstract layered onto the first, through the same resolution
	 * every other type's --extends uses.
	 *
	 * @return void
	 */
	public function test_make_abstract_can_extend_one_of_your_own(): void {
		$this->use_fixture_namespace();

		$this->run_make( array( 'CuratedField' ), array( 'extends' => 'EntityField' ), 'abstract' );

		$written = (string) file_get_contents( $this->target_plugin_dir . '/lib/Abstracts/CuratedField.php' );

		$this->assertStringContainsString( 'use ' . self::FIXTURE_NAMESPACE . '\\Abstracts\\EntityField;', $written );
		$this->assertStringContainsString( 'abstract class CuratedField extends EntityField {', $written );
	}

	/**
	 * @return void
	 */
	public function test_make_abstract_refuses_both_flags_at_once(): void {
		$this->run_make( array( 'EntityField' ), array( 'for' => 'field', 'extends' => 'Whatever' ), 'abstract' );

		$this->assertStringContainsString( 'not both', $this->last_error() );
	}

	/**
	 * @return void
	 */
	public function test_make_abstract_refuses_a_type_that_does_not_exist(): void {
		$this->run_make( array( 'EntityField' ), array( 'for' => 'nonsense' ), 'abstract' );

		$this->assertStringContainsString( 'no `make nonsense`', $this->last_error() );
	}

	/**
	 * Every `warning()` the command made, joined.
	 *
	 * More than one subject can be warned about in a single run -- a respelled
	 * name and a base class this plugin has not installed are both worth saying
	 * -- so a test asserting on one of them must not depend on it being last.
	 *
	 * @return string
	 */
	private function warnings(): string {
		$messages = array();

		foreach ( \WP_CLI::$calls as $call ) {
			if ( 'warning' === $call[0] ) {
				$messages[] = (string) ( $call[1] ?? '' );
			}
		}

		return implode( "\n", $messages );
	}

	/**
	 * The message of the last `error()` the command made.
	 *
	 * `WP_CLI::last()` hands back the call's arguments, so the message is the
	 * first of them.
	 *
	 * @return string The message, or an empty string when nothing errored.
	 */
	private function last_error(): string {
		return (string) ( \WP_CLI::last( 'error' )[0] ?? '' );
	}

	/**
	 * Point zestry.json at a namespace whose classes really exist.
	 *
	 * `--extends` resolves and reflects a live class, and checks it against the
	 * plugin's *copied* base -- so a fixture needs real classes at both the
	 * `Abstracts\` and the `Core\Modules\` ends, which `tests/Support/` provides
	 * through this package's own autoloader.
	 *
	 * @return void
	 */
	private function use_fixture_namespace(): void {
		file_put_contents(
			$this->target_plugin_dir . '/zestry.json',
			(string) json_encode(
				array(
					'namespace'   => self::FIXTURE_NAMESPACE,
					'root'        => 'lib',
					'text_domain' => 'acme-plugin',
				),
				JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * Read one generated shared package's manifest.
	 *
	 * @param string $name The package's local name.
	 * @return array<string, mixed>
	 */
	private function read_shared_manifest( string $name ): array {
		return (array) json_decode(
			(string) file_get_contents( $this->target_plugin_dir . '/src/shared/' . $name . '/package.json' ),
			true
		);
	}

	/**
	 * `make test` appends the suffix `phpunit.xml.dist` collects on, so the
	 * file is picked up by the suite rather than sitting in it unread.
	 */
	public function test_make_test_appends_the_test_suffix(): void {
		$this->install_test_suite();

		$this->run_make( array( 'Reports' ), array(), 'test.php.stub' );

		$this->assertFileExists( $this->target_plugin_dir . '/tests/Integration/ReportsTest.php' );
	}

	public function test_make_test_does_not_double_a_suffix_that_was_given(): void {
		$this->install_test_suite();

		$this->run_make( array( 'ReportsTest' ), array(), 'test.php.stub' );

		$this->assertFileExists( $this->target_plugin_dir . '/tests/Integration/ReportsTest.php' );
		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/tests/Integration/ReportsTestTest.php' );
	}

	public function test_make_test_nests_a_qualified_name(): void {
		$this->install_test_suite();

		$this->run_make( array( 'Modules/Reports' ), array(), 'test.php.stub' );

		$this->assertFileExists( $this->target_plugin_dir . '/tests/Integration/Modules/ReportsTest.php' );
	}

	/**
	 * A class extending one that does not exist is a fatal rather than a
	 * failing test: PHPUnit stops before it collects anything, and says so by
	 * naming the missing parent rather than what to do about it.
	 */
	public function test_make_test_refuses_a_plugin_with_no_suite(): void {
		$this->run_make( array( 'Reports' ), array(), 'test.php.stub' );

		$this->assertStringContainsString(
			'wp zt tests',
			(string) ( \WP_CLI::last( 'error' )[0] ?? '' )
		);

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/tests/Integration/ReportsTest.php' );
	}

	/**
	 * Stand up just enough of what `wp zt tests` writes for `make test` to run.
	 *
	 * @return void
	 */
	private function install_test_suite(): void {
		$path = $this->target_plugin_dir . '/tests/Support/TestCase.php';

		mkdir( dirname( $path ), 0777, true );
		file_put_contents( $path, "<?php\n" );
	}

	/**
	 * Build a concrete MakeCommand subclass, wire it, and invoke handle() with
	 * the CWD inside the throwaway target plugin directory.
	 *
	 * @param array  $args
	 * @param array  $assoc_args
	 * @param string $stub Stub filename identifying which type to exercise:
	 *                     'action.php.stub' (default) uses an anonymous
	 *                     action-like subclass; any stub named in
	 *                     STUB_TO_MAKE_FILE requires the real commands/make/*.php
	 *                     file instead, to exercise its own get_extra_values()
	 *                     prompts and/or collision override.
	 * @return MakeCommand The invoked command, for callers that need it.
	 */
	private function run_make( array $args, array $assoc_args = array(), string $stub = 'action.php.stub' ): MakeCommand {
		\WP_CLI::reset();

		$package_plugin = ( new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-make-test' ) )->declare_multiple( $this->get_toolkit_modules() );

		$stub_to_make_file = array(
			'command.php.stub'   => 'command.php',
			'post-type.php.stub' => 'post-type.php',
			'taxonomy.php.stub'  => 'taxonomy.php',
			'module.php.stub'    => 'module.php',
			'block'              => 'block.php',
			'page'               => 'page.php',
			'view'               => 'view.php',
			'shared'             => 'shared.php',
			'entry'              => 'entry.php',
			'field'              => 'field.php',
			'ability'            => 'ability.php',
			'abstract'           => 'abstract.php',
			'test.php.stub'      => 'test.php',
		);

		$command = isset( $stub_to_make_file[ $stub ] )
			? require dirname( __DIR__, 3 ) . '/resources/commands/make/' . $stub_to_make_file[ $stub ]
			: new class extends MakeCommand {
				public function handle( array $args, array $assoc_args ): void {
					parent::handle( $args, $assoc_args );
				}

				protected function get_stub(): string {
					return 'action.php.stub';
				}

				protected function get_default_dir( array $config ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
					return 'resources/actions';
				}

				protected static function get_type(): string {
					return 'action';
				}
			};

		$package_plugin->wire( $command );

		$previous_cwd = (string) getcwd();
		chdir( $this->target_plugin_dir );

		try {
			// Both, in this order, because that is what the CLI module's own
			// dispatcher does -- and confirm() reads --yes off the recorded
			// arguments rather than off handle()'s parameter, so a harness that
			// skips this tests every prompt as though it had been declined.
			$command->set_arguments( $args, $assoc_args );
			$command->handle( $args, $assoc_args );
		} finally {
			chdir( $previous_cwd );
		}

		return $command;
	}
}
