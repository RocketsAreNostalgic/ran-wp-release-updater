<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

/**
 * Closed, stable reasons a prospective inspection rejects a published release.
 *
 * The value is true when the reason may carry one short `detail` string.
 */
final class ReleaseRejection {

	public const MAX_DETAIL = 120;
	public const REASONS    = array(
		'release_draft'               => false,
		'tag_not_version'             => true,
		'tag_mismatch'                => true,
		'prerelease_not_allowed'      => true,
		'no_package_zip'              => false,
		'multiple_package_zips'       => true,
		'asset_not_uploaded'          => true,
		'asset_metadata_invalid'      => false,
		'asset_too_large'             => true,
		'digest_missing'              => false,
		'download_size_mismatch'      => true,
		'download_digest_mismatch'    => false,
		'archive_unreadable'          => false,
		'archive_entry_limit'         => false,
		'archive_size_limit'          => false,
		'archive_path_unsafe'         => false,
		'archive_path_duplicate'      => false,
		'archive_root_not_single'     => false,
		'no_package_header'           => false,
		'header_plugin_ambiguous'     => false,
		'header_name_missing'         => false,
		'header_version_missing'      => false,
		'header_update_uri_missing'   => false,
		'header_invalid'              => true,
		'header_duplicate'            => true,
		'header_version_mismatch'     => true,
		'header_update_uri_mismatch'  => true,
		'header_requires_php_missing' => false,
		'header_requires_php_unmet'   => true,
		'header_requires_wp_missing'  => false,
		'header_requires_wp_unmet'    => true,
	);

	public static function valid( string $reason ): bool {
		return array_key_exists( $reason, self::REASONS );
	}

	/** Returns the bounded printable-ASCII detail, or null when the reason takes none or the text is unsafe. */
	public static function detail( string $reason, ?string $detail ): ?string {
		if ( null === $detail || true !== ( self::REASONS[ $reason ] ?? false ) || 1 !== preg_match( '//u', $detail ) ) {
			return null;
		}
		$detail = preg_replace( '/[^\x20-\x7E]/u', '?', trim( $detail ) );
		$detail = is_string( $detail ) ? substr( $detail, 0, self::MAX_DETAIL ) : '';
		return '' === $detail || 1 === preg_match( '/sha256:|[a-f0-9]{40}/i', $detail ) ? null : $detail;
	}
}
