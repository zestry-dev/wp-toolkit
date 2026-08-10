<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\Manifest;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `zestry.lock.json`: telling a local edit from an upstream change.
 *
 * The whole reason the manifest exists is that "this file differs from the
 * current toolkit" cannot say *which side* moved, and the two call for opposite
 * responses -- take the upstream change, keep the local edit. Every case here is
 * one cell of that two-by-two.
 *
 * @covers \Zestry\WPToolkit\DevTools\Manifest
 */
final class ManifestTest extends TestCase {

	private Manifest $manifest;

	public function set_up(): void {
		parent::set_up();

		$this->manifest = $this->plugin->get( Manifest::class );
	}

	public function test_a_project_with_no_manifest_reads_as_empty_rather_than_failing(): void {
		// A plugin initialized before manifests existed has none, and every
		// command that reads one has to keep working for it.
		$this->assertFalse( $this->manifest->exists( $this->plugin_dir ) );
		$this->assertSame( array(), $this->manifest->read( $this->plugin_dir )['files'] );
	}

	public function test_a_file_matching_both_sides_is_unchanged(): void {
		$this->record_file( 'lib/Core/Kernel/Plugin.php', 'original' );

		$statuses = $this->manifest->compare( $this->plugin_dir, $this->render( 'lib/Core/Kernel/Plugin.php', 'original' ) );

		$this->assertSame( Manifest::UNCHANGED, $statuses['lib/Core/Kernel/Plugin.php'] );
	}

	/**
	 * The disk moved and the toolkit did not: the consumer's own work, which an
	 * update has nothing to offer and must not discard.
	 */
	public function test_a_file_changed_only_on_disk_is_edited(): void {
		$this->record_file( 'lib/Core/Kernel/Plugin.php', 'original' );
		file_put_contents( $this->plugin_dir . '/lib/Core/Kernel/Plugin.php', 'hand-edited' );

		$statuses = $this->manifest->compare( $this->plugin_dir, $this->render( 'lib/Core/Kernel/Plugin.php', 'original' ) );

		$this->assertSame( Manifest::EDITED, $statuses['lib/Core/Kernel/Plugin.php'] );
	}

	/**
	 * The toolkit moved and the disk did not: the update the consumer came for,
	 * and taking it costs nothing.
	 */
	public function test_a_file_changed_only_upstream_is_an_update(): void {
		$this->record_file( 'lib/Core/Kernel/Plugin.php', 'original' );

		$statuses = $this->manifest->compare( $this->plugin_dir, $this->render( 'lib/Core/Kernel/Plugin.php', 'a later release' ) );

		$this->assertSame( Manifest::UPSTREAM, $statuses['lib/Core/Kernel/Plugin.php'] );
	}

	/**
	 * Both moved. The only case needing a decision, and the one a comparison
	 * against the current toolkit alone reports identically to the other two.
	 */
	public function test_a_file_changed_on_both_sides_is_a_conflict(): void {
		$this->record_file( 'lib/Core/Kernel/Plugin.php', 'original' );
		file_put_contents( $this->plugin_dir . '/lib/Core/Kernel/Plugin.php', 'hand-edited' );

		$statuses = $this->manifest->compare( $this->plugin_dir, $this->render( 'lib/Core/Kernel/Plugin.php', 'a later release' ) );

		$this->assertSame( Manifest::CONFLICT, $statuses['lib/Core/Kernel/Plugin.php'] );
	}

	public function test_a_recorded_file_that_was_deleted_is_missing(): void {
		$this->record_file( 'lib/Core/Kernel/Plugin.php', 'original' );
		unlink( $this->plugin_dir . '/lib/Core/Kernel/Plugin.php' );

		$statuses = $this->manifest->compare( $this->plugin_dir, $this->render( 'lib/Core/Kernel/Plugin.php', 'original' ) );

		$this->assertSame( Manifest::MISSING, $statuses['lib/Core/Kernel/Plugin.php'] );
	}

	/**
	 * A file the toolkit would write that this plugin has never had: a class
	 * added upstream since the copy, which is an update rather than a surprise.
	 */
	public function test_a_file_the_toolkit_gained_since_the_copy_is_an_update(): void {
		$this->record_file( 'lib/Core/Kernel/Plugin.php', 'original' );

		$statuses = $this->manifest->compare(
			$this->plugin_dir,
			$this->render( 'lib/Core/Kernel/Plugin.php', 'original' )
				+ $this->render( 'lib/Core/Kernel/Something.php', 'new upstream' )
		);

		$this->assertSame( Manifest::UPSTREAM, $statuses['lib/Core/Kernel/Something.php'] );
	}

	/**
	 * `add` copies one module at a time, so a run that recorded only its own
	 * files would drop every module added before it -- and the next update would
	 * report those as never copied at all.
	 */
	public function test_recording_merges_into_what_earlier_runs_wrote(): void {
		$this->record_file( 'lib/Core/Kernel/Plugin.php', 'original' );
		$this->record_file( 'lib/Core/Modules/Cron/Cron.php', 'original' );

		$files = $this->manifest->read( $this->plugin_dir )['files'];

		$this->assertArrayHasKey( 'lib/Core/Kernel/Plugin.php', $files );
		$this->assertArrayHasKey( 'lib/Core/Modules/Cron/Cron.php', $files );
	}

	public function test_the_manifest_is_written_beside_zestry_json(): void {
		$this->record_file( 'lib/Core/Kernel/Plugin.php', 'original' );

		$this->assertFileExists( $this->plugin_dir . '/zestry.lock.json' );

		$decoded = json_decode( (string) file_get_contents( $this->plugin_dir . '/zestry.lock.json' ), true );
		$this->assertArrayHasKey( 'version', $decoded );
		$this->assertArrayHasKey( 'files', $decoded );
	}

	/**
	 * Write a file and record it, exactly as a copy would.
	 *
	 * @param string $relative Plugin-relative path.
	 * @param string $contents What the copy wrote there.
	 * @return void
	 */
	private function record_file( string $relative, string $contents ): void {
		$absolute = $this->plugin_dir . '/' . $relative;

		wp_mkdir_p( dirname( $absolute ) );
		file_put_contents( $absolute, $contents );

		$this->manifest->record( $this->plugin_dir, array( $absolute => hash( 'sha256', $contents ) ) );
	}

	/**
	 * What the toolkit would write now, in the shape compare() takes.
	 *
	 * @param string $relative Plugin-relative path.
	 * @param string $contents The bytes a copy would write there now.
	 * @return array<string, string>
	 */
	private function render( string $relative, string $contents ): array {
		return array( $relative => hash( 'sha256', $contents ) );
	}
}
