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

	public function testProspectiveAndInstalledPoliciesRejectAnArtifactOverTheirTargetLimit(): void {
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$size = filesize( $archive ); self::assertIsInt( $size );
		$prospective = $this->prospectivePolicy( $archive, 'plugin' ); $prospective['maximum_artifact_bytes'] = $size - 1;
		self::assertNull( ( new PackageIdentityValidator() )->inspectProspective( $prospective, $archive ) );
		$policy = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ); $policy['maximum_artifact_bytes'] = $size - 1;
		self::assertSame( 'archive_target_policy_invalid', ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $archive )->code() );
	}

	public function testArchivePathsShareCanonicalHeaderNormalization(): void
	{
		foreach ( array( "\r\n", "\r" ) as $lineEnding ) {
			foreach ( array( 'plugin', 'theme' ) as $type ) {
				$root = 'plugin' === $type ? 'example-plugin' : 'example-theme';
				$file = 'plugin' === $type ? 'example-plugin.php' : 'style.css';
				$nameLabel = 'plugin' === $type ? 'Plugin Name' : 'Theme Name';
				$name = 'plugin' === $type ? 'Example Plugin' : 'Example Theme';
				$header = str_replace(
					"\n",
					$lineEnding,
					"/*\n"
					. "{$nameLabel}: {$name} */ trailing\n"
					. "Version: 1.0.0 */\n"
					. "Update URI: https://updates.example.test/owner/package */\n"
					. "Requires PHP: 8.2\n"
					. "Requires at least: 6.8\n*/\n"
				);
				$archive = $this->archive( array( $root . '/' . $file => $header ) );
				$policy = $this->policy( $type, $root, $file, $name );

				self::assertSame(
					'installed_header_verified',
					PackageIdentityValidator::parseHeader( $header, $type )['code']
				);
				self::assertSame(
					array( 'package_root' => $root, 'main_file' => $file ),
					( new PackageIdentityValidator() )->inspectProspective(
						$this->prospectivePolicy( $archive, $type ),
						$archive
					)
				);
				self::assertTrue(
					( new PackageIdentityValidator() )->validate(
						$this->descriptor(
							$archive,
							$type,
							'theme' === $type ? $root : $root . '/' . $file
						),
						$policy,
						$archive
					)->isValid()
				);
			}
		}
	}

	public function testArchiveHeaderFailuresKeepArchiveFailureCodes(): void
	{
		$cases = array(
			'duplicate name' => array(
				"Plugin Name: Example Plugin\nPlugin Name: Example Plugin",
				'archive_metadata_identity_mismatch',
			),
			'control name' => array( "Plugin Name: Example\x01 Plugin", 'archive_metadata_identity_mismatch' ),
			'duplicate URI' => array(
				"Plugin Name: Example Plugin\n"
				. "Update URI: https://updates.example.test/owner/package\n"
				. "Update URI: https://updates.example.test/owner/package",
				'archive_update_uri_mismatch',
			),
			'control URI' => array(
				"Plugin Name: Example Plugin\n"
				. "Update URI: https://updates.example.test/owner/\x01package",
				'archive_update_uri_mismatch',
			),
		);
		foreach ( $cases as $case => list( $headers, $expected ) ) {
			$header = "<?php\n/*\n{$headers}\nVersion: 1.0.0\n"
				. "Update URI: https://updates.example.test/owner/package\n*/";
			$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $header ) );
			self::assertNotSame(
				'installed_header_verified',
				PackageIdentityValidator::parseHeader( $header, 'plugin' )['code'],
				$case
			);
			self::assertSame(
				$expected,
				( new PackageIdentityValidator() )->validate(
					$this->descriptor(
						$archive,
						'plugin',
						'example-plugin/example-plugin.php'
					),
					$this->policy(
						'plugin',
						'example-plugin',
						'example-plugin.php',
						'Example Plugin'
					),
					$archive
				)->code(),
				$case
			);
		}
	}

	public function testProspectiveInspectionDiscoversOneSafePluginOrThemeHeader(): void {
		$plugin = $this->archive( array( 'example-plugin/loader.php' => '<?php return true;', 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$theme = $this->archive( array( 'example-theme/style.css' => $this->header( 'Theme Name', 'Example Theme' ) ) );
		$validator = new PackageIdentityValidator();
		self::assertSame( array( 'package_root' => 'example-plugin', 'main_file' => 'example-plugin.php' ), $validator->inspectProspective( $this->prospectivePolicy( $plugin, 'plugin' ), $plugin ) );
		self::assertSame( array( 'package_root' => 'example-theme', 'main_file' => 'style.css' ), $validator->inspectProspective( $this->prospectivePolicy( $theme, 'theme' ), $theme ) );
	}

	public function testProspectiveInspectionRejectsAmbiguousPluginHeaders(): void {
		$archive = $this->archive( array( 'example-plugin/a.php' => $this->header( 'Plugin Name', 'Example Plugin' ), 'example-plugin/b.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		self::assertNull( ( new PackageIdentityValidator() )->inspectProspective( $this->prospectivePolicy( $archive, 'plugin' ), $archive ) );
	}

	/** @dataProvider prospectiveUnsafeArchives */
	public function testProspectiveInspectionRejectsUnsafeAndAmbiguousShapes(
		array $entries,
		array $links = array()
	): void {
		$archive = $this->archive( $entries, $links );
		self::assertNull( $this->prospectivePlugin( $archive ) );
	}

	/** @return array<string,array{0:array<string,string>,1?:list<string>}> */
	public static function prospectiveUnsafeArchives(): array {
		$header = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/";
		return array(
			'multiple roots' => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'other/payload.php' => '<?php return true;',
				),
			),
			'unsafe path' => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/../escape.php' => '<?php return true;',
				),
			),
			'duplicate case path' => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/EXAMPLE-PLUGIN.PHP' => '<?php return true;',
				),
			),
			'special entry' => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/link.php' => '../outside',
				),
				array( 'example-plugin/link.php' ),
			),
		);
	}

	public function testProspectiveInspectionRejectsEntryLimitAndHeaderMismatches(): void {
		$many = array(
			'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
		);
		for ( $index = 1; $index <= 10000; ++$index ) {
			$many[ 'example-plugin/entry-' . $index ] = '';
		}
		self::assertNull( $this->prospectivePlugin( $this->archive( $many ) ) );

		foreach (
			array(
				'missing PHP requirement' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires at least: 6.8\n*/",
				'missing WordPress requirement' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires PHP: 8.2\n*/",
				'URI mismatch' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/other\n*/",
				'version mismatch' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.1\nUpdate URI: https://updates.example.test/owner/package\n*/",
				'PHP mismatch' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires PHP: 8.3\n*/",
				'WordPress mismatch' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires at least: 6.9\n*/",
			) as $header
		) {
			$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $header ) );
			self::assertNull( $this->prospectivePlugin( $archive ) );
		}
	}

	public function testProspectiveInspectionKeepsCrossTypeHeadersAndRejectsReplacement(): void {
		$archive = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/style.css' => $this->header( 'Theme Name', 'Example Theme' ),
			)
		);
		self::assertSame(
			array( 'package_root' => 'example-plugin', 'main_file' => 'example-plugin.php' ),
			$this->prospectivePlugin( $archive )
		);

		$archive = $this->archive(
			array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) )
		);
		$replacement = $this->archive(
			array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Other Plugin' ) )
		);
		$validator = new PackageIdentityValidator();
		$afterOpen = new \ReflectionProperty( $validator, 'afterOpen' );
		$afterOpen->setValue(
			$validator,
			static function ( string $path ) use ( $replacement ): void {
				copy( $replacement, $path );
			}
		);
		self::assertNull(
			$validator->inspectProspective(
				$this->prospectivePolicy( $archive, 'plugin' ),
				$archive
			)
		);
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

	public function testArchiveValidationPermitsAbsentRequirementsAndRejectsPostClosingDuplicates(): void
	{
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$root = 'plugin' === $type ? 'example-plugin' : 'example-theme';
			$file = 'plugin' === $type ? 'example-plugin.php' : 'style.css';
			$name = 'plugin' === $type ? 'Example Plugin' : 'Example Theme';
			$kind = 'plugin' === $type ? 'Plugin Name' : 'Theme Name';
			$header = "<?php\n/*\n{$kind}: {$name}\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/";
			$archive = $this->archive( array( $root . '/' . $file => $header ) );
			$descriptor = $this->descriptor( $archive, $type, 'theme' === $type ? $root : $root . '/' . $file );
			$policy = $this->policy( $type, $root, $file, $name );
			self::assertTrue( ( new PackageIdentityValidator() )->validate( $descriptor, $policy, $archive )->isValid() );

			$duplicate = $this->archive( array( $root . '/' . $file => $header . "\nVersion: 1.0.0" ) );
			$descriptor = $this->descriptor( $duplicate, $type, 'theme' === $type ? $root : $root . '/' . $file );
			self::assertSame( 'archive_version_mismatch', ( new PackageIdentityValidator() )->validate( $descriptor, $policy, $duplicate )->code() );
		}
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

	private function header( string $kind, string $name ): string { return "<?php\n/*\n{$kind}: {$name}\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires PHP: 8.2\nRequires at least: 6.8\n*/"; }

	private function descriptor( string $path, string $type, string $identity ): IdentityDescriptor {
		return IdentityDescriptor::create( array( 'artifact_filename' => 'package.zip', 'artifact_identity' => 'asset:1', 'artifact_sha256' => hash_file( 'sha256', $path ), 'artifact_size' => filesize( $path ), 'assurance_facts' => array( 'exact_artifact_identity' => true, 'exact_commit_identity' => true, 'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true, 'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true ), 'canonical_update_uri' => 'https://updates.example.test/owner/package', 'channel' => 'stable', 'commit_identity' => 'commit:1', 'installed_package_identity' => $identity, 'prerelease' => false, 'provider_code' => 'neutral', 'release_identity' => 'release:1', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'tag' => 'v1.0.0', 'target_type' => $type, 'version' => '1.0.0' ) );
	}

	/** @return array{package_root:string,main_file:string}|null */
	private function prospectivePlugin( string $archive ): ?array {
		return ( new PackageIdentityValidator() )->inspectProspective(
			$this->prospectivePolicy( $archive, 'plugin' ),
			$archive
		);
	}

	/** @return array<string,mixed> */
	private function prospectivePolicy( string $archive, string $type ): array {
		return array(
			'artifact_sha256' => hash_file( 'sha256', $archive ),
			'artifact_size' => filesize( $archive ),
			'canonical_update_uri' => 'https://updates.example.test/owner/package',
			'maximum_artifact_bytes' => 52_428_800,
			'php_runtime_version' => '8.2',
			'target_type' => $type,
			'version' => '1.0.0',
			'wordpress_runtime_version' => '6.8',
		);
	}

	/** @return array<string,string> */
	private function policy( string $type, string $root, string $header, string $name ): array { return array( 'archive_root' => $root, 'configuration_update_uri' => 'https://updates.example.test/owner/package', 'header_file' => $header, 'installed_package_identity' => 'theme' === $type ? $root : $root . '/' . $header, 'maximum_artifact_bytes' => 52_428_800, 'metadata_name' => $name, 'offer_update_uri' => 'https://updates.example.test/owner/package', 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'staged_package_update_uri' => 'https://updates.example.test/owner/package', 'target_type' => $type, 'wordpress_runtime_version' => '6.8' ); }
}
