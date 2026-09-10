<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Runtime;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/RuntimeCopySelector.php';
require_once __DIR__ . '/RequestProtocolValidator.php';

/**
 * Request-local intake for physical runtime copies.
 *
 * It deliberately retains data, not callbacks: the sole hook registration
 * callable exists only for the one explicit activation attempt.
 */
final class RequestBroker {

	private const MAX_DIAGNOSTICS = 16;


	/** @var list<array{package_revision:string,package_version:string,php_floor:string,runtime_file:string,source_root:string,wordpress_floor:string}> */
	private array $candidates = array();
	/** @var array<string, true> */
	private array $candidateRoots = array();
	/** @var list<array{code:string}> */
	private array $diagnostics        = array();
	private bool $activationAttempted = false;
	private string $state             = 'collecting';
	private int $nextSubmissionId     = 1;
	/** @var array<int,array{declaration:array<string,mixed>,key?:string,handle?:object,last_status?:array<string,mixed>,terminal_code?:string}> */
	private array $submissions    = array();
	private ?object $handoff      = null;
	private ?string $selectedRoot = null;
	/** @var array<string, object> */
	private array $targetHandles  = array();
	private ?string $terminalCode = null;
	private RuntimeCopySelector $runtimeCopySelector;

	public function __construct( private bool $activationBoundaryMissed = false, private ?SelectedRuntimeState $selectedRuntimeState = null ) {
		$this->runtimeCopySelector = new RuntimeCopySelector();
	}

	public function protocolVersion(): int {
		return 4;
	}

	/** Register a physical runtime-copy.json without loading its runtime. */
	public function registerCandidate( string $copyFile ): bool {
		if ( ! $this->protocolLive() ) {
			return false;
		}
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return false;
		}
		$candidateRoot = realpath( dirname( $copyFile ) );
		if ( 'runtime-copy.json' === basename( $copyFile ) && is_string( $candidateRoot ) && isset( $this->candidateRoots[ $candidateRoot ] ) ) {
			return true;
		}
		if ( 'collecting' !== $this->state ) {
			$this->diagnoseOnce( 'late_candidate_rejected' );
			return false;
		}

		try {
			$candidate = $this->runtimeCopySelector->candidate( $copyFile );
		} catch ( Throwable ) {
			$this->diagnose( 'candidate_invalid' );
			return false;
		}
		if ( isset( $this->candidateRoots[ $candidate['source_root'] ] ) ) {
			return true;
		}

