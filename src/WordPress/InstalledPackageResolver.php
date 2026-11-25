<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;
use RAN\WPReleaseUpdater\V1\Dependency\PackageDirectory;
use RAN\WPReleaseUpdater\V1\Dependency\PluginFileIdentifier;
use RAN\WPReleaseUpdater\V1\Dependency\RuntimeVersion;
use RAN\WPReleaseUpdater\V1\Dependency\WordPressHeaders;

/** @internal Resolves one WordPress-registered installed target and lazily reads its headers. */
final class InstalledPackageResolver {

	private const MAX_HEADER_BYTES = WordPressHeaders::MAX_BYTES;

	/**
	 * @param array<string,mixed> $plugin_paths Registered logical-to-physical plugin paths.
	 * @param list<mixed>         $theme_directories Registered theme directories.
	 */
	public function __construct(
		private readonly string $plugin_directory,
		private readonly array $plugin_paths,
		private readonly array $theme_directories,
		private readonly ?string $php_version = null,
	) {
	}

	/**
	 * Resolves and validates the installed path and package identity without reading file bytes.
	 *
	 * @param array<string,mixed> $declaration Target declaration.
	 * @return array<string,mixed>
	 */
	public function resolve_location( array $declaration ): array {
		$file = $declaration['installed_file'] ?? null;
		$type = $declaration['target_type'] ?? null;
		if ( ! is_string( $file ) || ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! $this->valid_path( $file ) ) {
			return array( 'code' => 'installed_file_invalid' );
		}

		$file = self::normalize_path( $file );
		clearstatcache( true, $file );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Observe native filesystem identity without following a replacement abstraction; missing or changed facts fail existing validation.
		$initial = @lstat( $file );
		if ( ! is_array( $initial ) ) {
			return array( 'code' => 'installed_file_missing' );
		}
		if ( is_link( $file ) ) {
			return array( 'code' => 'installed_file_symlink' );
		}
		if ( ( $initial['mode'] & 0170000 ) !== 0100000 ) {
			return array( 'code' => 'installed_file_not_regular' );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Resolve the actual local path for containment checks; failure is rejected without emitting warnings.
		$real = @realpath( $file );
		if ( ! is_string( $real ) ) {
			return array( 'code' => 'installed_file_unreadable' );
		}
		$root = $this->root_for( $type, $file, $real );
		if ( null === $root ) {
			return array( 'code' => 'installed_file_outside_root' );
		}
		if ( false === $root ) {
			return array( 'code' => 'installed_file_root_ambiguous' );
		}
		$link_root = $this->inside( $file, $root['logical'] ) ? $root['logical'] : $root['real'];
		if ( $this->has_internal_link( $file, $link_root ) ) {
			return array( 'code' => 'installed_file_outside_root' );
		}

		$identity_root = 'plugin' === $type ? self::untrailingslash_path( $this->plugin_directory ) : $root['logical'];
		$identity      = $this->identity( $type, $root['file'], $identity_root );
		if ( is_string( $identity ) ) {
			return array( 'code' => $identity );
		}

		return array(
			'archive_root'               => $identity['root'],
			'code'                       => 'installed_location_verified',
			'file'                       => $file,
			'header_file'                => $identity['file'],
			'installed_package_identity' => $identity['identity'],
		);
	}

	/**
	 * Reads and strictly validates local package headers on demand.
	 *
	 * Delegates extraction to WordPress Core's get_file_data() when loaded, falling back to
	 * a single bounded read and WordPressHeaders::parse() in standalone contexts.
	 *
	 * @return array{code:string,headers?:array<string,string>,raw_update_uri?:string}
	 */
	public function read_headers( string $file, string $type ): array {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return array( 'code' => 'installed_header_invalid' );
		}
		clearstatcache( true, $file );
		if ( is_link( $file ) ) {
			return array( 'code' => 'installed_file_symlink' );
		}
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return array( 'code' => 'installed_file_unreadable' );
		}

