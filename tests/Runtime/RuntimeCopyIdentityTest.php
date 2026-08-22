<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Runtime;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RuntimeCopyIdentityTest extends TestCase
{
	private string $parent;

	protected function setUp(): void
	{
		$this->parent = sys_get_temp_dir() . '/neutral-runtime-copy-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->parent, 0700, true );
	}

	protected function tearDown(): void
	{
		$this->remove( $this->parent );
	}

	public function testCheckedInRuntimeCopyClaimsTheCanonicalRuntimeContentIdentity(): void
	{
		$copy = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );

		self::assertSame( $this->identity( dirname( __DIR__, 2 ) ), $copy['package_revision'] );
	}

	public function testEqualVersionPackageShapedCopiesWithDivergentContentFailClosed(): void
	{
		$left  = $this->packageCopy( 'left' );
		$right = $this->packageCopy( 'right' );
		file_put_contents( $right . '/src/Runtime/RequestBroker.php', (string) file_get_contents( $right . '/src/Runtime/RequestBroker.php' ) . "\n// Divergent physical package copy.\n" );
		$this->writeRuntimeCopy( $right );

		$result = $this->probe( 'require $data["left"] . "/bootstrap.php"; require $data["right"] . "/bootstrap.php"; $result=$GLOBALS["ran_wp_release_updater_v1_broker"]->activate(array("php_version"=>"8.2.0","runtime_protocol"=>1,"wordpress_version"=>"6.8.0")); echo json_encode($result);', array( 'left' => $left, 'right' => $right ) );

		self::assertFalse( $result['loaded'] );
		self::assertSame( array( 'runtime_selection_inactive' ), array_column( $result['diagnostics'], 'code' ) );
	}

	private function packageCopy( string $name ): string
	{
		$root = $this->parent . '/' . $name;
		mkdir( $root, 0700, true );
		foreach ( array( 'bootstrap.php', 'runtime.php' ) as $file ) {
			copy( dirname( __DIR__, 2 ) . '/' . $file, $root . '/' . $file );
		}
		$this->copyDirectory( dirname( __DIR__, 2 ) . '/src', $root . '/src' );
		$this->writeRuntimeCopy( $root );

		return $root;
	}

	private function writeRuntimeCopy( string $root ): void
	{
		file_put_contents( $root . '/runtime-copy.json', json_encode( array( 'package_revision' => $this->identity( $root ), 'package_version' => '0.1.0-beta.1', 'php_floor' => '8.2.0', 'runtime_file' => 'runtime.php', 'runtime_protocol' => 1, 'wordpress_floor' => '6.5.0' ), JSON_THROW_ON_ERROR ) );
	}

	private function identity( string $root ): string
	{
		$files = array( 'bootstrap.php', 'runtime.php' );
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}
		sort( $files, SORT_STRING );
		$payload = '';
		foreach ( $files as $file ) {
			$payload .= $file . "\0" . hash_file( 'sha256', $root . '/' . $file ) . "\n";
		}

		return hash( 'sha256', $payload );
	}

	private function copyDirectory( string $source, string $destination ): void
	{
		mkdir( $destination, 0700, true );
		foreach ( scandir( $source ) ?: array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$from = $source . '/' . $name;
			$to   = $destination . '/' . $name;
			is_dir( $from ) ? $this->copyDirectory( $from, $to ) : copy( $from, $to );
		}
	}

	/** @param array<string, string> $data
	 * @return array<string, mixed>
	 */
	private function probe( string $body, array $data ): array
	{
		$file = $this->parent . '/probe-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents( $file, '<?php $data = ' . var_export( $data, true ) . '; ' . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );

		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function remove( string $path ): void
	{
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = $path . '/' . $name;
			is_dir( $child ) && ! is_link( $child ) ? $this->remove( $child ) : unlink( $child );
		}
		rmdir( $path );
	}
}
