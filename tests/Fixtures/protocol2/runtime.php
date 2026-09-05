<?php

declare(strict_types=1);

/**
 * Selected-root production kernel.
 *
 * Composer autoload order cannot decide ownership when multiple physical
 * copies exist. The broker selects the root first; this entrypoint then loads
 * every lifecycle symbol from that root or fails closed if a loser already
 * defined one.
 */
$ran_wp_release_updater_runtime_files = array(
	'RAN\\WPReleaseUpdater\\V1\\Contract\\CanonicalUpdateUri' => 'src/Contract/CanonicalUpdateUri.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\IdentityDescriptor' => 'src/Contract/IdentityDescriptor.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\ReleaseVersion' => 'src/Contract/ReleaseVersion.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\BindingRecord' => 'src/Contract/BindingRecord.php',
	'RAN\\WPReleaseUpdater\\V1\\Archive\\ValidatedPackage' => 'src/Archive/ValidatedPackage.php',
	'RAN\\WPReleaseUpdater\\V1\\Archive\\TemporaryArtifact' => 'src/Archive/TemporaryArtifact.php',
	'RAN\\WPReleaseUpdater\\V1\\Archive\\PackageIdentityValidator' => 'src/Archive/PackageIdentityValidator.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\InstalledPackageResolver' => 'src/WordPress/InstalledPackageResolver.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\ReleaseAdapter' => 'src/Contract/ReleaseAdapter.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\ReleaseOperationCoordinator' => 'src/WordPress/ReleaseOperationCoordinator.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\AcquisitionReceipt' => 'src/Contract/AcquisitionReceipt.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePluginUpdater' => 'src/WordPress/NativePluginUpdater.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseReadUnavailable' => 'src/Provider/GitHub/GitHubReleaseReadUnavailable.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubCredentialResolver' => 'src/Provider/GitHub/GitHubCredentialResolver.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\ProspectiveReleaseInspection' => 'src/Provider/GitHub/ProspectiveReleaseInspection.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\ProspectiveReleaseArtifact' => 'src/Provider/GitHub/ProspectiveReleaseArtifact.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseService' => 'src/Provider/GitHub/GitHubReleaseService.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseAdapter' => 'src/Provider/GitHub/GitHubReleaseAdapter.php',
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

$ran_wp_release_updater_broker_origin = static function( mixed $broker, mixed $provenance ): bool {
	if ( ! is_object( $broker ) || ! is_array( $provenance ) || ( $provenance['broker'] ?? null ) !== $broker || ( $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null ) !== $broker ) {
		return false;
	}
	try {
		$source = ( new ReflectionClass( $broker ) )->getFileName();
		$source = is_string( $source ) ? realpath( $source ) : false;
	} catch ( Throwable ) {
		return false;
	}
	if ( ! is_string( $source ) || $source !== ( $provenance['source'] ?? null ) || 'RequestBroker.php' !== basename( $source ) ) {
		return false;
	}
	$root = realpath( dirname( $source, 3 ) );
	return is_string( $root ) && $root === ( $provenance['root'] ?? null ) && realpath( $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'RequestBroker.php' ) === $source;
};

/* The sealed catalog is deliberately local to this selected runtime. */
$ran_wp_release_updater_provider_catalog = array(
	'github' => static function(
		array $d,
		array $resolved,
		array $headers,
		string $identity,
		int $networkId,
		mixed $selectedRuntimeState
	): array {
		return \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter::composeFromDeclaration(
			$d,
			$resolved,
			$headers,
			$identity,
			$networkId,
			$selectedRuntimeState,
		);
	},
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
	private ?int $networkId;

	/** @param array<string,Closure> $providerCatalog */
	public function __construct( private mixed $broker, private mixed $brokerProvenance, private mixed $selectedRuntimeState, private array $providerCatalog, private Closure $brokerOrigin )
	{
		$this->networkId = $this->networkId();
	}
	private function live(): bool
	{
		if (
			! ( $this->brokerOrigin )( $this->broker, $this->brokerProvenance )
			||
			! $this->broker instanceof \RAN\WPReleaseUpdater\V1\Runtime\RequestBroker
			|| ( $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null ) !== $this->broker
			|| ! is_callable( array( $this->broker, 'protocolVersion' ) )
			|| ! is_callable( array( $this->broker, 'diagnostics' ) )
			|| 2 !== $this->broker->protocolVersion()
			|| isset( $GLOBALS['ran_wp_github_release_updater_v1_broker'] )
			|| function_exists( 'ran_wp_github_release_updater_v1_has_registered_target' )
		) {
			return false;
		}
		$diagnostics = $this->broker->diagnostics();
		return is_array( $diagnostics ) && in_array( $diagnostics['state'] ?? null, array( 'activating', 'active' ), true );
	}
	/** @param list<array<string,mixed>> $submissions @return array<string,mixed> */
	public function boot( array $environment, array $submissions ): array
	{
		if ( ! $this->live() ) {
			throw new RuntimeException( 'Inactive runtime handoff.' );
		}
		$results = array();
		foreach ( $submissions as $submission ) {
			$results[] = $this->registerTarget( $submission );
		}
		return array( 'accepted' => true, 'code' => 'runtime_active', 'results' => $results );
	}
	/** @param array<string,mixed> $submission @return array<string,mixed> */
	public function registerTarget( array $submission ): array
	{
		if ( ! $this->live() ) {
			throw new RuntimeException( 'Inactive runtime handoff.' );
		}
		$id = $submission['submission_id'] ?? 0;
		$d = $submission['declaration'] ?? null;
		if ( ! is_int( $id ) || 0 >= $id || ! is_array( $d ) ) {
			throw new RuntimeException( 'Invalid target submission.' );
		}
		if ( null === $this->networkId ) {
			return $this->failure( $id, 'runtime_environment_invalid' );
		}
		$installed = new \RAN\WPReleaseUpdater\V1\WordPress\InstalledPackageResolver(
			defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			is_array( $GLOBALS['wp_plugin_paths'] ?? null ) ? $GLOBALS['wp_plugin_paths'] : array(),
			is_array( $GLOBALS['wp_theme_directories'] ?? null ) ? $GLOBALS['wp_theme_directories'] : array(),
		);
		$resolved = $installed->resolve( $d );
		if ( 'installed_identity_verified' !== ( $resolved['code'] ?? null ) ) {
			return $this->failure( $id, is_string( $resolved['code'] ?? null ) ? $resolved['code'] : 'installed_file_invalid' );
		}
		$type = $d['target_type'];
		$headers = $resolved['headers'];
		$identity = $resolved['installed_package_identity'];
		$key = \RAN\WPReleaseUpdater\V1\Contract\BindingRecord::targetFenceKey( array(
			'network_id' => $this->networkId,
			'target_type' => $type,
			'installed_package_identity' => $identity,
		) );
		if ( isset( $this->targets[ $key ] ) ) {
			$target = $this->targets[ $key ];
			if ( $this->sameDeclaration( $target['declaration'], $d ) ) {
				return $this->accepted( $id, 'target_duplicate', $key, $target['handle'] );
			}
			return $this->failure( $id, 'target_declaration_conflict' );
		}
		$provider = $this->providerCatalog[ $d['provider_code'] ?? '' ] ?? null;
		if ( ! $provider instanceof Closure ) {
			return $this->failure( $id, 'unsupported_provider' );
		}
		if (
			is_object( $this->selectedRuntimeState )
			&& is_callable( array( $this->selectedRuntimeState, 'operationStarted' ) )
			&& true === $this->selectedRuntimeState->operationStarted( $type )
		) {
			$handle = $this->deferredHandle();
			$this->targets[ $key ] = array( 'declaration' => $d, 'handle' => $handle );
			return $this->accepted( $id, 'declaration_deferred_operation_started', $key, $handle );
		}
		$composition = $provider( $d, $resolved, $headers, $identity, $this->networkId, $this->selectedRuntimeState );
		$native = is_array( $composition ) ? ( $composition['native'] ?? null ) : null;
		if ( ! $native instanceof \RAN\WPReleaseUpdater\V1\WordPress\NativePluginUpdater ) {
			return $this->failure( $id, is_string( $composition['code'] ?? null ) ? $composition['code'] : 'target_composition_failed' );
		}
		$handle = new class( $native, $this->broker, $this->brokerProvenance, $this->selectedRuntimeState, $this->brokerOrigin ) {
			public function __construct( private object $native, private mixed $broker, private mixed $brokerProvenance, private mixed $selectedRuntimeState, private Closure $brokerOrigin )
			{
			}
			private function live(): bool
			{
				if (
					! ( $this->brokerOrigin )( $this->broker, $this->brokerProvenance )
					||
					! is_object( $this->broker )
					|| ( $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null ) !== $this->broker
					|| ! is_callable( array( $this->broker, 'protocolVersion' ) )
					|| ! is_callable( array( $this->broker, 'diagnostics' ) )
					|| 2 !== $this->broker->protocolVersion()
					|| isset( $GLOBALS['ran_wp_github_release_updater_v1_broker'] )
					|| function_exists( 'ran_wp_github_release_updater_v1_has_registered_target' )
				) {
					return false;
				}
				$diagnostics = $this->broker->diagnostics();
				return is_array( $diagnostics ) && in_array( $diagnostics['state'] ?? null, array( 'activating', 'active' ), true );
			}
			public function status(): array
			{
				if ( $this->live() ) {
					return array(
						'state' => 'active',
						'declaration_accepted' => true,
						'hooks_registered' => true,
						'code' => 'target_active',
						'native' => $this->native->status(),
					);
				}
				return array(
					'state' => 'inactive',
					'declaration_accepted' => true,
					'hooks_registered' => true,
					'code' => $this->livenessCode(),
					'native' => $this->native->status(),
				);
			}
			public function diagnostics(): array
			{
				if ( ! $this->live() ) {
					return array( 'state' => 'inactive', 'diagnostics' => array( array( 'code' => $this->livenessCode() ) ) );
				}
				return array(
					'state' => 'active',
					'diagnostics' => array_map( static fn( string $code ): array => array( 'code' => $code ), $this->native->diagnostics() ),
				);
			}
			public function refresh(): bool
			{
				if ( ! $this->live() ) {
					return false;
				}
				return $this->native->refresh();
			}
			private function livenessCode(): string
			{
				if ( is_object( $this->selectedRuntimeState ) && is_callable( array( $this->selectedRuntimeState, 'livenessCode' ) ) ) {
					$code = $this->selectedRuntimeState->livenessCode();
					if ( is_string( $code ) ) {
						return $code;
					}
				}
				return 'runtime_handoff_invalid';
			}
		};
		$this->targets[ $key ] = array( 'declaration' => $d, 'handle' => $handle );
		return $this->accepted( $id, 'target_active', $key, $handle );
	}
	private function networkId(): ?int
	{
		try {
			$networkId = function_exists( 'get_current_network_id' ) ? get_current_network_id() : 1;
		} catch ( Throwable ) {
			return null;
		}
		return is_int( $networkId ) && 0 < $networkId ? $networkId : null;
	}
	private function sameDeclaration( array $first, array $next ): bool
	{
		foreach ( array( 'target_type', 'provider_code', 'repository_locator', 'repository_identity', 'channel', 'update_policy', 'credential_resolver', 'maximum_artifact_bytes' ) as $fact ) {
			if ( $first[ $fact ] !== $next[ $fact ] ) {
				return false;
			}
		}
		return true;
	}
	private function deferredHandle(): object
	{
		return new class {
			public function status(): array
			{
				return array(
					'state' => 'deferred',
					'declaration_accepted' => true,
					'hooks_registered' => false,
					'code' => 'declaration_deferred_operation_started',
					'native' => null,
				);
			}

			public function diagnostics(): array
			{
				return array(
					'state' => 'deferred',
					'diagnostics' => array( array( 'code' => 'declaration_deferred_operation_started' ) ),
				);
			}

			public function refresh(): bool
			{
				return false;
			}
		};
	}
	/** @return array<string,mixed> */
	private function accepted( int $id, string $code, string $key, object $handle ): array
	{
		return array(
			'submission_id' => $id,
			'accepted' => true,
			'code' => $code,
			'target_key' => $key,
			'target_handle' => $handle,
		);
	}
	/** @return array<string,mixed> */
	private function failure( int $id, string $code ): array
	{
		return array(
			'submission_id' => $id,
			'accepted' => false,
			'code' => $code,
			'target_key' => null,
			'target_handle' => null,
		);
	}
};
