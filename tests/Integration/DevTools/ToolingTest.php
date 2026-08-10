<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\Tooling;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The linting/formatting scaffold `wp zestry init` offers.
 *
 * The property under test throughout is that every write is additive: a
 * consumer's composer.json, package.json and config files are their own, and
 * running init again in a configured plugin must change nothing.
 *
 * @covers \Zestry\WPToolkit\DevTools\Tooling
 */
final class ToolingTest extends TestCase {

	private Tooling $tooling;

	public function set_up(): void {
		parent::set_up();

		$this->tooling = $this->plugin->get( Tooling::class );
	}

	public function test_writes_a_config_file_that_is_not_there(): void {
		$written = $this->tooling->write_config_file( $this->plugin_dir, '.prettierrc', '"@wordpress/prettier-config"' );

		$this->assertTrue( $written );
		$this->assertSame( '"@wordpress/prettier-config"', (string) file_get_contents( $this->plugin_dir . '/.prettierrc' ) );
	}

	public function test_leaves_an_existing_config_file_exactly_as_it_is(): void {
		file_put_contents( $this->plugin_dir . '/.prettierrc', '{ "useTabs": false }' );

		$written = $this->tooling->write_config_file( $this->plugin_dir, '.prettierrc', '"@wordpress/prettier-config"' );

		$this->assertFalse( $written );
		$this->assertSame( '{ "useTabs": false }', (string) file_get_contents( $this->plugin_dir . '/.prettierrc' ) );
	}

	public function test_adds_dev_requires_unversioned(): void {
		$this->write_json( 'composer.json', array( 'name' => 'acme/plugin' ) );

		$added = $this->tooling->add_composer_dev_requires( $this->plugin_dir, array( 'wp-coding-standards/wpcs' ) );

		$this->assertSame( array( 'wp-coding-standards/wpcs' ), $added );
		$this->assertSame( '*', $this->read_json( 'composer.json' )['require-dev']['wp-coding-standards/wpcs'] );
	}

	public function test_does_not_touch_a_package_already_required(): void {
		$this->write_json(
			'composer.json',
			array( 'require-dev' => array( 'wp-coding-standards/wpcs' => '^3.1' ) )
		);

		$added = $this->tooling->add_composer_dev_requires( $this->plugin_dir, array( 'wp-coding-standards/wpcs' ) );

		$this->assertSame( array(), $added );
		$this->assertSame( '^3.1', $this->read_json( 'composer.json' )['require-dev']['wp-coding-standards/wpcs'] );
	}

	/**
	 * Moving a package from `require` to `require-dev` would change what the
	 * plugin ships, which is not this command's decision to make.
	 */
	public function test_does_not_move_a_package_from_require_to_require_dev(): void {
		$this->write_json( 'composer.json', array( 'require' => array( 'acme/thing' => '^1.0' ) ) );

		$added = $this->tooling->add_composer_dev_requires( $this->plugin_dir, array( 'acme/thing' ) );

		$this->assertSame( array(), $added );
		$this->assertArrayNotHasKey( 'require-dev', $this->read_json( 'composer.json' ) );
	}

	public function test_allows_the_phpcs_composer_plugin(): void {
		$this->write_json( 'composer.json', array( 'name' => 'acme/plugin' ) );

		$allowed = $this->tooling->allow_composer_plugin( $this->plugin_dir, Tooling::PHPCS_COMPOSER_PLUGIN );

		$this->assertTrue( $allowed );
		$this->assertTrue( $this->read_json( 'composer.json' )['config']['allow-plugins'][ Tooling::PHPCS_COMPOSER_PLUGIN ] );
	}

	/**
	 * An explicit `false` is a decision someone already made; only an absent
	 * key is this command's to fill in.
	 */
	public function test_leaves_an_explicitly_disallowed_composer_plugin_alone(): void {
		$this->write_json(
			'composer.json',
			array( 'config' => array( 'allow-plugins' => array( Tooling::PHPCS_COMPOSER_PLUGIN => false ) ) )
		);

		$allowed = $this->tooling->allow_composer_plugin( $this->plugin_dir, Tooling::PHPCS_COMPOSER_PLUGIN );

		$this->assertFalse( $allowed );
		$this->assertFalse( $this->read_json( 'composer.json' )['config']['allow-plugins'][ Tooling::PHPCS_COMPOSER_PLUGIN ] );
	}

