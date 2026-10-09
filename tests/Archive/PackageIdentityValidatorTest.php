<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Archive;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

final class PackageIdentityValidatorTest extends TestCase {

	/** @var list<string> */
	private array $archives = array();

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
	protected function tearDown(): void {
		foreach ( $this->archives as $archive ) {
			if ( is_file( $archive ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
				unlink( $archive );
			}
		}
		parent::tearDown();
	}

	public function test_accepts_an_exact_plugin_and_theme_without_extraction(): void {
		$plugin    = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$theme     = $this->archive( array( 'example-theme/style.css' => $this->header( 'Theme Name', 'Example Theme' ) ) );
		$validator = new PackageIdentityValidator( after_open: null );

		$result = $validator->validate( $this->descriptor( $plugin, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), archive_path: $plugin );
		self::assertTrue( $result->is_valid() );
		$zip = new \ZipArchive();
		self::assertTrue( $zip->open( $plugin ) );
		try {
			self::assertTrue( \RAN\WPReleaseUpdater\V1\Archive\ArchiveScanner::scan( $zip, expected_root: 'example-plugin' )->is_valid() );
			self::assertFalse( \RAN\WPReleaseUpdater\V1\Archive\ArchiveScanner::scan( $zip, expected_root: 'other-root' )->is_valid() );
		} finally {
			$zip->close();
		}
		self::assertSame( 'example-plugin/example-plugin.php', $result->to_array()['archive_root'] . '/' . $result->to_array()['header_file'] );
		self::assertTrue( $validator->validate( $this->descriptor( $theme, 'theme', 'example-theme' ), $this->policy( 'theme', 'example-theme', 'style.css', 'Example Theme' ), $theme )->is_valid() );
	}

	public function test_theme_template_must_exactly_preserve_child_or_standalone_identity(): void {
		$child      = $this->archive( array( 'child/style.css' => $this->header( 'Theme Name', 'Child Theme', "Template: parent\n" ) ) );
		$validator  = new PackageIdentityValidator();
		$descriptor = $this->descriptor( $child, 'theme', 'child' );
		$policy     = $this->policy( 'theme', 'child', 'style.css', 'Child Theme', 'parent' );
		self::assertTrue( $validator->validate( $descriptor, $policy, $child )->is_valid() );
		foreach ( array( '', 'other-parent' ) as $template ) {
			$candidate = $this->archive( array( 'child/style.css' => $this->header( 'Theme Name', 'Child Theme', '' === $template ? '' : "Template: {$template}\n" ) ) );
			self::assertSame( 'archive_metadata_identity_mismatch', $validator->validate( $this->descriptor( $candidate, 'theme', 'child' ), $policy, $candidate )->code() );
		}
		$standalone        = $this->archive( array( 'standalone/style.css' => $this->header( 'Theme Name', 'Standalone' ) ) );
		$standalone_policy = $this->policy( 'theme', 'standalone', 'style.css', 'Standalone' );
		self::assertTrue( $validator->validate( $this->descriptor( $standalone, 'theme', 'standalone' ), $standalone_policy, $standalone )->is_valid() );
		self::assertSame( 'archive_metadata_identity_mismatch', $validator->validate( $this->descriptor( $standalone, 'theme', 'standalone' ), array_replace( $standalone_policy, array( 'theme_template' => 'parent' ) ), $standalone )->code() );
	}

	public function test_prospective_and_installed_policies_reject_an_artifact_over_their_target_limit(): void {
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$size    = filesize( $archive );
		self::assertIsInt( $size );
		$prospective                           = $this->prospective_policy( $archive, 'plugin' );
		$prospective['maximum_artifact_bytes'] = $size - 1;
		self::assertNull( ( new PackageIdentityValidator() )->inspect_prospective( $prospective, $archive ) );
		$policy                           = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$policy['maximum_artifact_bytes'] = $size - 1;
		self::assertSame( 'archive_target_policy_invalid', ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $archive )->code() );
	}

