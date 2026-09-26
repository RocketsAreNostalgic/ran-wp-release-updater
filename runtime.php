<?php

/**
 * Selected-root production kernel.
 *
 * Composer autoload order cannot decide ownership when multiple physical
 * copies exist. The broker selects the root first; this entrypoint then loads
 * every lifecycle symbol from that root or fails closed if a loser already
 * defined one.
 */

declare(strict_types=1);
$ran_wp_release_updater_runtime_files = array(
	'RAN\\WPReleaseUpdater\\V1\\Dependency\\ArchiveSafety' => 'src/Dependency/ArchiveSafety.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\CanonicalUpdateUri' => 'src/Contract/CanonicalUpdateUri.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\IdentityDescriptor' => 'src/Contract/IdentityDescriptor.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\ReleaseVersion'  => 'src/Contract/ReleaseVersion.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\BindingRecord'   => 'src/Contract/BindingRecord.php',
	'RAN\\WPReleaseUpdater\\V1\\Archive\\ValidatedPackage' => 'src/Archive/ValidatedPackage.php',
	'RAN\\WPReleaseUpdater\\V1\\Archive\\TemporaryArtifact' => 'src/Archive/TemporaryArtifact.php',
	'RAN\\WPReleaseUpdater\\V1\\Archive\\ArchiveScanResult' => 'src/Archive/ArchiveScanResult.php',
	'RAN\\WPReleaseUpdater\\V1\\Archive\\ArchiveScanner'   => 'src/Archive/ArchiveScanner.php',
	'RAN\\WPReleaseUpdater\\V1\\Archive\\PackageIdentityValidator' => 'src/Archive/PackageIdentityValidator.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\InstalledPackageResolver' => 'src/WordPress/InstalledPackageResolver.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\ReleaseAdapter'  => 'src/Contract/ReleaseAdapter.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\BindingState'   => 'src/WordPress/BindingState.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\BindingFenceCoordinator' => 'src/WordPress/BindingFenceCoordinator.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\AcquisitionReceipt' => 'src/Contract/AcquisitionReceipt.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\OwnedArchiveStore' => 'src/WordPress/OwnedArchiveStore.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\StagedPackageManifest' => 'src/WordPress/StagedPackageManifest.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\PendingInstallState' => 'src/WordPress/PendingInstallState.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePackageUpdater' => 'src/WordPress/NativePackageUpdater.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseReadUnavailable' => 'src/Provider/GitHub/GitHubReleaseReadUnavailable.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubApiClient' => 'src/Provider/GitHub/GitHubApiClient.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubArtifactCustodyFailure' => 'src/Provider/GitHub/GitHubArtifactCustodyFailure.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubArtifactStore' => 'src/Provider/GitHub/GitHubArtifactStore.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubCredentialResolver' => 'src/Provider/GitHub/GitHubCredentialResolver.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\ProspectiveReleaseInspection' => 'src/Provider/GitHub/ProspectiveReleaseInspection.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\ProspectiveReleaseArtifact' => 'src/Provider/GitHub/ProspectiveReleaseArtifact.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseService' => 'src/Provider/GitHub/GitHubReleaseService.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseAdapter' => 'src/Provider/GitHub/GitHubReleaseAdapter.php',
	'RAN\\WPReleaseUpdater\\V1\\Runtime\\ReleaseFailure'   => 'src/Runtime/ReleaseFailure.php',
	'RAN\\WPReleaseUpdater\\V1\\Runtime\\ReleaseSource'    => 'src/Runtime/ReleaseSource.php',
);

foreach ( $ran_wp_release_updater_runtime_files as $ran_wp_release_updater_runtime_class => $ran_wp_release_updater_runtime_relative ) {
	$ran_wp_release_updater_runtime_file = __DIR__ . '/' . $ran_wp_release_updater_runtime_relative;
	if ( class_exists( $ran_wp_release_updater_runtime_class, false ) || interface_exists( $ran_wp_release_updater_runtime_class, false ) ) {
		$ran_wp_release_updater_runtime_loaded = ( new ReflectionClass( $ran_wp_release_updater_runtime_class ) )->getFileName();
		if (
			! is_string( $ran_wp_release_updater_runtime_loaded )
			|| ! hash_equals( $ran_wp_release_updater_runtime_file, $ran_wp_release_updater_runtime_loaded )
		) {
			throw new RuntimeException( 'A lifecycle symbol was loaded outside the selected runtime root.' );
		}
		continue;
	}
	require_once $ran_wp_release_updater_runtime_file;
}