	/**
	 * A JS linter is unusable without a package.json, so refusing to create one
	 * would leave the consumer holding a config file nothing can run.
	 */
	public function test_creates_package_json_when_the_plugin_has_none(): void {
		$added = $this->tooling->add_npm_dev_dependencies( $this->plugin_dir, Tooling::ESLINT_PACKAGES );

		$this->assertSame( Tooling::ESLINT_PACKAGES, $added );

		$package_json = $this->read_json( 'package.json' );
		$this->assertSame( '*', $package_json['devDependencies']['@wordpress/eslint-plugin'] );
		$this->assertTrue( $package_json['private'] );
	}

	public function test_keeps_an_existing_npm_dependency_constraint(): void {
		$this->write_json( 'package.json', array( 'devDependencies' => array( 'eslint' => '^8.0.0' ) ) );

		$added = $this->tooling->add_npm_dev_dependencies( $this->plugin_dir, array( 'eslint' ) );

		$this->assertSame( array(), $added );
		$this->assertSame( '^8.0.0', $this->read_json( 'package.json' )['devDependencies']['eslint'] );
	}

	public function test_adds_scripts_without_rewriting_one_already_defined(): void {
		$this->write_json( 'composer.json', array( 'scripts' => array( 'lint' => 'my-own-linter' ) ) );

		$added = $this->tooling->add_scripts(
			$this->plugin_dir,
			'composer.json',
			array(
				'lint'     => 'phpcs --standard=phpcs.xml',
				'lint:fix' => 'phpcbf --standard=phpcs.xml',
			)
		);

		$this->assertSame( array( 'lint:fix' ), $added );

		$scripts = $this->read_json( 'composer.json' )['scripts'];
		$this->assertSame( 'my-own-linter', $scripts['lint'] );
		$this->assertSame( 'phpcbf --standard=phpcs.xml', $scripts['lint:fix'] );
	}

	/**
	 * @param string $name The manifest's file name.
	 * @param array<string, mixed> $data The data to write.
	 * @return void
	 */
	private function write_json( string $name, array $data ): void {
		file_put_contents( $this->plugin_dir . '/' . $name, (string) json_encode( $data ) );
	}

	public function test_declares_a_workspace_pattern(): void {
		$this->write_json( 'package.json', array( 'name' => 'acme-plugin' ) );

		$added = $this->tooling->add_npm_workspaces( $this->plugin_dir, 'src/shared/*' );

		$this->assertTrue( $added );
		$this->assertSame( array( 'src/shared/*' ), $this->read_json( 'package.json' )['workspaces'] );
	}

	public function test_creates_a_package_json_to_declare_the_workspace_in(): void {
		$added = $this->tooling->add_npm_workspaces( $this->plugin_dir, 'src/shared/*' );

		$this->assertTrue( $added );
		$this->assertSame( array( 'src/shared/*' ), $this->read_json( 'package.json' )['workspaces'] );
	}

	/**
	 * The field is a list the consumer curates, and appending to it would pull
	 * directories into the install that were deliberately left out.
	 */
	public function test_leaves_an_existing_workspace_declaration_alone(): void {
		$this->write_json( 'package.json', array( 'workspaces' => array( 'libs/*' ) ) );

		$added = $this->tooling->add_npm_workspaces( $this->plugin_dir, 'src/shared/*' );

		$this->assertFalse( $added );
		$this->assertSame( array( 'libs/*' ), $this->read_json( 'package.json' )['workspaces'] );
	}

	/**
	 * @param string $name The manifest's file name.
	 * @return array<string, mixed>
	 */
	private function read_json( string $name ): array {
		return (array) json_decode( (string) file_get_contents( $this->plugin_dir . '/' . $name ), true );
	}
}
