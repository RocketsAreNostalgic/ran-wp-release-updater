<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

use RAN\WPReleaseUpdater\V1\Dependency\PackageDirectory;
use RAN\WPReleaseUpdater\V1\Dependency\PluginFileIdentifier;
use RAN\WPReleaseUpdater\V1\Dependency\RegularFileStat;
use RAN\WPReleaseUpdater\V1\Dependency\WordPressHeaders;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;
use RAN\WPReleaseUpdater\V1\Dependency\ArchiveSafety;
use WeakMap;

/** Inspects a locally acquired ZIP; it never extracts or changes the filesystem. */
final class PackageIdentityValidator {

	public const MAX_EXPANDED_ARCHIVE_BYTES = ArchiveScanner::MAX_EXPANDED_ARCHIVE_BYTES;
	public const MAX_ARCHIVE_PATH_BYTES     = ArchiveSafety::MAX_PATH_BYTES;
	private const MAX_HEADER_BYTES          = WordPressHeaders::MAX_BYTES;
	private const PROSPECTIVE_POLICY_KEYS   = array( 'artifact_sha256', 'artifact_size', 'canonical_update_uri', 'maximum_artifact_bytes', 'php_runtime_version', 'target_type', 'version', 'wordpress_runtime_version' );
	private const POLICY_KEYS               = array( 'archive_root', 'configuration_update_uri', 'header_file', 'installed_package_identity', 'maximum_artifact_bytes', 'metadata_name', 'offer_update_uri', 'php_runtime_version', 'provider_code', 'repository_identity', 'repository_locator', 'staged_package_update_uri', 'target_type', 'theme_template', 'wordpress_runtime_version' );
	private ?\Closure $after_open;
	/** @var WeakMap<ValidatedPackage, array{descriptor_fingerprint:string,sha256:string,size:int,update_uri:string}> */
	private WeakMap $receipt_proofs;

	/** @internal The optional callback is a deterministic TOCTOU test seam. */
	public function __construct( ?\Closure $after_open = null ) {
		$this->after_open     = $after_open;
		$this->receipt_proofs = new WeakMap(); }
	private function __clone(): void {}

