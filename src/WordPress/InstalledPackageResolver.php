<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;

/** @internal Resolves one stable, WordPress-registered installed target. */
final class InstalledPackageResolver {

	private const MAX_HEADER_BYTES = 8192;

	/** @var null|\Closure(string):void Tests inject a deterministic race by Reflection. */
	// @phpstan-ignore property.unusedType (InstalledPackageResolverTest assigns this seam through Reflection.)
	private ?\Closure $before_first_stat = null;

	/** @var null|\Closure(string):void Tests inject a deterministic race by Reflection. */
	// @phpstan-ignore property.unusedType (InstalledPackageResolverTest assigns this seam through Reflection.)
	private ?\Closure $after_first_read = null;

	/** @var (\Closure(resource,int):(string|false))|null Tests inject bounded read failures by Reflection. */
	// @phpstan-ignore property.unusedType (InstalledPackageResolverTest assigns this seam through Reflection.)
	private ?\Closure $read = null;

	/** @var null|\Closure(resource):bool Tests inject lock and rewind failures by Reflection. */
	// @phpstan-ignore property.unusedType (InstalledPackageResolverTest assigns this seam through Reflection.)
	private ?\Closure $lock = null;
	/** @var null|\Closure(resource):bool Tests inject rewind failures by Reflection. */
	// @phpstan-ignore property.unusedType (InstalledPackageResolverTest assigns this seam through Reflection.)
	private ?\Closure $rewind = null;

	/**
	 * @param array<string,mixed> $plugin_paths Registered logical-to-physical plugin paths.
	 * @param list<mixed>         $theme_directories Registered theme directories.
	 */
	public function __construct(
		private readonly string $plugin_directory,
		private readonly array $plugin_paths,
		private readonly array $theme_directories,
	) {
	}

