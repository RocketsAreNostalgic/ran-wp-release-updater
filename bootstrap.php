<?php

declare(strict_types=1);

use RAN\WPReleaseUpdater\V1\Runtime\RequestBroker;
use RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState;

$ran_wp_release_updater_valid_runtime_version   = static function ( string $value ): bool {
	$pattern = '/\Av?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
		. '(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?'
		. '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/D';
	if ( ! preg_match( $pattern, $value, $match ) ) {
		return false;
	}
	foreach ( isset( $match[4] ) ? explode( '.', $match[4] ) : array() as $identifier ) {
		if ( preg_match( '/\A[0-9]+\z/D', $identifier ) && ! preg_match( '/\A(?:0|[1-9]\d*)\z/D', $identifier ) ) {
			return false;
		}
	}
	return true;
};
$ran_wp_release_updater_package_origin          = static function ( string $root, ?string $source = null ) use ( $ran_wp_release_updater_valid_runtime_version ): ?array {
	$root = realpath( $root );
	if ( ! is_string( $root ) ) {
		return null;
	}
	$brokerSource = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'RequestBroker.php';
	$copyFile     = $root . DIRECTORY_SEPARATOR . 'runtime-copy.json';
	if ( ! is_string( $source ) ) {
		$source = realpath( $brokerSource );
	}
	if ( ! is_string( $source ) || realpath( $brokerSource ) !== $source || ! is_file( $copyFile ) || is_link( $copyFile ) ) {
		return null;
	}
	try {
		$copy = json_decode( (string) file_get_contents( $copyFile ), true, 512, JSON_THROW_ON_ERROR );
		$keys = array( 'package_revision', 'package_version', 'php_floor', 'runtime_file', 'runtime_protocol', 'wordpress_floor' );
		if (
			! is_array( $copy )
			|| array_is_list( $copy )
			|| $keys !== array_keys( $copy )
			|| ! is_string( $copy['package_revision'] )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $copy['package_revision'] )
			|| ! is_string( $copy['package_version'] )
			|| ! is_string( $copy['php_floor'] )
			|| ! $ran_wp_release_updater_valid_runtime_version( $copy['package_version'] )
			|| ! $ran_wp_release_updater_valid_runtime_version( $copy['php_floor'] )
			|| 'runtime.php' !== $copy['runtime_file']
			|| 4 !== $copy['runtime_protocol']
			|| ! is_string( $copy['wordpress_floor'] )
			|| ! $ran_wp_release_updater_valid_runtime_version( $copy['wordpress_floor'] )
		) {
			return null;
		}
		$files   = array( 'bootstrap.php', 'runtime.php' );
		$regular = static function ( string $relative ) use ( $root ): ?string {
			$expected = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			$actual   = realpath( $expected );
			return ! is_link( $expected ) && is_file( $expected ) && is_string( $actual ) && $actual === $expected ? $actual : null;
		};
		foreach ( $files as $file ) {
			if ( ! is_string( $regular( $file ) ) ) {
				return null;
			}
		}
		$sourceDirectory = $root . DIRECTORY_SEPARATOR . 'src';
		if ( is_link( $sourceDirectory ) || ! is_dir( $sourceDirectory ) || realpath( $sourceDirectory ) !== $sourceDirectory ) {
			return null;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $sourceDirectory, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isLink() ) {
				return null;
			}
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
				if ( ! is_string( $regular( $relative ) ) ) {
					return null;
				}
				$files[] = $relative;
			}
		}
		sort( $files, SORT_STRING );
		$payload = '';
		foreach ( $files as $file ) {
			$hash = hash_file( 'sha256', $regular( $file ) ?: '' );
			if ( ! is_string( $hash ) ) {
				return null;
			}
			$payload .= $file . "\0" . $hash . "\n";
		}
		if ( ! hash_equals( hash( 'sha256', $payload ), $copy['package_revision'] ) ) {
			return null;
		}
	} catch ( Throwable ) {
		return null;
	}
	return array(
		'root'   => $root,
		'source' => $source,
	);
};
$ran_wp_release_updater_request_broker_unloaded = ! class_exists( RequestBroker::class, false );
$ran_wp_release_updater_current_package_origin  = $ran_wp_release_updater_request_broker_unloaded ? $ran_wp_release_updater_package_origin( __DIR__ ) : null;
if ( $ran_wp_release_updater_request_broker_unloaded && is_array( $ran_wp_release_updater_current_package_origin ) ) {
	require_once $ran_wp_release_updater_current_package_origin['source'];
}
$ran_wp_release_updater_broker_origin            = static function ( object|string $broker ) use ( $ran_wp_release_updater_current_package_origin, $ran_wp_release_updater_package_origin ): ?array {
	try {
		$source = ( new ReflectionClass( $broker ) )->getFileName();
		$source = is_string( $source ) ? realpath( $source ) : false;
	} catch ( Throwable ) {
		return null;
	}
	if ( ! is_string( $source ) || 'RequestBroker.php' !== basename( $source ) ) {
		return null;
	}
	$origin = is_array( $ran_wp_release_updater_current_package_origin ) && $source === $ran_wp_release_updater_current_package_origin['source']
		? $ran_wp_release_updater_current_package_origin
		: $ran_wp_release_updater_package_origin( dirname( $source, 3 ), $source );
	if ( ! is_array( $origin ) ) {
		return null;
	}
	$origin['broker'] = is_object( $broker ) ? $broker : null;
	return $origin;
};
$ran_wp_release_updater_cached_broker_origin     = static function ( mixed $broker, mixed $provenance ): ?array {
	if (
		! is_object( $broker )
		|| ! is_array( $provenance )
		|| ( $provenance['broker'] ?? null ) !== $broker
		|| ! is_string( $provenance['root'] ?? null )
		|| ! is_string( $provenance['source'] ?? null )
	) {
		return null;
	}
	try {
		$source = ( new ReflectionClass( $broker ) )->getFileName();
		$source = is_string( $source ) ? realpath( $source ) : false;
	} catch ( Throwable ) {
		return null;
	}
	if ( ! is_string( $source ) || 'RequestBroker.php' !== basename( $source ) || $source !== $provenance['source'] ) {
		return null;
	}
	$root = realpath( dirname( $source, 3 ) );
	if (
		! is_string( $root )
		|| $root !== $provenance['root']
		|| realpath( $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'RequestBroker.php' ) !== $source
	) {
		return null;
	}

	return $provenance;
};
$ran_wp_release_updater_existing_broker          = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
$ran_wp_release_updater_cached_broker_provenance = $ran_wp_release_updater_cached_broker_origin(
	$ran_wp_release_updater_existing_broker,
	$GLOBALS['ran_wp_release_updater_v1_broker_provenance'] ?? null,
);
$ran_wp_release_updater_broker_class_origin      = is_array( $ran_wp_release_updater_cached_broker_provenance )
	? $ran_wp_release_updater_cached_broker_provenance
	: ( null === $ran_wp_release_updater_existing_broker ? $ran_wp_release_updater_broker_origin( RequestBroker::class ) : null );