unset(
	$ran_wp_release_updater_runtime_class,
	$ran_wp_release_updater_runtime_file,
	$ran_wp_release_updater_runtime_files,
	$ran_wp_release_updater_runtime_loaded,
	$ran_wp_release_updater_runtime_relative
);

$ran_wp_release_updater_broker_origin = static function ( mixed $broker, mixed $provenance ): bool {
	if ( ! is_object( $broker ) || ! is_array( $provenance ) || ( $provenance['broker'] ?? null ) !== $broker || ( $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null ) !== $broker ) {
		return false;
	}
	try {
		$source = ( new ReflectionClass( $broker ) )->getFileName();
		$source = is_string( $source ) ? realpath( $source ) : false;
	} catch ( Throwable ) {
		return false;
	}
	if ( ! is_string( $source ) || ( $provenance['source'] ?? null ) !== $source || 'RequestBroker.php' !== basename( $source ) ) {
		return false;
	}
	$root = realpath( dirname( $source, 3 ) );
	return is_string( $root ) && ( $provenance['root'] ?? null ) === $root && realpath( $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'RequestBroker.php' ) === $source;
};

/* The sealed catalog is deliberately local to this selected runtime. */
$ran_wp_release_updater_provider_catalog = array(
	'github' => array(
		'native'  => static function ( array $d, array $resolved, array $headers, string $identity, int $network_id, mixed $selected_runtime_state ): array {
			return \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter::compose_from_declaration( $d, $resolved, $headers, $identity, network_id: $network_id, selected_runtime_state: $selected_runtime_state );
		},
		'release' => static function ( array $d, \RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState $state ): object {
			$service = \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseService::from_release_declaration( $d, $state );
			return new \RAN\WPReleaseUpdater\V1\Runtime\ReleaseSource( $service, $state );
		},
	),
);

