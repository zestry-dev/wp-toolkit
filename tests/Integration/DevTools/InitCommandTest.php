<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `wp zt init`, run unattended against a throwaway plugin.
 *
 * Focused on where the recorded answers come from, since a wrong text domain
 * is the one that fails silently: the copied source's `__()` calls would name
 * a domain WordPress never loads, and nothing reports it.
 *
 * The tooling scaffolds are skipped throughout -- ToolingTest covers those, and
 * leaving them on would have every case here write eight more files.
 *
 * @covers \Zestry\WPToolkit\DevTools\ZestryConfig
 */
final class InitCommandTest extends TestCase {

	private string $target_plugin_dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->target_plugin_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/zestry-init-demo';
		$this->remove_dir( $this->target_plugin_dir );
		mkdir( $this->target_plugin_dir, 0777, true );

		file_put_contents(
			$this->target_plugin_dir . '/composer.json',
			(string) json_encode( array( 'autoload' => array( 'psr-4' => array( 'Acme\\Demo\\' => 'lib/' ) ) ) )
		);
	}

	public function tear_down(): void {
		$this->remove_dir( $this->target_plugin_dir );
		parent::tear_down();
	}

	/**
	 * The entry file's header is authoritative: it is the domain WordPress
	 * loads the plugin's translations under, so the copied source has to be
	 * stamped with the same one even when the directory says otherwise.
	 */
	public function test_the_text_domain_comes_from_the_entry_file_header(): void {
		$this->write_entry_file( 'Acme Demo', 'acme' );

		$this->run_init();

		$this->assertSame( 'acme', $this->written_config()['text_domain'] );
	}

	public function test_the_directory_name_is_used_when_no_header_declares_one(): void {
		$this->write_entry_file( 'Acme Demo', null );

		$this->run_init();

		$this->assertSame( 'zestry-init-demo', $this->written_config()['text_domain'] );
	}

	public function test_the_directory_name_is_used_when_there_is_no_entry_file_at_all(): void {
		$this->run_init();

		$this->assertSame( 'zestry-init-demo', $this->written_config()['text_domain'] );
	}

	/**
	 * A declared domain outside the grammar cannot be stamped into the copied
	 * source, so the directory name is used rather than silently "fixing" it.
	 */
	public function test_an_invalid_declared_domain_falls_back_to_the_directory_name(): void {
		$this->write_entry_file( 'Acme Demo', 'Acme_Demo' );

		$this->run_init();

		$this->assertSame( 'zestry-init-demo', $this->written_config()['text_domain'] );
	}

	/**
	 * A file without a `Plugin Name:` header is not the entry file, however
	 * many other headers it carries -- that is how WordPress itself decides.
	 */
	public function test_a_php_file_that_is_not_the_entry_file_is_ignored(): void {
		file_put_contents(
			$this->target_plugin_dir . '/uninstall.php',
			"<?php\n/**\n * Text Domain: not-the-entry-file\n */\n"
		);

		$this->run_init();

		$this->assertSame( 'zestry-init-demo', $this->written_config()['text_domain'] );
	}

	public function test_the_declared_domain_is_stamped_into_the_copied_source(): void {
		$this->write_entry_file( 'Acme Demo', 'acme' );

		$this->run_init();

		$plugin_file = (string) file_get_contents( $this->target_plugin_dir . '/lib/Core/Kernel/Plugin.php' );
		$this->assertStringContainsString( 'Acme\\Demo\\Core', $plugin_file );
		$this->assertStringNotContainsString( "'zestry-toolkit'", $plugin_file );
	}

	/**
	 * Write a plugin entry file carrying the usual headers.
	 *
	 * @param string      $name        The `Plugin Name:` header value.
	 * @param string|null $text_domain The `Text Domain:` header, or null to omit it.
	 * @return void
	 */
	private function write_entry_file( string $name, ?string $text_domain ): void {
		$headers = " * Plugin Name: {$name}\n";

		if ( null !== $text_domain ) {
			$headers .= " * Text Domain: {$text_domain}\n";
		}

		file_put_contents(
			$this->target_plugin_dir . '/acme-demo.php',
			"<?php\n/**\n{$headers} */\n"
		);
	}

	/**
	 * `--yes` fails with a message rather than recursing forever.
	 *
	 * `ask_for_root()` defaults to the directory composer.json already maps the
	 * namespace to, then refuses `src` as reserved for the JavaScript build --
	 * and re-asked. Under `--yes` nothing is read from STDIN, so `ask()` handed
	 * back the same rejected default and this recursed until the stack died.
	 * Its two sibling prompts already stopped here; this one did not.
	 */
	public function test_yes_reports_a_src_root_instead_of_recursing(): void {
		file_put_contents(
			$this->target_plugin_dir . '/composer.json',
			(string) json_encode( array( 'autoload' => array( 'psr-4' => array( 'Acme\\Demo\\' => 'src/' ) ) ) )
		);

		$this->write_entry_file( 'Acme Demo', 'acme-demo' );

		$this->run_init();

		$error = \WP_CLI::last( 'error' );

		$this->assertNotNull( $error, 'Running unattended against a src/ mapping must report an error.' );
		$this->assertStringContainsString( 'src', (string) $error[0] );
		$this->assertFileDoesNotExist(
			$this->target_plugin_dir . '/zestry.json',
			'Nothing should be written once the destination is refused.'
		);
	}

	/**
	 * The zestry.json init wrote.
	 *
	 * @return array<string, mixed>
	 */
	private function written_config(): array {
		return (array) json_decode(
			(string) file_get_contents( $this->target_plugin_dir . '/zestry.json' ),
			true
		);
	}

	/**
	 * `npm run format` is `prettier --write .`, so without an ignore list it
	 * reformats the consumer's `composer.json`, their `.wp-env.json` and any
	 * Markdown at the root -- wider than a command by that name reads.
	 *
	 * @return void
	 */
	public function test_writes_a_prettierignore_so_format_stays_in_source(): void {
		$this->run_init( array( 'prettier' => true ) );

		$file = $this->target_plugin_dir . '/.prettierignore';

		$this->assertFileExists( $file );

		$contents = (string) file_get_contents( $file );

		foreach ( array( 'composer.json', '.wp-env.json', '*.md', 'node_modules/' ) as $ignored ) {
			$this->assertStringContainsString( $ignored, $contents );
		}
	}

	/**
	 * Additive like every other file init writes: a project with its own list
	 * keeps it.
	 *
	 * @return void
	 */
	public function test_an_existing_prettierignore_is_left_alone(): void {
		file_put_contents( $this->target_plugin_dir . '/.prettierignore', "mine/\n" );

		$this->run_init( array( 'prettier' => true ) );

		$this->assertSame(
			"mine/\n",
			(string) file_get_contents( $this->target_plugin_dir . '/.prettierignore' )
		);
	}

	/**
	 * An agent opening this plugin sees a `lib/Core/` it did not write and a
	 * `bootstrap.php` whose entries look optional. The conventions explaining
	 * those are in documentation that is not in this repository, so they travel
	 * with the plugin instead.
	 */
	public function test_writes_the_instructions_an_agent_will_read(): void {
		$this->run_init();

		$this->assertFileExists( $this->target_plugin_dir . '/AGENTS.md' );

		$contents = (string) file_get_contents( $this->target_plugin_dir . '/AGENTS.md' );

		$this->assertStringContainsString( 'listed in `bootstrap.php`', $contents );
		$this->assertStringContainsString( 'wp zt describe --format=json', $contents );
	}

	/**
	 * A pointer, since Claude Code reads CLAUDE.md and most other tools read
	 * AGENTS.md. Two files saying the same thing is the drift this removes.
	 */
	public function test_points_claude_at_the_same_file(): void {
		$this->run_init();

		$this->assertStringContainsString(
			'AGENTS.md',
			(string) file_get_contents( $this->target_plugin_dir . '/.claude/CLAUDE.md' )
		);
	}

	public function test_agents_md_can_be_declined(): void {
		$this->run_init( array( 'agents' => false ) );

		$this->assertFileDoesNotExist( $this->target_plugin_dir . '/AGENTS.md' );
	}

	/**
	 * Additive like every other file `init` writes: a plugin that has written
	 * its own instructions keeps them.
	 */
	public function test_an_existing_agents_md_is_left_alone(): void {
		file_put_contents( $this->target_plugin_dir . '/AGENTS.md', '# Ours' );

		$this->run_init();

		$this->assertSame( '# Ours', file_get_contents( $this->target_plugin_dir . '/AGENTS.md' ) );
	}

	/**
	 * Run the real commands/init.php unattended, without the tooling scaffolds.
	 *
	 * @return void
	 *
	 * @param array<string, mixed> $overrides Named arguments to change, e.g. turning one scaffold back on.
	 * @return void
	 */
	private function run_init( array $overrides = array() ): void {
		\WP_CLI::reset();

		$package_plugin = new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-init-demo' );
		$command        = require dirname( __DIR__, 3 ) . '/commands/init.php';
		$package_plugin->wire( $command );

		$assoc_args = array_merge(
			array(
				'yes'      => true,
				'phpcs'    => false,
				'eslint'   => false,
				'prettier' => false,
			),
			$overrides
		);

		$command->set_arguments( array(), $assoc_args );

		$previous_cwd = (string) getcwd();
		chdir( $this->target_plugin_dir );

		try {
			$command->handle( array(), $assoc_args );
		} finally {
			chdir( $previous_cwd );
		}
	}
}