$ran_wp_release_updater_can_create_broker        = is_array( $ran_wp_release_updater_broker_class_origin ) && $ran_wp_release_updater_broker_class_origin['root'] === realpath( __DIR__ );
if ( null === $ran_wp_release_updater_existing_broker && $ran_wp_release_updater_can_create_broker ) {
	$ran_wp_release_updater_state_source = $ran_wp_release_updater_broker_class_origin['root'] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'SelectedRuntimeState.php';
	if ( ! class_exists( SelectedRuntimeState::class, false ) ) {
		require_once $ran_wp_release_updater_state_source;
	}
	try {
		$ran_wp_release_updater_loaded_state_source = ( new ReflectionClass( SelectedRuntimeState::class ) )->getFileName();
		$ran_wp_release_updater_loaded_state_source = is_string( $ran_wp_release_updater_loaded_state_source ) ? realpath( $ran_wp_release_updater_loaded_state_source ) : false;
		$ran_wp_release_updater_can_create_broker   = is_string( $ran_wp_release_updater_loaded_state_source ) && $ran_wp_release_updater_loaded_state_source === realpath( $ran_wp_release_updater_state_source );
	} catch ( Throwable ) {
		$ran_wp_release_updater_can_create_broker = false;
	}
}
$ran_wp_release_updater_broker          = $ran_wp_release_updater_existing_broker;
$ran_wp_release_updater_created_broker  = false;
$ran_wp_release_updater_can_schedule    = false;
$ran_wp_release_updater_boundary_missed = false;
if ( null === $ran_wp_release_updater_broker && $ran_wp_release_updater_can_create_broker ) {
	if ( function_exists( 'doing_action' ) && function_exists( 'did_action' ) && function_exists( 'add_action' ) ) {
		try {
			$ran_wp_release_updater_running      = doing_action( 'after_setup_theme' );
			$ran_wp_release_updater_completed    = did_action( 'after_setup_theme' );
			$ran_wp_release_updater_can_schedule = ! $ran_wp_release_updater_running && ! $ran_wp_release_updater_completed;
			if ( $ran_wp_release_updater_running ) {
				$ran_wp_release_updater_hook         = $GLOBALS['wp_filter']['after_setup_theme'] ?? null;
				$ran_wp_release_updater_priority     = is_object( $ran_wp_release_updater_hook ) && is_callable( array( $ran_wp_release_updater_hook, 'current_priority' ) )
					? $ran_wp_release_updater_hook->current_priority()
					: null;
				$ran_wp_release_updater_can_schedule = is_int( $ran_wp_release_updater_priority ) && PHP_INT_MAX > $ran_wp_release_updater_priority;
			}
			$ran_wp_release_updater_boundary_missed = ! $ran_wp_release_updater_can_schedule;
		} catch ( Throwable ) {
			$ran_wp_release_updater_boundary_missed = true;
		}
	}
	$ran_wp_release_updater_selected_state = new SelectedRuntimeState();
	$ran_wp_release_updater_broker         = new RequestBroker( $ran_wp_release_updater_boundary_missed, $ran_wp_release_updater_selected_state );
	$ran_wp_release_updater_selected_state->bind( $ran_wp_release_updater_broker );
	$ran_wp_release_updater_broker_class_origin['broker'] = $ran_wp_release_updater_broker;
	$GLOBALS['ran_wp_release_updater_v1_broker']          = $ran_wp_release_updater_broker;
	$ran_wp_release_updater_created_broker                = true;
}

