<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;
use RAN\WPReleaseUpdater\V1\Dependency\ArchiveSafety;
use WeakMap;

/** Inspects a locally acquired ZIP; it never extracts or changes the filesystem. */
final class PackageIdentityValidator {

	public const MAX_EXPANDED_ARCHIVE_BYTES = ArchiveScanner::MAX_EXPANDED_ARCHIVE_BYTES;
	public const MAX_ARCHIVE_PATH_BYTES     = ArchiveSafety::MAX_PATH_BYTES;
	private const MAX_ARCHIVE_ENTRIES       = ArchiveScanner::MAX_ENTRIES;
	private const MAX_HEADER_BYTES          = 8192;
	private const PROSPECTIVE_POLICY_KEYS   = array( 'artifact_sha256', 'artifact_size', 'canonical_update_uri', 'maximum_artifact_bytes', 'php_runtime_version', 'target_type', 'version', 'wordpress_runtime_version' );
	private const POLICY_KEYS               = array( 'archive_root', 'configuration_update_uri', 'header_file', 'installed_package_identity', 'maximum_artifact_bytes', 'metadata_name', 'offer_update_uri', 'php_runtime_version', 'provider_code', 'repository_identity', 'repository_locator', 'staged_package_update_uri', 'target_type', 'theme_template', 'wordpress_runtime_version' );
	private ?\Closure $after_open;
	/** @var WeakMap<ValidatedPackage, array{descriptor_fingerprint:string,manifest_entry_count:int,manifest_expanded_bytes:int,manifest_hash:string,sha256:string,size:int,update_uri:string}> */
	private WeakMap $receipt_proofs;

	/** @internal The optional callback is a deterministic TOCTOU test seam. */
	public function __construct( ?\Closure $after_open = null ) {
		$this->after_open     = $after_open;
		$this->receipt_proofs = new WeakMap(); }
	private function __clone(): void {}

