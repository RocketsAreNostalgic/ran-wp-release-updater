<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use RuntimeException;

/** Owns bounded WordPress HTTP transport, redirect validation, and response projection for GitHub. */
final class GitHubApiClient {

	private const API_HOST            = 'api.github.com';
	private const API_ORIGIN          = 'https://' . self::API_HOST;
	private const HTTP_TIMEOUT        = 10;
	private const RELEASE_ASSET_HOSTS = array(
		'github-releases.githubusercontent.com',
		'objects.githubusercontent.com',
		'release-assets.githubusercontent.com',
	);

	/** @param null|callable():void $livenessGuard */
	public function __construct( private $livenessGuard = null ) {}

	public function api( string $path ): string {
		return self::API_ORIGIN . $path;
	}

	/**
	 * @param array<string,string> $headers
	 * @return array<string,mixed>
	 */
	public function request(
		string $url,
		?string $token,
		array $headers,
		int $limit,
		?string $filename = null
	): array {
		$headers = array_merge(
			array(
				'Accept'               => 'application/vnd.github+json',
				'User-Agent'           => 'ran-wp-release-updater',
				'X-GitHub-Api-Version' => '2022-11-28',
			),
			$headers
		);
		if ( null !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$currentUrl       = $url;
		$credentialsBound = true;
		for ( $redirects = 0; ; ++$redirects ) {
			$this->assertLive();
			$response = self::send( $currentUrl, $headers, $limit, $filename );
			$this->assertLive();
			$status = self::responseCode( $response );
			if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				return $response;
			}
			if ( $redirects >= 1 ) {
				throw new RuntimeException( 'The GitHub redirect limit was exceeded.' );
			}

			$nextUrl = self::validatedRedirectUrl( self::responseHeader( $response, 'location' ) );
			if ( null === $nextUrl ) {
				throw new RuntimeException( 'The GitHub redirect is unsafe.' );
			}
			$nextHost = strtolower( (string) parse_url( $nextUrl, PHP_URL_HOST ) );
			if ( self::API_HOST !== $nextHost ) {
				$credentialsBound = false;
			}
			if ( ! $credentialsBound ) {
				unset( $headers['Authorization'] );
			}
			$currentUrl = $nextUrl;
		}
	}

	/** @param array<string,mixed> $response */
	public static function responseCode( array $response ): int {
		if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
			throw new GitHubReleaseReadUnavailable( 'The WordPress HTTP response API is unavailable.' );
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( is_string( $status ) && 1 === preg_match( '/\A[1-5]\d{2}\z/D', $status ) ) {
			$status = (int) $status;
		}
		if ( ! is_int( $status ) || $status < 100 || $status > 599 ) {
			throw new RuntimeException( 'The GitHub response status is invalid.' );
		}
		return $status;
	}

