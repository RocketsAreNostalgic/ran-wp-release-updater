<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;
use WeakMap;

/** Inspects a locally acquired ZIP; it never extracts or changes the filesystem. */
final class PackageIdentityValidator {

	public const MAX_EXPANDED_ARCHIVE_BYTES = 127826407;
	public const MAX_ARCHIVE_PATH_BYTES = 4096;
	private const MAX_ARCHIVE_ENTRIES = 10000;
	private const MAX_HEADER_BYTES = 8192;
	private const MAX_COMPRESSION_RATIO = 100;
	private const POLICY_KEYS = array( 'archive_root', 'configuration_update_uri', 'header_file', 'installed_package_identity', 'metadata_name', 'offer_update_uri', 'php_runtime_version', 'provider_code', 'repository_identity', 'repository_locator', 'staged_package_update_uri', 'target_type', 'wordpress_runtime_version' );
	private ?\Closure $afterOpen = null;
	private WeakMap $receiptProofs;

	public function __construct() { $this->receiptProofs = new WeakMap(); }
	private function __clone(): void {}


	/**
	 * @param array<string, mixed> $policy Exact target and archive policy, not caller-controlled discovery hints.
	 */
	public function validate( IdentityDescriptor $descriptor, array $policy, string $archivePath ): ValidatedPackage {
		$descriptorFacts = $descriptor->toArray();
		if ( ! $this->validPolicy( $descriptor, $policy ) ) return ValidatedPackage::blocked( 'archive_target_policy_invalid' );
		$identity = $this->archiveIdentity( $archivePath, $descriptorFacts );
		if ( null === $identity ) return ValidatedPackage::blocked( 'archive_file_identity_mismatch' );
		if ( ! class_exists( '\\ZipArchive' ) ) return ValidatedPackage::blocked( 'archive_zip_extension_unavailable' );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archivePath ) ) return ValidatedPackage::blocked( 'archive_unreadable' );
		try {
			if ( null !== $this->afterOpen ) ( $this->afterOpen )( $archivePath );
			if ( ! $this->matchesArchiveIdentity( $archivePath, $descriptorFacts, $identity ) ) return ValidatedPackage::blocked( 'archive_file_identity_mismatch' );
			if ( $zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES ) return ValidatedPackage::blocked( 'archive_entry_limit' );
			$seen = array(); $expanded = 0; $header = null; $manifest = array(); $manifestBytes = 0;
			$expectedPath = $policy['archive_root'] . '/' . $policy['header_file'];
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$name = $zip->getNameIndex( $index, \ZipArchive::FL_UNCHANGED );
				$stat = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
				$path = is_string( $name ) ? self::normalizePath( $name ) : null;
				if ( null === $path || ! is_array( $stat ) || ! isset( $stat['size'], $stat['comp_size'] ) || ! is_int( $stat['size'] ) || ! is_int( $stat['comp_size'] ) || $stat['size'] < 0 || $stat['comp_size'] < 0 || self::isSpecialEntry( $zip, $index ) ) return ValidatedPackage::blocked( 'archive_path_unsafe' );
				if ( $stat['size'] > self::MAX_EXPANDED_ARCHIVE_BYTES - $expanded || ( $stat['size'] > 0 && ( 0 === $stat['comp_size'] || $stat['size'] > self::MAX_COMPRESSION_RATIO * $stat['comp_size'] ) ) ) return ValidatedPackage::blocked( 'archive_size_limit' );
				$expanded += $stat['size']; $key = strtolower( $path['path'] );
				if ( isset( $seen[ $key ] ) ) return ValidatedPackage::blocked( 'archive_path_duplicate' );
				$seen[ $key ] = true;
				$parts = explode( '/', $path['path'] );
				if ( ! hash_equals( $policy['archive_root'], $parts[0] ) || ( 1 === count( $parts ) && ! $path['directory'] ) ) return ValidatedPackage::blocked( 'archive_root_mismatch' );
				if ( ! $path['directory'] ) { $relative = substr( $path['path'], strlen( $policy['archive_root'] ) + 1 ); $entry = self::entryIdentity( $zip, $name, $stat['size'] ); if ( '' === $relative || null === $entry ) return ValidatedPackage::blocked( 'archive_entry_unreadable' ); $manifest[ $relative ] = $entry; $manifestBytes += $entry['size']; }
				if ( ! hash_equals( $expectedPath, $path['path'] ) ) continue;
				if ( $path['directory'] || null !== $header ) return ValidatedPackage::blocked( 'archive_header_duplicate' );
				$header = self::readHeader( $zip, $name );
				if ( null === $header ) return ValidatedPackage::blocked( 'archive_header_unreadable' );
			}
			if ( ! is_string( $header ) ) return ValidatedPackage::blocked( 'archive_header_missing' );
			$name = self::headerValue( $header, 'theme' === $policy['target_type'] ? 'Theme Name' : 'Plugin Name' );
			$uri = self::headerValue( $header, 'Update URI' );
			$version = self::headerValue( $header, 'Version' );
			$requiresPhp = self::optionalHeaderValue( $header, 'Requires PHP' );
			$requiresWordPress = self::optionalHeaderValue( $header, 'Requires at least' );
			if ( null === $name || ! hash_equals( $policy['metadata_name'], $name ) ) return ValidatedPackage::blocked( 'archive_metadata_identity_mismatch' );
			if ( null === $uri || null === CanonicalUpdateUri::canonicalizeBoundaries( array( 'archive_preflight' => $uri, 'configuration' => $policy['configuration_update_uri'], 'offer' => $policy['offer_update_uri'], 'staged_package' => $policy['staged_package_update_uri'] ) ) ) return ValidatedPackage::blocked( 'archive_update_uri_mismatch' );
			if ( null === $version || 0 !== ReleaseVersion::compare( $version, $descriptorFacts['version'] ) ) return ValidatedPackage::blocked( 'archive_version_mismatch' );
			if ( null === $requiresPhp || ( is_string( $requiresPhp ) && ! self::meetsRequirement( $policy['php_runtime_version'], $requiresPhp ) ) ) return ValidatedPackage::blocked( 'archive_php_requirement_incompatible' );
			if ( null === $requiresWordPress || ( is_string( $requiresWordPress ) && ! self::meetsRequirement( $policy['wordpress_runtime_version'], $requiresWordPress ) ) ) return ValidatedPackage::blocked( 'archive_wordpress_requirement_incompatible' );
			ksort( $manifest, SORT_STRING ); $manifestJson = json_encode( $manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			return $this->ready( array( 'archive_root' => $policy['archive_root'], 'header_file' => $policy['header_file'], 'manifest_entry_count' => count( $manifest ), 'manifest_expanded_bytes' => $manifestBytes, 'manifest_hash' => hash( 'sha256', $manifestJson ), 'metadata_name' => $name, 'package_type' => $policy['target_type'], 'descriptor_fingerprint' => $descriptor->fingerprintValue(), 'sha256' => $descriptorFacts['artifact_sha256'], 'size' => $descriptorFacts['artifact_size'], 'update_uri' => $descriptorFacts['canonical_update_uri'] ) );
		} finally {
			$zip->close();
			if ( ! $this->matchesArchiveIdentity( $archivePath, $descriptorFacts, $identity ) ) return ValidatedPackage::blocked( 'archive_file_identity_mismatch' );
		}
	}

	/** @return array{descriptor_fingerprint:string,manifest_entry_count:int,manifest_expanded_bytes:int,manifest_hash:string,sha256:string,size:int,update_uri:string} */
	public function consumeReceiptProof( ValidatedPackage $package, IdentityDescriptor $descriptor ): array {
		if ( ! isset( $this->receiptProofs[ $package ] ) ) throw new \InvalidArgumentException( 'The package receipt proof is invalid.' );
		$proof = $this->receiptProofs[ $package ]; $facts = $descriptor->toArray();
		if ( ! hash_equals( $proof['descriptor_fingerprint'], $descriptor->fingerprintValue() ) || ! is_int( $proof['manifest_entry_count'] ) || $proof['manifest_entry_count'] < 1 || $proof['manifest_entry_count'] > self::MAX_ARCHIVE_ENTRIES || ! is_int( $proof['manifest_expanded_bytes'] ) || $proof['manifest_expanded_bytes'] < 0 || $proof['manifest_expanded_bytes'] > self::MAX_EXPANDED_ARCHIVE_BYTES || ! is_string( $proof['manifest_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $proof['manifest_hash'] ) || ! hash_equals( $proof['sha256'], $facts['artifact_sha256'] ) || $proof['size'] !== $facts['artifact_size'] || ! hash_equals( $proof['update_uri'], $facts['canonical_update_uri'] ) ) throw new \InvalidArgumentException( 'The package receipt proof is invalid.' );
		unset( $this->receiptProofs[ $package ] );
		return array( 'descriptor_fingerprint' => $proof['descriptor_fingerprint'], 'manifest_entry_count' => $proof['manifest_entry_count'], 'manifest_expanded_bytes' => $proof['manifest_expanded_bytes'], 'manifest_hash' => $proof['manifest_hash'], 'sha256' => $proof['sha256'], 'size' => $proof['size'], 'update_uri' => $proof['update_uri'] );
	}

	/** @param array<string, scalar> $snapshot */
	private function ready( array $snapshot ): ValidatedPackage { $package = ValidatedPackage::ready( $snapshot ); $this->receiptProofs[ $package ] = array( 'descriptor_fingerprint' => $snapshot['descriptor_fingerprint'], 'manifest_entry_count' => $snapshot['manifest_entry_count'], 'manifest_expanded_bytes' => $snapshot['manifest_expanded_bytes'], 'manifest_hash' => $snapshot['manifest_hash'], 'sha256' => $snapshot['sha256'], 'size' => $snapshot['size'], 'update_uri' => $snapshot['update_uri'] ); return $package; }

	/** @param array<string, mixed> $policy */
	private function validPolicy( IdentityDescriptor $descriptor, array $policy ): bool {
		if ( count( $policy ) !== count( self::POLICY_KEYS ) ) return false;
		foreach ( self::POLICY_KEYS as $key ) if ( ! array_key_exists( $key, $policy ) || ! is_string( $policy[ $key ] ) ) return false;
		$facts = $descriptor->toArray();
		foreach ( array( 'installed_package_identity', 'provider_code', 'repository_identity', 'repository_locator', 'target_type' ) as $key ) if ( ! hash_equals( $facts[ $key ], $policy[ $key ] ) ) return false;
		if ( ( 'plugin' === $policy['target_type'] && $policy['installed_package_identity'] !== $policy['archive_root'] . '/' . $policy['header_file'] ) || ( 'theme' === $policy['target_type'] && ( $policy['installed_package_identity'] !== $policy['archive_root'] || 'style.css' !== $policy['header_file'] ) ) ) return false;
		if ( ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $policy['archive_root'] ) || ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.(?:php|css)\z/D', $policy['header_file'] ) || ( 'plugin' === $policy['target_type'] && ! str_ends_with( $policy['header_file'], '.php' ) ) || ( 'theme' === $policy['target_type'] && 'style.css' !== $policy['header_file'] ) || '' === $policy['metadata_name'] || strlen( $policy['metadata_name'] ) > 500 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $policy['metadata_name'] ) || null === ReleaseVersion::normalizeHeader( $policy['php_runtime_version'] ) || null === ReleaseVersion::normalizeHeader( $policy['wordpress_runtime_version'] ) ) return false;
		return $facts['canonical_update_uri'] === CanonicalUpdateUri::canonicalizeBoundaries( array( 'archive_preflight' => $facts['canonical_update_uri'], 'configuration' => $policy['configuration_update_uri'], 'offer' => $policy['offer_update_uri'], 'staged_package' => $policy['staged_package_update_uri'] ) );
	}

	/** @param array<string, mixed> $facts */
	/** @param array<string,mixed> $facts @return array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null */
	private function archiveIdentity( string $path, array $facts ): ?array {
		clearstatcache( true, $path ); $stat = @lstat( $path );
		if ( ! is_array( $stat ) || ! is_readable( $path ) || ( $stat['mode'] & 0170000 ) !== 0100000 || $stat['size'] !== $facts['artifact_size'] ) return null;
		$identity = array( 'dev' => $stat['dev'], 'ino' => $stat['ino'], 'mode' => $stat['mode'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'], 'size' => $stat['size'] );
		return $this->matchesArchiveIdentity( $path, $facts, $identity ) ? $identity : null;
	}

	/** @param array<string,mixed> $facts @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity */
	private function matchesArchiveIdentity( string $path, array $facts, array $identity ): bool {
		$digest = @hash_file( 'sha256', $path ); clearstatcache( true, $path ); $stat = @lstat( $path );
		if ( ! is_string( $digest ) || ! hash_equals( $facts['artifact_sha256'], $digest ) || ! is_array( $stat ) || $stat['size'] !== $facts['artifact_size'] ) return false;
		foreach ( $identity as $key => $value ) if ( ! isset( $stat[ $key ] ) || $stat[ $key ] !== $value ) return false;
		return ( $stat['mode'] & 0170000 ) === 0100000;
	}

	/** @return array{path:string,directory:bool}|null */
	private static function normalizePath( string $name ): ?array {
		if ( '' === $name || strlen( $name ) > self::MAX_ARCHIVE_PATH_BYTES || str_starts_with( $name, '/' ) || str_contains( $name, '\\' ) || str_contains( $name, ':' ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $name ) || 1 === preg_match( '/[^\x20-\x7e]/', $name ) ) return null;
		$directory = str_ends_with( $name, '/' ); $path = $directory ? substr( $name, 0, -1 ) : $name;
		if ( '' === $path || str_ends_with( $path, '/' ) ) return null;
		foreach ( explode( '/', $path ) as $part ) if ( '' === $part || '.' === $part || '..' === $part || strlen( $part ) > 255 || str_ends_with( $part, '.' ) || str_ends_with( $part, ' ' ) || 1 === preg_match( '/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/iD', $part ) ) return null;
		return array( 'path' => $path, 'directory' => $directory );
	}
	private static function isSpecialEntry( \ZipArchive $zip, int $index ): bool {
		$os = 0; $attributes = 0;
		if ( ! $zip->getExternalAttributesIndex( $index, $os, $attributes, \ZipArchive::FL_UNCHANGED ) || \ZipArchive::OPSYS_UNIX !== $os ) return false;
		$type = ( $attributes >> 16 ) & 0170000;
		return 0 !== $type && 0100000 !== $type && 0040000 !== $type;
	}
	private static function readHeader( \ZipArchive $zip, string $name ): ?string { $stream = $zip->getStream( $name ); if ( ! is_resource( $stream ) ) return null; $contents = stream_get_contents( $stream, self::MAX_HEADER_BYTES ); fclose( $stream ); return is_string( $contents ) ? $contents : null; }
	/** @return array{sha256:string,size:int}|null */
	private static function entryIdentity( \ZipArchive $zip, string $name, int $expectedSize ): ?array { $stream = $zip->getStream( $name ); if ( ! is_resource( $stream ) ) return null; $context = hash_init( 'sha256' ); $size = 0; $valid = true; while ( ! feof( $stream ) ) { $chunk = fread( $stream, 65536 ); if ( ! is_string( $chunk ) || ( '' === $chunk && ! feof( $stream ) ) || strlen( $chunk ) > $expectedSize - $size ) { $valid = false; break; } $size += strlen( $chunk ); hash_update( $context, $chunk ); } fclose( $stream ); return $valid && $size === $expectedSize ? array( 'sha256' => hash_final( $context ), 'size' => $size ) : null; }
	private static function headerValue( string $contents, string $name ): ?string { if ( 1 !== preg_match_all( '/^[ \\t\\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $contents, $matches ) ) return null; $value = preg_replace( '/\\s*(?:\\*\\/)?\\s*$/', '', trim( $matches[1][0] ) ); return is_string( $value ) && '' !== $value && strlen( $value ) <= 500 ? $value : null; }
	/** @return string|false|null False means absent; null means malformed or duplicated. */
	private static function optionalHeaderValue( string $contents, string $name ): string|false|null { $count = preg_match_all( '/^[ \\t\\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $contents ); return 0 === $count ? false : ( 1 === $count ? self::headerValue( $contents, $name ) : null ); }
	private static function meetsRequirement( string $runtimeVersion, string $requiredVersion ): bool { $comparison = ReleaseVersion::compare( $runtimeVersion, $requiredVersion ); return null !== $comparison && $comparison >= 0; }
}