return new class(
	$GLOBALS['ran_wp_release_updater_v1_broker'] ?? null,
	$GLOBALS['ran_wp_release_updater_v1_broker_provenance'] ?? null,
	$ran_wp_release_updater_selected_state ?? null,
	$ran_wp_release_updater_provider_catalog,
	$ran_wp_release_updater_broker_origin,
) {
	/** @var array<string,array{declaration:array<string,mixed>,handle:object}> */
	private array $targets = array();
	private ?int $network_id;

	/** @param array<string,array{native:Closure,release:Closure}> $provider_catalog */
	public function __construct( private mixed $broker, private mixed $broker_provenance, private mixed $selected_runtime_state, private array $provider_catalog, private Closure $broker_origin ) {
		$this->network_id = $this->network_id();
	}
	private function live(): bool {
		if (
			! ( $this->broker_origin )( $this->broker, $this->broker_provenance )
			||
			! $this->broker instanceof \RAN\WPReleaseUpdater\V1\Runtime\RequestBroker
			|| ( $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null ) !== $this->broker
			|| ! is_callable( array( $this->broker, 'protocol_version' ) )
			|| ! is_callable( array( $this->broker, 'diagnostics' ) )
			|| 5 !== $this->broker->protocol_version()
		) {
			return false;
		}
		$diagnostics = $this->broker->diagnostics();
		return in_array( $diagnostics['state'] ?? null, array( 'activating', 'active' ), true );
	}
	/**
	 * @param array<string,mixed> $environment
	 * @param list<array<string,mixed>> $submissions
	 * @return array<string,mixed>
	 */
	public function boot( array $environment, array $submissions ): array {
		if ( ! $this->live() ) {
			throw new RuntimeException( 'Inactive runtime handoff.' );
		}
		$results = array();
		foreach ( $submissions as $submission ) {
			$results[] = $this->register_target( $submission );
		}
		return array(
			'accepted' => true,
			'code'     => 'runtime_active',
			'results'  => $results,
		);
	}
	/**
	 * @param array<string,mixed> $submission
	 * @return array<string,mixed>
	 */
	public function register_target( array $submission ): array {
		if ( ! $this->live() ) {
			throw new RuntimeException( 'Inactive runtime handoff.' );
		}
		$id = $submission['submission_id'] ?? 0;
		$d  = $submission['declaration'] ?? null;
		if ( ! is_int( $id ) || 0 >= $id || ! is_array( $d ) ) {
			throw new RuntimeException( 'Invalid target submission.' );
		}
		if ( null === $this->network_id ) {
			return $this->failure( $id, 'runtime_environment_invalid' );
		}
		$installed = new \RAN\WPReleaseUpdater\V1\WordPress\InstalledPackageResolver(
			defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			is_array( $GLOBALS['wp_plugin_paths'] ?? null ) ? $GLOBALS['wp_plugin_paths'] : array(),
			is_array( $GLOBALS['wp_theme_directories'] ?? null ) ? array_values( $GLOBALS['wp_theme_directories'] ) : array(),
		);
		$resolved  = $installed->resolve( $d );
		if ( 'installed_identity_verified' !== ( $resolved['code'] ?? null ) ) {
			return $this->failure( $id, is_string( $resolved['code'] ?? null ) ? $resolved['code'] : 'installed_file_invalid' );
		}
		$type     = $d['target_type'];
		$headers  = $resolved['headers'];
		$identity = $resolved['installed_package_identity'];
		$key      = \RAN\WPReleaseUpdater\V1\Contract\BindingRecord::target_fence_key(
			array(
				'network_id'                 => $this->network_id,
				'target_type'                => $type,
				'installed_package_identity' => $identity,
			)
		);
		if ( isset( $this->targets[ $key ] ) ) {
			$target = $this->targets[ $key ];
			if ( $this->same_declaration( $target['declaration'], $d ) ) {
				return $this->accepted( $id, 'target_duplicate', $key, $target['handle'] );
			}
			return $this->failure( $id, 'target_declaration_conflict' );
		}
		$provider = $this->provider_catalog[ $d['provider_code'] ?? '' ]['native'] ?? null;
		if ( ! $provider instanceof Closure ) {
			return $this->failure( $id, 'unsupported_provider' );
		}
		if (
			is_object( $this->selected_runtime_state )
			&& is_callable( array( $this->selected_runtime_state, 'operation_started' ) )
			&& true === $this->selected_runtime_state->operation_started( $type )
		) {
			$handle                = $this->deferred_handle();
			$this->targets[ $key ] = array(
				'declaration' => $d,
				'handle'      => $handle,
			);
			return $this->accepted( $id, 'declaration_deferred_operation_started', $key, $handle );
		}
		$composition = $provider( $d, $resolved, $headers, $identity, $this->network_id, $this->selected_runtime_state );
		$native      = is_array( $composition ) ? ( $composition['native'] ?? null ) : null;
		if ( ! $native instanceof \RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater ) {
			return $this->failure( $id, is_string( $composition['code'] ?? null ) ? $composition['code'] : 'target_composition_failed' );
		}
		$handle                = new class( $native, $this->broker, $this->broker_provenance, $this->selected_runtime_state, $this->broker_origin ) {
			public function __construct( private object $native, private mixed $broker, private mixed $broker_provenance, private mixed $selected_runtime_state, private Closure $broker_origin ) {
			}
			private function live(): bool {
				if (
					! ( $this->broker_origin )( $this->broker, $this->broker_provenance )
					||
					! is_object( $this->broker )
					|| ( $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null ) !== $this->broker
					|| ! is_callable( array( $this->broker, 'protocol_version' ) )
					|| ! is_callable( array( $this->broker, 'diagnostics' ) )
					|| 5 !== $this->broker->protocol_version()
				) {
					return false;
				}
				$diagnostics = $this->broker->diagnostics();
				return is_array( $diagnostics ) && in_array( $diagnostics['state'] ?? null, array( 'activating', 'active' ), true );
			}
			/** @return array<string,mixed> */
			public function status(): array {
				if ( ! is_callable( array( $this->native, 'status' ) ) ) {
					throw new RuntimeException( 'Invalid native handle.' );
				}
				if ( $this->live() ) {
					return array(
						'state'                => 'active',
						'declaration_accepted' => true,
						'hooks_registered'     => true,
						'code'                 => 'target_active',
						'native'               => $this->native->status(),
					);
				}
				return array(
					'state'                => 'inactive',
					'declaration_accepted' => true,
					'hooks_registered'     => true,
					'code'                 => $this->liveness_code(),
					'native'               => $this->native->status(),
				);
			}
			/** @return array{state:string,diagnostics:array<array{code:string}>} */
			public function diagnostics(): array {
				if ( ! $this->live() ) {
					return array(
						'state'       => 'inactive',
						'diagnostics' => array( array( 'code' => $this->liveness_code() ) ),
					);
				}
				if ( ! is_callable( array( $this->native, 'diagnostics' ) ) ) {
					throw new RuntimeException( 'Invalid native handle.' );
				}
				return array(
					'state'       => 'active',
					'diagnostics' => array_map( static fn( string $code ): array => array( 'code' => $code ), $this->native->diagnostics() ),
				);
			}
			public function refresh(): bool {
				if ( ! $this->live() ) {
					return false;
				}
				if ( ! is_callable( array( $this->native, 'refresh' ) ) ) {
					throw new RuntimeException( 'Invalid native handle.' );
				}
				return $this->native->refresh();
			}
			private function liveness_code(): string {
				if ( is_object( $this->selected_runtime_state ) && is_callable( array( $this->selected_runtime_state, 'liveness_code' ) ) ) {
					$code = $this->selected_runtime_state->liveness_code();
					if ( is_string( $code ) ) {
						return $code;
					}
				}
				return 'runtime_handoff_invalid';
			}
		};
		$this->targets[ $key ] = array(
			'declaration' => $d,
			'handle'      => $handle,
		);
		return $this->accepted( $id, 'target_active', $key, $handle );
	}
	/**
	 * @param array<string,mixed> $declaration
	 * @return array{accepted:bool,code:string,source_handle:object|null}
	 */
	public function release_source( array $declaration ): array {
		if ( ! $this->live() ) {
			return $this->release_failure( 'runtime_unavailable' );
		}
		$state = $this->selected_runtime_state;
		if ( ! $state instanceof \RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState ) {
			return $this->release_failure( 'runtime_unavailable' );
		}
		$readiness = $state->release_readiness_code();
		if ( null !== $readiness ) {
			return $this->release_failure( $readiness );
		}
		$compose = $this->provider_catalog[ $declaration['provider_code'] ?? '' ]['release'] ?? null;
		if ( ! $compose instanceof Closure ) {
			return $this->release_failure( 'provider_unavailable' );
		}
		try {
			$source = $compose( $declaration, $state );
		} catch ( \InvalidArgumentException ) {
			return $this->release_failure( 'invalid_configuration' );
		} catch ( Throwable ) {
			return $this->release_failure( 'runtime_unavailable' );
		}
		return $source instanceof \RAN\WPReleaseUpdater\V1\Runtime\ReleaseSource
			? array(
				'accepted'      => true,
				'code'          => 'release_source_ready',
				'source_handle' => $source,
			)
			: $this->release_failure( 'runtime_unavailable' );
	}
	/** @return array{accepted:false,code:string,source_handle:null} */
	private function release_failure( string $code ): array {
		return array(
			'accepted'      => false,
			'code'          => $code,
			'source_handle' => null,
		); }
	private function network_id(): ?int {
		try {
			$network_id = function_exists( 'get_current_network_id' ) ? get_current_network_id() : 1;
		} catch ( Throwable ) {
			return null;
		}
		return is_int( $network_id ) && 0 < $network_id ? $network_id : null;
	}
	/**
	 * @param array<string,mixed> $first
	 * @param array<string,mixed> $next
	 */
	private function same_declaration( array $first, array $next ): bool {
		foreach ( array( 'target_type', 'provider_code', 'repository_locator', 'repository_identity', 'channel', 'update_policy', 'credential_resolver', 'maximum_artifact_bytes' ) as $fact ) {
			if ( $first[ $fact ] !== $next[ $fact ] ) {
				return false;
			}
		}
		return true;
	}
	private function deferred_handle(): object {
		return new class() {
			/** @return array<string,mixed> */
			public function status(): array {
				return array(
					'state'                => 'deferred',
					'declaration_accepted' => true,
					'hooks_registered'     => false,
					'code'                 => 'declaration_deferred_operation_started',
					'native'               => null,
				);
			}

			/** @return array{state:string,diagnostics:list<array{code:string}>} */
			public function diagnostics(): array {
				return array(
					'state'       => 'deferred',
					'diagnostics' => array( array( 'code' => 'declaration_deferred_operation_started' ) ),
				);
			}

			public function refresh(): bool {
				return false;
			}
		};
	}
	/** @return array<string,mixed> */
	private function accepted( int $id, string $code, string $key, object $handle ): array {
		return array(
			'submission_id' => $id,
			'accepted'      => true,
			'code'          => $code,
			'target_key'    => $key,
			'target_handle' => $handle,
		);
	}
	/** @return array<string,mixed> */
	private function failure( int $id, string $code ): array {
		return array(
			'submission_id' => $id,
			'accepted'      => false,
			'code'          => $code,
			'target_key'    => null,
			'target_handle' => null,
		);
	}
};