	/**
	 * @param array<string,mixed> $policy
	 * @return array{package_root:string,main_file:string}|array{reason:string,detail:?string}|null A package, a closed rejection reason, or null for a failure with no safe reason.
	 */
	public function inspect_prospective( array $policy, string $archive_path ): ?array {
		if ( count( $policy ) !== count( self::PROSPECTIVE_POLICY_KEYS ) ) {
			return null;
		}
		foreach ( self::PROSPECTIVE_POLICY_KEYS as $key ) {
			if ( ! array_key_exists( $key, $policy ) ) {
				return null;
			}
		}
		if (
			! is_string( $policy['artifact_sha256'] )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $policy['artifact_sha256'] )
			|| ! is_int( $policy['artifact_size'] )
			|| $policy['artifact_size'] < 1
			|| ! is_int( $policy['maximum_artifact_bytes'] )
			|| $policy['maximum_artifact_bytes'] < 1
			|| $policy['artifact_size'] > $policy['maximum_artifact_bytes']
			|| ! is_string( $policy['canonical_update_uri'] )
			|| CanonicalUpdateUri::canonicalize( $policy['canonical_update_uri'] ) !== $policy['canonical_update_uri']
			|| ! in_array( $policy['target_type'], array( 'plugin', 'theme' ), true )
			|| ! is_string( $policy['version'] )
			|| null === ReleaseVersion::normalize( $policy['version'] )
			|| ! is_string( $policy['php_runtime_version'] )
			|| null === ReleaseVersion::normalize_php_version( $policy['php_runtime_version'] )
			|| ! is_string( $policy['wordpress_runtime_version'] )
			|| null === ReleaseVersion::normalize_header( $policy['wordpress_runtime_version'] )
		) {
			return null;
		}
		$facts    = $policy;
		$identity = $this->archive_stat( $archive_path, $facts );
		if ( null === $identity || ! class_exists( '\\ZipArchive' ) ) {
			return null;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return self::rejected( 'archive_unreadable' );
		}
		try {
			if ( null !== $this->after_open ) {
				( $this->after_open )( $archive_path );
			}
			if ( ! $this->matches_archive_identity( $archive_path, $facts, $identity ) ) {
				return null;
			}
			$maximum_expanded_bytes = self::maximum_expanded_bytes( $policy['maximum_artifact_bytes'] );
			$scan                   = ArchiveScanner::scan( $zip, null, $maximum_expanded_bytes );
			$root                   = $scan->root();
			if ( ! $scan->is_valid() || ! is_string( $root ) ) {
				return self::rejected( 'archive_root_mismatch' === $scan->failure_code() ? 'archive_root_not_single' : (string) $scan->failure_code() );
			}
			$candidate      = null;
			$pending        = null;
			$expanded_bytes = 0;
			foreach ( $scan->entries() as $entry ) {
				$parts            = explode( '/', $entry['path'] );
				$is_theme_header  = $root . '/style.css' === $entry['path'];
				$is_plugin_header = 2 === count( $parts )
					&& ! $entry['directory']
					&& str_ends_with( $parts[1], '.php' );
				$header           = 'theme' === $policy['target_type']
					? ( $is_theme_header ? 'style.css' : null )
					: (
						$is_plugin_header
						? $parts[1]
						: null
					);
				$expected_size    = $entry['directory'] ? 0 : $entry['size'];
				$entry_identity   = self::stream_entry( $zip, $entry['name'], $expected_size, null !== $header ? self::MAX_HEADER_BYTES : 0 );
				if (
					null === $entry_identity
					|| $entry_identity['size'] > $maximum_expanded_bytes - $expanded_bytes
				) {
					return self::rejected( 'archive_unreadable' );
				}
				$expanded_bytes += $entry_identity['size'];
				if ( null === $header ) {
					continue;
				}
				$contents = $entry_identity['prefix'];
				$parsed   = self::parse_header( $contents, $policy['target_type'] );
				$metadata = isset( $parsed['headers']['Name'] ) ? $parsed['headers']['Name'] : null;
				if ( 'plugin' === $policy['target_type'] && null === $metadata ) {
					if ( array() !== WordPressHeaders::parse( $contents, array( 'Name' => 'Plugin Name' ) ) ) {
						$pending ??= self::header_rejection( $parsed );
					}
					continue;
				}
				if ( null === $metadata ) {
					$header_failure = self::header_rejection( $parsed );
					return self::rejected( $header_failure[0], $header_failure[1] );
				}
				$uri       = $parsed['headers']['UpdateURI'] ?? '';
				$version   = $parsed['headers']['Version'] ?? '';
				$php       = '' !== ( $parsed['headers']['RequiresPHP'] ?? '' ) ? $parsed['headers']['RequiresPHP'] : null;
				$wordpress = '' !== ( $parsed['headers']['RequiresWP'] ?? '' ) ? $parsed['headers']['RequiresWP'] : null;
				$failed    = match ( true ) {
					CanonicalUpdateUri::canonicalize( $uri ) !== $policy['canonical_update_uri'] => array( 'header_update_uri_mismatch', self::uri_detail( $uri, $policy['canonical_update_uri'] ) ),
					0 !== ReleaseVersion::compare( $version, $policy['version'] ) => array( 'header_version_mismatch', 'found ' . $version . ', expected ' . $policy['version'] ),
					null !== $php && ! self::meets_php_requirement( $policy['php_runtime_version'], $php ) => array( 'header_requires_php_unmet', $php ),
					null !== $wordpress && ! self::meets_requirement( $policy['wordpress_runtime_version'], $wordpress ) => array( 'header_requires_wp_unmet', $wordpress ),
					null !== $candidate => array( 'header_plugin_ambiguous', null ),
					default => null,
				};
				if ( null !== $failed ) {
					return self::rejected( $failed[0], $failed[1] );
				}
				$candidate = array(
					'package_root' => $root,
					'main_file'    => $header,
				);
			}
			if ( null === $candidate ) {
				return null === $pending ? self::rejected( 'no_package_header' ) : self::rejected( $pending[0], $pending[1] );
			}
			return $this->matches_archive_stat( $archive_path, $facts, $identity ) ? $candidate : null;
		} finally {
			$zip->close();
		}
	}

	/** Only a canonical, credential-free URI on the expected host may be echoed. */
	private static function uri_detail( string $uri, string $expected ): ?string {
		$canonical = CanonicalUpdateUri::canonicalize( $uri );
		$host      = static fn ( string $value ): string => (string) strtok( substr( $value, (int) strpos( $value, '//' ) + 2 ), '/' );
		return null !== $canonical && $host( $canonical ) === $host( $expected ) ? $canonical : null;
	}

	/** @return array{reason:string,detail:?string} */
	private static function rejected( string $reason, ?string $detail = null ): array {
		return array(
			'reason' => $reason,
			'detail' => $detail,
		);
	}

	/**
	 * @param array{code?:string,header?:string} $parsed
	 * @return array{string,?string}
	 */
	private static function header_rejection( array $parsed ): array {
		$header = $parsed['header'] ?? 'Name';
		return match ( $parsed['code'] ?? '' ) {
			'installed_header_ambiguous' => array( 'header_duplicate', $header ),
			'installed_header_invalid' => array( 'header_invalid', $header ),
			default => match ( $header ) {
				'Version' => array( 'header_version_missing', null ),
				'UpdateURI' => array( 'header_update_uri_missing', null ),
				default => array( 'header_name_missing', null ),
			},
		};
	}

	/**
	 * @param array<string, mixed> $policy Exact target and archive policy, not caller-controlled discovery hints.
	 */
	public function validate( IdentityDescriptor $descriptor, array $policy, string $archive_path ): ValidatedPackage {
		$descriptor_facts = $descriptor->to_array();
		if ( ! $this->valid_policy( $descriptor, $policy ) ) {
			return ValidatedPackage::blocked( 'archive_target_policy_invalid' );
		}
		$identity = $this->archive_stat( $archive_path, $descriptor_facts );
		if ( null === $identity ) {
			return ValidatedPackage::blocked( 'archive_file_identity_mismatch' );
		}
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return ValidatedPackage::blocked( 'archive_zip_extension_unavailable' );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return ValidatedPackage::blocked( 'archive_unreadable' );
		}
		try {
			$outcome = $this->validate_opened_archive( $zip, $descriptor, $descriptor_facts, $policy, $archive_path, $identity );
		} finally {
			$zip->close();
		}
		if ( ! $this->matches_archive_stat( $archive_path, $descriptor_facts, $identity ) ) {
			return ValidatedPackage::blocked( 'archive_file_identity_mismatch' );
		}
		return $outcome instanceof ValidatedPackage ? $outcome : $this->ready( $outcome );
	}

	/**
	 * @param array<string, mixed> $descriptor_facts
	 * @param array<string, mixed> $policy
	 * @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity
	 * @return ValidatedPackage|array{archive_root:string,author:string,description:string,header_file:string,metadata_name:string,package_type:string,package_uri:string,requires_php:string,requires_wp:string,descriptor_fingerprint:string,sha256:string,size:int,update_uri:string}
	 */
	private function validate_opened_archive(
		\ZipArchive $zip,
		IdentityDescriptor $descriptor,
		array $descriptor_facts,
		array $policy,
		string $archive_path,
		array $identity
	): ValidatedPackage|array {
		if ( null !== $this->after_open ) {
			( $this->after_open )( $archive_path );
		}
		if ( ! $this->matches_archive_identity( $archive_path, $descriptor_facts, $identity ) ) {
			return ValidatedPackage::blocked( 'archive_file_identity_mismatch' );
		}
		$maximum_expanded_bytes = self::maximum_expanded_bytes( $policy['maximum_artifact_bytes'] );
		$scan                   = ArchiveScanner::scan( $zip, $policy['archive_root'], $maximum_expanded_bytes );
		if ( ! $scan->is_valid() ) {
			return ValidatedPackage::blocked( $scan->failure_code() ?? 'archive_path_unsafe' );
		}
		$header         = null;
		$expanded_bytes = 0;
		$expected_path  = $policy['archive_root'] . '/' . $policy['header_file'];
		foreach ( $scan->entries() as $entry ) {
			$is_header = hash_equals( $expected_path, $entry['path'] );
			if ( $is_header && ( $entry['directory'] || null !== $header ) ) {
				return ValidatedPackage::blocked( 'archive_header_duplicate' );
			}
			if ( ! $entry['directory'] ) {
				$relative       = substr( $entry['path'], strlen( $policy['archive_root'] ) + 1 );
				$entry_identity = self::stream_entry( $zip, $entry['name'], $entry['size'], $is_header ? self::MAX_HEADER_BYTES : 0 );
				if ( '' === $relative || null === $entry_identity ) {
					return ValidatedPackage::blocked( $is_header ? 'archive_header_unreadable' : 'archive_entry_unreadable' );
				}
				if ( $entry_identity['size'] > $maximum_expanded_bytes - $expanded_bytes ) {
					return ValidatedPackage::blocked( 'archive_size_limit' );
				}
				$expanded_bytes += $entry_identity['size'];
				if ( $is_header ) {
					$header = $entry_identity['prefix'];
				}
			}
		}
		if ( ! is_string( $header ) ) {
			return ValidatedPackage::blocked( 'archive_header_missing' );
		}
		$parsed = self::parse_header( $header, $policy['target_type'] );
		if ( 'installed_header_verified' !== $parsed['code'] ) {
			return ValidatedPackage::blocked( self::archive_header_failure_code( $parsed ) );
		}
		$headers             = $parsed['headers'] ?? array();
		$name                = $headers['Name'] ?? null;
		$uri                 = $headers['UpdateURI'] ?? null;
		$version             = $headers['Version'] ?? null;
		$template            = $headers['Template'] ?? null;
		$requires_php        = '' !== ( $headers['RequiresPHP'] ?? '' ) ? $headers['RequiresPHP'] : null;
		$requires_word_press = '' !== ( $headers['RequiresWP'] ?? '' ) ? $headers['RequiresWP'] : null;
		if ( null === $name || ! hash_equals( $policy['metadata_name'], $name ) ) {
			return ValidatedPackage::blocked( 'archive_metadata_identity_mismatch' );
		}
		if ( null === $template || ! hash_equals( $policy['theme_template'], $template ) ) {
			return ValidatedPackage::blocked( 'archive_metadata_identity_mismatch' );
		}
		if ( null === $uri || null === CanonicalUpdateUri::canonicalize_boundaries(
			array(
				'archive_preflight' => $uri,
				'configuration'     => $policy['configuration_update_uri'],
				'offer'             => $policy['offer_update_uri'],
				'staged_package'    => $policy['staged_package_update_uri'],
			)
		) ) {
			return ValidatedPackage::blocked( 'archive_update_uri_mismatch' );
		}
		if ( null === $version || 0 !== ReleaseVersion::compare( $version, $descriptor_facts['version'] ) ) {
			return ValidatedPackage::blocked( 'archive_version_mismatch' );
		}
		if ( null !== $requires_php && ! self::meets_php_requirement( $policy['php_runtime_version'], $requires_php ) ) {
			return ValidatedPackage::blocked( 'archive_php_requirement_incompatible' );
		}
		if ( null !== $requires_word_press && ! self::meets_requirement( $policy['wordpress_runtime_version'], $requires_word_press ) ) {
			return ValidatedPackage::blocked( 'archive_wordpress_requirement_incompatible' );
		}
		return array(
			'archive_root'           => $policy['archive_root'],
			'author'                 => $headers['Author'] ?? '',
			'description'            => $headers['Description'] ?? '',
			'header_file'            => $policy['header_file'],
			'metadata_name'          => $name,
			'package_type'           => $policy['target_type'],
			'package_uri'            => $headers['PackageURI'] ?? '',
			'requires_php'           => $headers['RequiresPHP'] ?? '',
			'requires_wp'            => $headers['RequiresWP'] ?? '',
			'descriptor_fingerprint' => $descriptor->fingerprint_value(),
			'sha256'                 => $descriptor_facts['artifact_sha256'],
			'size'                   => $descriptor_facts['artifact_size'],
			'update_uri'             => $descriptor_facts['canonical_update_uri'],
		);
	}

	/** @internal Verify descriptor-bound bytes with a stable stat/hash/post-hash-stat observation. */
	public function verify_archive_identity( IdentityDescriptor $descriptor, string $archive_path ): bool {
		$facts    = $descriptor->to_array();
		$identity = $this->archive_stat( $archive_path, $facts );
		return null !== $identity && $this->matches_archive_identity( $archive_path, $facts, $identity );
	}

	/** @return array{descriptor_fingerprint:string,sha256:string,size:int,update_uri:string} */
	public function consume_receipt_proof( ValidatedPackage $package, IdentityDescriptor $descriptor ): array {
		if ( ! isset( $this->receipt_proofs[ $package ] ) ) {
			throw new \InvalidArgumentException( 'The package receipt proof is invalid.' );
		}
		$proof = $this->receipt_proofs[ $package ];
		$facts = $descriptor->to_array();
		if ( ! hash_equals( $proof['descriptor_fingerprint'], $descriptor->fingerprint_value() ) || ! hash_equals( $proof['sha256'], $facts['artifact_sha256'] ) || $proof['size'] !== $facts['artifact_size'] || ! hash_equals( $proof['update_uri'], $facts['canonical_update_uri'] ) ) {
			throw new \InvalidArgumentException( 'The package receipt proof is invalid.' );
		}
		unset( $this->receipt_proofs[ $package ] );
		return $proof;
	}

	/** @param array{archive_root:string,author:string,description:string,header_file:string,metadata_name:string,package_type:string,package_uri:string,requires_php:string,requires_wp:string,descriptor_fingerprint:string,sha256:string,size:int,update_uri:string} $snapshot */
	private function ready( array $snapshot ): ValidatedPackage {
		$package                          = ValidatedPackage::ready( $snapshot );
		$this->receipt_proofs[ $package ] = array(
			'descriptor_fingerprint' => $snapshot['descriptor_fingerprint'],
			'sha256'                 => $snapshot['sha256'],
			'size'                   => $snapshot['size'],
			'update_uri'             => $snapshot['update_uri'],
		);
		return $package; }

	private static function maximum_expanded_bytes( int $maximum_artifact_bytes ): int {
		if ( $maximum_artifact_bytes > intdiv( PHP_INT_MAX, 4 ) ) {
			return PHP_INT_MAX;
		}
		return ArchiveSafety::maximum_expanded_bytes( $maximum_artifact_bytes );
	}

	/** @param array<string, mixed> $policy */
	private function valid_policy( IdentityDescriptor $descriptor, array $policy ): bool {
		if ( count( $policy ) !== count( self::POLICY_KEYS ) ) {
			return false;
		}
		foreach ( self::POLICY_KEYS as $key ) {
			if ( ! array_key_exists( $key, $policy ) || ( 'maximum_artifact_bytes' === $key ? ! is_int( $policy[ $key ] ) : ! is_string( $policy[ $key ] ) ) ) {
				return false;
			}
		}
		if ( $policy['maximum_artifact_bytes'] < 1 || $descriptor->to_array()['artifact_size'] > $policy['maximum_artifact_bytes'] ) {
			return false;
		}
		if ( ( 'plugin' === $policy['target_type'] && '' !== $policy['theme_template'] ) || ( 'theme' === $policy['target_type'] && '' !== $policy['theme_template'] && ! PackageDirectory::is_valid_name( $policy['theme_template'] ) ) ) {
			return false;
		}
		$facts = $descriptor->to_array();
		foreach ( array( 'installed_package_identity', 'provider_code', 'repository_identity', 'repository_locator', 'target_type' ) as $key ) {
			if ( ! hash_equals( $facts[ $key ], $policy[ $key ] ) ) {
				return false;
			}
		}
		if ( ( 'plugin' === $policy['target_type'] && $policy['installed_package_identity'] !== $policy['archive_root'] . '/' . $policy['header_file'] ) || ( 'theme' === $policy['target_type'] && ( $policy['installed_package_identity'] !== $policy['archive_root'] || 'style.css' !== $policy['header_file'] ) ) ) {
			return false;
		}
		if (
			! PackageDirectory::is_valid_name( $policy['archive_root'] )
			|| ( 'plugin' === $policy['target_type']
				? ( ! str_ends_with( $policy['header_file'], '.php' ) || strlen( $policy['header_file'] ) > 104 || str_contains( $policy['header_file'], '/' ) || ! PluginFileIdentifier::is_valid( $policy['header_file'] ) )
				: 'style.css' !== $policy['header_file'] )
			|| '' === $policy['metadata_name']
			|| strlen( $policy['metadata_name'] ) > 500
			|| 1 === preg_match( '/[\x00-\x1f\x7f]/', $policy['metadata_name'] )
			|| null === ReleaseVersion::normalize_php_version( $policy['php_runtime_version'] )
			|| null === ReleaseVersion::normalize_header( $policy['wordpress_runtime_version'] )
		) {
			return false;
		}
		return CanonicalUpdateUri::canonicalize_boundaries(
			array(
				'archive_preflight' => $facts['canonical_update_uri'],
				'configuration'     => $policy['configuration_update_uri'],
				'offer'             => $policy['offer_update_uri'],
				'staged_package'    => $policy['staged_package_update_uri'],
			)
		) === $facts['canonical_update_uri'];
	}

	/**
	 * @param array<string,mixed> $facts
	 * @return array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null
	 */
	private function archive_stat( string $path, array $facts ): ?array {
		$stat = RegularFileStat::read( $path );
		if ( null === $stat || ! is_readable( $path ) || $stat['size'] !== $facts['artifact_size'] ) {
			return null;
		}
		return array(
			'dev'   => $stat['dev'],
			'ino'   => $stat['ino'],
			'mode'  => $stat['mode'],
			'mtime' => $stat['mtime'],
			'ctime' => $stat['ctime'],
			'size'  => $stat['size'],
		);
	}

	/**
	 * @phpstan-impure
	 * @param array<string,mixed> $facts
	 * @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity
	 */
	private function matches_archive_identity( string $path, array $facts, array $identity ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hash the local bytes and recheck native identity; unreadable or changed content is rejected quietly.
		$digest = @hash_file( 'sha256', $path );
		return is_string( $digest )
			&& hash_equals( $facts['artifact_sha256'], $digest )
			&& $this->matches_archive_stat( $path, $facts, $identity );
	}

	/**
	 * @phpstan-impure
	 * @param array<string,mixed> $facts
	 * @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity
	 */
	private function matches_archive_stat( string $path, array $facts, array $identity ): bool {
		$stat = RegularFileStat::read( $path );
		if ( null === $stat || $stat['size'] !== $facts['artifact_size'] ) {
			return false;
		}
		foreach ( $identity as $key => $value ) {
			if ( $stat[ $key ] !== $value ) {
				return false;
			}
		}
		return true;
	}

	/** @return array{size:int,prefix:string}|null */
	private static function stream_entry( \ZipArchive $zip, string $name, int $expected_size, int $capture_bytes = 0 ): ?array {
		$stream = $zip->getStream( $name );
		if ( ! is_resource( $stream ) ) {
			return null;
		}
		$size   = 0;
		$prefix = '';
		$valid  = true;
		while ( ! feof( $stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read bounded bytes from the already opened native stream; existing type, length and identity checks govern acceptance.
			$chunk = fread( $stream, 65536 );
			if ( ! is_string( $chunk ) || ( '' === $chunk && ! feof( $stream ) ) || strlen( $chunk ) > $expected_size - $size ) {
				$valid = false;
				break;
			}
			if ( $capture_bytes > 0 && strlen( $prefix ) < $capture_bytes ) {
				$prefix .= substr( $chunk, 0, $capture_bytes - strlen( $prefix ) );
			}
			$size += strlen( $chunk );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exact native stream owned by this operation; no WordPress filesystem abstraction applies.
		fclose( $stream );
		return $valid && $size === $expected_size ? array(
			'size'   => $size,
			'prefix' => $prefix,
		) : null; }
	/** @param array{code:string,header?:string} $parsed */
	private static function archive_header_failure_code( array $parsed ): string {
		return match ( $parsed['header'] ?? 'Name' ) {
			'UpdateURI' => 'archive_update_uri_mismatch',
			'Version' => 'archive_version_mismatch',
			'RequiresPHP' => 'archive_php_requirement_incompatible',
			'RequiresWP' => 'archive_wordpress_requirement_incompatible',
			default => 'archive_metadata_identity_mismatch',
		};
	}
	private static function meets_php_requirement( string $runtime_version, string $required_version ): bool {
		$normalized = ReleaseVersion::normalize_php_version( $runtime_version );
		return null !== $normalized && self::meets_requirement( $normalized, $required_version );
	}

	private static function meets_requirement( string $runtime_version, string $required_version ): bool {
		$comparison = ReleaseVersion::compare( $runtime_version, $required_version );
		return null !== $comparison && $comparison >= 0; }

	/**
	 * @internal
	 * @param array<string,string> $raw_headers Values extracted by WordPress Core get_file_data().
	 * @return array{code:string,header?:string,headers?:array<string,string>}
	 */
	public static function validate_extracted_headers( array $raw_headers, string $type ): array {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return array( 'code' => 'installed_header_invalid' );
		}
		$keys = array( 'Author', 'Description', 'Name', 'PackageURI', 'RequiresPHP', 'RequiresWP', 'UpdateURI', 'Version' );
		if ( 'theme' === $type ) {
			$keys[] = 'Template';
			sort( $keys, SORT_STRING );
		}
		$result = array(
			'Author'      => '',
			'Description' => '',
			'Name'        => '',
			'PackageURI'  => '',
			'RequiresPHP' => '',
			'RequiresWP'  => '',
			'Template'    => '',
			'UpdateURI'   => '',
			'Version'     => '',
		);
		foreach ( $keys as $key ) {
			$value = $raw_headers[ $key ] ?? '';
			if (
				! is_string( $value )
				|| ! preg_match( '//u', $value )
				|| strlen( $value ) > 500
				|| 1 === preg_match( '/[\\p{Cc}\\p{Cf}]/u', $value )
			) {
				return array(
					'code'   => 'installed_header_invalid',
					'header' => $key,
				);
			}
			$result[ $key ] = $value;
		}
		if ( 'plugin' === $type ) {
			$result['Template'] = '';
		}
		foreach ( array( 'Name', 'Version', 'UpdateURI' ) as $key ) {
			if ( '' === $result[ $key ] ) {
				return array(
					'code'   => 'installed_header_missing',
					'header' => $key,
				);
			}
		}
		if ( null === ReleaseVersion::normalize_header( $result['Version'] ) ) {
			return array(
				'code'   => 'installed_header_invalid',
				'header' => 'Version',
			);
		}
		if (
			'theme' === $type
			&& '' !== $result['Template']
			&& ! PackageDirectory::is_valid_name( $result['Template'] )
		) {
			return array(
				'code'   => 'installed_header_invalid',
				'header' => 'Template',
			);
		}
		return array(
			'code'    => 'installed_header_verified',
			'headers' => $result,
		);
	}

	/**
	 * @internal
	 * @return array{code:string,header?:string,headers?:array<string,string>,present?:array<string,bool>}
	 * Shared bounded WordPress header projection.
	 */
	public static function parse_header( string $contents, string $type ): array {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return array( 'code' => 'installed_header_invalid' );
		}
		if ( str_starts_with( $contents, "\xEF\xBB\xBF" ) ) {
			$contents = substr( $contents, 3 );
		}
		$contents             = str_replace(
			array( "\r\n", "\r" ),
			"\n",
			substr( $contents, 0, self::MAX_HEADER_BYTES )
		);
		$name_label           = 'theme' === $type ? 'Theme Name' : 'Plugin Name';
		$scopes               = self::header_scopes( $contents, $name_label );
		$primary_labels       = array(
			'Author'      => 'Author',
			'Description' => 'Description',
			'Name'        => $name_label,
			'PackageURI'  => 'theme' === $type ? 'Theme URI' : 'Plugin URI',
			'UpdateURI'   => 'Update URI',
			'Version'     => 'Version',
		);
		$compatibility_labels = array(
			'RequiresPHP' => 'Requires PHP',
			'RequiresWP'  => 'Requires at least',
		);
		if ( 'theme' === $type ) {
			$primary_labels['Template'] = 'Template';
		}
		$labels = $primary_labels + $compatibility_labels;
		ksort( $labels, SORT_STRING );
		$extracted = WordPressHeaders::parse( $scopes['primary'], $primary_labels )
			+ WordPressHeaders::parse( $scopes['compatibility'], $compatibility_labels );
		$result    = array();
		$present   = array();
		foreach ( $labels as $key => $label ) {
			unset( $label );
			$matches = $extracted[ $key ] ?? array();
			$count   = count( $matches );
			if ( 1 < $count ) {
				return array(
					'code'   => 'installed_header_ambiguous',
					'header' => $key,
				);
			}
			$value = 1 === $count ? $matches[0] : '';
			if (
				! preg_match( '//u', $value )
				|| strlen( $value ) > 500
				|| 1 === preg_match( '/[\\p{Cc}\\p{Cf}]/u', $value )
			) {
				return array(
					'code'   => 'installed_header_invalid',
					'header' => $key,
				);
			}
			$result[ $key ]  = $value;
			$present[ $key ] = 1 === $count;
		}
		if ( 'plugin' === $type ) {
			$result['Template']  = '';
			$present['Template'] = false;
			ksort( $result, SORT_STRING );
			ksort( $present, SORT_STRING );
		}
		foreach ( array( 'Name', 'Version', 'UpdateURI' ) as $key ) {
			if ( '' === $result[ $key ] ) {
				return array(
					'code'   => 'installed_header_missing',
					'header' => $key,
				);
			}
		}
		if ( null === ReleaseVersion::normalize_header( $result['Version'] ) ) {
			return array(
				'code'   => 'installed_header_invalid',
				'header' => 'Version',
			);
		}
		if (
			'theme' === $type
			&& isset( $result['Template'] )
			&& '' !== $result['Template']
			&& ! PackageDirectory::is_valid_name( $result['Template'] )
		) {
			return array(
				'code'   => 'installed_header_invalid',
				'header' => 'Template',
			);
		}
		return array(
			'code'    => 'installed_header_verified',
			'headers' => $result,
			'present' => $present,
		);
	}

	/**
	 * Isolates the primary header comment block and contiguous header-region comment blocks
	 * so split compatibility headers are preserved while PHPDoc/CSS comments after code do not collide.
	 *
	 * @return array{primary:string,compatibility:string}
	 */
	private static function header_scopes( string $contents, string $name_label ): array {
		$name_pattern = '/^[ \t]*(?:<\?php[ \t]*)?[\/*#@ \t]*' . preg_quote( $name_label, '/' ) . ':/mi';
		if ( 1 > preg_match_all( '/\/\*.*?\*\//s', $contents, $blocks, PREG_OFFSET_CAPTURE ) ) {
			return array(
				'primary'       => $contents,
				'compatibility' => $contents,
			);
		}
		foreach ( $blocks[0] as $index => $block ) {
			$text = $block[0];
			if ( 1 !== preg_match( $name_pattern, $text ) ) {
				continue;
			}
			$has_core_sibling = 1 === preg_match( '/^[ \t]*(?:<\?php[ \t]*)?[\/*#@ \t]*(?:Version|Update URI):/mi', $text );
			$has_later_block  = isset( $blocks[0][ $index + 1 ] );
			if ( ! $has_core_sibling && ! $has_later_block ) {
				return array(
					'primary'       => $contents,
					'compatibility' => $contents,
				);
			}
			$region       = array( $text );
			$cursor_start = $block[1];
			for ( $prev = $index - 1; $prev >= 0; --$prev ) {
				$prev_end = $blocks[0][ $prev ][1] + strlen( $blocks[0][ $prev ][0] );
				$between  = substr( $contents, $prev_end, $cursor_start - $prev_end );
				if ( ! self::is_header_region_separator( $between ) ) {
					break;
				}
				array_unshift( $region, $blocks[0][ $prev ][0] );
				$cursor_start = $blocks[0][ $prev ][1];
			}
			$cursor_end  = $block[1] + strlen( $text );
			$block_count = count( $blocks[0] );
			for ( $next = $index + 1; $next < $block_count; ++$next ) {
				$next_start = $blocks[0][ $next ][1];
				$between    = substr( $contents, $cursor_end, $next_start - $cursor_end );
				if ( ! self::is_header_region_separator( $between ) ) {
					break;
				}
				$region[]   = $blocks[0][ $next ][0];
				$cursor_end = $next_start + strlen( $blocks[0][ $next ][0] );
			}
			return array(
				'primary'       => $text,
				'compatibility' => implode( "\n", $region ),
			);
		}
		return array(
			'primary'       => $contents,
			'compatibility' => $contents,
		);
	}

	private static function is_header_region_separator( string $between ): bool {
		return 1 === preg_match( '/\A(?:[ \t\r\n]|<\?php\b|\?>|\/\/[^\n]*\n?|#(?!\[)[^\n]*\n?)*\z/', $between );
	}
}