	/**
	 * @param array<string,mixed> $policy
	 * @return array{package_root:string,main_file:string}|null
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
			|| null === ReleaseVersion::normalize_header( $policy['php_runtime_version'] )
			|| ! is_string( $policy['wordpress_runtime_version'] )
			|| null === ReleaseVersion::normalize_header( $policy['wordpress_runtime_version'] )
		) {
			return null;
		}
		$facts    = $policy;
		$identity = $this->archive_identity( $archive_path, $facts );
		if ( null === $identity || ! class_exists( '\\ZipArchive' ) ) {
			return null;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return null;
		}
		try {
			if ( null !== $this->after_open ) {
				( $this->after_open )( $archive_path );
			}
			if ( ! $this->matches_archive_identity( $archive_path, $facts, $identity ) ) {
				return null;
			}
			$scan = ArchiveScanner::scan( $zip );
			$root = $scan->root();
			if ( ! $scan->is_valid() || ! is_string( $root ) ) {
				return null;
			}
			$candidate = null;
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
				if ( null === $header ) {
					continue;
				}
				$contents = self::read_header( $zip, $entry['name'] );
				$parsed   = is_string( $contents ) ? self::parse_header( $contents, $policy['target_type'] ) : array();
				$metadata = isset( $parsed['headers']['Name'] ) ? $parsed['headers']['Name'] : null;
				if ( 'plugin' === $policy['target_type'] && null === $metadata ) {
					continue;
				}
				if ( null === $contents || null === $metadata ) {
					return null;
				}
				$uri       = $parsed['headers']['UpdateURI'] ?? null;
				$version   = $parsed['headers']['Version'] ?? null;
				$php       = $parsed['headers']['RequiresPHP'] ?? null;
				$wordpress = $parsed['headers']['RequiresWP'] ?? null;
				if (
					null === $uri
					|| null === $version
					|| CanonicalUpdateUri::canonicalize( $uri ) !== $policy['canonical_update_uri']
					|| 0 !== ReleaseVersion::compare( $version, $policy['version'] )
					|| ! is_string( $php )
					|| ! self::meets_requirement( $policy['php_runtime_version'], $php )
					|| ! is_string( $wordpress )
					|| ! self::meets_requirement( $policy['wordpress_runtime_version'], $wordpress )
					|| null !== $candidate
				) {
					return null;
				}
				$candidate = array(
					'package_root' => $root,
					'main_file'    => $header,
				);
			}
			return $this->matches_archive_identity( $archive_path, $facts, $identity ) ? $candidate : null;
		} finally {
			$zip->close();
		}
	}

	/**
	 * @param array<string, mixed> $policy Exact target and archive policy, not caller-controlled discovery hints.
	 */
	public function validate( IdentityDescriptor $descriptor, array $policy, string $archive_path ): ValidatedPackage {
		$descriptor_facts = $descriptor->to_array();
		if ( ! $this->valid_policy( $descriptor, $policy ) ) {
			return ValidatedPackage::blocked( 'archive_target_policy_invalid' );
		}
		$identity = $this->archive_identity( $archive_path, $descriptor_facts );
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
			if ( null !== $this->after_open ) {
				( $this->after_open )( $archive_path );
			}
			if ( ! $this->matches_archive_identity( $archive_path, $descriptor_facts, $identity ) ) {
				return ValidatedPackage::blocked( 'archive_file_identity_mismatch' );
			}
			$scan = ArchiveScanner::scan( $zip, $policy['archive_root'] );
			if ( ! $scan->is_valid() ) {
				return ValidatedPackage::blocked( $scan->failure_code() ?? 'archive_path_unsafe' );
			}
			$header         = null;
			$manifest       = array();
			$manifest_bytes = 0;
			$expected_path  = $policy['archive_root'] . '/' . $policy['header_file'];
			foreach ( $scan->entries() as $entry ) {
				if ( ! $entry['directory'] ) {
					$relative       = substr( $entry['path'], strlen( $policy['archive_root'] ) + 1 );
					$entry_identity = self::entry_identity( $zip, $entry['name'], $entry['size'] );
					if ( '' === $relative || null === $entry_identity ) {
						return ValidatedPackage::blocked( 'archive_entry_unreadable' );
					}
					$manifest[ $relative ] = $entry_identity;
					$manifest_bytes       += $entry_identity['size'];
				}
				if ( ! hash_equals( $expected_path, $entry['path'] ) ) {
					continue;
				}
				if ( $entry['directory'] || null !== $header ) {
					return ValidatedPackage::blocked( 'archive_header_duplicate' );
				}
				$header = self::read_header( $zip, $entry['name'] );
				if ( null === $header ) {
					return ValidatedPackage::blocked( 'archive_header_unreadable' );
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
			$requires_php        = true === ( $parsed['present']['RequiresPHP'] ?? false )
				? $headers['RequiresPHP']
				: false;
			$requires_word_press = true === ( $parsed['present']['RequiresWP'] ?? false )
				? $headers['RequiresWP']
				: false;
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
			if ( null === $requires_php || ( is_string( $requires_php ) && ! self::meets_requirement( $policy['php_runtime_version'], $requires_php ) ) ) {
				return ValidatedPackage::blocked( 'archive_php_requirement_incompatible' );
			}
			if ( null === $requires_word_press || ( is_string( $requires_word_press ) && ! self::meets_requirement( $policy['wordpress_runtime_version'], $requires_word_press ) ) ) {
				return ValidatedPackage::blocked( 'archive_wordpress_requirement_incompatible' );
			}
			ksort( $manifest, SORT_STRING );
			$manifest_json = json_encode( $manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			return $this->ready(
				array(
					'archive_root'            => $policy['archive_root'],
					'header_file'             => $policy['header_file'],
					'manifest_entry_count'    => count( $manifest ),
					'manifest_expanded_bytes' => $manifest_bytes,
					'manifest_hash'           => hash( 'sha256', $manifest_json ),
					'metadata_name'           => $name,
					'package_type'            => $policy['target_type'],
					'descriptor_fingerprint'  => $descriptor->fingerprint_value(),
					'sha256'                  => $descriptor_facts['artifact_sha256'],
					'size'                    => $descriptor_facts['artifact_size'],
					'update_uri'              => $descriptor_facts['canonical_update_uri'],
				)
			);
		} finally {
			$zip->close();
			if ( ! $this->matches_archive_identity( $archive_path, $descriptor_facts, $identity ) ) {
				return ValidatedPackage::blocked( 'archive_file_identity_mismatch' );
			}
		}
	}

	/** @return array{descriptor_fingerprint:string,manifest_entry_count:int,manifest_expanded_bytes:int,manifest_hash:string,sha256:string,size:int,update_uri:string} */
	public function consume_receipt_proof( ValidatedPackage $package, IdentityDescriptor $descriptor ): array {
		if ( ! isset( $this->receipt_proofs[ $package ] ) ) {
			throw new \InvalidArgumentException( 'The package receipt proof is invalid.' );
		}
		$proof = $this->receipt_proofs[ $package ];
		$facts = $descriptor->to_array();
		if ( ! hash_equals( $proof['descriptor_fingerprint'], $descriptor->fingerprint_value() ) || ! is_int( $proof['manifest_entry_count'] ) || $proof['manifest_entry_count'] < 1 || $proof['manifest_entry_count'] > self::MAX_ARCHIVE_ENTRIES || ! is_int( $proof['manifest_expanded_bytes'] ) || $proof['manifest_expanded_bytes'] < 0 || $proof['manifest_expanded_bytes'] > self::MAX_EXPANDED_ARCHIVE_BYTES || ! is_string( $proof['manifest_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $proof['manifest_hash'] ) || ! hash_equals( $proof['sha256'], $facts['artifact_sha256'] ) || $proof['size'] !== $facts['artifact_size'] || ! hash_equals( $proof['update_uri'], $facts['canonical_update_uri'] ) ) {
			throw new \InvalidArgumentException( 'The package receipt proof is invalid.' );
		}
		unset( $this->receipt_proofs[ $package ] );
		return array(
			'descriptor_fingerprint'  => $proof['descriptor_fingerprint'],
			'manifest_entry_count'    => $proof['manifest_entry_count'],
			'manifest_expanded_bytes' => $proof['manifest_expanded_bytes'],
			'manifest_hash'           => $proof['manifest_hash'],
			'sha256'                  => $proof['sha256'],
			'size'                    => $proof['size'],
			'update_uri'              => $proof['update_uri'],
		);
	}

	/** @param array{archive_root:string,header_file:string,manifest_entry_count:int,manifest_expanded_bytes:int,manifest_hash:string,metadata_name:string,package_type:string,descriptor_fingerprint:string,sha256:string,size:int,update_uri:string} $snapshot */
	private function ready( array $snapshot ): ValidatedPackage {
		$package                          = ValidatedPackage::ready( $snapshot );
		$this->receipt_proofs[ $package ] = array(
			'descriptor_fingerprint'  => $snapshot['descriptor_fingerprint'],
			'manifest_entry_count'    => $snapshot['manifest_entry_count'],
			'manifest_expanded_bytes' => $snapshot['manifest_expanded_bytes'],
			'manifest_hash'           => $snapshot['manifest_hash'],
			'sha256'                  => $snapshot['sha256'],
			'size'                    => $snapshot['size'],
			'update_uri'              => $snapshot['update_uri'],
		);
		return $package; }

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
		if ( ( 'plugin' === $policy['target_type'] && '' !== $policy['theme_template'] ) || ( 'theme' === $policy['target_type'] && '' !== $policy['theme_template'] && 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $policy['theme_template'] ) ) ) {
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
		if ( ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $policy['archive_root'] ) || ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.(?:php|css)\z/D', $policy['header_file'] ) || ( 'plugin' === $policy['target_type'] && ! str_ends_with( $policy['header_file'], '.php' ) ) || ( 'theme' === $policy['target_type'] && 'style.css' !== $policy['header_file'] ) || '' === $policy['metadata_name'] || strlen( $policy['metadata_name'] ) > 500 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $policy['metadata_name'] ) || null === ReleaseVersion::normalize_header( $policy['php_runtime_version'] ) || null === ReleaseVersion::normalize_header( $policy['wordpress_runtime_version'] ) ) {
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
	private function archive_identity( string $path, array $facts ): ?array {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( ! is_array( $stat ) || ! is_readable( $path ) || ( $stat['mode'] & 0170000 ) !== 0100000 || $stat['size'] !== $facts['artifact_size'] ) {
			return null;
		}
		$identity = array(
			'dev'   => $stat['dev'],
			'ino'   => $stat['ino'],
			'mode'  => $stat['mode'],
			'mtime' => $stat['mtime'],
			'ctime' => $stat['ctime'],
			'size'  => $stat['size'],
		);
		return $this->matches_archive_identity( $path, $facts, $identity ) ? $identity : null;
	}

	/**
	 * @phpstan-impure
	 * @param array<string,mixed> $facts
	 * @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity
	 */
	private function matches_archive_identity( string $path, array $facts, array $identity ): bool {
		$digest = @hash_file( 'sha256', $path );
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( ! is_string( $digest ) || ! hash_equals( $facts['artifact_sha256'], $digest ) || ! is_array( $stat ) || $stat['size'] !== $facts['artifact_size'] ) {
			return false;
		}
		foreach ( $identity as $key => $value ) {
			if ( ! isset( $stat[ $key ] ) || $stat[ $key ] !== $value ) {
				return false;
			}
		}
		return ( $stat['mode'] & 0170000 ) === 0100000;
	}

	private static function read_header( \ZipArchive $zip, string $name ): ?string {
		$stream = $zip->getStream( $name );
		if ( ! is_resource( $stream ) ) {
			return null;
		} $contents = stream_get_contents( $stream, self::MAX_HEADER_BYTES );
		fclose( $stream );
		return is_string( $contents ) ? $contents : null; }
	/** @return array{sha256:string,size:int}|null */
	private static function entry_identity( \ZipArchive $zip, string $name, int $expected_size ): ?array {
		$stream = $zip->getStream( $name );
		if ( ! is_resource( $stream ) ) {
			return null;
		} $context = hash_init( 'sha256' );
		$size      = 0;
		$valid     = true;
		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 65536 );
			if ( ! is_string( $chunk ) || ( '' === $chunk && ! feof( $stream ) ) || strlen( $chunk ) > $expected_size - $size ) {
				$valid = false;
				break;
			} $size += strlen( $chunk );
			hash_update( $context, $chunk );
		} fclose( $stream );
		return $valid && $size === $expected_size ? array(
			'sha256' => hash_final( $context ),
			'size'   => $size,
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
	private static function meets_requirement( string $runtime_version, string $required_version ): bool {
		$comparison = ReleaseVersion::compare( $runtime_version, $required_version );
		return null !== $comparison && $comparison >= 0; }

	/**
	 * @internal
	 * @return array{code:string,header?:string,headers?:array<string,string>,present?:array<string,bool>}
	 * Shared bounded WordPress header projection.
	 */
	public static function parse_header( string $contents, string $type ): array {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return array( 'code' => 'installed_header_invalid' );
		}
		$contents = str_replace(
			array( "\r\n", "\r" ),
			"\n",
			substr( $contents, 0, self::MAX_HEADER_BYTES )
		);
		$labels   = array(
			'Author'      => 'Author',
			'Description' => 'Description',
			'Name'        => 'theme' === $type ? 'Theme Name' : 'Plugin Name',
			'PackageURI'  => 'theme' === $type ? 'Theme URI' : 'Plugin URI',
			'RequiresPHP' => 'Requires PHP',
			'RequiresWP'  => 'Requires at least',
			'Template'    => 'Template',
			'UpdateURI'   => 'Update URI',
			'Version'     => 'Version',
		);
		$result   = array();
		$present  = array();
		foreach ( $labels as $key => $label ) {
			$pattern = '/^[ \\t]*(?:<\\?php[ \\t]*)?[\\/*#@ \\t]*'
				. preg_quote( $label, '/' )
				. ':[ \\t]*(.*)$/mi';
			$count   = preg_match_all( $pattern, $contents, $matches );
			if ( 1 < $count ) {
				return array(
					'code'   => 'installed_header_ambiguous',
					'header' => $key,
				);
			}
			$value = 1 === $count
				? preg_replace( '/(?:\\*\\/|\\?>).*$/', '', $matches[1][0] )
				: '';
			$value = is_string( $value ) ? trim( $value, " \t\n\v\f" ) : null;
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
			$result[ $key ]  = $value;
			$present[ $key ] = 1 === $count;
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
		if ( 'plugin' === $type ) {
			$result['Template'] = '';
		} elseif (
			'' !== $result['Template']
			&& 1 !== preg_match(
				'/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}\z/D',
				$result['Template']
			)
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
}
