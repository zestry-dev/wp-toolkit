<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The docs build's guard against an import naming a class that does not exist.
 *
 * `zestry_verify_examples()` resolves `$variable->method()` calls but never reads a
 * `use` statement, so when copied source gained its `Core/` segment every stub
 * and example kept importing the pre-rename spelling and the build stayed
 * green. Fifteen dead imports shipped, one of them a parameter type
 * declaration. This pins the guard that closed that gap.
 *
 * Asserted against the real tree rather than fixtures: the property under test
 * is that *this repository's* stubs and examples resolve, which is exactly what
 * regressed.
 *
 * @coversNothing
 */
final class ImportVerificationTest extends TestCase {

	/**
	 * Everything the stubs and docblock examples name has to exist.
	 */
	public function test_every_example_and_stub_import_resolves(): void {
		$this->assertSame(
			array(),
			zestry_verify_imports( $this->repository_root() ),
			'Every toolkit class named in a stub, a docblock example or a hand-written page must exist.'
		);
	}

	/**
	 * A copied-root reference to a class that is not there is reported.
	 */
	public function test_reports_a_reference_to_a_class_that_does_not_exist(): void {
		$problems = $this->verify_line( 'use Acme\\Plugin\\Core\\Kernel\\Abstracts\\Servize;' );

		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'does not exist', $problems[0] );
	}

	/**
	 * The failure that actually shipped: toolkit source without `Core`.
	 *
	 * The spelling is valid PHP and names nothing, so only knowing that the same
	 * class exists in this package can tell it apart from a consumer's own file.
	 */
	public function test_reports_toolkit_source_imported_without_the_copied_segment(): void {
		$problems = $this->verify_line( 'use Acme\\Plugin\\Modules\\Ajax\\AjaxAction;' );

		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'toolkit source', $problems[0] );
		$this->assertStringContainsString( 'Acme\\Plugin\\Core\\Modules\\Ajax\\AjaxAction', $problems[0] );
	}

	/**
	 * A consumer's own class through the plain root is left alone.
	 *
	 * `wp zt make service Mailer --dir=Modules/Services` legitimately writes
	 * this, and nothing here can know whether it exists.
	 */
	public function test_ignores_a_class_that_is_not_the_toolkit_s(): void {
		$this->assertSame( array(), $this->verify_line( 'use Acme\\Plugin\\Modules\\Services\\Mailer;' ) );
	}

	/**
	 * A namespace named in prose is not a class, and is not reported.
	 *
	 * `Zestry\WPToolkit\Modules\...` written mid-sentence reaches the check as `Zestry\WPToolkit\Modules`,
	 * which resolves to a directory rather than a file.
	 */
	public function test_ignores_a_namespace_mentioned_in_prose(): void {
		$this->assertSame( array(), $this->verify_line( 'each file declares its own `Zestry\\WPToolkit\\Modules\\...` namespace' ) );
	}

	/**
	 * Run the guard over one docblock line written into a throwaway file.
	 *
	 * @param string $line The docblock body to check.
	 * @return string[] Problems reported for it.
	 */
	private function verify_line( string $line ): array {
		$root = $this->repository_root();
		$file = $root . '/src/DevTools/__import_verification_fixture.php';

		file_put_contents( $file, "<?php\n\n/**\n * " . $line . "\n */\n" );

		try {
			$problems = zestry_verify_imports( $root );
		} finally {
			unlink( $file );
		}

		return array_values(
			array_filter(
				$problems,
				static function ( string $problem ): bool {
					return str_contains( $problem, '__import_verification_fixture' );
				}
			)
		);
	}

	private function repository_root(): string {
		return dirname( __DIR__, 3 );
	}

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once dirname( __DIR__, 3 ) . '/bin/docs/verify-examples.php';
	}
}