	public function test_archive_paths_share_canonical_header_normalization(): void {
		foreach ( array( "\r\n", "\r" ) as $line_ending ) {
			foreach ( array( 'plugin', 'theme' ) as $type ) {
				$root       = 'plugin' === $type ? 'example-plugin' : 'example-theme';
				$file       = 'plugin' === $type ? 'example-plugin.php' : 'style.css';
				$name_label = 'plugin' === $type ? 'Plugin Name' : 'Theme Name';
				$name       = 'plugin' === $type ? 'Example Plugin' : 'Example Theme';
				$header     = str_replace(
					"\n",
					$line_ending,
					"/*\n"
					. "{$name_label}: {$name} */ trailing\n"
					. "Version: 1.0.0 */\n"
					. "Update URI: https://updates.example.test/owner/package */\n"
					. "Requires PHP: 8.2\n"
					. "Requires at least: 6.8\n*/\n"
				);
				$archive    = $this->archive( array( $root . '/' . $file => $header ) );
				$policy     = $this->policy( $type, $root, $file, $name );

				self::assertSame(
					'installed_header_verified',
					PackageIdentityValidator::parse_header( $header, $type )['code']
				);
				self::assertSame(
					array(
						'package_root' => $root,
						'main_file'    => $file,
					),
					( new PackageIdentityValidator() )->inspect_prospective(
						$this->prospective_policy( $archive, $type ),
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
					)->is_valid()
				);
			}
		}
	}

