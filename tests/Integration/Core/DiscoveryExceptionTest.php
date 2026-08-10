<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\Cron\Cron;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The message every module raises for a root it was told to read.
 *
 * One sentence, composed in one place. It used to be composed at each of the
 * fourteen throw sites, which is a sentence in fourteen places and eventually a
 * sentence in thirteen wrong ones -- and none of the fourteen said what to do
 * about it.
 *
 * @covers \Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException
 */
final class DiscoveryExceptionTest extends TestCase {

	public function test_names_the_directory_it_looked_in(): void {
		$exception = DiscoveryException::missing_root( 'Schedules', '/srv/acme/jobs', 'set_schedules_root()' );

		$this->assertStringContainsString( 'Schedules root directory does not exist', $exception->getMessage() );
		$this->assertStringContainsString( '/srv/acme/jobs', $exception->getMessage() );
	}

	/**
	 * The part worth having. A path alone says what is wrong and leaves the
	 * reader to work out that they wrote it themselves, in an initializer, in
	 * another file.
	 */
	public function test_names_the_setter_that_asked_for_it_and_what_to_do(): void {
		$message = DiscoveryException::missing_root( 'Schedules', '/srv/acme/jobs', 'set_schedules_root()' )->getMessage();

		$this->assertStringContainsString( 'set_schedules_root()', $message );
		$this->assertStringContainsString( 'create that directory', $message );
	}

	/**
	 * Reaching this at all means someone named the path. Saying so is what stops
	 * a reader concluding that any absent directory is fatal, and going off to
	 * create nine empty ones.
	 */
	public function test_says_a_default_root_is_not_this_error(): void {
		$message = DiscoveryException::missing_root( 'Actions', '/srv/acme/actions', 'set_actions_root()' )->getMessage();

		$this->assertStringContainsString( 'default root that is absent is not an error', $message );
	}

	/**
	 * Reached through a real module rather than only constructed, so the
	 * factory cannot drift away from the guards that call it.
	 */
	public function test_a_module_raises_it_for_a_root_it_was_told_to_read(): void {
		$this->plugin->configure(
			Cron::class,
			static function ( Cron $cron ): void {
				$cron->set_schedules_root( 'jobs-that-are-not-there' );
			}
		);

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'set_schedules_root()' );

		$this->plugin->get( Cron::class );
	}
}
