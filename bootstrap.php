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
	$broker_source = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'RequestBroker.php';
	$copy_file     = $root . DIRECTORY_SEPARATOR . 'runtime-copy.json';
	if ( ! is_string( $source ) ) {
		$source = realpath( $broker_source );
	}
	if ( ! is_string( $source ) || realpath( $broker_source ) !== $source || ! is_file( $copy_file ) || is_link( $copy_file ) ) {
		return null;
	}
	try {
		$copy = json_decode( (string) file_get_contents( $copy_file ), true, 512, JSON_THROW_ON_ERROR );
		$keys = array( 'package_revision', 'package_version', 'php_floor', 'runtime_file', 'runtime_protocol', 'wordpress_floor' );
		if (
			! is_array( $copy )
			|| array_is_list( $copy )
			|| array_keys( $copy ) !== $keys
			|| ! is_string( $copy['package_revision'] )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $copy['package_revision'] )
			|| ! is_string( $copy['package_version'] )
			|| ! is_string( $copy['php_floor'] )
			|| ! $ran_wp_release_updater_valid_runtime_version( $copy['package_version'] )
			|| ! $ran_wp_release_updater_valid_runtime_version( $copy['php_floor'] )
			|| 'runtime.php' !== $copy['runtime_file']
			|| 5 !== $copy['runtime_protocol']
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
		$source_directory = $root . DIRECTORY_SEPARATOR . 'src';
		if ( is_link( $source_directory ) || ! is_dir( $source_directory ) || realpath( $source_directory ) !== $source_directory ) {
			return null;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source_directory, FilesystemIterator::SKIP_DOTS ) );
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
			$regular_file = $regular( $file );
			if ( ! is_string( $regular_file ) ) {
				return null;
			}
			$hash = hash_file( 'sha256', $regular_file );
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
	if ( is_string( $broker ) && ! class_exists( $broker, false ) ) {
		return null;
	}
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
$ran_wp_release_updater_can_create_broker        = is_array( $ran_wp_release_updater_broker_class_origin ) && realpath( __DIR__ ) === $ran_wp_release_updater_broker_class_origin['root'];
if ( null === $ran_wp_release_updater_existing_broker && $ran_wp_release_updater_can_create_broker ) {
	$ran_wp_release_updater_state_source = $ran_wp_release_updater_broker_class_origin['root'] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'SelectedRuntimeState.php';
	if ( ! class_exists( SelectedRuntimeState::class, false ) ) {
		require_once $ran_wp_release_updater_state_source;
	}
	try {
		$ran_wp_release_updater_loaded_state_source = ( new ReflectionClass( SelectedRuntimeState::class ) )->getFileName();
		$ran_wp_release_updater_loaded_state_source = is_string( $ran_wp_release_updater_loaded_state_source ) ? realpath( $ran_wp_release_updater_loaded_state_source ) : false;
		$ran_wp_release_updater_can_create_broker   = is_string( $ran_wp_release_updater_loaded_state_source ) && realpath( $ran_wp_release_updater_state_source ) === $ran_wp_release_updater_loaded_state_source;
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
	&& is_callable( array( $ran_wp_release_updater_broker, 'protocol_version' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'register_candidate' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'activate' ) )
		&& is_callable( array( $ran_wp_release_updater_broker, 'register_target' ) )
		&& is_callable( array( $ran_wp_release_updater_broker, 'release_source' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'target_status' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'target_diagnostics' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'refresh_target' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'diagnostics' ) );
if ( $ran_wp_release_updater_broker_compatible ) {
	try {
		$ran_wp_release_updater_broker_compatible = 5 === $ran_wp_release_updater_broker->protocol_version();
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

		public function protocol_version(): int {
			return 5;
		}

		public function register_candidate( string $copy_file ): bool {
			unset( $copy_file );
			return false;
		}

		/**
		 * @param array<string,mixed> $environment
		 * @return array<string,mixed>
		 */
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
				'protocol_version'     => 5,
				'state'                => 'conflict',
				'activation_attempted' => false,
				'candidate_count'      => 0,
				'submission_count'     => 0,
				'logical_target_count' => 0,
				'diagnostics'          => $this->diagnostics,
			);
		}

		/**
		 * @param array<string,mixed> $declaration
		 * @return array{accepted:false,submission_id:0,code:string}
		 */
		public function register_target( array $declaration ): array {
			unset( $declaration );
			return array(
				'accepted'      => false,
				'submission_id' => 0,
				'code'          => 'protocol_conflict_inactive',
			);
		}

		/**
		 * @param array<string,mixed> $declaration
		 * @return array{accepted:false,code:string,source_handle:null}
		 */
		public function release_source( array $declaration ): array {
			unset( $declaration );
			return array(
				'accepted'      => false,
				'code'          => 'runtime_unavailable',
				'source_handle' => null,
			);
		}

		/** @return array<string,mixed> */
		public function target_status( int $id ): array {
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
		public function target_diagnostics( int $id ): array {
			unset( $id );
			return array(
				'state'       => 'inactive',
				'diagnostics' => $this->diagnostics,
			);
		}

		public function refresh_target( int $id ): bool {
			unset( $id );
			return false;
		}
	};
}

if ( $ran_wp_release_updater_broker_compatible ) {
	$ran_wp_release_updater_broker->register_candidate( __DIR__ . '/runtime-copy.json' );

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

	public function plugin( string $provider, string $plugin_file, string $repository, string $repository_id, string $channel = 'stable', string $update_policy = 'manual', ?callable $credentials = null, mixed $maximum_artifact_bytes = 52428800 ): object {
		return $this->target( 'plugin', $plugin_file, $provider, $repository, $repository_id, $channel, $update_policy, $credentials, $maximum_artifact_bytes );
	}

	public function theme( string $provider, string $stylesheet_file, string $repository, string $repository_id, string $channel = 'stable', string $update_policy = 'manual', ?callable $credentials = null, mixed $maximum_artifact_bytes = 52428800 ): object {
		return $this->target( 'theme', $stylesheet_file, $provider, $repository, $repository_id, $channel, $update_policy, $credentials, $maximum_artifact_bytes );
	}

	public function releases( string $provider, string $package_type, string $repository, string $repository_id, string $channel = 'stable', ?callable $credentials = null, mixed $maximum_artifact_bytes = 52428800 ): object {
		$declaration = array(
			'provider_code'          => $provider,
			'target_type'            => $package_type,
			'repository_locator'     => $repository,
			'repository_identity'    => $repository_id,
			'channel'                => $channel,
			'credential_resolver'    => $credentials,
			'maximum_artifact_bytes' => $maximum_artifact_bytes,
		);
		return new class( $this->broker, $declaration ) {
			private ?object $selected = null;
			private bool $terminal    = false;

			/** @param array<string,mixed> $declaration */
			public function __construct( private object $broker, private array $declaration ) {
			}

			/**
			 * @param array<array-key,mixed> $conditional
			 * @return array<string,mixed>
			 */
			public function list( array $conditional = array() ): array {
				if ( $this->terminal_now() ) {
					return $this->failure( 'runtime_unavailable' );
				}
				if ( ! $this->valid_conditional( $conditional ) ) {
					return $this->failure( 'invalid_configuration' );
				}
				return $this->call( 'list', array( $conditional ) );
			}

			/** @return array<string,mixed> */
			public function inspect( string $release_id, string $expected_tag ): array {
				if ( $this->terminal_now() ) {
					return $this->failure( 'runtime_unavailable' );
				}
				if ( ! $this->opaque( $release_id, 191 ) || ! $this->opaque( $expected_tag, 191 ) ) {
					return $this->failure( 'invalid_release' );
				}
				return $this->call( 'inspect', array( $release_id, $expected_tag ) );
			}

			/** @return array<string,mixed> */
			public function acquire( string $release_id, string $expected_tag, string $expected_fingerprint ): array {
				if ( $this->terminal_now() ) {
					return $this->failure( 'runtime_unavailable' );
				}
				if ( ! $this->opaque( $release_id, 191 ) || ! $this->opaque( $expected_tag, 191 ) || 1 !== preg_match( '/\\Av2:[a-f0-9]{64}\\z/D', $expected_fingerprint ) ) {
					return $this->failure( 'invalid_release' );
				}
				return $this->call( 'acquire', array( $release_id, $expected_tag, $expected_fingerprint ) );
			}

			/**
			 * @param list<mixed> $arguments
			 * @return array<string,mixed>
			 */
			private function call( string $method, array $arguments ): array {
				if ( ! is_object( $this->selected ) ) {
					try {
						if ( ! is_callable( array( $this->broker, 'release_source' ) ) ) {
							throw new RuntimeException( 'Invalid broker handle.' );
						}
						$resolved = $this->broker->release_source( $this->declaration );
					} catch ( Throwable ) {
						$this->terminal = true;
						return $this->failure( 'runtime_unavailable' );
					}
					if ( ! $this->valid_resolution( $resolved ) ) {
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
					$valid = $this->valid_result( $method, $result ) && ! $this->terminal_now();
				} catch ( Throwable ) {
					$valid = false;
				}
				if ( ! $valid ) {
					$this->terminal = true;
					return $this->failure( 'runtime_unavailable', $this->discard_malformed_artifact( $method, $result ) );
				}
				if ( 'runtime_unavailable' === $result['code'] ) {
					$this->terminal = true;
				}
				return $result;
			}

			private function valid_resolution( mixed $value ): bool {
				if ( ! is_array( $value ) || array_keys( $value ) !== array( 'accepted', 'code', 'source_handle' ) || ! is_bool( $value['accepted'] ) || ! is_string( $value['code'] ) ) {
					return false;
				}
				if ( $value['accepted'] ) {
					return 'release_source_ready' === $value['code'] && is_object( $value['source_handle'] );
				}
				return null === $value['source_handle'] && in_array( $value['code'], array( 'invalid_configuration', 'runtime_not_ready', 'runtime_unavailable', 'provider_unavailable', 'filesystem_unsupported' ), true );
			}

			private function terminal_now(): bool {
				if ( $this->terminal ) {
					return true;
				}
				try {
					if ( ! is_callable( array( $this->broker, 'diagnostics' ) ) ) {
						throw new RuntimeException( 'Invalid broker handle.' );
					}
					$diagnostics    = $this->broker->diagnostics();
					$this->terminal = ! is_array( $diagnostics ) || in_array( $diagnostics['state'] ?? null, array( 'inactive', 'conflict' ), true );
				} catch ( Throwable ) {
					$this->terminal = true;
				}
				return $this->terminal;
			}

			private function valid_result( string $operation, mixed $result ): bool {
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
						'list' => 'not_applicable' === $cleanup && $this->valid_listing( $result['value'] )
							&& ( $result['value']['not_modified'] ? 'releases_not_modified' : 'releases_listed' ) === $result['code'],
						'inspect' => 'release_inspected' === $result['code'] && 'complete' === $cleanup && $this->valid_inspection( $result['value'] ),
						'acquire' => 'release_acquired' === $result['code'] && 'retained' === $cleanup
							&& $this->keys( $result['value'], array( 'inspection', 'artifact' ) )
							&& $this->valid_inspection( $result['value']['inspection'] ) && $this->owned_artifact( $result['value']['artifact'] ),
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

			private function valid_listing( mixed $value ): bool {
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
						|| ! $this->public_url( $candidate['details_url'] ) || ! is_array( $candidate['expected_asset_names'] )
						|| ! array_is_list( $candidate['expected_asset_names'] ) || 2 < count( $candidate['expected_asset_names'] )
						|| ! is_bool( $candidate['prerelease'] ) || ! is_bool( $candidate['publication_immutable'] )
						|| ! $this->matches( $candidate['published_at'], '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/D' )
						|| ! $this->bounded_identity( $candidate['release_identity'] ) || ! $this->bounded_identity( $candidate['tag'] )
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

			private function valid_inspection( mixed $value ): bool {
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
					if ( ! $this->bounded_identity( $value[ $key ] ) ) {
						return false;
					}
				}
				foreach ( array( 'php_runtime_version', 'wordpress_runtime_version' ) as $key ) {
					if ( ! is_string( $value[ $key ] ) || null === \RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion::normalize_header( $value[ $key ] ) ) {
						return false;
					}
				}
				if ( ! is_string( $value['version'] ) || null === \RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion::normalize( $value['version'] )
					|| ! $this->bounded_identity( $value['repository_locator'], 255 )
					|| ! $this->matches( $value['provider_code'], '/\A[a-z][a-z0-9_-]{0,31}\z/D' )
					|| ! $this->matches( $value['artifact_filename'], '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,215}\.zip\z/Di' )
					|| ! $this->matches( $value['artifact_sha256'], '/\A[a-f0-9]{64}\z/D' )
					|| ! $this->matches( $value['package_root'], '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D' )
					|| ( 'theme' === $value['target_type'] ? 'style.css' !== $value['main_file'] : ! $this->matches( $value['main_file'], '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.php\z/D' ) )
					|| ! is_string( $value['canonical_update_uri'] )
					|| \RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri::canonicalize( $value['canonical_update_uri'] ) !== $value['canonical_update_uri']
					|| ! $this->matches( $value['fingerprint'], '/\Av2:[a-f0-9]{64}\z/D' ) ) {
					return false;
				}
				$facts = $value;
				unset( $facts['fingerprint'] );
				return hash_equals( 'v2:' . hash( 'sha256', json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), $value['fingerprint'] );
			}

			/** @param list<string> $keys */
			private function keys( mixed $value, array $keys ): bool {
				return is_array( $value ) && array_keys( $value ) === $keys;
			}

			private function matches( mixed $value, string $pattern ): bool {
				return is_string( $value ) && 1 === preg_match( $pattern, $value );
			}

			private function bounded_identity( mixed $value, int $limit = 191 ): bool {
				return is_string( $value ) && $this->opaque( $value, $limit );
			}

			private function public_url( mixed $value ): bool {
				if ( ! $this->bounded_identity( $value, 2048 ) || str_contains( $value, '\\' ) ) {
					return false;
				}
				$parts = parse_url( $value );
				return is_array( $parts ) && 'https' === strtolower( $parts['scheme'] ?? '' )
					&& isset( $parts['host'], $parts['path'] ) && '' !== $parts['host']
					&& ! array_intersect( array( 'user', 'pass', 'port', 'query', 'fragment' ), array_keys( $parts ) );
			}

			/** @phpstan-assert-if-true \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact $artifact */
			private function owned_artifact( mixed $artifact ): bool {
				if ( ! is_object( $this->selected ) || ! is_object( $artifact ) || 'RAN\\WPReleaseUpdater\\V1\\Archive\\TemporaryArtifact' !== $artifact::class ) {
					return false;
				}
				$source = ( new ReflectionClass( $this->selected ) )->getFileName();
				$file   = ( new ReflectionClass( $artifact ) )->getFileName();
				return is_string( $source ) && is_string( $file )
					&& realpath( $file ) === realpath( dirname( $source, 2 ) . '/Archive/TemporaryArtifact.php' );
			}

			private function discard_malformed_artifact( string $operation, mixed $result ): string {
				if ( 'list' === $operation ) {
					return 'not_applicable';
				}
				$artifact = is_array( $result ) && is_array( $result['value'] ?? null ) ? ( $result['value']['artifact'] ?? null ) : null;
				try {
					if ( 'acquire' === $operation && $this->owned_artifact( $artifact ) ) {
						return $artifact->discard() || $artifact->discard() ? 'complete' : 'failed';
					}
				} catch ( Throwable ) {
					return 'failed';
				}
				$cleanup = is_array( $result ) ? ( $result['cleanup_status'] ?? null ) : null;
				return in_array( $cleanup, array( 'not_applicable', 'complete', 'failed' ), true ) ? $cleanup : 'failed';
			}

			/** @param array<array-key,mixed> $value */
			private function valid_conditional( array $value ): bool {
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

			/** @return array{ok:false,code:string,value:null,retry_after:null,cleanup_status:string} */
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
		if ( ! is_callable( array( $this->broker, 'diagnostics' ) ) ) {
			throw new RuntimeException( 'Invalid broker handle.' );
		}
		return $this->broker->diagnostics();
	}

	private function target( string $type, string $file, string $provider, string $repository, string $repository_id, string $channel, string $policy, ?callable $credentials, mixed $maximum_artifact_bytes ): object {
		$declaration = array(
			'target_type'            => $type,
			'installed_file'         => $file,
			'provider_code'          => $provider,
			'repository_locator'     => $repository,
			'repository_identity'    => $repository_id,
			'channel'                => $channel,
			'update_policy'          => $policy,
			'credential_resolver'    => $credentials,
			'maximum_artifact_bytes' => $maximum_artifact_bytes,
		);
		return new class( $this->broker, $declaration ) {
			private int $submission_id = 0;
			private bool $submitted    = false;
			private bool $accepted     = false;
			private ?string $code      = null;

			/** @param array<string,mixed> $declaration */
			public function __construct( private object $broker, private array $declaration ) {
			}

			public function register(): bool {
				if ( $this->submitted ) {
					if ( ! $this->accepted || 0 === $this->submission_id ) {
						return false;
					}
					if ( ! is_callable( array( $this->broker, 'target_status' ) ) ) {
						throw new RuntimeException( 'Invalid broker handle.' );
					}
					return 'inactive' !== ( $this->broker->target_status( $this->submission_id )['state'] ?? null );
				}
				$this->submitted = true;
				if ( ! is_callable( array( $this->broker, 'register_target' ) ) ) {
					throw new RuntimeException( 'Invalid broker handle.' );
				}
				$result              = $this->broker->register_target( $this->declaration );
				$this->submission_id = $result['submission_id'];
				$this->accepted      = $result['accepted'];
				$this->code          = $result['code'];
				return $this->accepted;
			}

			/** @return array<string,mixed> */
			public function status(): array {
				if ( 0 < $this->submission_id ) {
					if ( ! is_callable( array( $this->broker, 'target_status' ) ) ) {
						throw new RuntimeException( 'Invalid broker handle.' );
					}
					return $this->broker->target_status( $this->submission_id );
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
				if ( 0 < $this->submission_id ) {
					if ( ! is_callable( array( $this->broker, 'target_diagnostics' ) ) ) {
						throw new RuntimeException( 'Invalid broker handle.' );
					}
					return $this->broker->target_diagnostics( $this->submission_id );
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
				if ( 0 >= $this->submission_id ) {
					return false;
				}
				if ( ! is_callable( array( $this->broker, 'refresh_target' ) ) ) {
					throw new RuntimeException( 'Invalid broker handle.' );
				}
				return $this->broker->refresh_target( $this->submission_id );
			}
		};
	}
};
