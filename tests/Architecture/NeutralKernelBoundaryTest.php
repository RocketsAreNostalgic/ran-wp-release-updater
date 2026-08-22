<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class NeutralKernelBoundaryTest extends TestCase {

	public function testEveryProductionFileExcludesProviderProtocolAndIdentityAssumptions(): void {
		$root = dirname( __DIR__, 2 );
		$files = glob( $root . '/src/{Archive,Contract,Runtime,WordPress}/*.php', GLOB_BRACE ) ?: array();
		self::assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$source = file_get_contents( $file );
			self::assertIsString( $source, $file );
			$source = str_replace( array( 'ran_wp_github_release_updater_v1_broker', 'ran_wp_github_release_updater_v1_has_registered_target' ), 'legacy_release_updater_marker', $source );
			self::assertDoesNotMatchRegularExpression(
				'/api\.github|github\.com|bitbucket|gitlab|downloads|authorization|bearer|private-token|job-token|wp_remote_|curl_|Requests::|sha-?1/i',
				$source,
				$file
			);
			self::assertDoesNotMatchRegularExpression( "/['\"](?:github|bitbucket|gitlab)['\"]/i", $source, $file );
		}
	}

	public function testNeutralKernelContainsNoProviderProtocolOrWordPressTransport(): void {
		$root = dirname( __DIR__, 2 );
		$files = array_merge(
			glob( $root . '/src/Contract/*.php' ) ?: array(),
			glob( $root . '/src/Runtime/*.php' ) ?: array()
		);

		self::assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$source = file_get_contents( $file );
			self::assertIsString( $source, $file );
			self::assertDoesNotMatchRegularExpression(
				'/api\.github|bitbucket|gitlab|downloads|authorization|bearer|private-token|job-token|wp_remote_|curl_|Requests::|sha-?1/i',
				$source,
				$file
			);
			self::assertDoesNotMatchRegularExpression( "/['\"]github['\"]/i", $source, $file );
			self::assertDoesNotMatchRegularExpression(
				'/\b(?:add|do)_action\s*\(|\b(?:add|apply)_filter\s*\(/',
				$source,
				$file
			);
		}
	}

	public function testRuntimeHasNoProviderCatalogueOrCompositionActivation(): void {
		$root = dirname( __DIR__, 2 );
		self::assertFileDoesNotExist( $root . '/runtime-catalogue.json' );
		self::assertFileDoesNotExist( $root . '/src/Runtime/Composition/Github.php' );
		self::assertStringNotContainsString( 'registerTarget', (string) file_get_contents( $root . '/src/Runtime/RequestBroker.php' ) );
	}

	public function testSelectedRuntimeEntrypointOwnsEveryLifecycleClass(): void {
		$root = dirname( __DIR__, 2 );
		require $root . '/runtime.php';
		foreach ( array(
			'RAN\\WPReleaseUpdater\\V1\\Contract\\CanonicalUpdateUri',
			'RAN\\WPReleaseUpdater\\V1\\Contract\\IdentityDescriptor',
			'RAN\\WPReleaseUpdater\\V1\\Contract\\ReleaseVersion',
			'RAN\\WPReleaseUpdater\\V1\\Contract\\BindingRecord',
			'RAN\\WPReleaseUpdater\\V1\\Contract\\ReleaseAdapter',
			'RAN\\WPReleaseUpdater\\V1\\Archive\\ValidatedPackage',
			'RAN\\WPReleaseUpdater\\V1\\Archive\\TemporaryArtifact',
			'RAN\\WPReleaseUpdater\\V1\\Archive\\PackageIdentityValidator',
			'RAN\\WPReleaseUpdater\\V1\\WordPress\\ReleaseOperationCoordinator',
			'RAN\\WPReleaseUpdater\\V1\\Contract\\AcquisitionReceipt',
			'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePluginUpdater',
			'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubCredentialResolver',
			'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseAdapter',
		) as $class ) {
			$levels = str_starts_with( $class, 'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\' ) ? 4 : 3;
			self::assertSame( $root, dirname( ( new \ReflectionClass( $class ) )->getFileName(), $levels ), $class );
		}
	}

	public function testGitHubProtocolIsConfinedToTheSelectedProviderDirectory(): void {
		$root = dirname( __DIR__, 2 );
		$provider = $root . '/src/Provider/GitHub';
		self::assertFileExists( $provider . '/GitHubCredentialResolver.php' );
		self::assertFileExists( $provider . '/GitHubReleaseAdapter.php' );
		self::assertFileDoesNotExist( $provider . '/GitHubTemporaryArtifact.php' );
		self::assertFileExists( $root . '/src/Archive/TemporaryArtifact.php' );
		foreach ( glob( $root . '/src/{Archive,Contract,Runtime,WordPress}/*.php', GLOB_BRACE ) ?: array() as $file ) {
			self::assertStringNotContainsString( 'GitHub', (string) file_get_contents( $file ), $file );
		}
	}
}
