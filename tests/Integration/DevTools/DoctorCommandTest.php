<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `wp zt doctor`: the silent-misconfiguration checks.
 *
 * Every case here is one that produces no runtime error in a real plugin -- a
 * module nothing declares, a declaration pointing at a deleted file -- which is
 * the whole reason the command exists.
 *
 * The declarations are written in the shape `bootstrap.php` actually has: two
 * sections, and an entry that is a class name with an optional initializer.
 * Reading the top level directly instead finds the two section names and no
 * declarations at all, which reported every correctly declared module as
 * undeclared -- so the bare-entry cases below are the regression.
 *
 * Run against a throwaway plugin directory under WP_PLUGIN_DIR, matching how
 * MakeCommandTest exercises the same real-CWD requirement. The consumer's own
 * classes are never loadable here (nothing autoloads `Acme\Plugin`), so the
 * Module/Service detection exercises its source-scan fallback rather than
 * reflection.
 *
 * @covers \Zestry\WPToolkit\DevTools\BootstrapFile
 */
final class DoctorCommandTest extends TestCase {

	private string $target_plugin_dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->target_plugin_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/zestry-doctor-test-' . uniqid();
		mkdir( $this->target_plugin_dir . '/lib/Modules', 0777, true );

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
		 * Reaching `wp zt doctor` at all means WordPress loaded this plugin and
		 * its entry file ran, which is the state every test here but one is
		 * about. `Plugin::run()` publishes itself as its last act; a bare
		 * fixture directory has no entry file to do that, so it is done here.
		 */
		$GLOBALS[ RuntimePlugin::REGISTRY ][ $this->target_plugin_dir ] = new Plugin(
			$this->target_plugin_dir . '/acme-plugin.php',
			'acme-plugin'
		);

