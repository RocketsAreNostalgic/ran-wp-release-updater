<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class RuntimeClassMapTest extends TestCase {

	/** Loaded by bootstrap.php from the elected root before runtime.php runs. */
	private const BOOTSTRAP_LOADED = array(
		'src/Runtime/RequestBroker.php',
		'src/Runtime/RequestProtocolValidator.php',
		'src/Runtime/RuntimeCopySelector.php',
		'src/Runtime/SelectedRuntimeState.php',
	);

	public function test_every_production_class_is_loaded_by_the_selected_runtime_or_the_bootstrap(): void {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository architecture assertions without requiring WordPress filesystem initialization.
		$runtime = (string) file_get_contents( $root . '/runtime.php' );
		preg_match_all( "/=>\\s*'(src\\/[^']+\\.php)'/", $runtime, $matches );
		$mapped = $matches[1];

		$actual   = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			if ( $entry instanceof \SplFileInfo && 'php' === $entry->getExtension() ) {
				$actual[] = str_replace( '\\', '/', substr( $entry->getPathname(), strlen( $root ) + 1 ) );
			}
		}
		$expected = array_values( array_diff( $actual, self::BOOTSTRAP_LOADED ) );
		sort( $expected );
		sort( $mapped );

		self::assertSame( $expected, $mapped, 'A production class missing from the runtime.php map has no autoloader in a bundled deployment.' );
		foreach ( self::BOOTSTRAP_LOADED as $file ) {
			self::assertFileExists( $root . '/' . $file );
		}
	}
}
