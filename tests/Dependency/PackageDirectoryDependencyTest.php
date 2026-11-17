<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Dependency;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Dependency\PackageDirectory;

final class PackageDirectoryDependencyTest extends TestCase {

	public function test_published_folder_rule_preserves_case_and_its_length_boundary(): void {
		foreach ( array( 100, 101, 191 ) as $length ) {
			self::assertTrue( PackageDirectory::is_valid_name( str_repeat( 'A', $length ) ) );
		}
		self::assertFalse( PackageDirectory::is_valid_name( str_repeat( 'A', 192 ) ) );
		self::assertFalse( PackageDirectory::is_valid_name( '../Other' ) );
		self::assertSame( 'MiXeD', PackageDirectory::installed_slug( 'plugin', 'MiXeD/main.php' ) );
		self::assertSame( 'MiXeD', PackageDirectory::installation_slug( 'other', 'src/MiXeD' ) );
		self::assertSame( 'mixed', PackageDirectory::deployment_slug( 'other', 'src/MiXeD' ) );
	}

	public function test_generated_helpers_retain_namespace_only_parity_with_published_sources(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array( 'PackageDirectory', 'RepositoryRelativePath', 'InvalidRepositoryPathException' ) as $class ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Compare exact locked dependency bytes for namespace-only generation.
			$source = file_get_contents( $root . '/vendor/ran/updater-support/src/' . $class . '.php' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Compare the exact generated runtime copy with its published source.
			$generated = file_get_contents( $root . '/src/Dependency/' . $class . '.php' );
			self::assertIsString( $source );
			self::assertSame( str_replace( 'RAN\\UpdaterSupport\\V1', 'RAN\\WPReleaseUpdater\\V1\\Dependency', $source ), $generated );
		}
	}
}