$ran_wp_release_updater_broker_provenance = $ran_wp_release_updater_created_broker
	? $ran_wp_release_updater_broker_class_origin
	: ( is_array( $ran_wp_release_updater_cached_broker_provenance )
		? $ran_wp_release_updater_cached_broker_provenance
		: ( is_object( $ran_wp_release_updater_broker ) ? $ran_wp_release_updater_broker_origin( $ran_wp_release_updater_broker ) : null ) );
$ran_wp_release_updater_broker_compatible = is_array( $ran_wp_release_updater_broker_provenance )
	&& $ran_wp_release_updater_broker instanceof RequestBroker
	&& is_callable( array( $ran_wp_release_updater_broker, 'protocolVersion' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'registerCandidate' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'activate' ) )
		&& is_callable( array( $ran_wp_release_updater_broker, 'registerTarget' ) )
		&& is_callable( array( $ran_wp_release_updater_broker, 'releaseSource' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'targetStatus' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'targetDiagnostics' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'refreshTarget' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'diagnostics' ) );
if ( $ran_wp_release_updater_broker_compatible ) {
	try {
		$ran_wp_release_updater_broker_compatible = 4 === $ran_wp_release_updater_broker->protocolVersion();
	} catch ( Throwable ) {
		$ran_wp_release_updater_broker_compatible = false;
	}
}

if ( $ran_wp_release_updater_broker_compatible ) {
	$GLOBALS['ran_wp_release_updater_v1_broker_provenance'] = $ran_wp_release_updater_broker_provenance;
} else {
	unset( $GLOBALS['ran_wp_release_updater_v1_broker_provenance'] );
}