		$raw_update_uri = null;
		if ( function_exists( 'get_file_data' ) ) {
			$labels = array(
				'Author'      => 'Author',
				'Description' => 'Description',
				'Name'        => 'theme' === $type ? 'Theme Name' : 'Plugin Name',
				'PackageURI'  => 'theme' === $type ? 'Theme URI' : 'Plugin URI',
				'RequiresPHP' => 'Requires PHP',
				'RequiresWP'  => 'Requires at least',
				'UpdateURI'   => 'Update URI',
				'Version'     => 'Version',
			);
			if ( 'theme' === $type ) {
				$labels['Template'] = 'Template';
			}
			$extracted      = get_file_data( $file, $labels );
			$raw_update_uri = is_string( $extracted['UpdateURI'] ?? null ) && '' !== $extracted['UpdateURI']
				? $extracted['UpdateURI']
				: null;
			$header_result  = PackageIdentityValidator::validate_extracted_headers( $extracted, $type );
		} else {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Single bounded local header read when WordPress get_file_data() is unavailable.
			$contents = @file_get_contents( $file, false, null, 0, self::MAX_HEADER_BYTES );
			if ( ! is_string( $contents ) ) {
				return array( 'code' => 'installed_file_unreadable' );
			}
			$header_result = PackageIdentityValidator::parse_header( $contents, $type );
			if ( 'installed_header_verified' !== $header_result['code'] || ! isset( $header_result['headers'] ) ) {
				$parsed_uri = WordPressHeaders::parse(
					str_replace( array( "\r\n", "\r" ), "\n", substr( $contents, 0, self::MAX_HEADER_BYTES ) ),
					array( 'UpdateURI' => 'Update URI' )
				);
				$matches    = $parsed_uri['UpdateURI'] ?? array();
				if ( 1 === count( $matches ) && '' !== $matches[0] ) {
					$raw_update_uri = $matches[0];
				}
			}
		}

		if ( 'installed_header_verified' !== $header_result['code'] || ! isset( $header_result['headers'] ) ) {
			$failure = array( 'code' => $header_result['code'] );
			if ( is_string( $raw_update_uri ) ) {
				$failure['raw_update_uri'] = $raw_update_uri;
			}
			return $failure;
		}
		$headers = $header_result['headers'];
		if ( $this->requirements_incompatible( $headers ) ) {
			$failure = array( 'code' => 'installed_requirement_incompatible' );
			if ( '' !== $headers['UpdateURI'] ) {
				$failure['raw_update_uri'] = $headers['UpdateURI'];
			}
			return $failure;
		}

