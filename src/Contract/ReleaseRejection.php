<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

use RAN\WPReleaseUpdater\V1\Dependency\FailureText;

/**
 * Closed release rejection reasons and bounded public listing observations.
 *
 * In REASONS, the value is true when an inspection reason may carry one short `detail` string.
 */
final class ReleaseRejection {

	private const LISTING_REASONS = array( 'release_metadata_invalid', 'release_immutable_invalid', 'release_publication_invalid', 'release_page_invalid', 'release_draft', 'tag_not_version', 'prerelease_not_allowed' );
	public const MAX_DETAIL       = 120;
	public const REASONS          = array(
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

	/** @return array{release_identity:?string,tag:?string,reason:string} */
	public static function sample( mixed $identity, mixed $tag, string $reason ): array {
		return array(
			'release_identity' => is_string( $identity ) && 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/D', $identity ) ? $identity : null,
			'tag'              => self::screen_tag( $tag ),
			'reason'           => $reason,
		);
	}

	/** Tags may start with a digit and contain upper case or SemVer punctuation; identifier() cannot admit those. */
	public static function screen_tag( mixed $tag ): ?string {
		return is_string( $tag ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,119}\z/D', $tag )
			&& FailureText::strict( $tag, 120 ) === $tag ? $tag : null;
	}

	/** Validates the bounded, message-free listing report at the public wire boundary. */
	public static function valid_diagnostics( mixed $value ): bool {
		if ( null === $value ) {
			return true;
		}
		if ( ! is_array( $value ) || 4 !== count( $value ) || ! is_int( $value['scanned_count'] ?? null )
			|| ! is_int( $value['dropped_count'] ?? null ) || ! is_array( $value['drops'] ?? null ) || ! is_bool( $value['truncated'] ?? null )
			|| $value['scanned_count'] < 0 || $value['scanned_count'] > 40 || $value['dropped_count'] < 0 || $value['dropped_count'] > $value['scanned_count']
			|| ! array_is_list( $value['drops'] ) || count( $value['drops'] ) !== min( 8, $value['dropped_count'] )
			|| ( $value['dropped_count'] > 8 ) !== $value['truncated'] ) {
			return false;
		}
		foreach ( $value['drops'] as $drop ) {
			if ( ! is_array( $drop ) || 3 !== count( $drop ) || ! array_key_exists( 'release_identity', $drop ) || ! array_key_exists( 'tag', $drop )
				|| ! is_string( $drop['reason'] ?? null ) || ! in_array( $drop['reason'], self::LISTING_REASONS, true )
				|| self::sample( $drop['release_identity'], $drop['tag'], $drop['reason'] ) !== $drop ) {
				return false;
			}
		}
		return true;
	}

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
	 * is kept when it fits the bound and the host and path segments, read together as words, pass the strict screen; any other text
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

	/** The joined screen over host and path words is the control; the length guard keeps the echo within the bound. */
	private static function canonical_update_uri( string $text ): bool {
		if ( strlen( $text ) > self::MAX_DETAIL || CanonicalUpdateUri::canonicalize( $text ) !== $text ) {
			return false;
		}
		$words = implode( ' ', explode( '/', substr( $text, strlen( 'https://' ) ) ) );
		return FailureText::strict( $words, self::MAX_DETAIL ) === $words;
	}
}
