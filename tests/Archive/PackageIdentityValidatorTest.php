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

	public function test_archive_root_uses_the_shared_191_byte_folder_boundary(): void {
		foreach ( array( 100, 101, 191, 192 ) as $length ) {
			$root       = str_repeat( 'A', $length );
			$archive    = $this->archive( array( $root . '/main.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
			$descriptor = $this->descriptor( $archive, 'plugin', $root . '/main.php' );
			$policy     = $this->policy( 'plugin', $root, 'main.php', 'Example Plugin' );
			$result     = ( new PackageIdentityValidator() )->validate( $descriptor, $policy, $archive );
			self::assertSame( $length <= 191, $result->is_valid(), (string) $length );
			self::assertSame( $length <= 191 ? 'archive_identity_verified' : 'archive_target_policy_invalid', $result->code() );
		}
	}

	public function test_owned_identity_gate_rejects_replacement_during_hashing(): void {
		$archive    = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$descriptor = $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' );
		$policy     = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$initial    = ( new PackageIdentityValidator() )->validate( $descriptor, $policy, $archive );
		self::assertTrue( $initial->is_valid() );
		self::assertSame( 'archive_identity_verified', $initial->code() );
		$script = tempnam( sys_get_temp_dir(), 'ran-owned-hash-race-' );
		self::assertIsString( $script );
		$this->archives[] = $script;
		$this->archives[] = $archive . '.before-race';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode exact fixture facts for an isolated process; WordPress JSON filters must not alter descriptor bytes.
		$encoded          = json_encode(
			array(
				'archive'    => $archive,
				'descriptor' => $descriptor->to_array(),
				'policy'     => $policy,
				'bootstrap'  => dirname( __DIR__ ) . '/bootstrap.php',
			),
			JSON_THROW_ON_ERROR
		);
		$fixture          = <<<'PHP'
<?php
namespace RAN\WPReleaseUpdater\V1\Archive {
    function hash_file(string $algorithm, string $filename, bool $binary = false): string|false {
        if ($filename === $GLOBALS['ran_owned_race_path'] && !$GLOBALS['ran_owned_race_fired']) {
            $GLOBALS['ran_owned_race_fired'] = true;
            $stat = lstat($filename);
            $bytes = file_get_contents($filename);
            rename($filename, $filename . '.before-race');
            file_put_contents($filename, $bytes);
            chmod($filename, $stat['mode'] & 0777);
        }
        return \hash_file($algorithm, $filename, $binary);
    }
}
namespace {
    $ran_owned_facts = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    require $ran_owned_facts['bootstrap'];
    $GLOBALS['ran_owned_race_path'] = $ran_owned_facts['archive'];
    $GLOBALS['ran_owned_race_fired'] = false;
    $ran_owned_before = lstat($ran_owned_facts['archive']);
    $ran_owned_result = (new \RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator())->validate(
        \RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor::rehydrate($ran_owned_facts['descriptor']),
        $ran_owned_facts['policy'],
        $ran_owned_facts['archive']
    );
    clearstatcache(true, $ran_owned_facts['archive']);
    $ran_owned_after = lstat($ran_owned_facts['archive']);
    echo json_encode([
        'valid' => $ran_owned_result->is_valid(),
        'code' => $ran_owned_result->code(),
        'race_fired' => $GLOBALS['ran_owned_race_fired'],
        'changed_inode' => $ran_owned_before['ino'] !== $ran_owned_after['ino'],
        'same_mode' => $ran_owned_before['mode'] === $ran_owned_after['mode'],
        'same_bytes' => hash_file('sha256', $ran_owned_facts['archive']) === $ran_owned_facts['descriptor']['artifact_sha256']
    ], JSON_THROW_ON_ERROR);
}
PHP;
		$facts_file       = $script . '.json';
		$this->archives[] = $facts_file;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact isolated fixture bytes; no production source is replaced or loaded into this PHPUnit symbol world.
		file_put_contents( $script, $fixture );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact descriptor input bytes for the native hash race subprocess.
		file_put_contents( $facts_file, $encoded );
		$output = array();
		$status = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- A separate native PHP process isolates the namespaced hash_file test seam from production and other test symbol worlds.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $facts_file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true, 64, JSON_THROW_ON_ERROR );
		self::assertIsArray( $result );
		self::assertFalse( $result['valid'] );
		self::assertSame( 'archive_file_identity_mismatch', $result['code'] );
		self::assertTrue( $result['race_fired'] );
		self::assertTrue( $result['changed_inode'] );
		self::assertTrue( $result['same_mode'] );
		self::assertTrue( $result['same_bytes'] );
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

	/** @param array<string,mixed>|null $result */
	private function assert_rejected( ?array $result, ?string $reason = null ): void {
		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'package_root', $result );
		self::assertIsString( $result['reason'] ?? null );
		if ( null !== $reason ) {
			self::assertSame( $reason, $result['reason'] );
		}
	}

	public function test_prospective_inspection_rejects_ambiguous_plugin_headers(): void {
		$archive = $this->archive(
			array(
				'example-plugin/a.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/b.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
			)
		);
		$this->assert_rejected( ( new PackageIdentityValidator() )->inspect_prospective( $this->prospective_policy( $archive, 'plugin' ), $archive ), 'header_plugin_ambiguous' );
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
		$this->assert_rejected( $this->prospective_plugin( $archive ) );
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
		$this->assert_rejected( $this->prospective_plugin( $this->archive( $many ) ), 'archive_entry_limit' );

		foreach (
			array(
				'URI mismatch'       => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/other\n*/",
				'version mismatch'   => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.1\nUpdate URI: https://updates.example.test/owner/package\n*/",
				'PHP mismatch'       => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires PHP: 8.3\n*/",
				'WordPress mismatch' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires at least: 6.9\n*/",
			) as $header
		) {
			$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $header ) );
			$this->assert_rejected( $this->prospective_plugin( $archive ) );
		}
	}

	public function test_prospective_requires_headers_match_install_time_validation(): void {
		$head      = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n";
		$validator = new PackageIdentityValidator();
		$policy    = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$cases     = array(
			'both absent'      => array( '', null ),
			'php absent'       => array( "Requires at least: 6.8\n", null ),
			'wordpress absent' => array( "Requires PHP: 8.2\n", null ),
			'both met'         => array( "Requires PHP: 8.2\nRequires at least: 6.8\n", null ),
			'php empty'        => array( "Requires PHP:\nRequires at least: 6.8\n", null ),
			'php invalid'      => array( "Requires PHP: soon\n", 'header_requires_php_unmet' ),
			'php unmet'        => array( "Requires PHP: 8.3\n", 'header_requires_php_unmet' ),
			'wordpress empty'  => array( "Requires at least:\n", null ),
			'wordpress unmet'  => array( "Requires at least: 6.9\n", 'header_requires_wp_unmet' ),
		);
		foreach ( $cases as $name => [$requires, $reason] ) {
			$archive  = $this->archive( array( 'example-plugin/example-plugin.php' => $head . $requires . '*/' ) );
			$result   = $this->prospective_plugin( $archive );
			$accepted = $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' );
			self::assertSame( null === $reason, $validator->validate( $accepted, $policy, $archive )->is_valid(), $name );
			if ( null === $reason ) {
				self::assertSame( 'example-plugin', $result['package_root'] ?? null, $name );
			} else {
				$this->assert_rejected( $result, $reason );
			}
		}
	}

	/** @return array<string,array{string,?string}> */
	public static function update_uri_detail_provider(): array {
		return array(
			'userinfo and query'   => array( 'https://user:password@updates.example.test/owner/package?token=secret', null ),
			'fragment'             => array( 'https://updates.example.test/owner/other#frag', null ),
			'encoded userinfo'     => array( 'https://us%40er:p%40ss@updates.example.test/owner/package', null ),
			'ipv6 host'            => array( 'https://[::1]/owner/package', null ),
			'other host'           => array( 'https://evil.example.test/owner/package', null ),
			'canonical other path' => array( 'https://Updates.Example.Test/owner/other/', 'https://updates.example.test/owner/other' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'update_uri_detail_provider' )]
	public function test_update_uri_rejection_echoes_only_a_canonical_credential_free_value( string $uri, ?string $detail ): void {
		$header  = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: " . $uri . "\nRequires PHP: 8.2\nRequires at least: 6.8\n*/";
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $header ) );
		$result  = $this->prospective_plugin( $archive );
		$this->assert_rejected( $result, 'header_update_uri_mismatch' );
		self::assertSame( $detail, is_array( $result ) && array_key_exists( 'detail', $result ) ? $result['detail'] : 'missing' );
	}

	/** @return array<string,array{string,string,?string}> */
	public static function line_ending_reason_provider(): array {
		$cases = array();
		foreach ( array(
			"\r"   => 'CR',
			"\r\n" => 'CRLF',
		) as $ending => $label ) {
			$cases[ $label . ' version missing' ]    = array( $ending, "Plugin Name: Example Plugin\nUpdate URI: https://updates.example.test/owner/package\n", 'header_version_missing', null );
			$cases[ $label . ' update uri missing' ] = array( $ending, "Plugin Name: Example Plugin\nVersion: 1.0.0\n", 'header_update_uri_missing', null );
			$cases[ $label . ' invalid version' ]    = array( $ending, "Plugin Name: Example Plugin\nVersion: soon\nUpdate URI: https://updates.example.test/owner/package\n", 'header_invalid', 'Version' );
			$cases[ $label . ' duplicate name' ]     = array( $ending, "Plugin Name: Example Plugin\nPlugin Name: Again\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n", 'header_duplicate', 'Name' );
		}
		return $cases;
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'line_ending_reason_provider' )]
	public function test_header_rejection_reasons_survive_non_unix_line_endings( string $ending, string $body, string $reason, ?string $detail ): void {
		$header  = str_replace( "\n", $ending, "<?php\n/*\n" . $body . '*/' );
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $header ) );
		$result  = $this->prospective_plugin( $archive );
		$this->assert_rejected( $result, $reason );
		self::assertSame( $detail, is_array( $result ) && array_key_exists( 'detail', $result ) ? $result['detail'] : 'missing' );
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

	public function test_archive_validation_succeeds_across_zip_entry_order(): void {
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
		self::assertSame( 'example-plugin', $first_package->to_array()['archive_root'] );
		self::assertSame( $first_package->to_array()['archive_root'], $second_package->to_array()['archive_root'] );
		self::assertSame( $first_package->to_array()['header_file'], $second_package->to_array()['header_file'] );
		self::assertSame( $first_package->to_array()['metadata_name'], $second_package->to_array()['metadata_name'] );
		self::assertSame( $first_package->to_array()['update_uri'], $second_package->to_array()['update_uri'] );
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
			$this->assert_rejected( $validator->inspect_prospective( $this->prospective_policy( $archive, 'plugin' ), $archive ), 'archive_path_unsafe' );
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
		$this->assert_rejected( $validator->inspect_prospective( $this->prospective_policy( $mismatch, 'plugin' ), $mismatch ), 'archive_path_unsafe' );
		self::assertSame( 'archive_path_unsafe', $validator->validate( $this->descriptor( $mismatch, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $mismatch )->code() );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'archive_compatibility_cases' )]
	public function test_validates_archive_version_and_optional_runtime_requirements( string $header, string $expected ): void {
		$archive = $this->archive( array( 'example-plugin/example-plugin.php' => $header ) );
		$result  = ( new PackageIdentityValidator() )->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' ), $archive );
		self::assertSame( $expected, $result->code() );
	}

	public function test_archive_validation_permits_absent_requirements_and_rejects_in_block_duplicates(): void {
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

			$phpdoc     = $this->archive( array( $root . '/' . $file => $header . "\n/**\n * Version: 9.9.9\n * Author: Docs\n * Description: Helper\n * Template: ignored\n */" ) );
			$descriptor = $this->descriptor( $phpdoc, $type, 'theme' === $type ? $root : $root . '/' . $file );
			self::assertTrue( ( new PackageIdentityValidator() )->validate( $descriptor, $policy, $phpdoc )->is_valid() );

			$duplicate  = $this->archive( array( $root . '/' . $file => "<?php\n/*\n{$kind}: {$name}\nVersion: 1.0.0\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/" ) );
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

	public function test_validator_and_prospective_inspection_accept_distro_php_runtime_versions(): void {
		$header_82  = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires PHP: 8.2\nRequires at least: 6.8\n*/";
		$header_83  = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\nRequires PHP: 8.3\nRequires at least: 6.8\n*/";
		$archive_82 = $this->archive( array( 'example-plugin/example-plugin.php' => $header_82 ) );
		$archive_83 = $this->archive( array( 'example-plugin/example-plugin.php' => $header_83 ) );
		$validator  = new PackageIdentityValidator();

		foreach ( array( '8.2.28-0+deb12u1', '8.2.28-1+ubuntu22.04.1+deb.sury.org+1', '8.2.28-1~deb12u1' ) as $php_version ) {
			$policy                        = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
			$policy['php_runtime_version'] = $php_version;
			self::assertTrue( $validator->validate( $this->descriptor( $archive_82, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $archive_82 )->is_valid(), $php_version );
			self::assertSame( 'archive_php_requirement_incompatible', $validator->validate( $this->descriptor( $archive_83, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $archive_83 )->code(), $php_version );

			$prospective_82                        = $this->prospective_policy( $archive_82, 'plugin' );
			$prospective_82['php_runtime_version'] = $php_version;
			self::assertSame(
				array(
					'package_root' => 'example-plugin',
					'main_file'    => 'example-plugin.php',
				),
				$validator->inspect_prospective( $prospective_82, $archive_82 ),
				$php_version
			);

			$prospective_83                        = $this->prospective_policy( $archive_83, 'plugin' );
			$prospective_83['php_runtime_version'] = $php_version;
			$rejected                              = $validator->inspect_prospective( $prospective_83, $archive_83 );
			self::assertIsArray( $rejected );
			self::assertSame( 'header_requires_php_unmet', $rejected['reason'] ?? null, $php_version );
		}
	}

	public function test_parse_header_strips_utf8_bom_and_ignores_post_header_phpdoc_and_plugin_template(): void {
		$plugin_header = "\xEF\xBB\xBF<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nAuthor: Real Author\nDescription: Real Description\nPlugin URI: https://example.test/plugin\nUpdate URI: https://updates.example.test/owner/package\nTemplate: ../ignored-on-plugin\n*/\n/**\n * Helper docs\n * Version: 9.9.9\n * Author: Doc Author\n * Description: Doc Description\n * Template: ../bad\n */\n";
		$parsed        = PackageIdentityValidator::parse_header( $plugin_header, 'plugin' );
		self::assertSame( 'installed_header_verified', $parsed['code'] );
		if ( ! isset( $parsed['headers'] ) ) {
			self::fail( 'Verified plugin header omitted headers array.' );
		}
		self::assertSame( 'Real Author', $parsed['headers']['Author'] );
		self::assertSame( 'Real Description', $parsed['headers']['Description'] );
		self::assertSame( 'https://example.test/plugin', $parsed['headers']['PackageURI'] );
		self::assertSame( '', $parsed['headers']['Template'] );

		$theme_header = "\xEF\xBB\xBF/*\nTheme Name: Example Theme\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/\n/*\n * Version: 9.9.9\n * Template: ../bad\n */\n";
		self::assertSame( 'installed_header_verified', PackageIdentityValidator::parse_header( $theme_header, 'theme' )['code'] );
	}

	public function test_split_header_region_compatibility_blocks_are_enforced_while_post_code_phpdoc_is_ignored(): void {
		$validator = new PackageIdentityValidator();
		$policy    = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );

		$split_php_header = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/\n/*\nRequires PHP: 99.0\n*/\ndefined( 'ABSPATH' ) || exit;\n";
		$split_php_zip    = $this->archive( array( 'example-plugin/example-plugin.php' => $split_php_header ) );
		$prospective_php  = $this->prospective_plugin( $split_php_zip );
		self::assertIsArray( $prospective_php );
		self::assertSame( 'header_requires_php_unmet', $prospective_php['reason'] ?? null );
		self::assertSame(
			'archive_php_requirement_incompatible',
			$validator->validate( $this->descriptor( $split_php_zip, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $split_php_zip )->code()
		);

		$split_wp_header = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/\n/*\nRequires at least: 9.0\n*/\n";
		$split_wp_zip    = $this->archive( array( 'example-plugin/example-plugin.php' => $split_wp_header ) );
		$prospective_wp  = $this->prospective_plugin( $split_wp_zip );
		self::assertIsArray( $prospective_wp );
		self::assertSame( 'header_requires_wp_unmet', $prospective_wp['reason'] ?? null );
		self::assertSame(
			'archive_wordpress_requirement_incompatible',
			$validator->validate( $this->descriptor( $split_wp_zip, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $split_wp_zip )->code()
		);

		$post_code_doc = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/\ndefined( 'ABSPATH' ) || exit;\n/**\n * Documentation note.\n * Requires PHP: 99.0\n * Requires at least: 9.0\n * Version: 9.9.9\n */\n";
		$post_code_zip = $this->archive( array( 'example-plugin/example-plugin.php' => $post_code_doc ) );
		self::assertSame(
			array(
				'package_root' => 'example-plugin',
				'main_file'    => 'example-plugin.php',
			),
			$this->prospective_plugin( $post_code_zip )
		);
		self::assertTrue(
			$validator->validate( $this->descriptor( $post_code_zip, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $post_code_zip )->is_valid()
		);

		$hash_comment_header = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/\n# Shell-style header separator comment\n/*\nRequires PHP: 99.0\n*/\n";
		$hash_comment_zip    = $this->archive( array( 'example-plugin/example-plugin.php' => $hash_comment_header ) );
		$prospective_hash    = $this->prospective_plugin( $hash_comment_zip );
		self::assertIsArray( $prospective_hash );
		self::assertSame( 'header_requires_php_unmet', $prospective_hash['reason'] ?? null );

		$attribute_header = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n*/\n#[AllowDynamicProperties]\n/**\n * Requires PHP: 99.0\n */\nclass Example_Attr {}\n";
		$attribute_zip    = $this->archive( array( 'example-plugin/example-plugin.php' => $attribute_header ) );
		self::assertSame(
			array(
				'package_root' => 'example-plugin',
				'main_file'    => 'example-plugin.php',
			),
			$this->prospective_plugin( $attribute_zip )
		);
	}

	public function test_validate_propagates_exceptions_from_after_open_without_swallowing_in_finally(): void {
		$archive    = $this->archive( array( 'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		$descriptor = $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' );
		$policy     = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$validator  = new PackageIdentityValidator();
		( new \ReflectionProperty( PackageIdentityValidator::class, 'after_open' ) )->setValue(
			$validator,
			static function (): void {
				throw new \RuntimeException( 'after_open_boom' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'after_open_boom' );
		$validator->validate( $descriptor, $policy, $archive );
	}

	public function test_archive_scanner_allows_small_high_ratio_files_and_macos_or_ntfs_entries(): void {
		$validator = new PackageIdentityValidator();
		$policy    = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );

		$small_compressible = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/icon.svg'           => str_repeat( 'A', 4096 ),
			)
		);
		self::assertTrue(
			$validator->validate( $this->descriptor( $small_compressible, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $small_compressible )->is_valid()
		);

		$large_bomb = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/bomb.txt'           => str_repeat( "\0", 131072 ),
			)
		);
		self::assertSame(
			'archive_size_limit',
			$validator->validate( $this->descriptor( $large_bomb, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $large_bomb )->code()
		);

		$macos_and_ntfs = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/macos.txt'          => 'macos-entry',
				'example-plugin/ntfs.txt'           => 'ntfs-entry',
			),
			array(),
			array(
				'example-plugin/macos.txt' => array( 19, 0100644 << 16 ),
				'example-plugin/ntfs.txt'  => array( 10, 0x00 ),
			)
		);
		self::assertTrue(
			$validator->validate( $this->descriptor( $macos_and_ntfs, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $macos_and_ntfs )->is_valid()
		);

		$macos_symlink = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/link.txt'           => '../outside',
			),
			array(),
			array(
				'example-plugin/link.txt' => array( 19, 0120777 << 16 ),
			)
		);
		self::assertSame(
			'archive_path_unsafe',
			$validator->validate( $this->descriptor( $macos_symlink, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $macos_symlink )->code()
		);

		$ntfs_volume_label = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/vol.txt'            => 'label',
			),
			array(),
			array(
				'example-plugin/vol.txt' => array( 10, 0x08 ),
			)
		);
		self::assertSame(
			'archive_path_unsafe',
			$validator->validate( $this->descriptor( $ntfs_volume_label, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $ntfs_volume_label )->code()
		);

		foreach ( array(
			'ntfs device'        => array( 'example-plugin/dev.txt', 0x0040 ),
			'ntfs reparse point' => array( 'example-plugin/reparse.txt', 0x0400 ),
			'ntfs junction dir'  => array( 'example-plugin/junction/', 0x0410 ),
		) as $label => [$entry_name, $ntfs_attr] ) {
			$ntfs_special = $this->archive(
				array(
					'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
					$entry_name                         => str_ends_with( $entry_name, '/' ) ? '' : 'special',
				),
				array(),
				array(
					$entry_name => array( 10, $ntfs_attr ),
				)
			);
			self::assertSame(
				'archive_path_unsafe',
				$validator->validate( $this->descriptor( $ntfs_special, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $ntfs_special )->code(),
				$label
			);
		}
	}

	public function test_prospective_inspection_rejects_forged_entry_size_and_unreadable_top_level_php(): void {
		$header = $this->header( 'Plugin Name', 'Example Plugin' );

		// Forged central-directory uncompressed size on a payload entry must fail closed during prospective inspection.
		$forged = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $header,
				'example-plugin/payload.txt'        => str_repeat( 'A', 4096 ),
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local ZIP fixture bytes to modify a central-directory size field.
		$bytes = file_get_contents( $forged );
		self::assertIsString( $bytes );
		$cd_marker = "PK\x01\x02";
		$offset    = strpos( $bytes, $cd_marker );
		$patched   = false;
		while ( false !== $offset ) {
			$name_len = unpack( 'v', substr( $bytes, $offset + 28, 2 ) );
			self::assertIsArray( $name_len );
			$entry_name = substr( $bytes, $offset + 46, (int) $name_len[1] );
			if ( 'example-plugin/payload.txt' === $entry_name ) {
				$bytes   = substr_replace( $bytes, pack( 'V', 4 ), $offset + 24, 4 );
				$patched = true;
				break;
			}
			$offset = strpos( $bytes, $cd_marker, $offset + 4 );
		}
		self::assertTrue( $patched );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write modified ZIP fixture bytes directly.
		file_put_contents( $forged, $bytes );
		$this->assert_rejected(
			( new PackageIdentityValidator() )->inspect_prospective( $this->prospective_policy( $forged, 'plugin' ), $forged ),
			'archive_unreadable'
		);

		// Unreadable top-level .php file must fail closed rather than being skipped as lacking a plugin header.
		$unreadable = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $header,
				'example-plugin/helper.php'         => "<?php\nreturn true;\n",
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local ZIP fixture bytes to set an unsupported compression method.
		$unreadable_bytes = file_get_contents( $unreadable );
		self::assertIsString( $unreadable_bytes );
		$patched_local = false;
		$patched_cd    = false;
		$local_offset  = strpos( $unreadable_bytes, "PK\x03\x04" );
		while ( false !== $local_offset ) {
			$name_len = unpack( 'v', substr( $unreadable_bytes, $local_offset + 26, 2 ) );
			self::assertIsArray( $name_len );
			$entry_name = substr( $unreadable_bytes, $local_offset + 30, (int) $name_len[1] );
			if ( 'example-plugin/helper.php' === $entry_name ) {
				$unreadable_bytes = substr_replace( $unreadable_bytes, pack( 'v', 98 ), $local_offset + 8, 2 );
				$patched_local    = true;
				break;
			}
			$local_offset = strpos( $unreadable_bytes, "PK\x03\x04", $local_offset + 4 );
		}
		$cd_offset = strpos( $unreadable_bytes, $cd_marker );
		while ( false !== $cd_offset ) {
			$name_len = unpack( 'v', substr( $unreadable_bytes, $cd_offset + 28, 2 ) );
			self::assertIsArray( $name_len );
			$entry_name = substr( $unreadable_bytes, $cd_offset + 46, (int) $name_len[1] );
			if ( 'example-plugin/helper.php' === $entry_name ) {
				$unreadable_bytes = substr_replace( $unreadable_bytes, pack( 'v', 98 ), $cd_offset + 10, 2 );
				$patched_cd       = true;
				break;
			}
			$cd_offset = strpos( $unreadable_bytes, $cd_marker, $cd_offset + 4 );
		}
		self::assertTrue( $patched_local && $patched_cd );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write unreadable-entry ZIP fixture bytes directly.
		file_put_contents( $unreadable, $unreadable_bytes );
		// Prove the archive opens and passes inventory scan cleanly, and only fails when streaming helper.php.
		$probe = new \ZipArchive();
		self::assertTrue( $probe->open( $unreadable ) );
		self::assertTrue( \RAN\WPReleaseUpdater\V1\Archive\ArchiveScanner::scan( $probe )->is_valid() );
		self::assertFalse( $probe->getStream( 'example-plugin/helper.php' ) );
		$probe->close();
		$opened    = false;
		$validator = new PackageIdentityValidator(
			static function () use ( &$opened ): void {
				$opened = true;
			}
		);
		$this->assert_rejected(
			$validator->inspect_prospective( $this->prospective_policy( $unreadable, 'plugin' ), $unreadable ),
			'archive_unreadable'
		);
		self::assertTrue( $opened );
	}

	public function test_optional_requires_headers_may_be_omitted_or_empty_for_plugins_and_themes(): void {
		$validator = new PackageIdentityValidator();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$root     = 'plugin' === $type ? 'example-plugin' : 'example-theme';
			$file     = 'plugin' === $type ? 'example-plugin.php' : 'style.css';
			$name     = 'plugin' === $type ? 'Example Plugin' : 'Example Theme';
			$kind     = 'plugin' === $type ? 'Plugin Name' : 'Theme Name';
			$identity = 'theme' === $type ? $root : $root . '/' . $file;
			$policy   = $this->policy( $type, $root, $file, $name );
			$head     = ( 'plugin' === $type ? "<?php\n" : '' ) . "/*\n{$kind}: {$name}\nVersion: 1.0.0\nUpdate URI: https://updates.example.test/owner/package\n";

			foreach ( array(
				'both omitted'      => '',
				'php omitted'       => "Requires at least: 6.8\n",
				'wordpress omitted' => "Requires PHP: 8.2\n",
				'both empty'        => "Requires PHP:\nRequires at least:\n",
				'php empty wp met'  => "Requires PHP:   \nRequires at least: 6.8\n",
				'wp empty php met'  => "Requires PHP: 8.2\nRequires at least:   \n",
			) as $label => $requires ) {
				$archive = $this->archive( array( $root . '/' . $file => $head . $requires . '*/' ) );
				self::assertSame(
					array(
						'package_root' => $root,
						'main_file'    => $file,
					),
					$validator->inspect_prospective( $this->prospective_policy( $archive, $type ), $archive ),
					$type . ': ' . $label
				);
				self::assertTrue(
					$validator->validate( $this->descriptor( $archive, $type, $identity ), $policy, $archive )->is_valid(),
					$type . ': ' . $label
				);
			}

			foreach ( array(
				'malformed php'       => array( "Requires PHP: not-a-version\n", 'header_requires_php_unmet', 'archive_php_requirement_incompatible' ),
				'malformed wordpress' => array( "Requires at least: not-a-version\n", 'header_requires_wp_unmet', 'archive_wordpress_requirement_incompatible' ),
				'unmet php'           => array( "Requires PHP: 8.3\n", 'header_requires_php_unmet', 'archive_php_requirement_incompatible' ),
				'unmet wordpress'     => array( "Requires at least: 6.9\n", 'header_requires_wp_unmet', 'archive_wordpress_requirement_incompatible' ),
			) as $label => [$requires, $prospective_reason, $archive_code] ) {
				$archive = $this->archive( array( $root . '/' . $file => $head . $requires . '*/' ) );
				$this->assert_rejected(
					$validator->inspect_prospective( $this->prospective_policy( $archive, $type ), $archive ),
					$prospective_reason
				);
				self::assertSame(
					$archive_code,
					$validator->validate( $this->descriptor( $archive, $type, $identity ), $policy, $archive )->code(),
					$type . ': ' . $label
				);
			}
		}
	}

	public function test_utf8_zip_entry_paths_are_admitted_while_unicode_controls_and_malformed_utf8_fail(): void {
		$validator = new PackageIdentityValidator();
		$policy    = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$utf8_zip  = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/assets/ümlaut.png'  => 'png-bytes',
				'example-plugin/assets/café.txt'    => 'nfc-latin',
				'example-plugin/assets/画像.svg'      => '<svg/>',
				'example-plugin/assets/한국어.txt'     => 'nfc-hangul',
				'example-plugin/ресурсы/файл.txt'   => 'text-bytes',
			)
		);
		self::assertSame(
			array(
				'package_root' => 'example-plugin',
				'main_file'    => 'example-plugin.php',
			),
			$validator->inspect_prospective( $this->prospective_policy( $utf8_zip, 'plugin' ), $utf8_zip )
		);
		self::assertTrue(
			$validator->validate( $this->descriptor( $utf8_zip, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $utf8_zip )->is_valid()
		);

		foreach ( array(
			'bidi format'    => "example-plugin/assets/name\u{202E}gnp.php",
			'lrm format'     => "example-plugin/assets/name\u{200E}.png",
			'line separator' => "example-plugin/assets/name\u{2028}.png",
			'c1 control'     => "example-plugin/assets/name\u{0085}.png",
		) as $label => $unsafe_entry ) {
			$archive = $this->archive(
				array(
					'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
					$unsafe_entry                       => 'payload',
				)
			);
			$this->assert_rejected(
				$validator->inspect_prospective( $this->prospective_policy( $archive, 'plugin' ), $archive ),
				'archive_path_unsafe'
			);
			self::assertSame(
				'archive_path_unsafe',
				$validator->validate( $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $archive )->code(),
				$label
			);
		}

		// Malformed UTF-8 bytes are rejected both at the path helper and when libzip opens a UTF-8-flagged entry.
		self::assertNull( \RAN\WPReleaseUpdater\V1\Dependency\ArchiveSafety::normalize_path( "example-plugin/assets/\xff.png", allow_utf8: true ) );
		$invalid_utf8 = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/assets/üX.png'      => 'payload',
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local ZIP fixture bytes to inject a raw non-UTF-8 filename byte.
		$raw_bytes = file_get_contents( $invalid_utf8 );
		self::assertIsString( $raw_bytes );
		$patched = str_replace( 'example-plugin/assets/üX.png', "example-plugin/assets/ü\xff.png", $raw_bytes, $count );
		self::assertSame( 2, $count );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write modified ZIP fixture bytes directly.
		file_put_contents( $invalid_utf8, $patched );
		$this->assert_rejected(
			$validator->inspect_prospective( $this->prospective_policy( $invalid_utf8, 'plugin' ), $invalid_utf8 ),
			'archive_unreadable'
		);
		self::assertSame(
			'archive_unreadable',
			$validator->validate( $this->descriptor( $invalid_utf8, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $invalid_utf8 )->code(),
			'invalid utf8'
		);

		// Unicode case-folding collisions (for example Ü.png vs ü.png, ß vs SS) and Unicode file-parent collisions must fail closed.
		foreach ( array(
			'unicode case duplicate'                   => array(
				array(
					'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
					'example-plugin/assets/Ü.png'       => 'upper',
					'example-plugin/assets/ü.png'       => 'lower',
				),
				'archive_path_duplicate',
			),
			'unicode sharp-s case fold'                => array(
				array(
					'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
					'example-plugin/assets/straße.txt'  => 'sharp-s',
					'example-plugin/assets/STRASSE.txt' => 'ascii-ss',
				),
				'archive_path_duplicate',
			),
			'unicode file parent collision'            => array(
				array(
					'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
					'example-plugin/assets/ü'           => 'file',
					'example-plugin/assets/Ü/child.txt' => 'child',
				),
				'archive_path_unsafe',
			),
			'unicode nfc vs nfd duplicate'             => array(
				array(
					'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
					"example-plugin/assets/caf\xc3\xa9.txt" => 'nfc',
					"example-plugin/assets/cafe\xcc\x81.txt" => 'nfd',
				),
				class_exists( \Normalizer::class ) ? 'archive_path_duplicate' : 'archive_path_unsafe',
			),
			'unicode nfc vs nfd file parent collision' => array(
				array(
					'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
					"example-plugin/assets/caf\xc3\xa9" => 'file',
					"example-plugin/assets/cafe\xcc\x81/sub.txt" => 'child',
				),
				'archive_path_unsafe',
			),
			'unicode canonical singleton semicolon duplicate' => array(
				array(
					'example-plugin/example-plugin.php'    => $this->header( 'Plugin Name', 'Example Plugin' ),
					'example-plugin/assets/a;b.txt'        => 'ascii-semicolon',
					"example-plugin/assets/a\u{037E}b.txt" => 'greek-question-mark',
				),
				class_exists( \Normalizer::class ) ? 'archive_path_duplicate' : 'archive_path_unsafe',
			),
			'unicode canonical singleton greek oxia duplicate' => array(
				array(
					'example-plugin/example-plugin.php'  => $this->header( 'Plugin Name', 'Example Plugin' ),
					"example-plugin/assets/\u{03AC}.txt" => 'tonos',
					"example-plugin/assets/\u{1F71}.txt" => 'oxia',
				),
				class_exists( \Normalizer::class ) ? 'archive_path_duplicate' : 'archive_path_unsafe',
			),
			'unicode canonical singleton angle bracket duplicate' => array(
				array(
					'example-plugin/example-plugin.php'    => $this->header( 'Plugin Name', 'Example Plugin' ),
					"example-plugin/assets/a\u{2329}b.txt" => 'tech-angle',
					"example-plugin/assets/a\u{3008}b.txt" => 'cjk-angle',
				),
				class_exists( \Normalizer::class ) ? 'archive_path_duplicate' : 'archive_path_unsafe',
			),
			'unicode canonical singleton file parent collision' => array(
				array(
					'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
					'example-plugin/assets/a;b'         => 'file',
					"example-plugin/assets/a\u{037E}b/child.txt" => 'child',
				),
				'archive_path_unsafe',
			),
		) as $label => [$files, $expected_code] ) {
			$colliding = $this->archive( $files );
			$this->assert_rejected(
				$validator->inspect_prospective( $this->prospective_policy( $colliding, 'plugin' ), $colliding ),
				$expected_code
			);
			self::assertSame(
				$expected_code,
				$validator->validate( $this->descriptor( $colliding, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $colliding )->code(),
				$label
			);
		}
	}

	public function test_expanded_archive_limit_scales_dynamically_from_maximum_artifact_bytes(): void {
		$validator = new PackageIdentityValidator();
		$header    = $this->header( 'Plugin Name', 'Example Plugin' );
		$payload   = str_repeat( '0123456789abcdef', 1024 ); // 16 KiB compressible, well below 128 KiB ratio floor.
		$archive   = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $header,
				'example-plugin/data.txt'           => $payload,
			)
		);
		$expanded  = strlen( $header ) + strlen( $payload );
		$zip_size  = filesize( $archive );
		self::assertIsInt( $zip_size );
		$small_limit = max( $zip_size, intdiv( $expanded - 1, 4 ) );
		self::assertLessThan( $expanded, 4 * $small_limit );
		$large_limit = intdiv( $expanded, 4 ) + 1;
		self::assertGreaterThanOrEqual( $expanded, 4 * $large_limit );

		$prospective_small                           = $this->prospective_policy( $archive, 'plugin' );
		$prospective_small['maximum_artifact_bytes'] = $small_limit;
		$this->assert_rejected(
			$validator->inspect_prospective( $prospective_small, $archive ),
			'archive_size_limit'
		);

		$prospective_large                           = $this->prospective_policy( $archive, 'plugin' );
		$prospective_large['maximum_artifact_bytes'] = $large_limit;
		self::assertSame(
			array(
				'package_root' => 'example-plugin',
				'main_file'    => 'example-plugin.php',
			),
			$validator->inspect_prospective( $prospective_large, $archive )
		);

		$policy_small                           = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$policy_small['maximum_artifact_bytes'] = $small_limit;
		$descriptor                             = $this->descriptor( $archive, 'plugin', 'example-plugin/example-plugin.php' );
		self::assertSame(
			'archive_size_limit',
			$validator->validate( $descriptor, $policy_small, $archive )->code()
		);

		$policy_large                           = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );
		$policy_large['maximum_artifact_bytes'] = $large_limit;
		$validated                              = $validator->validate( $descriptor, $policy_large, $archive );
		self::assertTrue( $validated->is_valid() );
		self::assertSame( $descriptor->to_array()['artifact_sha256'], $validator->consume_receipt_proof( $validated, $descriptor )['sha256'] );

		$zip = new \ZipArchive();
		self::assertTrue( $zip->open( $archive ) );
		try {
			self::assertTrue( \RAN\WPReleaseUpdater\V1\Archive\ArchiveScanner::scan( $zip )->is_valid() );
			self::assertSame( 'archive_size_limit', \RAN\WPReleaseUpdater\V1\Archive\ArchiveScanner::scan( $zip, 'example-plugin', $expanded - 1 )->failure_code() );
			self::assertTrue( \RAN\WPReleaseUpdater\V1\Archive\ArchiveScanner::scan( $zip, 'example-plugin', $expanded )->is_valid() );
		} finally {
			$zip->close();
		}
	}

	public function test_encrypted_zip_entries_and_nonzero_directory_payloads_are_rejected(): void {
		$validator = new PackageIdentityValidator();
		$policy    = $this->policy( 'plugin', 'example-plugin', 'example-plugin.php', 'Example Plugin' );

		$encrypted = tempnam( sys_get_temp_dir(), 'ran-encrypted-' );
		self::assertIsString( $encrypted );
		$this->archives[] = $encrypted;
		$zip              = new \ZipArchive();
		self::assertTrue( $zip->open( $encrypted, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		self::assertTrue( $zip->addFromString( 'example-plugin/example-plugin.php', $this->header( 'Plugin Name', 'Example Plugin' ) ) );
		self::assertTrue( $zip->addFromString( 'example-plugin/secret.txt', 'classified' ) );
		self::assertTrue( $zip->setEncryptionName( 'example-plugin/secret.txt', \ZipArchive::EM_AES_256, 'password' ) );
		self::assertTrue( $zip->close() );

		$probe = new \ZipArchive();
		self::assertTrue( $probe->open( $encrypted ) );
		try {
			self::assertSame( 'archive_unreadable', \RAN\WPReleaseUpdater\V1\Archive\ArchiveScanner::scan( $probe )->failure_code() );
		} finally {
			$probe->close();
		}
		$this->assert_rejected(
			$validator->inspect_prospective( $this->prospective_policy( $encrypted, 'plugin' ), $encrypted ),
			'archive_unreadable'
		);
		self::assertSame(
			'archive_unreadable',
			$validator->validate( $this->descriptor( $encrypted, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $encrypted )->code()
		);

		$dir_payload = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->header( 'Plugin Name', 'Example Plugin' ),
				'example-plugin/sub/'               => 'unexpected-directory-bytes',
			),
			array(),
			array(
				'example-plugin/sub/' => array( \ZipArchive::OPSYS_UNIX, 0040755 << 16 ),
			)
		);
		$probe       = new \ZipArchive();
		self::assertTrue( $probe->open( $dir_payload ) );
		try {
			self::assertSame( 'archive_size_limit', \RAN\WPReleaseUpdater\V1\Archive\ArchiveScanner::scan( $probe )->failure_code() );
		} finally {
			$probe->close();
		}
		$this->assert_rejected(
			$validator->inspect_prospective( $this->prospective_policy( $dir_payload, 'plugin' ), $dir_payload ),
			'archive_size_limit'
		);
		self::assertSame(
			'archive_size_limit',
			$validator->validate( $this->descriptor( $dir_payload, 'plugin', 'example-plugin/example-plugin.php' ), $policy, $dir_payload )->code()
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

	/** @return array{package_root:string,main_file:string}|array{reason:string,detail:?string}|null */
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
