<?php

declare(strict_types=1);

use RAN\WPReleaseUpdater\V1\Runtime\RequestBroker;
use RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState;

if ( ! class_exists( RequestBroker::class, false ) ) {
	require_once __DIR__ . '/src/Runtime/RequestBroker.php';
}
if ( ! class_exists( SelectedRuntimeState::class, false ) ) {
	require_once __DIR__ . '/src/Runtime/SelectedRuntimeState.php';
}
$ran_wp_release_updater_broker = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
$ran_wp_release_updater_created_broker = false;
$ran_wp_release_updater_can_schedule = false;
$ran_wp_release_updater_boundary_missed = false;
if ( null === $ran_wp_release_updater_broker ) {
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
	$GLOBALS['ran_wp_release_updater_v1_broker'] = $ran_wp_release_updater_broker;
	$ran_wp_release_updater_created_broker = true;
}


$ran_wp_release_updater_broker_compatible = $ran_wp_release_updater_broker instanceof RequestBroker
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
