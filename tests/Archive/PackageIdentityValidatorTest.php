<?php

declare(strict_types=1);

namespace Tests\Archive;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

final class PackageIdentityValidatorTest extends TestCase {

	/** @var list<string> */
	private array $archives = array();

	protected function tearDown(): void {
		foreach ( $this->archives as $archive ) if ( is_file( $archive ) ) unlink( $archive );
		parent::tearDown();
	}

	public function testAcceptsAnExactPluginAndThemeWithoutExtraction(): void {
		$plugin = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$theme = $this->archive( array( 'example-theme/style.css' => $this->header( 'Theme Name', 'Example Theme' ) ) );
		$validator = new PackageIdentityValidator();

		$result = $validator->validate( $this->descriptor( $plugin, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $plugin );
		self::assertTrue( $result->isValid() );
		self::assertSame( 'example-plugin/example-plugin.php', $result->toArray()['archive_root'] . '/' . $result->toArray()['header_file'] );
		self::assertTrue( $validator->validate( $this->descriptor( $theme, 'theme', 'example-theme' ), $this->policy( 'theme', 'example-theme', 'style.css', 'Example Theme' ), $theme )->isValid() );
	}

	public function testFailsClosedForDigestSizeUriTargetAndMetadataMismatches(): void {
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$validator = new PackageIdentityValidator(); $descriptor = $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ); $policy = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$facts = $descriptor->toArray(); unset( $facts['fingerprint'] );
		$badDigest = IdentityDescriptor::create( array_replace( $facts, array( 'artifact_sha256' => str_repeat( 'b', 64 ) ) ) );
		self::assertSame( 'archive_file_identity_mismatch', $validator->validate( $badDigest, $policy, $archive )->code() );
		self::assertSame( 'archive_target_policy_invalid', $validator->validate( $descriptor, array_replace( $policy, array( 'provider_code' => 'other' ) ), $archive )->code() );
		self::assertSame( 'archive_target_policy_invalid', $validator->validate( $descriptor, array_replace( $policy, array( 'archive_root' => 'other-root' ) ), $archive )->code() );
		self::assertSame( 'archive_target_policy_invalid', $validator->validate( $descriptor, array_replace( $policy, array( 'header_file' => 'other.php' ) ), $archive )->code() );
		self::assertSame( 'archive_target_policy_invalid', $validator->validate( $descriptor, array_replace( $policy, array( 'header_file' => 'main.css', 'installed_package_identity' => 'example-plugin/main.css' ) ), $archive )->code() );
		$wrongUri = $this->archive( array( 'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/other\n*/" ) );
		self::assertSame( 'archive_update_uri_mismatch', $validator->validate( $this->descriptor( $wrongUri, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $wrongUri )->code() );
		self::assertSame( 'archive_metadata_identity_mismatch', $validator->validate( $descriptor, array_replace( $policy, array( 'metadata_name' => 'Other Plugin' ) ), $archive )->code() );
	}

	public function testRejectsReplacementAfterArchiveOpenBeforeInspection(): void {
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$replacement = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Other Plugin' ) ) );
		$validator = new PackageIdentityValidator();
		$afterOpen = new \ReflectionProperty( $validator, 'afterOpen' );
		$afterOpen->setValue( $validator, static function ( string $path ) use ( $replacement ): void { copy( $replacement, $path ); } );
		$result = $validator->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( 'archive_file_identity_mismatch', $result->code() );
	}

	public function testReceiptProofCannotCrossDescriptorOrValidatorCloneAndOnlyConsumesOnce(): void {
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$validator = new PackageIdentityValidator(); $descriptor = $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' );
		$package = $validator->validate( $descriptor, $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		$facts = $descriptor->toArray(); unset( $facts['fingerprint'] ); $wrong = IdentityDescriptor::create( array_replace( $facts, array( 'artifact_sha256' => str_repeat( 'b', 64 ) ) ) );
		try { $validator->consumeReceiptProof( $package, $wrong ); self::fail( 'Wrong descriptor consumed the proof.' ); } catch ( \InvalidArgumentException ) { self::addToAssertionCount( 1 ); }
		self::assertSame( $descriptor->fingerprintValue(), $validator->consumeReceiptProof( $package, $descriptor )['descriptor_fingerprint'] );
		try { $validator->consumeReceiptProof( $package, $descriptor ); self::fail( 'Proof consumed twice.' ); } catch ( \InvalidArgumentException ) { self::addToAssertionCount( 1 ); }
		try { clone $validator; self::fail( 'Validator clone unexpectedly succeeded.' ); } catch ( \Error ) { self::addToAssertionCount( 1 ); }
	}

	public function testArchiveManifestIsCanonicalAcrossZipEntryOrder(): void {
		$header = $this->header( 'Plugin Name', 'Example Plugin' ); $first = $this->archive( array( 'example-plugin/example-plugin.php' => $header, 'example-plugin/payload.php' => '<?php return true;' ) ); $second = $this->archive( array( 'example-plugin/payload.php' => '<?php return true;', 'example-plugin/example-plugin.php' => $header ) ); $validator = new PackageIdentityValidator(); $policy = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$firstPackage = $validator->validate( $this->descriptor( $first, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $first ); $secondPackage = $validator->validate( $this->descriptor( $second, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $second );
		self::assertTrue( $firstPackage->isValid() ); self::assertTrue( $secondPackage->isValid() ); self::assertSame( $firstPackage->toArray()['manifest_hash'], $secondPackage->toArray()['manifest_hash'] ); self::assertSame( 2, $firstPackage->toArray()['manifest_entry_count'] ); self::assertSame( strlen( $header ) + strlen( '<?php return true;' ), $firstPackage->toArray()['manifest_expanded_bytes'] );
	}

	public function testRejectsDuplicateSemanticHeaders(): void {
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nUpdate URI: https://updates.example.test/owner/package\n*/" ) );
		$result = ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( 'archive_metadata_identity_mismatch', $result->code() );
		$conflict = $this->archive( array( 'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nUpdate URI: https://updates.example.test/other\n*/" ) );
		self::assertSame( 'archive_update_uri_mismatch', ( new PackageIdentityValidator() )->validate( $this->descriptor( $conflict, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $conflict )->code() );
	}

	/** @dataProvider unsafeArchives */
	public function testRejectsUnsafeOrAmbiguousArchiveShapes( array $entries, string $expected ): void {
		$archive = $this->archive( $entries );
		$result = ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( $expected, $result->code() );
	}

	/** @return array<string, array{array<string,string>,string}> */
	public static function unsafeArchives(): array {
		$header = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/";
		return array(
			'traversal' => array( array( 'example-plugin/example-plugin.php' => $header, 'example-plugin/../escape.php' => 'x' ), 'archive_path_unsafe' ),
			'wrong root' => array( array( 'other/example-plugin.php' => $header ), 'archive_root_mismatch' ),
			'case collision' => array( array( 'example-plugin/example-plugin.php' => $header, 'example-plugin/EXAMPLE-PLUGIN.PHP' => $header ), 'archive_path_duplicate' ),
		);
	}

	public function testRejectsSymlinkAndExcessiveEntryInventory(): void {
		$entries = array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ), 'example-plugin/link.php' => '../outside' );
		$archive = $this->archive( $entries, array( 'example-plugin/link.php' ) );
		$result = ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( 'archive_path_unsafe', $result->code() );
		$many = array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) );
		for ( $index = 1; $index <= 10000; ++$index ) $many['example-plugin/entry-' . $index] = '';
		$archive = $this->archive( $many );
		self::assertSame( 'archive_entry_limit', ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive )->code() );
	}

	/** @dataProvider archiveCompatibilityCases */
	public function testValidatesArchiveVersionAndOptionalRuntimeRequirements( string $header, string $expected ): void {
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $header ) );
		$result = ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( $expected, $result->code() );
	}

	/** @return array<string, array{string,string}> */
	public static function archiveCompatibilityCases(): array {
		$base = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: %s\nUpdate URI: https://updates.example.test/owner/package%s\n*/";
		return array(
			'ready with compatible floors' => array( sprintf( $base, '1.0', "\nRequires PHP: 8.1\nRequires at least: 6.7" ), 'archive_identity_verified' ),
			'version mismatch' => array( sprintf( $base, '1.0.1', '' ), 'archive_version_mismatch' ),
			'malformed version' => array( sprintf( $base, '1.0.0+build', '' ), 'archive_version_mismatch' ),
			'duplicate version' => array( "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/", 'archive_version_mismatch' ),
			'php floor incompatible' => array( sprintf( $base, '1.0.0', "\nRequires PHP: 8.3" ), 'archive_php_requirement_incompatible' ),
			'wordpress floor incompatible' => array( sprintf( $base, '1.0.0', "\nRequires at least: 6.9" ), 'archive_wordpress_requirement_incompatible' ),
			'malformed php floor' => array( sprintf( $base, '1.0.0', "\nRequires PHP: eight.two" ), 'archive_php_requirement_incompatible' ),
			'duplicate wordpress floor' => array( sprintf( $base, '1.0.0', "\nRequires at least: 6.7\nRequires at least: 6.7" ), 'archive_wordpress_requirement_incompatible' ),
		);
	}

	/** @param array<string,string> $entries @param list<string> $links */
	private function archive( array $entries, array $links = array() ): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-archive-' ); self::assertIsString( $path ); $this->archives[] = $path;
		$zip = new \ZipArchive(); self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		foreach ( $entries as $name => $contents ) self::assertTrue( $zip->addFromString( $name, $contents ) );
		foreach ( $links as $name ) self::assertTrue( $zip->setExternalAttributesName( $name, \ZipArchive::OPSYS_UNIX, 0120777 << 16 ) );
		$zip->close(); return $path;
	}

	private function header( string $kind, string $name ): string { return "<?php\n/*\n{$kind}: {$name}\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/"; }

	private function descriptor( string $path, string $type, string $identity ): IdentityDescriptor {
		return IdentityDescriptor::create( array( 'artifact_filename' => 'package.zip', 'artifact_identity' => 'asset:1', 'artifact_sha256' => hash_file( 'sha256', $path ), 'artifact_size' => filesize( $path ), 'assurance_facts' => array( 'exact_artifact_identity' => true, 'exact_commit_identity' => true, 'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true, 'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true ), 'canonical_update_uri' => 'https://updates.example.test/owner/package', 'channel' => 'stable', 'commit_identity' => 'commit:1', 'installed_package_identity' => $identity, 'prerelease' => false, 'provider_code' => 'neutral', 'release_identity' => 'release:1', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'tag' => 'v1.0.0', 'target_type' => $type, 'version' => '1.0.0' ) );
	}

	/** @return array<string,string> */
	private function policy( string $type, string $root, string $header, string $name ): array { return array( 'archive_root' => $root, 'configuration_update_uri' => 'https://updates.example.test/owner/package', 'header_file' => $header, 'installed_package_identity' => 'theme' === $type ? $root : $root . '/' . $header, 'metadata_name' => $name, 'offer_update_uri' => 'https://updates.example.test/owner/package', 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'staged_package_update_uri' => 'https://updates.example.test/owner/package', 'target_type' => $type, 'wordpress_runtime_version' => '6.8' ); }
}