		$this->candidateRoots[ $candidate['source_root'] ] = true;
		$this->candidates[]                                = $candidate;
		if ( $this->activationBoundaryMissed ) {
			$this->activationAttempted = true;
			$this->disable( 'activation_boundary_missed' );
		}
		return true;
	}

	/**
	 * Select one physical copy and load only its runtime entrypoint. Provider
	 * activation is deliberately deferred until a real adapter owns that seam.
	 *
	 * @param array<string, mixed> $environment
	 * @return array{loaded:bool,state:string,code:string,diagnostics:list<array{code:string}>}
	 */
	public function activate( array $environment ): array {
		if ( ! $this->protocolLive() ) {
			return $this->result( false, 'protocol_conflict_inactive' );
		}
		if ( 'active' === $this->state ) {
			return $this->result( true, 'runtime_active' );
		}
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return $this->result( false, $this->terminalCode ?? 'runtime_selection_inactive' );
		}
		if ( $this->activationAttempted ) {
			return $this->result( false, 'activation_in_progress' );
		}
		$this->activationAttempted = true;
		$this->state               = 'activating';
		if ( ! $this->validEnvironment( $environment ) ) {
			return $this->disable( 'runtime_environment_invalid' );
		}

		try {
			$selected = $this->runtimeCopySelector->select( $this->candidates, $environment );
		} catch ( Throwable ) {
			return $this->disable( 'runtime_selection_inactive' );
		}

		try {
			$ran_wp_release_updater_selected_state = $this->selectedRuntimeState;
			$handoff                               = require $selected['runtime_file'];
		} catch ( Throwable ) {
			return $this->disable( 'runtime_load_failed' );
		}
		if (
			! is_object( $handoff )
			|| ! RequestProtocolValidator::ownedBy( $handoff, $selected['source_root'] )
			|| ! RequestProtocolValidator::exactPublicMethods( $handoff, array( 'boot', 'registerTarget', 'releaseSource' ) )
		) {
			return $this->disable( 'runtime_handoff_invalid' );
		}
		$this->handoff      = $handoff;
		$this->selectedRoot = $selected['source_root'];
		$batch              = array();
		foreach ( $this->submissions as $id => $submission ) {
			$batch[] = array(
				'submission_id' => $id,
				'declaration'   => $submission['declaration'],
			);
		}
		try {
			$result = $this->handoff->boot( $environment, $batch );
			if (
				! is_array( $result )
				|| ! RequestProtocolValidator::exactKeys( $result, array( 'accepted', 'code', 'results' ) )
				|| true !== $result['accepted']
				|| 'runtime_active' !== $result['code']
				|| ! is_array( $result['results'] )
				|| count( $batch ) !== count( $result['results'] )
			) {
				throw new RuntimeException( 'Invalid runtime handoff.' );
			}
			$drained = array();
			foreach ( $result['results'] as $index => $item ) {
				if ( ! is_array( $item ) || ( $batch[ $index ]['submission_id'] ?? null ) !== ( $item['submission_id'] ?? null ) ) {
					throw new RuntimeException( 'Invalid runtime handoff.' );
				}
				$id = $batch[ $index ]['submission_id'];
				$this->applyComposition( $id, $item );
				$drained[ $id ] = true;
			}
			while ( true ) {
				$id = null;
				foreach ( array_keys( $this->submissions ) as $submissionId ) {
					if ( ! isset( $drained[ $submissionId ] ) ) {
						$id = $submissionId;
						break;
					}
				}
				if ( ! is_int( $id ) ) {
					break;
				}
				$submission = $this->submissions[ $id ];
				$item       = $this->handoff->registerTarget(
					array(
						'submission_id' => $id,
						'declaration'   => $submission['declaration'],
					)
				);
				if ( ! is_array( $item ) ) {
					throw new RuntimeException( 'Invalid target result.' );
				}
				$this->applyComposition( $id, $item );
				$drained[ $id ] = true;
			}
		} catch ( Throwable ) {
			return $this->disable( 'runtime_handoff_invalid' );
		}
		$this->state = 'active';
		return $this->result( true, 'runtime_active' );
	}

	/** @param array<string,mixed> $declaration @return array{accepted:bool,submission_id:int,code:string} */
	public function registerTarget( array $declaration ): array {
		if ( ! $this->protocolLive() ) {
			return array(
				'accepted'      => false,
				'submission_id' => 0,
				'code'          => 'protocol_conflict_inactive',
			);
		}
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return array(
				'accepted'      => false,
				'submission_id' => 0,
				'code'          => $this->terminalCode ?? 'runtime_selection_inactive',
			);
		}
		$code = $this->declarationCode( $declaration );
		if ( null !== $code ) {
			return array(
				'accepted'      => false,
				'submission_id' => 0,
				'code'          => $code,
			);
		}
		$id                       = $this->nextSubmissionId++;
		$this->submissions[ $id ] = array(
			'declaration' => $declaration,
		);
		if ( 'active' === $this->state && is_object( $this->handoff ) ) {
			try {
				$result = $this->handoff->registerTarget(
					array(
						'submission_id' => $id,
						'declaration'   => $declaration,
					)
				);
				if ( ! is_array( $result ) ) {
					throw new RuntimeException( 'Invalid target result.' );
				}
				$this->applyComposition( $id, $result );
			} catch ( Throwable ) {
				$this->disable( 'runtime_handoff_invalid' );
				return array(
					'accepted'      => false,
					'submission_id' => $id,
					'code'          => 'runtime_handoff_invalid',
				);
			}
			$resultCode = $result['code'];
			return array(
				'accepted'      => in_array( $resultCode, array( 'target_active', 'target_duplicate', 'declaration_deferred_operation_started' ), true ),
				'submission_id' => $id,
				'code'          => $resultCode,
			);
		}
		return array(
			'accepted'      => true,
			'submission_id' => $id,
			'code'          => 'target_queued',
		);
	}

	/** @param array<string,mixed> $declaration @return array{accepted:bool,code:string,source_handle:object|null} */
	public function releaseSource( array $declaration ): array {
		$this->protocolLive();
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return $this->releaseFailure( 'runtime_unavailable' );
		}
		if ( null !== RequestProtocolValidator::releaseDeclarationCode( $declaration ) ) {
			return $this->releaseFailure( 'invalid_configuration' );
		}
		if ( 'active' !== $this->state || ! is_object( $this->handoff ) ) {
			return $this->releaseFailure( 'runtime_not_ready' );
		}
		try {
			$result = $this->handoff->releaseSource( $declaration );
		} catch ( Throwable ) {
			$this->disable( 'runtime_handoff_invalid' );
			return $this->releaseFailure( 'runtime_unavailable' );
		}
		if ( ! is_array( $result ) || ! RequestProtocolValidator::exactKeys( $result, array( 'accepted', 'code', 'source_handle' ) ) || ! is_bool( $result['accepted'] ) || ! is_string( $result['code'] ) ) {
			$this->disable( 'runtime_handoff_invalid' );
			return $this->releaseFailure( 'runtime_unavailable' );
		}
		$validHandle = is_object( $result['source_handle'] ) && is_string( $this->selectedRoot )
			&& RequestProtocolValidator::ownedBy( $result['source_handle'], $this->selectedRoot )
			&& RequestProtocolValidator::exactPublicMethods( $result['source_handle'], array( 'acquire', 'inspect', 'list' ) );
		if ( true === $result['accepted'] && 'release_source_ready' === $result['code'] && $validHandle ) {
			return $result;
		}
		if ( false === $result['accepted'] && null === $result['source_handle'] && in_array( $result['code'], array( 'provider_unavailable', 'filesystem_unsupported', 'invalid_configuration', 'runtime_not_ready', 'runtime_unavailable' ), true ) ) {
			return $result;
		}
		$this->disable( 'runtime_handoff_invalid' );
		return $this->releaseFailure( 'runtime_unavailable' );
	}

	/** @return array<string,mixed> */
	public function targetStatus( int $submissionId ): array {
		$this->protocolLive();
		$item = $this->submissions[ $submissionId ] ?? null;
		if ( ! is_array( $item ) ) {
			return $this->status( 'inactive', false, false, 'declaration_invalid' );
		}
		return $this->projectStatus( $item );
	}
	/** @return array<string,mixed> */
	public function targetDiagnostics( int $submissionId ): array {
		$this->protocolLive();
		$item = $this->submissions[ $submissionId ] ?? null;
		if ( ! is_array( $item ) ) {
			return array(
				'state'       => 'inactive',
				'diagnostics' => array( array( 'code' => 'declaration_invalid' ) ),
			);
		}
		$status = $this->projectStatus( $item );
		if ( ! isset( $item['handle'] ) || ! is_object( $item['handle'] ) || 'inactive' === $status['state'] ) {
			return array(
				'state'       => $status['state'],
				'diagnostics' => array( array( 'code' => $status['code'] ) ),
			);
		}
		try {
			$diagnostics = $item['handle']->diagnostics();
			if ( ! RequestProtocolValidator::validDiagnostics( $diagnostics, $status['state'] ) ) {
				$this->disable( 'runtime_handoff_invalid' );
				return $this->inactiveDiagnostics( $this->submissions[ $submissionId ] );
			}
			return $diagnostics;
		} catch ( Throwable ) {
			$this->disable( 'runtime_handoff_invalid' );
			return $this->inactiveDiagnostics( $this->submissions[ $submissionId ] );
		}
	}
	public function refreshTarget( int $submissionId ): bool {
		if ( ! $this->protocolLive() ) {
			return false;
		}
		$item = $this->submissions[ $submissionId ] ?? null;
		if ( ! is_array( $item ) || 'active' !== $this->projectStatus( $item )['state'] || ! is_object( $item['handle'] ?? null ) ) {
			return false;
		}
		try {
			$refreshed = $item['handle']->refresh();
			if ( ! is_bool( $refreshed ) ) {
				$this->disable( 'runtime_handoff_invalid' );
				return false;
			}
			return $refreshed;
		} catch ( Throwable ) {
			$this->disable( 'runtime_handoff_invalid' );
			return false;
		}
	}

	/** @return array<string,mixed> */
	public function diagnostics(): array {
		$this->protocolLive();
		return array(
			'protocol_version'     => 4,
			'state'                => $this->state,
			'activation_attempted' => $this->activationAttempted,
			'candidate_count'      => count( $this->candidates ),
			'submission_count'     => count( $this->submissions ),
			'logical_target_count' => count( $this->targetHandles ),
			'diagnostics'          => $this->diagnostics,
		);
	}

	private function protocolLive(): bool {
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return true;
		}
		$conflict = null !== $this->selectedRuntimeState
			&& ( $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null ) !== $this;
		$conflict = $conflict || isset( $GLOBALS['ran_wp_github_release_updater_v1_broker'] )
			|| function_exists( 'ran_wp_github_release_updater_v1_has_registered_target' );
		if ( $conflict && 'protocol_conflict_inactive' !== $this->terminalCode ) {
			$this->disable( 'protocol_conflict_inactive' );
		}
		return ! $conflict;
	}

	/** @param array<string,mixed> $environment */
	private function validEnvironment( array $environment ): bool {
		return $this->runtimeCopySelector->validEnvironment( $environment );
	}

	/** @return array{loaded:bool,state:string,code:string,diagnostics:list<array{code:string}>} */
	private function result( bool $loaded, string $code ): array {
		return array(
			'loaded'      => $loaded,
			'state'       => $this->state,
			'code'        => $code,
			'diagnostics' => $this->diagnostics,
		);
	}

	/** @param array<string,mixed> $result */
	private function applyComposition( int $expectedId, array $result ): void {
		$id = $result['submission_id'] ?? 0;
		if ( ! RequestProtocolValidator::exactKeys( $result, array( 'submission_id', 'accepted', 'code', 'target_key', 'target_handle' ) )
			|| ! is_int( $id )
			|| $expectedId !== $id
			|| ! isset( $this->submissions[ $id ] )
			|| ! is_bool( $result['accepted'] )
			|| ! is_string( $result['code'] ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$admitted    = in_array( $result['code'], array( 'target_active', 'target_duplicate', 'declaration_deferred_operation_started' ), true );
		$validHandle = is_object( $result['target_handle'] )
			&& is_string( $this->selectedRoot )
			&& RequestProtocolValidator::ownedBy( $result['target_handle'], $this->selectedRoot )
			&& RequestProtocolValidator::exactPublicMethods( $result['target_handle'], array( 'diagnostics', 'refresh', 'status' ) );
		if ( $admitted && ( true !== $result['accepted']
			|| ! is_string( $result['target_key'] )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $result['target_key'] )
			|| ! $validHandle ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		if ( ! $admitted && ( ! RequestProtocolValidator::isTerminalCode( $result['code'] )
			|| true === $result['accepted']
			|| null !== $result['target_key']
			|| null !== $result['target_handle'] ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		if ( ! $admitted ) {
			$this->terminal( $id, $result['code'] );
			return;
		}
		if ( 'target_duplicate' === $result['code'] ) {
			$canonical = $this->targetHandles[ $result['target_key'] ] ?? null;
			if ( ! is_object( $canonical ) || $canonical !== $result['target_handle'] ) {
				throw new RuntimeException( 'Invalid target result.' );
			}
		} elseif ( isset( $this->targetHandles[ $result['target_key'] ] ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$status      = $result['target_handle']->status();
		$diagnostics = $result['target_handle']->diagnostics();
		if ( ! RequestProtocolValidator::validStatus( $status ) || ! RequestProtocolValidator::validDiagnostics( $diagnostics, $status['state'] ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$invalidActive    = 'target_active' === $result['code']
			&& ( 'active' !== $status['state'] || 'target_active' !== $status['code'] );
		$invalidDeferred  = 'declaration_deferred_operation_started' === $result['code']
			&& ( 'deferred' !== $status['state'] || 'declaration_deferred_operation_started' !== $status['code'] );
		$invalidDuplicate = 'target_duplicate' === $result['code']
			&& ! (
				( 'active' === $status['state'] && 'target_active' === $status['code'] )
				|| ( 'deferred' === $status['state'] && 'declaration_deferred_operation_started' === $status['code'] )
			);
		if ( $invalidActive || $invalidDeferred || $invalidDuplicate ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$this->submissions[ $id ]['handle']      = $result['target_handle'];
		$this->submissions[ $id ]['key']         = $result['target_key'];
		$this->submissions[ $id ]['last_status'] = $status;
		if ( 'target_duplicate' !== $result['code'] ) {
			$this->targetHandles[ $result['target_key'] ] = $result['target_handle'];
		}
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	private function projectStatus( array $item ): array {
		if ( isset( $item['terminal_code'] ) || null !== $this->terminalCode ) {
			$last = $this->lastNativeStatus( $item );
			if ( is_array( $last['native'] ?? null ) ) {
				$last['native']['offered_release_identity'] = null;
				$last['native']['offered_version']          = null;
			}
			return $this->status(
				'inactive',
				true,
				true === ( $last['hooks_registered'] ?? false ),
				$item['terminal_code'] ?? $this->terminalCode ?? 'runtime_handoff_invalid',
				$last['native'] ?? null
			);
		}
		if ( ! isset( $item['handle'] ) || ! is_object( $item['handle'] ) ) {
			return $this->status( 'queued', true, false, 'target_queued' );
		}
		try {
			$status = $item['handle']->status();
			if ( ! RequestProtocolValidator::validStatus( $status ) ) {
				$this->disable( 'runtime_handoff_invalid' );
				return $this->projectStatus( $item );
			}
			return $status;
		} catch ( Throwable ) {
			$this->disable( 'runtime_handoff_invalid' );
			return $this->projectStatus( $item );
		}
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	private function lastNativeStatus( array $item ): array {
		if ( isset( $item['last_status'] ) && is_array( $item['last_status'] ) ) {
			return $item['last_status'];
		}
		if ( ! isset( $item['handle'] ) || ! is_object( $item['handle'] ) ) {
			return array();
		}
		try {
			$status = $item['handle']->status();
			return RequestProtocolValidator::validStatus( $status ) ? $status : array();
		} catch ( Throwable ) {
			return array();
		}
	}

	/** @param array<string,mixed> $item @return array{state:string,diagnostics:list<array{code:string}>} */
	private function inactiveDiagnostics( array $item ): array {
		$status = $this->projectStatus( $item );
		return array(
			'state'       => $status['state'],
			'diagnostics' => array( array( 'code' => $status['code'] ) ),
		);
	}

	private function disable( string $code ): array {
		$this->diagnose( $code );
		$this->terminalCode = $code;
		$this->state        = 'protocol_conflict_inactive' === $code ? 'conflict' : 'inactive';
		return $this->result( false, $code );
	}
	private function ownedBy( object $value, string $root ): bool {
		return RequestProtocolValidator::ownedBy( $value, $root );
	}

	private function exactPublicMethods( object $value, array $expected ): bool {
		return RequestProtocolValidator::exactPublicMethods( $value, $expected );
	}

	private function terminal( int $id, string $code ): void {
		$this->submissions[ $id ]['terminal_code'] = $code;
	}

	/** @return array<string,mixed> */
	private function status( string $state, bool $accepted, bool $hooks, string $code, mixed $native = null ): array {
		return array(
			'state'                => $state,
			'declaration_accepted' => $accepted,
			'hooks_registered'     => $hooks,
			'code'                 => $code,
			'native'               => $native,
		);
	}

	/** @param array<string,mixed> $status */
	private function validStatus( mixed $status ): bool {
		return RequestProtocolValidator::validStatus( $status );
	}

	private function validNativeStatus( mixed $native ): bool {
		return RequestProtocolValidator::validNativeStatus( $native );
	}

	/** @param array<string,mixed> $diagnostics */
	private function validDiagnostics( mixed $diagnostics, string $state ): bool {
		return RequestProtocolValidator::validDiagnostics( $diagnostics, $state );
	}

	private function validDiagnosticCode( string $code ): bool {
		return RequestProtocolValidator::validDiagnosticCode( $code );
	}
	/** @param array<string,mixed> $value */
	private function declarationCode( array $value ): ?string {
		return RequestProtocolValidator::declarationCode( $value );
	}

	/** @param array<string,mixed> $value */
	private function releaseDeclarationCode( array $value ): ?string {
		return RequestProtocolValidator::releaseDeclarationCode( $value );
	}

	/** @return array{accepted:false,code:string,source_handle:null} */
	private function releaseFailure( string $code ): array {
		return array(
			'accepted'      => false,
			'code'          => $code,
			'source_handle' => null,
		);
	}

	private function opaque( mixed $value, int $limit ): bool {
		return RequestProtocolValidator::opaque( $value, $limit );
	}

	private function diagnose( string $code ): void {
		if ( self::MAX_DIAGNOSTICS === count( $this->diagnostics ) ) {
			array_shift( $this->diagnostics );
		}
		$this->diagnostics[] = array( 'code' => $code );
	}

	private function diagnoseOnce( string $code ): void {
		foreach ( $this->diagnostics as $diagnostic ) {
			if ( $code === $diagnostic['code'] ) {
				return;
			}
		}
		$this->diagnose( $code );
	}

	private function exactKeys( array $value, array $keys ): bool {
		return RequestProtocolValidator::exactKeys( $value, $keys );
	}
}