	/** @param array<string,mixed> $response */
	public static function responseHeader( array $response, string $name ): ?string {
		if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
			throw new GitHubReleaseReadUnavailable( 'The WordPress HTTP response API is unavailable.' );
		}
		$value = wp_remote_retrieve_header( $response, $name );
		return is_string( $value ) || is_numeric( $value ) ? (string) $value : null;
	}

	/** @param array<string,mixed> $response */
	public static function responseBody( array $response, int $limit ): string {
		if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
			throw new GitHubReleaseReadUnavailable( 'The WordPress HTTP response API is unavailable.' );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || strlen( $body ) > $limit ) {
			throw new RuntimeException( 'The GitHub response body is invalid.' );
		}
		return $body;
	}

	private function assertLive(): void {
		if ( null !== $this->livenessGuard ) {
			( $this->livenessGuard )();
		}
	}

	/**
	 * @param array<string,string> $headers
	 * @return array<string,mixed>
	 */
	private static function send( string $url, array $headers, int $limit, ?string $filename ): array {
		if ( ! function_exists( 'wp_safe_remote_get' ) || ! function_exists( 'is_wp_error' ) ) {
			throw new GitHubReleaseReadUnavailable( 'WordPress safe HTTP is unavailable.' );
		}
		$args = array(
			'headers'             => $headers,
			'limit_response_size' => PHP_INT_MAX === $limit ? PHP_INT_MAX : $limit + 1,
			'redirection'         => 0,
			'timeout'             => self::HTTP_TIMEOUT,
		);
		if ( null !== $filename ) {
			$args['filename'] = $filename;
			$args['stream']   = true;
		}
		$response = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			throw new GitHubReleaseReadUnavailable( 'The GitHub request failed.' );
		}
		self::responseCode( $response );
		if ( null === $filename ) {
			self::responseBody( $response, $limit );
		}
		return $response;
	}

	private static function validatedRedirectUrl( ?string $url ): ?string {
		if (
			null === $url
			|| '' === $url
			|| strlen( $url ) > 4096
			|| 1 === preg_match( '/[\x00-\x1f\x7f]/', $url )
			|| ! function_exists( 'wp_http_validate_url' )
			|| false === wp_http_validate_url( $url )
		) {
			return null;
		}
		$parts = parse_url( $url );
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| ! is_string( $parts['host'] ?? null )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| ( isset( $parts['port'] ) && 443 !== $parts['port'] )
			|| isset( $parts['fragment'] )
		) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		if (
			false !== filter_var( $host, FILTER_VALIDATE_IP )
			|| ( self::API_HOST !== $host && ! in_array( $host, self::RELEASE_ASSET_HOSTS, true ) )
			|| self::signedUrlExpired( (string) ( $parts['query'] ?? '' ) )
		) {
			return null;
		}
		return $url;
	}

	private static function signedUrlExpired( string $query ): bool {
		if ( '' === $query ) {
			return false;
		}
		$values = array();
		foreach ( explode( '&', $query ) as $pair ) {
			list( $rawKey, $rawValue ) = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$key                       = strtolower( rawurldecode( $rawKey ) );
			if ( ! in_array( $key, array( 'se', 'expires', 'x-amz-date', 'x-amz-expires' ), true ) ) {
				continue;
			}
			if ( array_key_exists( $key, $values ) ) {
				return true;
			}
			$values[ $key ] = rawurldecode( $rawValue );
		}
		if ( array() === $values ) {
			return false;
		}
		$families = ( array_key_exists( 'se', $values ) ? 1 : 0 )
			+ ( array_key_exists( 'expires', $values ) ? 1 : 0 )
			+ ( ( array_key_exists( 'x-amz-date', $values ) || array_key_exists( 'x-amz-expires', $values ) ) ? 1 : 0 );
		if ( 1 !== $families ) {
			return true;
		}
		if ( array_key_exists( 'se', $values ) ) {
			if ( 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?Z\z/D', $values['se'] ) ) {
				return true;
			}
			$base      = substr( $values['se'], 0, 19 ) . 'Z';
			$expiresAt = self::exactUtcDate( '!Y-m-d\TH:i:s\Z', $base );
			return null === $expiresAt || $expiresAt <= time();
		}
		if ( array_key_exists( 'expires', $values ) ) {
			return 1 !== preg_match( '/\A\d{1,12}\z/D', $values['expires'] ) || (int) $values['expires'] <= time();
		}
		if (
			! isset( $values['x-amz-date'], $values['x-amz-expires'] )
			|| 1 !== preg_match( '/\A\d{8}T\d{6}Z\z/D', $values['x-amz-date'] )
			|| 1 !== preg_match( '/\A\d{1,7}\z/D', $values['x-amz-expires'] )
		) {
			return true;
		}
		$issuedAt = self::exactUtcDate( '!Ymd\THis\Z', $values['x-amz-date'] );
		return null === $issuedAt || $issuedAt + (int) $values['x-amz-expires'] <= time();
	}

	private static function exactUtcDate( string $format, string $value ): ?int {
		$date   = \DateTimeImmutable::createFromFormat( $format, $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if (
			false === $date
			|| ( is_array( $errors ) && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) )
			|| $date->format( substr( $format, 1 ) ) !== $value
		) {
			return null;
		}
		return $date->getTimestamp();
	}
}