	public function test_archive_header_failures_keep_archive_failure_codes(): void {
		$cases = array(
			'duplicate name' => array(
				"Plugin Name: Example Plugin\nPlugin Name: Example Plugin",
				'archive_metadata_identity_mismatch',
			),
			'control name'   => array( "Plugin Name: Example\x01 Plugin", 'archive_metadata_identity_mismatch' ),
			'duplicate URI'  => array(
				"Plugin Name: Example Plugin\n"
				. "Update URI: https://updates.example.test/owner/package\n"
				. 'Update URI: https://updates.example.test/owner/package',
				'archive_update_uri_mismatch',
			),
			'control URI'    => array(
				"Plugin Name: Example Plugin\n"
				. "Update URI: https://updates.example.test/owner/\x01package",
				'archive_update_uri_mismatch',
			),
		);
		foreach ( $cases as $case => list( $headers, $expected ) ) {
			$header  = "<?php\n/*\n{$headers}\nVersion: 1.0.0\n"
				. "Update URI: https://updates.example.test/owner/package\n*/";
			$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $header ) );
			self::assertNotSame(
				'installed_header_verified',
				PackageIdentityValidator::parse_header( $header, 'plugin' )['code'],
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

	public function test_prospective_inspection_discovers_one_safe_plugin_or_theme_header(): void {
		$plugin    = $this->archive(
			array(
				'example-plugin/loader.php'         => '<?php return true;',
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
			)
		);
		$theme     = $this->archive( array( 'example-theme/style.css' => $this->header( 'Theme Name', 'Example Theme' ) ) );
		$validator = new PackageIdentityValidator();
		self::assertSame(
			array(
				'package_root' => 'example-plugin',
				'main_file'    => 'example-plugin.php',
			),
			$validator->inspect_prospective( $this->prospective_policy( $plugin, 'plugin' ), archive_path: $plugin )
		);
		self::assertSame(
			array(
				'package_root' => 'example-theme',
				'main_file'    => 'style.css',
			),
			$validator->inspect_prospective( $this->prospective_policy( $theme, 'theme' ), $theme )
		);
	}

	public function test_prospective_inspection_rejects_ambiguous_plugin_headers(): void {
		$archive = $this->archive(
			array(
				'example-plugin/a.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/b.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
			)
		);
		self::assertNull( ( new PackageIdentityValidator() )->inspect_prospective( $this->prospective_policy( $archive, 'plugin' ), $archive ) );
	}

	/**
	 * @param array<string,string> $entries
	 * @param list<string> $links
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'prospective_unsafe_archives' )]
	public function test_prospective_inspection_rejects_unsafe_and_ambiguous_shapes(
		array $entries,
		array $links = array()
	): void {
		$archive = $this->archive( $entries, $links );
		self::assertNull( $this->prospective_plugin( $archive ) );
	}

	/** @return array<string,array{0:array<string,string>,1?:list<string>}> */
	public static function prospective_unsafe_archives(): array {
		$header = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/";
		return array(
			'multiple roots'      => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'other/payload.php'                 => '<?php return true;',
				),
			),
			'unsafe path'         => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/../escape.php'      => '<?php return true;',
				),
			),
			'duplicate case path' => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/EXAMPLE-PLUGIN.PHP' => '<?php return true;',
				),
			),
			'special entry'       => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/link.php'           => '../outside',
				),
				array( 'example-plugin/link.php' ),
			),
		);
	}

	public function test_prospective_inspection_rejects_entry_limit_and_header_mismatches(): void {
		$many = array(
			'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
		);
		for ( $index = 1; $index <= 10000; ++$index ) {
			$many[ 'example-plugin/entry-' . $index ] = '';
		}
		self::assertNull( $this->prospective_plugin( $this->archive( $many ) ) );

		foreach (
			array(
				'missing PHP requirement'       => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires at least: 6.8\n*/",
				'missing WordPress requirement' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires PHP: 8.2\n*/",
				'URI mismatch'                  => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/other\n*/",
				'version mismatch'              => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.1\nUpdate URI: https://updates.example.test/owner/package\n*/",
				'PHP mismatch'                  => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires PHP: 8.3\n*/",
				'WordPress mismatch'            => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires at least: 6.9\n*/",
			) as $header
		) {
			$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $header ) );
			self::assertNull( $this->prospective_plugin( $archive ) );
		}
	}

	public function test_prospective_inspection_keeps_cross_type_headers_and_rejects_replacement(): void {
		$archive = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/style.css'          => $this->header( 'Theme Name', 'Example Theme' ),
			)
		);
		self::assertSame(
			array(
				'package_root' => 'example-plugin',
				'main_file'    => 'example-plugin.php',
			),
			$this->prospective_plugin( $archive )
		);

		$archive     = $this->archive(
			array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) )
		);
		$replacement = $this->archive(
			array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Other Plugin' ) )
		);
		$validator   = new PackageIdentityValidator();
		$after_open  = new \ReflectionProperty( $validator, 'after_open' );
		$after_open->setValue(
			$validator,
			static function ( string $path ) use ( $replacement ): void {
				copy( $replacement, $path );
			}
		);
		self::assertNull(
			$validator->inspect_prospective(
				$this->prospective_policy( $archive, 'plugin' ),
				$archive
			)
		);
	}

	public function test_fails_closed_for_digest_size_uri_target_and_metadata_mismatches(): void {
		$archive    = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$validator  = new PackageIdentityValidator();
		$descriptor = $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' );
		$policy     = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$facts      = $descriptor->to_array();
		unset( $facts['fingerprint'] );
		$bad_digest = IdentityDescriptor::create( array_replace( $facts, array( 'artifact_sha256' => str_repeat( 'b', 64 ) ) ) );
		self::assertSame( 'archive_file_identity_mismatch', $validator->validate( $bad_digest, $policy, $archive )->code() );
		self::assertSame( 'archive_target_policy_invalid', $validator->validate( $descriptor, array_replace( $policy, array( 'provider_code' => 'other' ) ), $archive )->code() );
		self::assertSame( 'archive_target_policy_invalid', $validator->validate( $descriptor, array_replace( $policy, array( 'archive_root' => 'other-root' ) ), $archive )->code() );
		self::assertSame( 'archive_target_policy_invalid', $validator->validate( $descriptor, array_replace( $policy, array( 'header_file' => 'other.php' ) ), $archive )->code() );
		self::assertSame(
			'archive_target_policy_invalid',
			$validator->validate(
				$descriptor,
				array_replace(
					$policy,
					array(
						'header_file'                => 'main.css',
						'installed_package_identity' => 'example-plugin/main.css',
					)
				),
				$archive
			)->code()
		);
		$wrong_uri = $this->archive( array( 'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/other\n*/" ) );
		self::assertSame( 'archive_update_uri_mismatch', $validator->validate( $this->descriptor( $wrong_uri, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $wrong_uri )->code() );
		self::assertSame( 'archive_metadata_identity_mismatch', $validator->validate( $descriptor, array_replace( $policy, array( 'metadata_name' => 'Other Plugin' ) ), $archive )->code() );
	}

	public function test_rejects_replacement_after_archive_open_before_inspection(): void {
		$archive     = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$replacement = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Other Plugin' ) ) );
		$validator   = new PackageIdentityValidator();
		$after_open  = new \ReflectionProperty( $validator, 'after_open' );
		$after_open->setValue(
			$validator,
			static function ( string $path ) use ( $replacement ): void {
				copy( $replacement, $path );
			}
		);
		$result = $validator->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( 'archive_file_identity_mismatch', $result->code() );
	}

	public function test_receipt_proof_cannot_cross_descriptor_or_validator_clone_and_only_consumes_once(): void {
		$archive    = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$validator  = new PackageIdentityValidator();
		$descriptor = $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' );
		$package    = $validator->validate( $descriptor, $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		$facts      = $descriptor->to_array();
		unset( $facts['fingerprint'] );
		$wrong = IdentityDescriptor::create( array_replace( $facts, array( 'artifact_sha256' => str_repeat( 'b', 64 ) ) ) );
		try {
			$validator->consume_receipt_proof( $package, $wrong );
			self::fail( 'Wrong descriptor consumed the proof.' );
		} catch ( \InvalidArgumentException ) {
			self::addToAssertionCount( 1 ); }
		self::assertSame( $descriptor->fingerprint_value(), $validator->consume_receipt_proof( $package, $descriptor )['descriptor_fingerprint'] );
		try {
			$validator->consume_receipt_proof( $package, $descriptor );
			self::fail( 'Proof consumed twice.' );
		} catch ( \InvalidArgumentException ) {
			self::addToAssertionCount( 1 ); }
		try {
			clone $validator; // @phpstan-ignore expr.resultUnused (Deliberate private-clone denial; the expected Error is the observable result.)
			self::fail( 'Validator clone unexpectedly succeeded.' );
		} catch ( \Error ) { // @phpstan-ignore catch.neverThrown (The PHP engine throws Error for this inaccessible private clone; runtime test preserves that contract.)
			self::addToAssertionCount( 1 ); }
	}

	public function test_archive_manifest_is_canonical_across_zip_entry_order(): void {
		$header         = $this->header( 'Plugin Name', 'Example Plugin' );
		$first          = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $header,
				'example-plugin/payload.php'        => '<?php return true;',
			)
		);
		$second         = $this->archive(
			array(
				'example-plugin/payload.php'        => '<?php return true;',
				'example-plugin/example-plugin.php' => $header,
			)
		);
		$validator      = new PackageIdentityValidator();
		$policy         = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$first_package  = $validator->validate( $this->descriptor( $first, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $first );
		$second_package = $validator->validate( $this->descriptor( $second, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $second );
		self::assertTrue( $first_package->is_valid() );
		self::assertTrue( $second_package->is_valid() );
		self::assertSame( $first_package->to_array()['manifest_hash'], $second_package->to_array()['manifest_hash'] );
		self::assertSame( 2, $first_package->to_array()['manifest_entry_count'] );
		self::assertSame( strlen( $header ) + strlen( '<?php return true;' ), $first_package->to_array()['manifest_expanded_bytes'] );
	}

	public function test_rejects_duplicate_semantic_headers(): void {
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nUpdate URI: https://updates.example.test/owner/package\n*/" ) );
		$result  = ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( 'archive_metadata_identity_mismatch', $result->code() );
		$conflict = $this->archive( array( 'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nUpdate URI: https://updates.example.test/other\n*/" ) );
		self::assertSame( 'archive_update_uri_mismatch', ( new PackageIdentityValidator() )->validate( $this->descriptor( $conflict, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $conflict )->code() );
	}

	/**
	 * @param array<string,string> $entries
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unsafe_archives' )]
	public function test_rejects_unsafe_or_ambiguous_archive_shapes( array $entries, string $expected ): void {
		$archive = $this->archive( $entries );
		$result  = ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( $expected, $result->code() );
	}

	/** @return array<string, array{array<string,string>,string}> */
	public static function unsafe_archives(): array {
		$header = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/";
		return array(
			'traversal'      => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/../escape.php'      => 'x',
				),
				'archive_path_unsafe',
			),
			'wrong root'     => array( array( 'other/example-plugin.php' => $header ), 'archive_root_mismatch' ),
			'case collision' => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/EXAMPLE-PLUGIN.PHP' => $header,
				),
				'archive_path_duplicate',
			),
		);
	}

	public function test_rejects_symlink_and_excessive_entry_inventory(): void {
		$entries = array(
			'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
			'example-plugin/link.php'           => '../outside',
		);
		$archive = $this->archive( $entries, array( 'example-plugin/link.php' ) );
		$result  = ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( 'archive_path_unsafe', $result->code() );
		$many = array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) );
		for ( $index = 1; $index <= 10000; ++$index ) {
			$many[ 'example-plugin/entry-' . $index ] = '';
		}
		$archive = $this->archive( $many );
		self::assertSame( 'archive_entry_limit', ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive )->code() );
	}

	public function test_delegates_ancestor_and_metadata_safety_to_the_scoped_dependency(): void {
		$header    = $this->header( 'Plugin Name', 'Example Plugin' );
		$validator = new PackageIdentityValidator();
		$policy    = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		foreach ( array(
			'file before child' => array(
				'example-plugin/example-plugin.php' => $header,
				'example-plugin/payload'            => 'file',
				'example-plugin/payload/child.php'  => '<?php return true;',
			),
			'child before file' => array(
				'example-plugin/example-plugin.php' => $header,
				'example-plugin/payload/child.php'  => '<?php return true;',
				'example-plugin/payload'            => 'file',
			),
		) as $name => $entries ) {
			$archive = $this->archive( $entries );
			self::assertNull( $validator->inspect_prospective( $this->prospective_policy( $archive, 'plugin' ), $archive ), $name );
			self::assertSame( 'archive_path_unsafe', $validator->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $archive )->code(), $name );
		}

		$dos = $this->archive(
			array( 'example-plugin/example-plugin.php' => $header ),
			array(),
			array( 'example-plugin/example-plugin.php' => array( \ZipArchive::OPSYS_DOS, 0120000 << 16 ) )
		);
		self::assertSame(
			array(
				'package_root' => 'example-plugin',
				'main_file'    => 'example-plugin.php',
			),
			$validator->inspect_prospective( $this->prospective_policy( $dos, 'plugin' ), $dos )
		);
		self::assertTrue( $validator->validate( $this->descriptor( $dos, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $dos )->is_valid() );

		$mismatch = $this->archive(
			array( 'example-plugin/example-plugin.php' => $header ),
			array(),
			array( 'example-plugin/example-plugin.php' => array( \ZipArchive::OPSYS_UNIX, 0040000 << 16 ) )
		);
		self::assertNull( $validator->inspect_prospective( $this->prospective_policy( $mismatch, 'plugin' ), $mismatch ) );
		self::assertSame( 'archive_path_unsafe', $validator->validate( $this->descriptor( $mismatch, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $mismatch )->code() );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'archive_compatibility_cases' )]
	public function test_validates_archive_version_and_optional_runtime_requirements( string $header, string $expected ): void {
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $header ) );
		$result  = ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( $expected, $result->code() );
	}

	public function test_archive_validation_permits_absent_requirements_and_rejects_post_closing_duplicates(): void {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$root       = 'plugin' === $type ? 'example-plugin' : 'example-theme';
			$file       = 'plugin' === $type ? 'example-plugin.php' : 'style.css';
			$name       = 'plugin' === $type ? 'Example Plugin' : 'Example Theme';
			$kind       = 'plugin' === $type ? 'Plugin Name' : 'Theme Name';
			$header     = "<?php\n/*\n{$kind}: {$name}\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/";
			$archive    = $this->archive( array( $root . '/' . $file => $header ) );
			$descriptor = $this->descriptor( $archive, $type, 'theme' === $type ? $root : $root . '/' . $file );
			$policy     = $this->policy( $type, $root, $file, $name );
			self::assertTrue( ( new PackageIdentityValidator() )->validate( $descriptor, $policy, $archive )->is_valid() );

			$duplicate  = $this->archive( array( $root . '/' . $file => $header . "\nVersion: 1.0.0" ) );
			$descriptor = $this->descriptor( $duplicate, $type, 'theme' === $type ? $root : $root . '/' . $file );
			self::assertSame( 'archive_version_mismatch', ( new PackageIdentityValidator() )->validate( $descriptor, $policy, $duplicate )->code() );
		}
	}

	/** @return array<string, array{string,string}> */
	public static function archive_compatibility_cases(): array {
		$base = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: %s\nUpdate URI: https://updates.example.test/owner/package%s\n*/";
		return array(
			'ready with compatible floors' => array( sprintf( $base, '1.0', "\nRequires PHP: 8.1\nRequires at least: 6.7" ), 'archive_identity_verified' ),
			'version mismatch'             => array( sprintf( $base, '1.0.1', '' ), 'archive_version_mismatch' ),
			'malformed version'            => array( sprintf( $base, '1.0.0+build', '' ), 'archive_version_mismatch' ),
			'duplicate version'            => array( "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/", 'archive_version_mismatch' ),
			'php floor incompatible'       => array( sprintf( $base, '1.0.0', "\nRequires PHP: 8.3" ), 'archive_php_requirement_incompatible' ),
			'wordpress floor incompatible' => array( sprintf( $base, '1.0.0', "\nRequires at least: 6.9" ), 'archive_wordpress_requirement_incompatible' ),
			'malformed php floor'          => array( sprintf( $base, '1.0.0', "\nRequires PHP: eight.two" ), 'archive_php_requirement_incompatible' ),
			'duplicate wordpress floor'    => array( sprintf( $base, '1.0.0', "\nRequires at least: 6.7\nRequires at least: 6.7" ), 'archive_wordpress_requirement_incompatible' ),
		);
	}

	/**
	 * @param array<string,string> $entries
	 * @param list<string> $links
	 * @param array<string,array{int,int}> $attributes
	 */
	private function archive( array $entries, array $links = array(), array $attributes = array() ): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-archive-' );
		self::assertIsString( $path );
		$this->archives[] = $path;
		$zip              = new \ZipArchive();
		self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		foreach ( $entries as $name => $contents ) {
			self::assertTrue( $zip->addFromString( $name, $contents ) );
		}
		foreach ( $links as $name ) {
			self::assertTrue( $zip->setExternalAttributesName( $name, \ZipArchive::OPSYS_UNIX, 0120777 << 16 ) );
		}
		foreach ( $attributes as $name => [$origin, $attribute] ) {
			self::assertTrue( $zip->setExternalAttributesName( $name, $origin, $attribute ) );
		}
		$zip->close();
		return $path;
	}

	private function header( string $kind, string $name, string $extra = '' ): string {
		return "<?php\n/*\n{$kind}: {$name}\n{$extra}Version: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires PHP: 8.2\nRequires at least: 6.8\n*/"; }

	private function descriptor( string $path, string $type, string $identity ): IdentityDescriptor {
		return IdentityDescriptor::create(
			array(
				'artifact_filename'          => 'package.zip',
				'artifact_identity'          => 'asset:1',
				'artifact_sha256'            => hash_file( 'sha256', $path ),
				'artifact_size'              => filesize( $path ),
				'assurance_facts'            => array(
					'exact_artifact_identity'       => true,
					'exact_commit_identity'         => true,
					'exact_reacquisition_supported' => true,
					'exact_release_identity'        => true,
					'provenance_verified'           => true,
					'publication_immutable'         => true,
					'repository_identity_stable'    => true,
					'trusted_digest_source'         => true,
				),
				'canonical_update_uri'       => 'https://updates.example.test/owner/package',
				'channel'                    => 'stable',
				'commit_identity'            => 'commit:1',
				'installed_package_identity' => $identity,
				'prerelease'                 => false,
				'provider_code'              => 'neutral',
				'release_identity'           => 'release:1',
				'repository_identity'        => 'repo:1',
				'repository_locator'         => 'owner/package',
				'tag'                        => 'v1.0.0',
				'target_type'                => $type,
				'version'                    => '1.0.0',
			)
		);
	}

	/** @return array{package_root:string,main_file:string}|null */
	private function prospective_plugin( string $archive ): ?array {
		return ( new PackageIdentityValidator() )->inspect_prospective(
			$this->prospective_policy( $archive, 'plugin' ),
			$archive
		);
	}

	/** @return array<string,mixed> */
	private function prospective_policy( string $archive, string $type ): array {
		return array(
			'artifact_sha256'           => hash_file( 'sha256', $archive ),
			'artifact_size'             => filesize( $archive ),
			'canonical_update_uri'      => 'https://updates.example.test/owner/package',
			'maximum_artifact_bytes'    => 52_428_800,
			'php_runtime_version'       => '8.2',
			'target_type'               => $type,
			'version'                   => '1.0.0',
			'wordpress_runtime_version' => '6.8',
		);
	}

	/** @return array<string,int|string> */
	private function policy( string $type, string $root, string $header, string $name, string $template = '' ): array {
		return array(
			'archive_root'               => $root,
			'configuration_update_uri'   => 'https://updates.example.test/owner/package',
			'header_file'                => $header,
			'installed_package_identity' => 'theme' === $type ? $root : $root . '/' . $header,
			'maximum_artifact_bytes'     => 52_428_800,
			'metadata_name'              => $name,
			'offer_update_uri'           => 'https://updates.example.test/owner/package',
			'php_runtime_version'        => '8.2',
			'provider_code'              => 'neutral',
			'repository_identity'        => 'repo:1',
			'repository_locator'         => 'owner/package',
			'staged_package_update_uri'  => 'https://updates.example.test/owner/package',
			'target_type'                => $type,
			'theme_template'             => $template,
			'wordpress_runtime_version'  => '6.8',
		); }
}