		$this->write_entry_file();
	}

	/**
	 * Write the fixture plugin's entry file.
	 *
	 * A plugin is a directory with a file declaring one, and `Requires at least:`
	 * is what the version checks read. Declared high enough that nothing in the
	 * registry is out of reach here.
	 *
	 * @return void
	 */
	private function write_entry_file(): void {
		file_put_contents(
			$this->target_plugin_dir . '/acme-plugin.php',
			"<?php\n/**\n * Plugin Name: Acme Plugin\n * Requires at least: 7.1\n */\n"
		);
	}

	public function tear_down(): void {
		unset( $GLOBALS[ RuntimePlugin::REGISTRY ] );
		$this->remove_dir( $this->target_plugin_dir );
		parent::tear_down();
	}

	/**
	 * The largest silent failure there is: every module declared, nothing built,
	 * no error anywhere, and every other check on this page passing. An entry
	 * file that never reaches `run()` looks exactly like a working plugin from
	 * every file doctor can read.
	 */
	public function test_reports_a_plugin_that_declared_modules_and_never_ran(): void {
		$this->write_module( 'Shortcode', 'Module' );
		$this->write_bootstrap( array( '\\Acme\\Plugin\\Modules\\Shortcode::class' ) );

		unset( $GLOBALS[ RuntimePlugin::REGISTRY ] );

		$this->run_doctor();

		$this->assertStringContainsString(
			'nothing in this plugin built any of them',
			implode( ' ', $this->logged_messages() )
		);
	}

	/**
	 * A plugin declaring nothing has nothing that should have been built, and is
	 * free not to use this toolkit at run time at all.
	 */
	public function test_does_not_report_a_plugin_that_declares_nothing(): void {
		unset( $GLOBALS[ RuntimePlugin::REGISTRY ] );

		$this->run_doctor();

		$this->assertStringNotContainsString(
			'built any of them',
			implode( ' ', $this->logged_messages() )
		);
	}

	public function test_a_correctly_wired_plugin_reports_no_problems(): void {
		$this->write_module( 'Shortcode', 'Module' );
		$this->write_bootstrap( array( '\\Acme\\Plugin\\Modules\\Shortcode::class' ) );

		$this->run_doctor();

		$this->assertNotNull( \WP_CLI::last( 'success' ) );
		$this->assertStringContainsString( 'No problems found', (string) \WP_CLI::last( 'success' )[0] );
	}

	/**
	 * A bare entry is the shape `wp zt add` and `wp zt make module` write.
	 *
	 * It has an integer key, so a reader looking only for string keys sees
	 * nothing -- and a module that is declared is then reported as undeclared,
	 * which is the opposite of the truth and the one thing this command exists
	 * to get right.
	 */
	public function test_a_bare_declaration_counts_as_declaring_the_module(): void {
		$this->write_registry_module( 'AdminPages' );
		$this->write_bootstrap( array( '\\Acme\\Plugin\\Core\\Modules\\AdminPages\\AdminPages::class' ) );

		$this->run_doctor();

		$this->assertStringNotContainsString( 'never declared', $this->stdout() );
		$this->assertNotNull( \WP_CLI::last( 'success' ) );
	}

	/**
	 * An entry with an initializer is the other written shape, and the class
	 * name is its key rather than its value.
	 */
	public function test_a_declaration_with_an_initializer_counts_too(): void {
		$this->write_registry_module( 'AdminPages' );
		$this->write_bootstrap( array( '\\Acme\\Plugin\\Core\\Modules\\AdminPages\\AdminPages::class => array( \'configure\' => static function ( $m ): void {} )' ) );

		$this->run_doctor();

		$this->assertStringNotContainsString( 'never declared', $this->stdout() );
		$this->assertNotNull( \WP_CLI::last( 'success' ) );
	}

	/**
	 * The source scan reads only the name written after `extends`, so a class
	 * reaching Module through a base of its own cannot be classified from that
	 * file alone. Saying nothing beats telling someone to move an entry that was
	 * already right.
	 */
	public function test_a_class_extending_an_unknown_base_is_not_second_guessed(): void {
		$this->write_module( 'Installer', 'ActivationHandler' );
		$this->write_bootstrap( array( '\\Acme\\Plugin\\Modules\\Installer::class' ) );

		$this->run_doctor();

		$this->assertNotNull( \WP_CLI::last( 'success' ) );
		$this->assertStringNotContainsString( 'is declared under', $this->stdout() );
	}

	public function test_a_declaration_pointing_at_a_missing_file_is_reported(): void {
		$this->write_bootstrap( array( '\\Acme\\Plugin\\Modules\\Deleted::class' ) );

		$this->run_doctor();

		$this->assertStringContainsString( 'its file does not exist', $this->stdout() );
	}

	/**
	 * A problem's title, detail and path must all land on the same stream.
	 *
	 * The title used to go to STDERR via warning() while its own detail and
	 * path went to STDOUT, so `doctor > report.txt` captured an explanation
	 * naming no module and `doctor 2> report.txt` captured names with no
	 * paths. On a terminal the unbuffered STDERR writes also overtook the
	 * STDOUT ones, printing the summary in the middle of a problem.
	 */
	public function test_a_problem_is_reported_entirely_on_stdout(): void {
		$this->write_registry_module( 'AdminPages' );
		$this->write_bootstrap( array() );

		$this->run_doctor();

		$stdout = $this->stdout();
		$this->assertStringContainsString( '! The "admin-pages" module is copied in but never declared.', $stdout );
		$this->assertStringContainsString( 'A module is built because bootstrap.php lists it', $stdout );
		$this->assertStringContainsString( 'lib/Core/Modules/AdminPages/AdminPages.php', $stdout );

		// error() stays the sole STDERR write, so the non-zero exit survives.
		$this->assertNull( \WP_CLI::last( 'warning' ) );
		$this->assertNotNull( \WP_CLI::last( 'error' ) );
	}

	public function test_a_copied_in_module_that_is_never_declared_is_reported(): void {
		// A module acts on its own, so copying it in and never declaring it
		// means nothing builds it: it discovers nothing and says nothing.
		$this->write_registry_module( 'AdminPages' );
		$this->write_bootstrap( array() );

		$this->run_doctor();

		$this->assertStringContainsString( '"admin-pages" module is copied in but never declared', $this->stdout() );
	}

	/**
	 * A service is built the moment something asks for it, so one that is copied
	 * in and never declared is doing exactly what it should.
	 */
	public function test_a_copied_in_service_that_is_never_declared_is_not_reported(): void {
		mkdir( $this->target_plugin_dir . '/lib/Core/Services', 0777, true );
		file_put_contents(
			$this->target_plugin_dir . '/lib/Core/Services/Path.php',
			"<?php\nnamespace Acme\\Plugin\\Core\\Services;\nclass Path extends Service {}\n"
		);
		$this->write_bootstrap( array() );

		$this->run_doctor();

		$this->assertNotNull( \WP_CLI::last( 'success' ) );
	}

	public function test_an_zestry_json_root_that_does_not_exist_is_reported(): void {
		$this->remove_dir( $this->target_plugin_dir . '/lib' );
		$this->write_bootstrap( array() );

		$this->run_doctor();

		$this->assertStringContainsString( 'no such directory', $this->stdout() );
	}

	public function test_a_structured_format_lists_the_problems_and_halts_non_zero(): void {
		$this->write_registry_module( 'AdminPages' );
		$this->write_bootstrap( array() );

		$this->run_doctor( array( 'format' => 'json' ) );

		$formatted = \WP_CLI::last( 'format_items' );
		$this->assertNotNull( $formatted );
		$this->assertSame( 'json', $formatted[0] );
		$this->assertSame( array( 'file', 'problem' ), $formatted[2] );
		$this->assertSame( 'lib/Core/Modules/AdminPages/AdminPages.php', $formatted[1][0]['file'] );
		$this->assertStringContainsString( 'never declared', $formatted[1][0]['problem'] );

		$this->assertSame( array( 1 ), \WP_CLI::last( 'halt' ) );
	}

	/**
	 * A structured format has to parse, so the two summary lines the report
	 * opens with must not be printed ahead of it.
	 */
	public function test_a_structured_format_prints_nothing_but_the_problems(): void {
		$this->write_registry_module( 'AdminPages' );
		$this->write_bootstrap( array() );

		$this->run_doctor( array( 'format' => 'json' ) );

		$this->assertSame( '', $this->stdout() );
		$this->assertNull( \WP_CLI::last( 'error' ) );
		$this->assertNull( \WP_CLI::last( 'warning' ) );
	}

	public function test_a_clean_plugin_halts_zero_in_a_structured_format(): void {
		$this->write_module( 'Shortcode', 'Module' );
		$this->write_bootstrap( array( '\\Acme\\Plugin\\Modules\\Shortcode::class' ) );

		$this->run_doctor( array( 'format' => 'json' ) );

		$this->assertSame( array(), \WP_CLI::last( 'format_items' )[1] );
		$this->assertSame( array( 0 ), \WP_CLI::last( 'halt' ) );
	}

	public function test_missing_zestry_json_asks_for_init(): void {
		unlink( $this->target_plugin_dir . '/zestry.json' );

		$this->run_doctor();

		$this->assertStringContainsString( 'Run `wp zt init` first', (string) \WP_CLI::last( 'error' )[0] );
	}

	/**
	 * Everything the command wrote to STDOUT, in order.
	 *
	 * Assertions read this rather than a single recorded call, so a problem
	 * split back across two streams fails the test instead of passing on
	 * whichever half the assertion happened to look at.
	 *
	 * @return string
	 */
	private function stdout(): string {
		$lines = array();

		foreach ( \WP_CLI::$calls as $call ) {
			if ( 'log' === $call[0] ) {
				$lines[] = (string) $call[1];
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Write a class into the throwaway plugin's own lib/Modules.
	 *
	 * @param string $class_name The class's short name.
	 * @param string $extends    The base it is written to extend.
	 * @return void
	 */
	private function write_module( string $class_name, string $extends ): void {
		file_put_contents(
			$this->target_plugin_dir . '/lib/Modules/' . $class_name . '.php',
			"<?php\nnamespace Acme\\Plugin\\Modules;\nclass " . $class_name . ' extends ' . $extends . " {}\n"
		);
	}

	/**
	 * WordPress reads `Requires at least:` to refuse activation on a site too old
	 * for the plugin. Without it there is nothing stopping the plugin loading
	 * anywhere, and nothing here can tell whether a copied module would have an
	 * API to call.
	 */
	public function test_a_missing_requires_at_least_header_is_reported(): void {
		file_put_contents(
			$this->target_plugin_dir . '/acme-plugin.php',
			"<?php\n/**\n * Plugin Name: Acme Plugin\n */\n"
		);
		$this->write_bootstrap( array() );

		$this->run_doctor();

		$this->assertStringContainsString( 'does not declare a `Requires at least:` header', $this->stdout() );
	}

	/**
	 * `wp zt add` refuses this outright, so reaching it means the header was
	 * lowered afterwards -- leaving a plugin that activates on sites where the
	 * module registers against an API that is not there.
	 */
	public function test_a_module_needing_more_than_the_plugin_promises_is_reported(): void {
		file_put_contents(
			$this->target_plugin_dir . '/acme-plugin.php',
			"<?php\n/**\n * Plugin Name: Acme Plugin\n * Requires at least: 6.5\n */\n"
		);
		$this->write_registry_module( 'IconsLibrary' );
		$this->write_bootstrap( array( '\\Acme\\Plugin\\Core\\Modules\\IconsLibrary\\IconsLibrary::class' ) );

		$this->run_doctor();

		$this->assertStringContainsString( '"icons-library" module needs WordPress 7.1', $this->stdout() );
		$this->assertStringContainsString( 'Requires at least: 6.5', $this->stdout() );
	}

	/**
	 * Write a module the registry knows by name, at the path PSR-4 gives it.
	 *
	 * The undeclared-module check walks the registry and looks for each entry's
	 * file under the consumer's own namespace, so a module has to sit exactly
	 * where `wp zt add` would have put it for that check to see it at all.
	 *
	 * @param string $class_name The class's short name, e.g. `AdminPages`.
	 * @return void
	 */
	private function write_registry_module( string $class_name ): void {
		$dir = $this->target_plugin_dir . '/lib/Core/Modules/' . $class_name;
		mkdir( $dir, 0777, true );

		file_put_contents(
			$dir . '/' . $class_name . '.php',
			"<?php\nnamespace Acme\\Plugin\\Core\\Modules\\" . $class_name . ";\nclass " . $class_name . " extends Module {}\n"
		);
	}

	/**
	 * Write a bootstrap.php in the flat shape the toolkit writes.
	 *
	 * @param string[] $entries Entry expressions, without their trailing comma.
	 * @return void
	 */
	private function write_bootstrap( array $entries ): void {
		file_put_contents(
			$this->target_plugin_dir . '/bootstrap.php',
			"<?php\nreturn array(\n" . $this->render_entries( $entries ) . ");\n"
		);
	}

	/**
	 * Render the entries as indented, comma-terminated lines.
	 *
	 * @param string[] $entries Entry expressions, without their trailing comma.
	 * @return string
	 */
	private function render_entries( array $entries ): string {
		$rendered = '';

		foreach ( $entries as $entry ) {
			$rendered .= "\t" . $entry . ",\n";
		}

		return $rendered;
	}

	/**
	 * Every message the last run printed, problems included.
	 *
	 * @return string[]
	 */
	private function logged_messages(): array {
		$messages = array();

		foreach ( \WP_CLI::$calls as $call ) {
			if ( in_array( $call[0], array( 'log', 'warning', 'error' ), true ) ) {
				$messages[] = (string) $call[1];
			}
		}

		return $messages;
	}

	/**
	 * Wire and run the real commands/doctor.php against the throwaway plugin.
	 *
	 * @param array<string, mixed> $assoc_args WP-CLI's named arguments.
	 * @return Command
	 */
	private function run_doctor( array $assoc_args = array() ): Command {
		\WP_CLI::reset();

		$package_plugin = ( new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-doctor-test' ) )->declare_modules( $this->get_toolkit_modules() );

		/** @var Command $command */
		$command = require dirname( __DIR__, 3 ) . '/commands/doctor.php';

		$package_plugin->wire( $command );

		$previous_cwd = (string) getcwd();
		chdir( $this->target_plugin_dir );

		try {
			$command->handle( array(), $assoc_args );
		} finally {
			chdir( $previous_cwd );
		}

		return $command;
	}
}
