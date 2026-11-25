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
		$sha256 = hash_file( 'sha256', $this->path );
		self::assertIsString( $sha256 );
		$artifact = new TemporaryArtifact( $this->path, $sha256, $this->identity() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for archive custody and identity fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $this->path, 'changed' );
		$this->expectException( \RuntimeException::class );
		$artifact->inspect( static fn( string $path ): string => $path ); }

	public function test_symlink_swap_is_rejected_before_hashing(): void {
		$sha256 = hash_file( 'sha256', $this->path );
		self::assertIsString( $sha256 );
		$identity   = $this->identity();
		$target     = tempnam( sys_get_temp_dir(), 'ran-core-target-' );
		$script     = tempnam( sys_get_temp_dir(), 'ran-core-symlink-probe-' );
		$facts_file = tempnam( sys_get_temp_dir(), 'ran-core-symlink-facts-' );
		self::assertIsString( $target );
		self::assertIsString( $script );
		self::assertIsString( $facts_file );
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for archive custody and identity fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $target, 'artifact' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
			chmod( $target, 0600 );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode exact fixture facts for an isolated process; WordPress JSON filters must not alter fixture bytes.
			$encoded = json_encode(
				array(
					'bootstrap' => dirname( __DIR__ ) . '/bootstrap.php',
					'identity'  => $identity,
					'path'      => $this->path,
					'sha256'    => $sha256,
					'target'    => $target,
				),
				JSON_THROW_ON_ERROR
			);
			$fixture = <<<'PHP'
<?php
namespace RAN\WPReleaseUpdater\V1\Archive {
	function hash_file( string $algorithm, string $filename, bool $binary = false ): string|false {
		++$GLOBALS['ran_temp_artifact_hash_calls'];
		return \hash_file( $algorithm, $filename, $binary );
	}
}
namespace {
	$ran_facts = json_decode( (string) file_get_contents( $argv[1] ), true, 64, JSON_THROW_ON_ERROR );
	require $ran_facts['bootstrap'];
	$GLOBALS['ran_temp_artifact_hash_calls'] = 0;
	$ran_artifact                            = new \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact(
		$ran_facts['path'],
		$ran_facts['sha256'],
		$ran_facts['identity']
	);
	$GLOBALS['ran_temp_artifact_hash_calls'] = 0;
	if ( ! unlink( $ran_facts['path'] ) || ! symlink( $ran_facts['target'], $ran_facts['path'] ) ) {
		fwrite( STDERR, "Failed to replace fixture with symlink.\n" );
		exit( 1 );
	}
	clearstatcache( true, $ran_facts['path'] );
	if ( ! is_link( $ran_facts['path'] ) ) {
		fwrite( STDERR, "Fixture path is not a symlink.\n" );
		exit( 1 );
	}
	$ran_threw                               = false;
	try {
		$ran_artifact->inspect( static fn ( string $path ): string => $path );
	} catch ( \RuntimeException ) {
		$ran_threw = true;
	}
	echo json_encode(
		array(
			'hash_calls' => $GLOBALS['ran_temp_artifact_hash_calls'],
			'threw'      => $ran_threw,
		),
		JSON_THROW_ON_ERROR
	);
}
PHP;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write isolated subprocess script for native hash interception verification.
			file_put_contents( $script, $fixture );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact fixture input bytes for the native subprocess.
			file_put_contents( $facts_file, $encoded );
			$output = array();
			$status = 0;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- A separate native PHP process isolates the namespaced hash_file test seam from production and other test symbol worlds.
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $facts_file ), $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			$result = json_decode( implode( "\n", $output ), true, 64, JSON_THROW_ON_ERROR );
			self::assertIsArray( $result );
			self::assertTrue( $result['threw'] );
			self::assertSame( 0, $result['hash_calls'] );
		} finally {
			foreach ( array( $target, $script, $facts_file ) as $cleanup_path ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario.
				@unlink( $cleanup_path );
			}
		}
	}

	public function test_inspect_accepts_non_private_file_permissions_without_rehashing(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set non-0600 fixture permission bits to verify ACL and setgid host compatibility.
		chmod( $this->path, 0644 );
		clearstatcache( true, $this->path );
		$sha256 = hash_file( 'sha256', $this->path );
		self::assertIsString( $sha256 );
		$artifact = new TemporaryArtifact( $this->path, $sha256, $this->identity() );
		self::assertSame( $this->path, $artifact->inspect( static fn( string $path ): string => $path ) );
	}
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
