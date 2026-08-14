<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\Formatter;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Which formatter a generated file is handed to, and what happens when the
 * plugin has none installed.
 *
 * The formatters themselves are stubbed: each fake binary records the arguments
 * it was called with, so these assert on what was asked of which tool rather
 * than on phpcbf's or prettier's own output.
 *
 * @covers \Zestry\WPToolkit\DevTools\Formatter
 */
final class FormatterTest extends TestCase {

	private Formatter $formatter;

	public function set_up(): void {
		parent::set_up();

		$this->formatter = $this->plugin->get( Formatter::class );
	}

	public function test_hands_php_files_to_phpcbf(): void {
		$this->fake_binary( 'vendor/bin/phpcbf' );
		$this->write_plugin_file( 'phpcs.xml', '<?xml version="1.0"?><ruleset name="acme"/>' );
		$file = $this->write_plugin_file( 'resources/actions/send.php', '<?php' );

		$formatted = $this->formatter->format( $this->plugin_dir, array( $file ) );

		$this->assertSame( array( $file ), $formatted );
		$this->assertStringContainsString( $file, $this->recorded( 'phpcbf' ) );
	}

	/**
	 * ESLint rather than Prettier for a script, because in a WordPress project
	 * it is the superset: the recommended config runs Prettier through it, so
	 * `--fix` formats and applies lint autofixes in one pass.
	 */
	public function test_hands_scripts_to_eslint_and_the_rest_to_prettier(): void {
		$this->fake_binary( 'node_modules/.bin/eslint' );
		$this->fake_binary( 'node_modules/.bin/prettier' );
		$this->write_plugin_file( 'eslint.config.mjs', 'export default [];' );

		$script = $this->write_plugin_file( 'src/entries/settings/index.ts', 'export {};' );
		$style  = $this->write_plugin_file( 'src/entries/settings/style.scss', '.a{}' );
		$json   = $this->write_plugin_file( 'src/entries/settings/package.json', '{}' );

		$formatted = $this->formatter->format( $this->plugin_dir, array( $script, $style, $json ) );

		$this->assertCount( 3, $formatted );

		$this->assertStringContainsString( $script, $this->recorded( 'eslint' ) );
		$this->assertStringNotContainsString( $style, $this->recorded( 'eslint' ) );

		// ESLint has no opinion on JSON or a stylesheet.
		$this->assertStringContainsString( $style, $this->recorded( 'prettier' ) );
		$this->assertStringContainsString( $json, $this->recorded( 'prettier' ) );
		$this->assertStringNotContainsString( $script, $this->recorded( 'prettier' ) );
	}

	/**
	 * A plugin with no ESLint still gets formatted scripts, since Prettier alone
	 * is most of what ESLint would have applied to them.
	 */
	public function test_falls_back_to_prettier_for_scripts_without_eslint(): void {
		$this->fake_binary( 'node_modules/.bin/prettier' );
		$script = $this->write_plugin_file( 'src/entries/settings/index.ts', 'export {};' );

		$this->assertSame( array( $script ), $this->formatter->format( $this->plugin_dir, array( $script ) ) );
		$this->assertStringContainsString( $script, $this->recorded( 'prettier' ) );
	}

	/**
	 * ESLint 9 reads flat config only and refuses to start without one, so an
	 * installed binary with no configuration is not something to run.
	 */
	public function test_does_not_run_eslint_without_a_configuration(): void {
		$this->fake_binary( 'node_modules/.bin/eslint' );
		$this->fake_binary( 'node_modules/.bin/prettier' );
		$script = $this->write_plugin_file( 'src/entries/settings/index.ts', 'export {};' );

		$this->formatter->format( $this->plugin_dir, array( $script ) );

		$this->assertSame( '', $this->recorded( 'eslint' ) );
		$this->assertStringContainsString( $script, $this->recorded( 'prettier' ) );
	}

	/**
	 * One process per tool, not per file: each start costs hundreds of
	 * milliseconds, and a block writes six files at once.
	 */
	public function test_runs_each_formatter_once_for_all_its_files(): void {
		$this->fake_binary( 'node_modules/.bin/prettier' );

		$files = array(
			$this->write_plugin_file( 'a.json', '{}' ),
			$this->write_plugin_file( 'b.json', '{}' ),
		);

		$this->formatter->format( $this->plugin_dir, $files );

		$this->assertSame( 1, substr_count( $this->recorded( 'prettier' ), "\n" ) );
	}

	public function test_splits_a_mixed_set_between_the_two_formatters(): void {
		$this->fake_binary( 'vendor/bin/phpcbf' );
		$this->fake_binary( 'node_modules/.bin/prettier' );
		$this->write_plugin_file( 'phpcs.xml', '<?xml version="1.0"?><ruleset name="acme"/>' );

		$php = $this->write_plugin_file( 'block.php', '<?php' );
		$ts  = $this->write_plugin_file( 'view.json', '{}' );

		$this->formatter->format( $this->plugin_dir, array( $php, $ts ) );

		$this->assertStringContainsString( $php, $this->recorded( 'phpcbf' ) );
		$this->assertStringNotContainsString( $ts, $this->recorded( 'phpcbf' ) );
		$this->assertStringContainsString( $ts, $this->recorded( 'prettier' ) );
		$this->assertStringNotContainsString( $php, $this->recorded( 'prettier' ) );
	}

	/**
	 * A plugin that declined the tooling scaffolds, or has simply not installed
	 * yet, gets its file written and left alone rather than an error.
	 */
	public function test_does_nothing_when_no_formatter_is_installed(): void {
		$file = $this->write_plugin_file( 'resources/actions/send.php', '<?php' );

		$this->assertSame( array(), $this->formatter->format( $this->plugin_dir, array( $file ) ) );
	}

	/**
	 * phpcbf discovers its ruleset from the working directory, and has nothing
	 * to apply without one -- running it would only print a usage error.
	 */
	public function test_does_not_run_phpcbf_without_a_ruleset(): void {
		$this->fake_binary( 'vendor/bin/phpcbf' );
		$file = $this->write_plugin_file( 'resources/actions/send.php', '<?php' );

		$this->assertSame( array(), $this->formatter->format( $this->plugin_dir, array( $file ) ) );
		$this->assertFileDoesNotExist( $this->plugin_dir . '/phpcbf.recorded' );
	}

	public function test_leaves_a_file_no_formatter_claims_alone(): void {
		$this->fake_binary( 'node_modules/.bin/prettier' );
		$file = $this->write_plugin_file( 'readme.txt', 'hello' );

		$this->assertSame( array(), $this->formatter->format( $this->plugin_dir, array( $file ) ) );
	}

	public function test_formats_nothing_when_given_nothing(): void {
		$this->assertSame( array(), $this->formatter->format( $this->plugin_dir, array() ) );
	}

	/**
	 * Write an executable standing in for a formatter, which appends the
	 * arguments it was called with to a file this test can read back.
	 *
	 * @param string $relative_path Path within the plugin, e.g. `vendor/bin/phpcbf`.
	 * @return void
	 */
	private function fake_binary( string $relative_path ): void {
		$name = basename( $relative_path );
		$path = $this->write_plugin_file(
			$relative_path,
			"#!/bin/sh\necho \"$@\" >> \"$( dirname \"$0\" )/../../{$name}.recorded\"\n"
		);

		chmod( $path, 0755 );
	}

	/**
	 * What a fake formatter was asked to do.
	 *
	 * @param string $name The binary's name.
	 * @return string
	 */
	private function recorded( string $name ): string {
		$path = $this->plugin_dir . '/' . $name . '.recorded';

		return is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}
}