if ( ! $ran_wp_release_updater_broker_compatible ) {
	$ran_wp_release_updater_broker = new class() {
		/** @var list<array{code:string}> */
		private array $diagnostics = array( array( 'code' => 'protocol_conflict_inactive' ) );

		public function protocolVersion(): int {
			return 4;
		}

		public function registerCandidate( string $copyFile ): bool {
			unset( $copyFile );
			return false;
		}

		/** @return array<string,mixed> */
		public function activate( array $environment ): array {
			unset( $environment );
			return array(
				'loaded'      => false,
				'state'       => 'conflict',
				'code'        => 'protocol_conflict_inactive',
				'diagnostics' => $this->diagnostics,
			);
		}

		/** @return array<string,mixed> */
		public function diagnostics(): array {
			return array(
				'protocol_version'     => 4,
				'state'                => 'conflict',
				'activation_attempted' => false,
				'candidate_count'      => 0,
				'submission_count'     => 0,
				'logical_target_count' => 0,
				'diagnostics'          => $this->diagnostics,
			);
		}

		/** @return array{accepted:false,submission_id:0,code:string} */
		public function registerTarget( array $declaration ): array {
			unset( $declaration );
			return array(
				'accepted'      => false,
				'submission_id' => 0,
				'code'          => 'protocol_conflict_inactive',
			);
		}

		public function releaseSource( array $declaration ): array {
			unset( $declaration );
			return array(
				'accepted'      => false,
				'code'          => 'runtime_unavailable',
				'source_handle' => null,
			);
		}

		/** @return array<string,mixed> */
		public function targetStatus( int $id ): array {
			unset( $id );
			return array(
				'state'                => 'inactive',
				'declaration_accepted' => false,
				'hooks_registered'     => false,
				'code'                 => 'protocol_conflict_inactive',
				'native'               => null,
			);
		}

		/** @return array<string,mixed> */
		public function targetDiagnostics( int $id ): array {
			unset( $id );
			return array(
				'state'       => 'inactive',
				'diagnostics' => $this->diagnostics,
			);
		}

		public function refreshTarget( int $id ): bool {
			unset( $id );
			return false;
		}
	};
}

if ( $ran_wp_release_updater_broker_compatible ) {
	$ran_wp_release_updater_broker->registerCandidate( __DIR__ . '/runtime-copy.json' );

	if ( $ran_wp_release_updater_created_broker && $ran_wp_release_updater_can_schedule ) {
		add_action(
			'after_setup_theme',
			static function () use ( $ran_wp_release_updater_selected_state ): void {
				$ran_wp_release_updater_selected_state->activate();
			},
			PHP_INT_MAX,
			0
		);
	}
}

