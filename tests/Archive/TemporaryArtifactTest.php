<?php
declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Archive;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;

final class TemporaryArtifactTest extends TestCase {

	private string $path;
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
	protected function setUp(): void {
		$this->path = tempnam( sys_get_temp_dir(), 'ran-core-artifact-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
		chmod( $this->path, 0600 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for archive custody and identity fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $this->path, 'artifact' ); }
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
	protected function tearDown(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		@unlink( $this->path ); }
	public function test_inspect_rejects_mutation(): void {
		$artifact = new TemporaryArtifact( $this->path, hash_file( 'sha256', $this->path ), $this->identity() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for archive custody and identity fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $this->path, 'changed' );
		$this->expectException( \RuntimeException::class );
		$artifact->inspect( static fn( string $path ): string => $path ); }
	/** @return array<string,int> */
	private function identity(): array {
		$stat = lstat( $this->path );
		self::assertIsArray( $stat );
		return array(
			'dev'   => (int) $stat['dev'],
			'ino'   => (int) $stat['ino'],
			'mode'  => (int) $stat['mode'],
			'nlink' => (int) $stat['nlink'],
			'uid'   => (int) $stat['uid'],
			'gid'   => (int) $stat['gid'],
			'size'  => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
			'ctime' => (int) $stat['ctime'],
		); }
}
