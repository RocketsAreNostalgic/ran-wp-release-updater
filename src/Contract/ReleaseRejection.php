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
	 * Returns a bounded printable-ASCII detail, or null when the reason takes none or the text looks unsafe.
	 *
	 * The complete text is screened before it is bounded, and an unsafe detail is dropped, never redacted.
	 * Screening is by shape and is a safety net, not a guarantee. Ordinary words such as "tokenizer"
	 * survive. The whole detail is dropped for: userinfo or an at sign; a query string; a credential-style
	 * `name=` or `name:` assignment; a provider-token prefix of any length (common code-host, chat, cloud and
	 * package-registry token families, payment-style keys, a JWT start, an HTTP basic or token-scheme word followed by text); a password, secret, token,
	 * authorisation or API-key word beside any other word; a hash-algorithm prefix; a 32+ character hex run,
	 * or a 32+ character mixed-case base64-like run, once every non-alphanumeric character (including
	 * whitespace and non-ASCII) is removed; a `file:` or other `://` URL (other than a lone canonical
	 * `https` host/path value); an absolute, home, parent-relative or drive path anywhere in the text; two
	 * or more slashes; a single-slash path ending in a letter file extension; a backslash; and markup.
	 * The result is still publisher-controlled text; consumers must escape it on output.
	 */
	public static function detail( string $reason, ?string $detail ): ?string {
		if ( null === $detail || true !== ( self::REASONS[ $reason ] ?? false ) || 1 !== preg_match( '//u', $detail ) ) {
			return null;
		}
		$detail = preg_replace( '/[^\x20-\x7E]/u', '?', trim( $detail ) );
		if ( ! is_string( $detail ) || '' === $detail || self::unsafe( $detail ) ) {
			return null;
		}
		return substr( $detail, 0, self::MAX_DETAIL );
	}

	private static function unsafe( string $text ): bool {
		$word      = '(?<![A-Za-z])(?:passw(?:or)?d|pwd|secret|token|api[^A-Za-z0-9]?key|authori[sz]ation)s?(?![A-Za-z])';
		$canonical = 1 === preg_match( '#\Ahttps://[a-z0-9.-]+(?:/[A-Za-z0-9._~-]+)*\z#', $text );
		$patterns  = array(
			'#@|%40|[<>\\\\]|[?&;][^\s=]*=|\bsha-?\d{1,3}\s*[:=]|(?:token|passw(?:or)?d|pwd|secret|api[_-]?key|credentials?|key)s?\s*[=:]|[A-Za-z0-9+/_=]{32}#i',
			'#gh[pousr]_|github_pat_|glpat-|xox[abprs]-|npm_|sk_(?:live|test)_|\b(?:basic|be(?:ar)er)\s+[A-Za-z0-9._~+/=-]#i',
			'#(?-i:AKIA|ASIA|eyJ)#',
			'#' . $word . '[^A-Za-z0-9]*[A-Za-z0-9]|[A-Za-z0-9][^A-Za-z0-9]*' . $word . '#i',
			'#(?:^|[^A-Za-z0-9])(?:~|[A-Za-z]:[\\/])|\.\.?/|/\.\./#',
		);
		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $text ) ) {
				return true;
			}
		}
		$flat = (string) preg_replace( '/[^A-Za-z0-9]+/', '', $text );
		if ( 1 === preg_match( '/[0-9a-f]{32}/i', $flat ) || 1 === preg_match( '/(?=[A-Za-z0-9]*[0-9])(?=[A-Za-z0-9]*[a-z])(?=[A-Za-z0-9]*[A-Z])[A-Za-z0-9]{32}/', $flat ) ) {
			return true;
		}
		return ! $canonical && ( str_contains( $text, '://' ) || 1 === preg_match( '#(?<![A-Za-z0-9._~-])/|/.*/|/[A-Za-z0-9_.-]*\.[A-Za-z][A-Za-z0-9]{0,7}(?![A-Za-z0-9])#', $text ) );
	}
}