		return array(
			'code'    => 'installed_header_verified',
			'headers' => $headers,
		);
	}

	/**
	 * @param array<string,mixed> $declaration Target declaration.
	 * @return array<string,mixed>
	 */
	public function resolve( array $declaration ): array {
		$location = $this->resolve_location( $declaration );
		if ( 'installed_location_verified' !== ( $location['code'] ?? null ) ) {
			return $location;
		}
		$type          = is_string( $declaration['target_type'] ?? null ) ? $declaration['target_type'] : '';
		$header_result = $this->read_headers( $location['file'], $type );
		if ( 'installed_header_verified' !== $header_result['code'] || ! isset( $header_result['headers'] ) ) {
			return array( 'code' => $header_result['code'] );
		}

		return array(
			'archive_root'               => $location['archive_root'],
			'code'                       => 'installed_identity_verified',
			'header_file'                => $location['header_file'],
			'headers'                    => $header_result['headers'],
			'installed_package_identity' => $location['installed_package_identity'],
		);
	}

	private static function normalize_path( string $path ): string {
		if ( function_exists( 'wp_normalize_path' ) ) {
			return wp_normalize_path( $path );
		}
		return str_replace( '\\', '/', $path );
	}

	private static function untrailingslash_path( string $path ): string {
		$normalized = self::normalize_path( $path );
		return function_exists( 'untrailingslashit' ) ? untrailingslashit( $normalized ) : rtrim( $normalized, '/' );
	}

	private function valid_path( string $path ): bool {
		$path  = str_replace( '\\', '/', $path );
		$drive = 1 === preg_match( '/\A[A-Za-z]:\//', $path );
		$unc   = str_starts_with( $path, '//' );
		if ( '' === $path || strlen( $path ) > 4096 || ( ! $drive && ! str_starts_with( $path, '/' ) ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $path ) ) {
			return false;
		}
		$parts = explode( '/', substr( $path, $drive ? 3 : ( $unc ? 2 : 1 ) ) );
		if ( $unc && count( $parts ) < 2 ) {
			return false;
		}
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	/** @return array{logical:string,real:string,file:string}|false|null */
	private function root_for( string $type, string $file, string $real ): array|false|null {
		$roots = array();
		if ( 'plugin' === $type ) {
			$this->add_root( $roots, $this->plugin_directory, $this->plugin_directory );
			$plugin_root = self::untrailingslash_path( $this->plugin_directory );
			foreach ( $this->plugin_paths as $logical => $actual ) {
				if ( ! is_string( $logical ) || ! is_string( $actual ) ) {
					continue;
				}
				if ( $this->inside( self::untrailingslash_path( $logical ), $plugin_root ) ) {
					$this->add_root( $roots, $logical, $actual, true );
				}
			}
		} else {
			foreach ( $this->theme_directories as $directory ) {
				if ( is_string( $directory ) ) {
					$this->add_root( $roots, $directory, $directory );
				}
			}
		}

		$matches = array();
		foreach ( $roots as $root ) {
			$logical_file = null;
			if ( $this->inside( $file, $root['logical'] ) && $this->inside( $real, $root['real'] ) ) {
				$logical_file = $file;
			}
			if ( $this->inside( $file, $root['real'] ) && $this->inside( $real, $root['real'] ) ) {
				$logical_file = $root['logical'] . substr( $file, strlen( $root['real'] ) );
			}
			if ( is_string( $logical_file ) ) {
				$matches[ $logical_file ] = array(
					'logical'  => $root['logical'],
					'real'     => $root['real'],
					'file'     => $logical_file,
					'explicit' => $root['explicit'],
				);
			}
		}
		if ( 0 === count( $matches ) ) {
			return null;
		}
		$explicit_matches = array_filter( $matches, static fn ( array $candidate ): bool => $candidate['explicit'] );
		if ( 0 !== count( $explicit_matches ) ) {
			$matches = $explicit_matches;
		}
		if ( 1 !== count( $matches ) ) {
			return false;
		}
		$match = array_values( $matches )[0];
		return array(
			'logical' => $match['logical'],
			'real'    => $match['real'],
			'file'    => $match['file'],
		);
	}

	/** @param array<string,array{logical:string,real:string,explicit:bool}> $roots */
	private function add_root( array &$roots, string $logical, string $actual, bool $explicit = false ): void {
		$logical = self::untrailingslash_path( $logical );
		$actual  = self::untrailingslash_path( $actual );
		if ( ! $this->valid_path( $logical ) || ! $this->valid_path( $actual ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Resolve the actual local path for containment checks; failure is rejected without emitting warnings.
		$real = @realpath( $actual );
		if ( ! is_string( $real ) || ! is_dir( $real ) ) {
			return;
		}
		$real                             = self::normalize_path( $real );
		$roots[ $logical . "\0" . $real ] = array(
			'logical'  => $logical,
			'real'     => $real,
			'explicit' => $explicit,
		);
	}

	private function inside( string $path, string $root ): bool {
		$path = self::normalize_path( $path );
		$root = self::normalize_path( $root );
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$path = strtolower( $path );
			$root = strtolower( $root );
		}
		return str_starts_with( $path, $root . '/' );
	}

	private function has_internal_link( string $file, string $root ): bool {
		$relative = substr( $file, strlen( $root ) + 1 );
		$path     = $root;
		foreach ( explode( '/', $relative ) as $part ) {
			$path .= '/' . $part;
			if ( $path !== $file && is_link( $path ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{identity:string,root:string,file:string}|string */
	private function identity( string $type, string $file, string $root ): array|string {
		$parts = explode( '/', (string) substr( $file, strlen( $root ) + 1 ) );
		if ( 'plugin' === $type ) {
			if ( 2 !== count( $parts ) ) {
				return 1 === count( $parts ) ? 'plugin_root_level_unsupported' : 'installed_file_outside_root';
			}
			if ( ! PackageDirectory::is_valid_name( $parts[0] ) || ! str_ends_with( $parts[1], '.php' ) || strlen( $parts[1] ) > 104 || ! PluginFileIdentifier::is_valid( $parts[1] ) ) {
				return 'installed_file_invalid';
			}
			return array(
				'identity' => $parts[0] . '/' . $parts[1],
				'root'     => $parts[0],
				'file'     => $parts[1],
			);
		}

		if ( 'style.css' !== basename( $file ) ) {
			return 'theme_header_file_invalid';
		}
		if ( 2 !== count( $parts ) ) {
			return 'theme_nested_identity_unsupported';
		}
		if ( ! PackageDirectory::is_valid_name( $parts[0] ) ) {
			return 'theme_nested_identity_unsupported';
		}
		return array(
			'identity' => $parts[0],
			'root'     => $parts[0],
			'file'     => 'style.css',
		);
	}

	/** @param array<string,string> $headers */
	private function requirements_incompatible( array $headers ): bool {
		if ( null === ReleaseVersion::normalize_header( $headers['Version'] ) ) {
			return true;
		}
		if ( '' !== $headers['RequiresPHP'] ) {
			$php_version    = RuntimeVersion::php( $this->php_version ?? PHP_VERSION );
			$php_comparison = is_string( $php_version ) ? ReleaseVersion::compare( $php_version, $headers['RequiresPHP'] ) : null;
			if ( null === ReleaseVersion::normalize_header( $headers['RequiresPHP'] ) || null === $php_comparison || $php_comparison < 0 ) {
				return true;
			}
		}
		$raw_wp     = $GLOBALS['wp_version'] ?? null;
		$wordpress  = is_string( $raw_wp ) ? RuntimeVersion::wordpress( $raw_wp ) : null;
		$comparison = is_string( $wordpress ) ? ReleaseVersion::compare( $wordpress, $headers['RequiresWP'] ) : null;
		return '' !== $headers['RequiresWP'] && ( null === ReleaseVersion::normalize_header( $headers['RequiresWP'] ) || null === $comparison || $comparison < 0 );
	}
}