	/**
	 * @param array<string,mixed> $declaration Target declaration.
	 * @return array<string,mixed>
	 */
	public function resolve( array $declaration ): array {
		$file = $declaration['installed_file'] ?? null;
		$type = $declaration['target_type'] ?? null;
		if ( ! is_string( $file ) || ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! $this->valid_path( $file ) ) {
			return array( 'code' => 'installed_file_invalid' );
		}

		$file = str_replace( '\\', '/', $file );
		clearstatcache( true, $file );
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

		$identity_root = 'plugin' === $type ? rtrim( str_replace( '\\', '/', $this->plugin_directory ), '/' ) : $root['logical'];
		$identity      = $this->identity( $type, $root['file'], $identity_root );
		if ( is_string( $identity ) ) {
			return array( 'code' => $identity );
		}
		$captured = $this->capture( $file, $initial );
		if ( is_string( $captured ) ) {
			return array( 'code' => $captured );
		}

		clearstatcache( true, $file );
		$last      = @lstat( $file );
		$last_real = @realpath( $file );
		$last_root = is_string( $last_real ) ? $this->root_for( $type, $file, $last_real ) : null;
		if ( ! $this->same_stat( $initial, $last ) || $real !== $last_real || ! is_array( $last_root ) || $root !== $last_root ) {
			return array( 'code' => 'installed_file_changed' );
		}

		$header_result = PackageIdentityValidator::parse_header( $captured[0], $type );
		if ( 'installed_header_verified' !== $header_result['code'] || ! isset( $header_result['headers'] ) ) {
			return array( 'code' => $header_result['code'] );
		}
		$headers = $header_result['headers'];
		if ( $this->requirements_incompatible( $headers ) ) {
			return array( 'code' => 'installed_requirement_incompatible' );
		}

		return array(
			'archive_root'               => $identity['root'],
			'code'                       => 'installed_identity_verified',
			'header_file'                => $identity['file'],
			'headers'                    => $headers,
			'installed_package_identity' => $identity['identity'],
		);
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
			foreach ( $this->plugin_paths as $logical => $actual ) {
				if ( ! is_string( $logical ) || ! is_string( $actual ) ) {
					continue;
				}
				if ( $this->inside( rtrim( str_replace( '\\', '/', $logical ), '/' ), rtrim( str_replace( '\\', '/', $this->plugin_directory ), '/' ) ) ) {
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
		$logical = rtrim( str_replace( '\\', '/', $logical ), '/' );
		$actual  = rtrim( str_replace( '\\', '/', $actual ), '/' );
		if ( ! $this->valid_path( $logical ) || ! $this->valid_path( $actual ) ) {
			return;
		}
		$real = @realpath( $actual );
		if ( ! is_string( $real ) || ! is_dir( $real ) ) {
			return;
		}
		$real                             = str_replace( '\\', '/', $real );
		$roots[ $logical . "\0" . $real ] = array(
			'logical'  => $logical,
			'real'     => $real,
			'explicit' => $explicit,
		);
	}

	private function inside( string $path, string $root ): bool {
		$path = str_replace( '\\', '/', $path );
		$root = str_replace( '\\', '/', $root );
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
			$base = substr( $parts[1], 0, -4 );
			if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $parts[0] ) || ! str_ends_with( $parts[1], '.php' ) || 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $base ) ) {
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
		if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $parts[0] ) ) {
			return 'theme_nested_identity_unsupported';
		}
		return array(
			'identity' => $parts[0],
			'root'     => $parts[0],
			'file'     => 'style.css',
		);
	}

	/**
	 * @param array<int|string,int> $initial Initial file metadata.
	 * @return string|array{0:string,1:array<int|string,int>}
	 */
	private function capture( string $file, array $initial ): string|array {
		$stream = @fopen( $file, 'rb' );
		if ( ! is_resource( $stream ) ) {
			return 'installed_file_unreadable';
		}

		try {
			if ( null !== $this->before_first_stat ) {
				( $this->before_first_stat )( $file );
			}
			$first = @fstat( $stream );
			if ( ! $this->same_stat( $initial, $first ) ) {
				return 'installed_file_changed';
			}
			$locked = null === $this->lock ? @flock( $stream, LOCK_SH | LOCK_NB ) : ( $this->lock )( $stream );
			if ( ! $locked ) {
				return 'installed_file_unreadable';
			}
			$one     = null === $this->read ? fread( $stream, self::MAX_HEADER_BYTES ) : ( $this->read )( $stream, 1 );
			$rewound = null === $this->rewind ? -1 !== @fseek( $stream, 0 ) : ( $this->rewind )( $stream );
			if ( ! is_string( $one ) || ! $rewound ) {
				return 'installed_file_unreadable';
			}
			if ( null !== $this->after_first_read ) {
				( $this->after_first_read )( $file );
			}
			$two  = null === $this->read ? fread( $stream, self::MAX_HEADER_BYTES ) : ( $this->read )( $stream, 2 );
			$last = @fstat( $stream );
			if ( ! is_string( $two ) ) {
				return 'installed_file_unreadable';
			}
			if ( $one !== $two || ! $this->same_stat( $initial, $last ) ) {
				return 'installed_file_changed';
			}
			return array( $one, $last );
		} finally {
			fclose( $stream );
		}
	}

	/** @param array<string,string> $headers */
	private function requirements_incompatible( array $headers ): bool {
		if ( null === ReleaseVersion::normalize_header( $headers['Version'] ) ) {
			return true;
		}
		if ( '' !== $headers['RequiresPHP'] && ( null === ReleaseVersion::normalize_header( $headers['RequiresPHP'] ) || ReleaseVersion::compare( PHP_VERSION, $headers['RequiresPHP'] ) < 0 ) ) {
			return true;
		}
		$wordpress  = \RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState::normalize_word_press_version( $GLOBALS['wp_version'] ?? null );
		$comparison = is_string( $wordpress ) ? ReleaseVersion::compare( $wordpress, $headers['RequiresWP'] ) : null;
		return '' !== $headers['RequiresWP'] && ( null === ReleaseVersion::normalize_header( $headers['RequiresWP'] ) || null === $comparison || $comparison < 0 );
	}

	/**
	 * @param array<int|string,int> $one Initial file metadata.
	 * @phpstan-assert-if-true =array<mixed> $two
	 */
	private function same_stat( array $one, mixed $two ): bool {
		if ( ! is_array( $two ) ) {
			return false;
		}
		foreach ( array( 'dev', 'ino', 'mode', 'size', 'mtime', 'ctime' ) as $key ) {
			if ( ( $one[ $key ] ?? null ) !== ( $two[ $key ] ?? null ) ) {
				return false;
			}
		}
		return true;
	}
}
