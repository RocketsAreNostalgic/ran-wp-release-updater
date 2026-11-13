<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

use RAN\WPReleaseUpdater\V1\Dependency\FailureText;

/**
 * Closed, stable reasons a prospective inspection rejects a published release.
 *
 * The value is true when the reason may carry one short `detail` string.
 */
final class ReleaseRejection {

	public const MAX_DETAIL = 120;
	public const REASONS    = array(
		'release_draft'              => false,
		'tag_not_version'            => true,
		'tag_mismatch'               => true,
		'prerelease_not_allowed'     => true,
		'no_package_zip'             => false,
		'multiple_package_zips'      => true,
		'asset_not_uploaded'         => true,
		'asset_metadata_invalid'     => false,
		'asset_too_large'            => true,
		'digest_missing'             => false,
		'download_size_mismatch'     => true,
		'download_digest_mismatch'   => false,
		'archive_unreadable'         => false,
		'archive_entry_limit'        => false,
		'archive_size_limit'         => false,
		'archive_path_unsafe'        => false,
		'archive_path_duplicate'     => false,
		'archive_root_not_single'    => false,
		'no_package_header'          => false,
		'header_plugin_ambiguous'    => false,
		'header_name_missing'        => false,
		'header_version_missing'     => false,
		'header_update_uri_missing'  => false,
		'header_invalid'             => true,
		'header_duplicate'           => true,
		'header_version_mismatch'    => true,
		'header_update_uri_mismatch' => true,
		'header_requires_php_unmet'  => true,
		'header_requires_wp_unmet'   => true,
	);

	public static function valid( string $reason ): bool {
		return array_key_exists( $reason, self::REASONS );
	}

	/**
	 * Returns a bounded printable-ASCII detail, or null when the reason takes none or the text is not safe.
	 *
	 * Screening is the shared `FailureText::strict()` of `ran/updater-support`: the whole text is
	 * screened first and then bounded to MAX_DETAIL bytes, and a text that matches any rule is
	 * dropped whole, never redacted or rewritten. Text that is not printable ASCII is dropped, not
	 * repaired. The screen is by shape, deliberately broad and a safety net rather than a proof, so it
	 * also drops some benign text. The result is still publisher-controlled text; consumers must escape it
	 * on output.
	 *
	 * The one exception is the `Update URI` echo of `header_update_uri_mismatch`. A producer has
	 * already validated it as a canonical `https` URI on the expected host, with no userinfo, port,
	 * query or fragment, and the strict screen would drop its `:` and `/`. Exactly that canonical form
	 * is kept when it fits the bound and every path segment passes the strict screen; any other text
	 * for that reason takes the ordinary strict path.
	 */
	public static function detail( string $reason, ?string $detail ): ?string {
		if ( null === $detail || true !== ( self::REASONS[ $reason ] ?? false ) ) {
			return null;
		}
		if ( 'header_update_uri_mismatch' === $reason && self::canonical_update_uri( $detail ) ) {
			return $detail;
		}
		return FailureText::strict( $detail, self::MAX_DETAIL );
	}

	private static function canonical_update_uri( string $text ): bool {
		if ( strlen( $text ) > self::MAX_DETAIL || CanonicalUpdateUri::canonicalize( $text ) !== $text ) {
			return false;
		}
		$segments = explode( '/', substr( $text, strlen( 'https://' ) ) );
		array_shift( $segments );
		foreach ( $segments as $segment ) {
			if ( FailureText::strict( $segment, self::MAX_DETAIL ) !== $segment ) {
				return false;
			}
		}
		return true;
	}
}
