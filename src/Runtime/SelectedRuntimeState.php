<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Runtime;

/**
 * Request-local ownership of the selected protocol-11 broker.
 */
final class SelectedRuntimeState {

	private const MAX_WORDPRESS_VERSION_LENGTH = 100;

	/** @var array<string,true> */
	private array $operations = array();

	public function __construct( private ?RequestBroker $broker = null ) {
	}

	public function bind( RequestBroker $broker ): void {
		$this->broker = $broker;
	}

	public function broker_is_live(): bool {
		return null === $this->liveness_code();
	}

	/** @internal */
	public function liveness_code(): ?string {
		if (
			! $this->broker instanceof RequestBroker
			|| ( $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null ) !== $this->broker
		) {
			return 'protocol_conflict_inactive';
		}

		try {
			$diagnostics = $this->broker->diagnostics();
			if ( 11 !== $this->broker->protocol_version() ) {
				return 'protocol_conflict_inactive';
			}
			return in_array( $diagnostics['state'] ?? null, array( 'activating', 'active' ), true )
				? null
				: 'runtime_handoff_invalid';
		} catch ( \Throwable ) {
			return 'runtime_handoff_invalid';
		}
	}

	/** @internal */
	public function release_readiness_code(): ?string {
		$liveness = $this->liveness_code();
		if ( null !== $liveness ) {
			return 'runtime_unavailable';
		}
		try {
			return 'active' === ( $this->broker?->diagnostics()['state'] ?? null ) ? null : 'runtime_not_ready';
		} catch ( \Throwable ) {
			return 'runtime_unavailable';
		}
	}

	/** @return array{loaded:bool,state:string,code:string,diagnostics:list<array{code:string}>} */
	public function activate(): array {
		if ( ! $this->broker instanceof RequestBroker ) {
			return array(
				'loaded'      => false,
				'state'       => 'inactive',
				'code'        => 'protocol_conflict_inactive',
				'diagnostics' => array( array( 'code' => 'protocol_conflict_inactive' ) ),
			);
		}
		try {
			if ( 11 !== $this->broker->protocol_version() ) {
				throw new \RuntimeException( 'Inactive broker.' );
			}
			return $this->broker->activate(
				array(
					'php_version'       => self::current_php_version(),
					'runtime_protocol'  => 11,
					'wordpress_version' => self::normalize_word_press_version( $GLOBALS['wp_version'] ?? null ),
				)
			);
		} catch ( \Throwable ) {
			return array(
				'loaded'      => false,
				'state'       => 'inactive',
				'code'        => 'protocol_conflict_inactive',
				'diagnostics' => array( array( 'code' => 'protocol_conflict_inactive' ) ),
			);
		}
	}

	public function operation_started( string $type ): bool {
		return $this->valid_type( $type ) && isset( $this->operations[ $type ] );
	}

	public function begin_operation( string $type ): void {
		if ( $this->valid_type( $type ) ) {
			$this->operations[ $type ] = true;
		}
	}

	/** @internal Returns the normalized runtime PHP version, preserving prereleases while stripping distro suffixes. */
	public static function current_php_version( string $version = PHP_VERSION ): string {
		return self::normalize_php_version( $version ) ?? $version;
	}

	/** @internal Shared WordPress direct-filesystem check, loading file.php during WP-Cron when available. */
	public static function direct_filesystem_available(): bool {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			if ( defined( 'ABSPATH' ) && '' !== ABSPATH ) {
				$file = rtrim( ABSPATH, '/\\' ) . '/wp-admin/includes/file.php';
				if ( is_file( $file ) && is_readable( $file ) ) {
					require_once $file;
				}
			}
		}
		if ( function_exists( 'get_filesystem_method' ) ) {
			return 'direct' === get_filesystem_method();
		}
		// @phpstan-ignore phpstanWP.wpConstant.fetch (The sealed runtime must inspect direct-filesystem policy without invoking filesystem negotiation.)
		return ( defined( 'FS_METHOD' ) && 'direct' === FS_METHOD )
			|| ( ! defined( 'FS_METHOD' ) && ( ( $GLOBALS['wp_filesystem'] ?? null ) instanceof \WP_Filesystem_Direct ) );
	}

	/** @internal Normalizes runtime PHP version strings including Debian/Ubuntu/Sury distro suffixes. */
	public static function normalize_php_version( mixed $value ): ?string {
		if ( ! is_string( $value ) || self::MAX_WORDPRESS_VERSION_LENGTH < strlen( $value ) ) {
			return null;
		}
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $value, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.0';
		}
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $value ) ) {
			return $value;
		}
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(?:0|[1-9]\d*)[+~][0-9A-Za-z.+~-]+|\+[0-9A-Za-z.+~-]+)\z/D', $value, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.' . $matches[3];
		}
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)[-~]?(alpha|beta|rc)([1-9]\d*)(?:[+~-][0-9A-Za-z.+~-]+)?\z/Di', $value, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.' . $matches[3] . '-' . strtolower( $matches[4] ) . '.' . $matches[5];
		}
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)(?:[+~][0-9A-Za-z.+~-]+)?\z/D', $value, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.' . $matches[3] . '-' . $matches[4];
		}
		return null;
	}

	/** @internal Normalizes WordPress core's bounded development-version forms. */
	public static function normalize_word_press_version( mixed $value ): ?string {
		if ( ! is_string( $value ) || self::MAX_WORDPRESS_VERSION_LENGTH < strlen( $value ) ) {
			return null;
		}
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $value, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.0';
		}
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?-src\z/D', $value, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.' . ( '' !== ( $matches[3] ?? '' ) ? $matches[3] : '0' ) . '-src';
		}
		if (
			1 === preg_match(
				'/\A(0|[1-9]\d*)\.(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?-(?:(alpha)([1-9]\d*)?|(beta|rc)([1-9]\d*))(?:-(?:[1-9]\d*|src)(?:-src)?)?\z/Di',
				$value,
				$matches
			)
		) {
			$prerelease = '' !== ( $matches[4] ?? '' )
				? 'alpha.' . ( '' !== ( $matches[5] ?? '' ) ? $matches[5] : '0' )
				: strtolower( $matches[6] ?? '' ) . '.' . ( $matches[7] ?? '' );
			return $matches[1] . '.' . $matches[2] . '.' . ( '' !== ( $matches[3] ?? '' ) ? $matches[3] : '0' ) . '-' . $prerelease;
		}
		return 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?\z/D', $value ) ? $value : null;
	}

	private function valid_type( string $type ): bool {
		return 'plugin' === $type || 'theme' === $type;
	}
}
