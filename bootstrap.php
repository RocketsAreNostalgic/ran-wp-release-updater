<?php

declare(strict_types=1);

use RAN\WPReleaseUpdater\V1\Runtime\RequestBroker;
use RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState;

if ( ! class_exists( RequestBroker::class, false ) ) {
	require_once __DIR__ . '/src/Runtime/RequestBroker.php';
}
$ran_wp_release_updater_broker_origin = static function( object|string $broker ): ?array {
	try {
		$source = ( new ReflectionClass( $broker ) )->getFileName();
		$source = is_string( $source ) ? realpath( $source ) : false;
	} catch ( Throwable ) {
		return null;
	}
	if ( ! is_string( $source ) || 'RequestBroker.php' !== basename( $source ) ) {
		return null;
	}
	$root = realpath( dirname( $source, 3 ) );
	if ( ! is_string( $root ) ) {
		return null;
	}
	$brokerSource = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'RequestBroker.php';
	$copyFile = $root . DIRECTORY_SEPARATOR . 'runtime-copy.json';
	if ( realpath( $brokerSource ) !== $source || ! is_file( $copyFile ) || is_link( $copyFile ) ) {
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
			|| 'runtime.php' !== $copy['runtime_file']
			|| 2 !== $copy['runtime_protocol']
			|| ! is_string( $copy['wordpress_floor'] )
		) {
			return null;
		}
		$files = array( 'bootstrap.php', 'runtime.php' );
		$regular = static function( string $relative ) use ( $root ): ?string {
			$expected = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			$actual = realpath( $expected );
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
	return array( 'broker' => is_object( $broker ) ? $broker : null, 'root' => $root, 'source' => $source );
};
$ran_wp_release_updater_cached_broker_origin = static function( mixed $broker, mixed $provenance ): ?array {
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
$ran_wp_release_updater_existing_broker = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
$ran_wp_release_updater_cached_broker_provenance = $ran_wp_release_updater_cached_broker_origin(
	$ran_wp_release_updater_existing_broker,
	$GLOBALS['ran_wp_release_updater_v1_broker_provenance'] ?? null,
);
$ran_wp_release_updater_broker_class_origin = is_array( $ran_wp_release_updater_cached_broker_provenance )
	? $ran_wp_release_updater_cached_broker_provenance
	: ( null === $ran_wp_release_updater_existing_broker ? $ran_wp_release_updater_broker_origin( RequestBroker::class ) : null );
$ran_wp_release_updater_can_create_broker = is_array( $ran_wp_release_updater_broker_class_origin ) && $ran_wp_release_updater_broker_class_origin['root'] === realpath( __DIR__ );
if ( ! class_exists( SelectedRuntimeState::class, false ) ) {
	require_once __DIR__ . '/src/Runtime/SelectedRuntimeState.php';
}
$ran_wp_release_updater_broker = $ran_wp_release_updater_existing_broker;
$ran_wp_release_updater_created_broker = false;
$ran_wp_release_updater_can_schedule = false;
$ran_wp_release_updater_boundary_missed = false;
if ( null === $ran_wp_release_updater_broker && $ran_wp_release_updater_can_create_broker ) {
	if ( function_exists( 'doing_action' ) && function_exists( 'did_action' ) && function_exists( 'add_action' ) ) {
		try {
			$ran_wp_release_updater_running = doing_action( 'after_setup_theme' );
			$ran_wp_release_updater_completed = did_action( 'after_setup_theme' );
			$ran_wp_release_updater_can_schedule = ! $ran_wp_release_updater_running && ! $ran_wp_release_updater_completed;
			if ( $ran_wp_release_updater_running ) {
				$ran_wp_release_updater_hook = $GLOBALS['wp_filter']['after_setup_theme'] ?? null;
				$ran_wp_release_updater_priority = is_object( $ran_wp_release_updater_hook ) && is_callable( array( $ran_wp_release_updater_hook, 'current_priority' ) )
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
	$ran_wp_release_updater_broker = new RequestBroker( $ran_wp_release_updater_boundary_missed, $ran_wp_release_updater_selected_state );
	$ran_wp_release_updater_selected_state->bind( $ran_wp_release_updater_broker );
	$ran_wp_release_updater_broker_class_origin['broker'] = $ran_wp_release_updater_broker;
	$GLOBALS['ran_wp_release_updater_v1_broker'] = $ran_wp_release_updater_broker;
	$ran_wp_release_updater_created_broker = true;
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
	&& is_callable( array( $ran_wp_release_updater_broker, 'targetStatus' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'targetDiagnostics' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'refreshTarget' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'diagnostics' ) );
if ( $ran_wp_release_updater_broker_compatible ) {
	try {
		$ran_wp_release_updater_broker_compatible = 2 === $ran_wp_release_updater_broker->protocolVersion();
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

		public function protocolVersion(): int
		{
			return 2;
		}

		public function registerCandidate( string $copyFile ): bool
		{
			unset( $copyFile );
			return false;
		}

		/** @return array<string,mixed> */
		public function activate( array $environment ): array
		{
			unset( $environment );
			return array(
				'loaded' => false,
				'state' => 'conflict',
				'code' => 'protocol_conflict_inactive',
				'diagnostics' => $this->diagnostics,
			);
		}

		/** @return array<string,mixed> */
		public function diagnostics(): array
		{
			return array(
				'protocol_version' => 2,
				'state' => 'conflict',
				'activation_attempted' => false,
				'candidate_count' => 0,
				'submission_count' => 0,
				'logical_target_count' => 0,
				'diagnostics' => $this->diagnostics,
			);
		}

		/** @return array{accepted:false,submission_id:0,code:string} */
		public function registerTarget( array $declaration ): array
		{
			unset( $declaration );
			return array(
				'accepted' => false,
				'submission_id' => 0,
				'code' => 'protocol_conflict_inactive',
			);
		}

		/** @return array<string,mixed> */
		public function targetStatus( int $id ): array
		{
			unset( $id );
			return array(
				'state' => 'inactive',
				'declaration_accepted' => false,
				'hooks_registered' => false,
				'code' => 'protocol_conflict_inactive',
				'native' => null,
			);
		}

		/** @return array<string,mixed> */
		public function targetDiagnostics( int $id ): array
		{
			unset( $id );
			return array(
				'state' => 'inactive',
				'diagnostics' => $this->diagnostics,
			);
		}

		public function refreshTarget( int $id ): bool
		{
			unset( $id );
			return false;
		}
	};
}

if ( $ran_wp_release_updater_broker_compatible ) {
	$ran_wp_release_updater_broker->registerCandidate( __DIR__ . '/runtime-copy.json' );

	if ( $ran_wp_release_updater_created_broker && $ran_wp_release_updater_can_schedule ) {
		add_action( 'after_setup_theme', static function () use ( $ran_wp_release_updater_selected_state ): void {
			$ran_wp_release_updater_selected_state->activate();
		}, PHP_INT_MAX, 0 );
	}
}

return new class( $ran_wp_release_updater_broker ) {
	public function __construct( private object $broker )
	{
	}

	public function plugin( string $provider, string $pluginFile, string $repository, string $repositoryId, string $channel = 'stable', string $updatePolicy = 'manual', ?callable $credentials = null, mixed $maximumArtifactBytes = 52428800 ): object
	{
		return $this->target( 'plugin', $pluginFile, $provider, $repository, $repositoryId, $channel, $updatePolicy, $credentials, $maximumArtifactBytes );
	}

	public function theme( string $provider, string $stylesheetFile, string $repository, string $repositoryId, string $channel = 'stable', string $updatePolicy = 'manual', ?callable $credentials = null, mixed $maximumArtifactBytes = 52428800 ): object
	{
		return $this->target( 'theme', $stylesheetFile, $provider, $repository, $repositoryId, $channel, $updatePolicy, $credentials, $maximumArtifactBytes );
	}

	/** @return array<string,mixed> */
	public function diagnostics(): array
	{
		return $this->broker->diagnostics();
	}

	private function target( string $type, string $file, string $provider, string $repository, string $repositoryId, string $channel, string $policy, ?callable $credentials, mixed $maximumArtifactBytes ): object
	{
		$declaration = array(
			'target_type' => $type,
			'installed_file' => $file,
			'provider_code' => $provider,
			'repository_locator' => $repository,
			'repository_identity' => $repositoryId,
			'channel' => $channel,
			'update_policy' => $policy,
			'credential_resolver' => $credentials,
			'maximum_artifact_bytes' => $maximumArtifactBytes,
		);
		return new class( $this->broker, $declaration ) {
			private int $submissionId = 0;
			private bool $submitted = false;
			private bool $accepted = false;
			private ?string $code = null;

			public function __construct( private object $broker, private array $declaration )
			{
			}

			public function register(): bool
			{
				if ( $this->submitted ) {
					if ( ! $this->accepted || 0 === $this->submissionId ) {
						return false;
					}
					return 'inactive' !== ( $this->broker->targetStatus( $this->submissionId )['state'] ?? null );
				}
				$this->submitted = true;
				$result = $this->broker->registerTarget( $this->declaration );
				$this->submissionId = $result['submission_id'];
				$this->accepted = $result['accepted'];
				$this->code = $result['code'];
				return $this->accepted;
			}

			/** @return array<string,mixed> */
			public function status(): array
			{
				if ( 0 < $this->submissionId ) {
					return $this->broker->targetStatus( $this->submissionId );
				}
				if ( $this->submitted ) {
					return array(
						'state' => 'inactive',
						'declaration_accepted' => false,
						'hooks_registered' => false,
						'code' => $this->code,
						'native' => null,
					);
				}
				return array(
					'state' => 'new',
					'declaration_accepted' => false,
					'hooks_registered' => false,
					'code' => 'target_unregistered',
					'native' => null,
				);
			}

			/** @return array<string,mixed> */
			public function diagnostics(): array
			{
				if ( 0 < $this->submissionId ) {
					return $this->broker->targetDiagnostics( $this->submissionId );
				}
				if ( $this->submitted ) {
					return array(
						'state' => 'inactive',
						'diagnostics' => array( array( 'code' => $this->code ) ),
					);
				}
				return array( 'state' => 'new', 'diagnostics' => array() );
			}

			public function refresh(): bool
			{
				return 0 < $this->submissionId && $this->broker->refreshTarget( $this->submissionId );
			}
		};
	}
};