return new class( $ran_wp_release_updater_broker ) {
	public function __construct( private object $broker ) {
	}

	public function plugin( string $provider, string $pluginFile, string $repository, string $repositoryId, string $channel = 'stable', string $updatePolicy = 'manual', ?callable $credentials = null, mixed $maximumArtifactBytes = 52428800 ): object {
		return $this->target( 'plugin', $pluginFile, $provider, $repository, $repositoryId, $channel, $updatePolicy, $credentials, $maximumArtifactBytes );
	}

	public function theme( string $provider, string $stylesheetFile, string $repository, string $repositoryId, string $channel = 'stable', string $updatePolicy = 'manual', ?callable $credentials = null, mixed $maximumArtifactBytes = 52428800 ): object {
		return $this->target( 'theme', $stylesheetFile, $provider, $repository, $repositoryId, $channel, $updatePolicy, $credentials, $maximumArtifactBytes );
	}

	public function releases( string $provider, string $packageType, string $repository, string $repositoryId, string $channel = 'stable', ?callable $credentials = null, mixed $maximumArtifactBytes = 52428800 ): object {
		$declaration = array(
			'provider_code'          => $provider,
			'target_type'            => $packageType,
			'repository_locator'     => $repository,
			'repository_identity'    => $repositoryId,
			'channel'                => $channel,
			'credential_resolver'    => $credentials,
			'maximum_artifact_bytes' => $maximumArtifactBytes,
		);
		return new class( $this->broker, $declaration ) {
			private ?object $selected = null;
			private bool $terminal    = false;

			public function __construct( private object $broker, private array $declaration ) {
			}

			public function list( array $conditional = array() ): array {
				if ( $this->terminalNow() ) {
					return $this->failure( 'runtime_unavailable' );
				}
				if ( ! $this->validConditional( $conditional ) ) {
					return $this->failure( 'invalid_configuration' );
				}
				return $this->call( 'list', array( $conditional ) );
			}

			public function inspect( string $releaseId, string $expectedTag ): array {
				if ( $this->terminalNow() ) {
					return $this->failure( 'runtime_unavailable' );
				}
				if ( ! $this->opaque( $releaseId, 191 ) || ! $this->opaque( $expectedTag, 191 ) ) {
					return $this->failure( 'invalid_release' );
				}
				return $this->call( 'inspect', array( $releaseId, $expectedTag ) );
			}

			public function acquire( string $releaseId, string $expectedTag, string $expectedFingerprint ): array {
				if ( $this->terminalNow() ) {
					return $this->failure( 'runtime_unavailable' );
				}
				if ( ! $this->opaque( $releaseId, 191 ) || ! $this->opaque( $expectedTag, 191 ) || 1 !== preg_match( '/\\Av2:[a-f0-9]{64}\\z/D', $expectedFingerprint ) ) {
					return $this->failure( 'invalid_release' );
				}
				return $this->call( 'acquire', array( $releaseId, $expectedTag, $expectedFingerprint ) );
			}

			private function call( string $method, array $arguments ): array {
				if ( ! is_object( $this->selected ) ) {
					try {
						$resolved = $this->broker->releaseSource( $this->declaration );
					} catch ( Throwable ) {
						$this->terminal = true;
						return $this->failure( 'runtime_unavailable' );
					}
					if ( ! $this->validResolution( $resolved ) ) {
						$this->terminal = true;
						return $this->failure( 'runtime_unavailable' );
					}
					if ( ! $resolved['accepted'] ) {
						if ( 'runtime_unavailable' === $resolved['code'] ) {
							$this->terminal = true;
						}
						return $this->failure( $resolved['code'] );
					}
					$this->selected = $resolved['source_handle'];
				}
				try {
					$result = $this->selected->{$method}( ...$arguments );
				} catch ( Throwable ) {
					$this->terminal = true;
					return $this->failure( 'runtime_unavailable' );
				}
				try {
					$valid = $this->validResult( $method, $result ) && ! $this->terminalNow();
				} catch ( Throwable ) {
					$valid = false;
				}
				if ( ! $valid ) {
					$this->terminal = true;
					return $this->failure( 'runtime_unavailable', $this->discardMalformedArtifact( $method, $result ) );
				}
				if ( 'runtime_unavailable' === $result['code'] ) {
					$this->terminal = true;
				}
				return $result;
			}

			private function validResolution( mixed $value ): bool {
				if ( ! is_array( $value ) || array_keys( $value ) !== array( 'accepted', 'code', 'source_handle' ) || ! is_bool( $value['accepted'] ) || ! is_string( $value['code'] ) ) {
					return false;
				}
				if ( $value['accepted'] ) {
					return 'release_source_ready' === $value['code'] && is_object( $value['source_handle'] );
				}
				return null === $value['source_handle'] && in_array( $value['code'], array( 'invalid_configuration', 'runtime_not_ready', 'runtime_unavailable', 'provider_unavailable', 'filesystem_unsupported' ), true );
			}

			private function terminalNow(): bool {
				if ( $this->terminal ) {
					return true;
				}
				try {
					$diagnostics    = $this->broker->diagnostics();
					$this->terminal = ! is_array( $diagnostics ) || in_array( $diagnostics['state'] ?? null, array( 'inactive', 'conflict' ), true );
				} catch ( Throwable ) {
					$this->terminal = true;
				}
				return $this->terminal;
			}

			private function validResult( string $operation, mixed $result ): bool {
				if ( ! $this->keys( $result, array( 'ok', 'code', 'value', 'retry_after', 'cleanup_status' ) )
					|| ! is_bool( $result['ok'] ) || ! is_string( $result['code'] ) ) {
					return false;
				}
				$cleanup = $result['cleanup_status'];
				if ( $result['ok'] ) {
					if ( null !== $result['retry_after'] ) {
						return false;
					}
					return match ( $operation ) {
						'list' => 'not_applicable' === $cleanup && $this->validListing( $result['value'] )
							&& $result['code'] === ( $result['value']['not_modified'] ? 'releases_not_modified' : 'releases_listed' ),
						'inspect' => 'release_inspected' === $result['code'] && 'complete' === $cleanup && $this->validInspection( $result['value'] ),
						'acquire' => 'release_acquired' === $result['code'] && 'retained' === $cleanup
							&& $this->keys( $result['value'], array( 'inspection', 'artifact' ) )
							&& $this->validInspection( $result['value']['inspection'] ) && $this->ownedArtifact( $result['value']['artifact'] ),
						default => false,
					};
				}
				$codes = array(
					'invalid_configuration',
					'invalid_release',
					'runtime_not_ready',
					'runtime_unavailable',
					'provider_unavailable',
					'filesystem_unsupported',
					'credential_unavailable',
					'repository_access_unavailable',
					'rate_limited',
					'release_unavailable',
					'package_incompatible',
					'release_changed',
					'operation_failed',
					'cleanup_failed',
				);
				if ( null !== $result['value'] || ! in_array( $result['code'], $codes, true )
					|| ! in_array( $cleanup, array( 'not_applicable', 'complete', 'failed' ), true )
					|| ( 'list' === $operation && ( 'not_applicable' !== $cleanup || in_array( $result['code'], array( 'invalid_release', 'package_incompatible', 'release_changed', 'cleanup_failed' ), true ) ) )
					|| ( 'release_changed' === $result['code'] && ( 'acquire' !== $operation || 'not_applicable' === $cleanup ) )
					|| ( 'cleanup_failed' === $result['code'] && 'failed' !== $cleanup )
					|| ( in_array( $result['code'], array( 'invalid_configuration', 'invalid_release', 'runtime_not_ready', 'provider_unavailable', 'filesystem_unsupported', 'credential_unavailable' ), true ) && 'not_applicable' !== $cleanup ) ) {
					return false;
				}
				return 'rate_limited' === $result['code']
					? is_int( $result['retry_after'] ) && 1 <= $result['retry_after'] && 86400 >= $result['retry_after']
					: null === $result['retry_after'];
			}

			private function validListing( mixed $value ): bool {
				if ( ! $this->keys( $value, array( 'candidates', 'conditional', 'not_modified', 'rate_limit', 'search_exhausted' ) )
					|| ! is_array( $value['candidates'] ) || ! array_is_list( $value['candidates'] ) || count( $value['candidates'] ) > 8
					|| ! is_bool( $value['not_modified'] ) || ! is_bool( $value['search_exhausted'] )
					|| ( $value['not_modified'] && array() !== $value['candidates'] )
					|| ! $this->keys( $value['conditional'], array( 'etag', 'last_modified' ) )
					|| ! $this->keys( $value['rate_limit'], array( 'limited', 'remaining', 'reset_at', 'retry_after' ) ) ) {
					return false;
				}
				foreach ( array(
					'etag'          => 512,
					'last_modified' => 128,
				) as $key => $limit ) {
					$item = $value['conditional'][ $key ];
					if ( null !== $item && ( ! is_string( $item ) || strlen( $item ) > $limit || 1 !== preg_match( '/\A[\x20-\x7E]*\z/D', $item ) ) ) {
						return false;
					}
				}
				$rate = $value['rate_limit'];
				if ( false !== $rate['limited'] || 0 !== $rate['retry_after'] ) {
					return false;
				}
				foreach ( array( 'remaining', 'reset_at' ) as $key ) {
					if ( null !== $rate[ $key ] && ( ! is_int( $rate[ $key ] ) || 0 > $rate[ $key ] ) ) {
						return false;
					}
				}
				foreach ( $value['candidates'] as $candidate ) {
					if ( ! $this->keys( $candidate, array( 'details_url', 'expected_asset_names', 'prerelease', 'publication_immutable', 'published_at', 'release_identity', 'tag', 'version' ) )
						|| ! $this->publicUrl( $candidate['details_url'] ) || ! is_array( $candidate['expected_asset_names'] )
						|| ! array_is_list( $candidate['expected_asset_names'] ) || 2 < count( $candidate['expected_asset_names'] )
						|| ! is_bool( $candidate['prerelease'] ) || ! is_bool( $candidate['publication_immutable'] )
						|| ! $this->matches( $candidate['published_at'], '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/D' )
						|| ! $this->boundedIdentity( $candidate['release_identity'] ) || ! $this->boundedIdentity( $candidate['tag'] )
						|| ! is_string( $candidate['version'] ) || null === \RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion::normalize( $candidate['version'] ) ) {
						return false;
					}
					foreach ( $candidate['expected_asset_names'] as $name ) {
						if ( ! $this->matches( $name, '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,215}\.zip\z/Di' ) ) {
							return false;
						}
					}
				}
				return true;
			}

			private function validInspection( mixed $value ): bool {
				$keys      = array(
					'artifact_filename',
					'artifact_identity',
					'artifact_sha256',
					'artifact_size',
					'assurance_facts',
					'canonical_update_uri',
					'channel',
					'commit_identity',
					'main_file',
					'maximum_artifact_bytes',
					'package_root',
					'php_runtime_version',
					'provider_code',
					'release_identity',
					'repository_identity',
					'repository_locator',
					'tag',
					'target_type',
					'version',
					'wordpress_runtime_version',
					'fingerprint',
				);
				$assurance = array(
					'exact_artifact_identity',
					'exact_commit_identity',
					'exact_reacquisition_supported',
					'exact_release_identity',
					'provenance_verified',
					'publication_immutable',
					'repository_identity_stable',
					'trusted_digest_source',
				);
				if ( ! $this->keys( $value, $keys ) || ! $this->keys( $value['assurance_facts'], $assurance )
					|| ! is_int( $value['artifact_size'] ) || 1 > $value['artifact_size']
					|| ! is_int( $value['maximum_artifact_bytes'] ) || $value['artifact_size'] > $value['maximum_artifact_bytes']
					|| ! in_array( $value['target_type'], array( 'plugin', 'theme' ), true )
					|| ! in_array( $value['channel'], array( 'stable', 'prerelease' ), true ) ) {
					return false;
				}
				foreach ( $assurance as $key ) {
					if ( ! is_bool( $value['assurance_facts'][ $key ] ) ) {
						return false;
					}
				}
				foreach ( array( 'artifact_identity', 'commit_identity', 'release_identity', 'repository_identity', 'tag' ) as $key ) {
					if ( ! $this->boundedIdentity( $value[ $key ] ) ) {
						return false;
					}
				}
				foreach ( array( 'php_runtime_version', 'wordpress_runtime_version' ) as $key ) {
					if ( ! is_string( $value[ $key ] ) || null === \RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion::normalizeHeader( $value[ $key ] ) ) {
						return false;
					}
				}
				if ( ! is_string( $value['version'] ) || null === \RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion::normalize( $value['version'] )
					|| ! $this->boundedIdentity( $value['repository_locator'], 255 )
					|| ! $this->matches( $value['provider_code'], '/\A[a-z][a-z0-9_-]{0,31}\z/D' )
					|| ! $this->matches( $value['artifact_filename'], '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,215}\.zip\z/Di' )
					|| ! $this->matches( $value['artifact_sha256'], '/\A[a-f0-9]{64}\z/D' )
					|| ! $this->matches( $value['package_root'], '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D' )
					|| ( 'theme' === $value['target_type'] ? 'style.css' !== $value['main_file'] : ! $this->matches( $value['main_file'], '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.php\z/D' ) )
					|| ! is_string( $value['canonical_update_uri'] )
					|| $value['canonical_update_uri'] !== \RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri::canonicalize( $value['canonical_update_uri'] )
					|| ! $this->matches( $value['fingerprint'], '/\Av2:[a-f0-9]{64}\z/D' ) ) {
					return false;
				}
				$facts = $value;
				unset( $facts['fingerprint'] );
				return hash_equals( 'v2:' . hash( 'sha256', json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), $value['fingerprint'] );
			}

			private function keys( mixed $value, array $keys ): bool {
				return is_array( $value ) && array_keys( $value ) === $keys;
			}

			private function matches( mixed $value, string $pattern ): bool {
				return is_string( $value ) && 1 === preg_match( $pattern, $value );
			}

			private function boundedIdentity( mixed $value, int $limit = 191 ): bool {
				return is_string( $value ) && $this->opaque( $value, $limit );
			}

			private function publicUrl( mixed $value ): bool {
				if ( ! $this->boundedIdentity( $value, 2048 ) || str_contains( $value, '\\' ) ) {
					return false;
				}
				$parts = parse_url( $value );
				return is_array( $parts ) && 'https' === strtolower( $parts['scheme'] ?? '' )
					&& isset( $parts['host'], $parts['path'] ) && '' !== $parts['host']
					&& ! array_intersect( array( 'user', 'pass', 'port', 'query', 'fragment' ), array_keys( $parts ) );
			}

			private function ownedArtifact( mixed $artifact ): bool {
				if ( ! is_object( $artifact ) || 'RAN\\WPReleaseUpdater\\V1\\Archive\\TemporaryArtifact' !== $artifact::class ) {
					return false;
				}
				$source = ( new ReflectionClass( $this->selected ) )->getFileName();
				$file   = ( new ReflectionClass( $artifact ) )->getFileName();
				return is_string( $source ) && is_string( $file )
					&& realpath( $file ) === realpath( dirname( $source, 2 ) . '/Archive/TemporaryArtifact.php' );
			}

			private function discardMalformedArtifact( string $operation, mixed $result ): string {
				if ( 'list' === $operation ) {
					return 'not_applicable';
				}
				$artifact = is_array( $result ) && is_array( $result['value'] ?? null ) ? ( $result['value']['artifact'] ?? null ) : null;
				try {
					if ( 'acquire' === $operation && $this->ownedArtifact( $artifact ) ) {
						return $artifact->discard() || $artifact->discard() ? 'complete' : 'failed';
					}
				} catch ( Throwable ) {
					return 'failed';
				}
				$cleanup = is_array( $result ) ? ( $result['cleanup_status'] ?? null ) : null;
				return in_array( $cleanup, array( 'not_applicable', 'complete', 'failed' ), true ) ? $cleanup : 'failed';
			}

			private function validConditional( array $value ): bool {
				foreach ( $value as $key => $item ) {
					if ( ! in_array( $key, array( 'etag', 'last_modified' ), true ) || ( null !== $item && ! is_string( $item ) ) ) {
						return false;
					}
				}
				return true;
			}

			private function opaque( string $value, int $limit ): bool {
				return '' !== $value && strlen( $value ) <= $limit && 1 === preg_match( '//u', $value ) && 1 === preg_match( '/\\A[^\\p{C}\\p{Z}\\s]+\\z/u', $value );
			}

			private function failure( string $code, string $cleanup = 'not_applicable' ): array {
				return array(
					'ok'             => false,
					'code'           => $code,
					'value'          => null,
					'retry_after'    => null,
					'cleanup_status' => $cleanup,
				);
			}
		};
	}

	/** @return array<string,mixed> */
	public function diagnostics(): array {
		return $this->broker->diagnostics();
	}

	private function target( string $type, string $file, string $provider, string $repository, string $repositoryId, string $channel, string $policy, ?callable $credentials, mixed $maximumArtifactBytes ): object {
		$declaration = array(
			'target_type'            => $type,
			'installed_file'         => $file,
			'provider_code'          => $provider,
			'repository_locator'     => $repository,
			'repository_identity'    => $repositoryId,
			'channel'                => $channel,
			'update_policy'          => $policy,
			'credential_resolver'    => $credentials,
			'maximum_artifact_bytes' => $maximumArtifactBytes,
		);
		return new class( $this->broker, $declaration ) {
			private int $submissionId = 0;
			private bool $submitted   = false;
			private bool $accepted    = false;
			private ?string $code     = null;

			public function __construct( private object $broker, private array $declaration ) {
			}

			public function register(): bool {
				if ( $this->submitted ) {
					if ( ! $this->accepted || 0 === $this->submissionId ) {
						return false;
					}
					return 'inactive' !== ( $this->broker->targetStatus( $this->submissionId )['state'] ?? null );
				}
				$this->submitted    = true;
				$result             = $this->broker->registerTarget( $this->declaration );
				$this->submissionId = $result['submission_id'];
				$this->accepted     = $result['accepted'];
				$this->code         = $result['code'];
				return $this->accepted;
			}

			/** @return array<string,mixed> */
			public function status(): array {
				if ( 0 < $this->submissionId ) {
					return $this->broker->targetStatus( $this->submissionId );
				}
				if ( $this->submitted ) {
					return array(
						'state'                => 'inactive',
						'declaration_accepted' => false,
						'hooks_registered'     => false,
						'code'                 => $this->code,
						'native'               => null,
					);
				}
				return array(
					'state'                => 'new',
					'declaration_accepted' => false,
					'hooks_registered'     => false,
					'code'                 => 'target_unregistered',
					'native'               => null,
				);
			}

			/** @return array<string,mixed> */
			public function diagnostics(): array {
				if ( 0 < $this->submissionId ) {
					return $this->broker->targetDiagnostics( $this->submissionId );
				}
				if ( $this->submitted ) {
					return array(
						'state'       => 'inactive',
						'diagnostics' => array( array( 'code' => $this->code ) ),
					);
				}
				return array(
					'state'       => 'new',
					'diagnostics' => array(),
				);
			}

			public function refresh(): bool {
				return 0 < $this->submissionId && $this->broker->refreshTarget( $this->submissionId );
			}
		};
	}
};
