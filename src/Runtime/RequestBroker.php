<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Runtime;

use RuntimeException;
use Throwable;

/**
 * Request-local intake for physical runtime copies.
 *
 * It deliberately retains data, not callbacks: the sole hook registration
 * callable exists only for the one explicit activation attempt.
 */
final class RequestBroker
{
	private const COPY_KEYS = array(
		'package_revision',
		'package_version',
		'php_floor',
		'runtime_file',
		'runtime_protocol',
		'wordpress_floor',
	);
	private const MAX_DIAGNOSTICS = 16;
	private const SHA256 = '/\A[a-f0-9]{64}\z/D';
	private const TERMINAL_CODES = array(
		'declaration_invalid', 'provider_code_invalid', 'repository_locator_invalid',
		'repository_identity_invalid', 'release_channel_invalid', 'update_policy_invalid',
		'credential_resolver_invalid', 'maximum_artifact_bytes_invalid', 'installed_file_invalid', 'installed_file_missing',
		'installed_file_unreadable', 'installed_file_not_regular', 'installed_file_symlink',
		'installed_file_outside_root', 'installed_file_root_ambiguous', 'installed_file_changed',
		'plugin_root_level_unsupported', 'theme_header_file_invalid',
		'theme_nested_identity_unsupported', 'installed_header_missing',
		'installed_header_ambiguous', 'installed_header_invalid',
		'installed_update_uri_mismatch', 'installed_requirement_incompatible',
		'unsupported_provider', 'target_declaration_conflict', 'target_composition_failed',
		'activation_boundary_missed', 'runtime_environment_invalid',
		'runtime_selection_inactive', 'runtime_load_failed', 'runtime_handoff_invalid',
		'protocol_conflict_inactive',
	);
	private const CANDIDATE_VALIDATION_CODES = array(
		'archive_entry_limit', 'archive_entry_unreadable', 'archive_file_identity_mismatch',
		'archive_header_duplicate', 'archive_header_missing', 'archive_header_unreadable',
		'archive_identity_verified', 'archive_metadata_identity_mismatch',
		'archive_path_duplicate', 'archive_path_unsafe', 'archive_php_requirement_incompatible',
		'archive_root_mismatch', 'archive_size_limit', 'archive_target_policy_invalid',
		'archive_unreadable', 'archive_update_uri_mismatch', 'archive_version_mismatch',
		'archive_wordpress_requirement_incompatible', 'archive_zip_extension_unavailable',
		'candidate_descriptor_mismatch', 'candidate_inspection_failed', 'candidate_invalid',
		'candidate_list_invalid', 'candidate_not_newer', 'candidate_validation_failed',
		'release_list_failed',
	);
	private const FAILURE_CODES = array(
		'acquisition_failed', 'acquisition_identity_invalid', 'archive_changed_before_extraction',
		'binding_fence_lost', 'outcome_uncertain', 'remote_release_changed',
		'runtime_liveness_lost',
		'runtime_package_identity_invalid', 'staged_package_identity_invalid',
		'unverified_install_result', 'unverified_pre_download',
		'unverified_pre_download_result', 'unverified_pre_install',
	);
	private const RELATIONSHIPS = array( 'invalid', 'newer', 'older', 'same' );

	/** @var list<array{package_revision:string,package_version:string,php_floor:string,runtime_file:string,source_root:string,wordpress_floor:string}> */
	private array $candidates = array();
	/** @var array<string, true> */
	private array $candidateRoots = array();
	/** @var list<array{code:string}> */
	private array $diagnostics = array();
	private bool $activationAttempted = false;
	private string $state = 'collecting';
	private int $nextSubmissionId = 1;
	/** @var array<int,array{declaration:array<string,mixed>,key?:string,handle?:object,last_status?:array<string,mixed>,terminal_code?:string}> */
	private array $submissions = array();
	private ?object $handoff = null;
	private ?string $selectedRoot = null;
	/** @var array<string, object> */
	private array $targetHandles = array();
	private ?string $terminalCode = null;

	public function __construct( private bool $activationBoundaryMissed = false, private ?SelectedRuntimeState $selectedRuntimeState = null )
	{
	}

	public function protocolVersion(): int
	{
		return 2;
	}

	/** Register a physical runtime-copy.json without loading its runtime. */
	public function registerCandidate( string $copyFile ): bool
	{
		if ( ! $this->protocolLive() ) {
			return false;
		}
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return false;
		}
		if ( 'runtime-copy.json' === basename( $copyFile ) && isset( $this->candidateRoots[ realpath( dirname( $copyFile ) ) ?: '' ] ) ) {
			return true;
		}
		if ( 'collecting' !== $this->state ) {
			$this->diagnoseOnce( 'late_candidate_rejected' );
			return false;
		}

		try {
			$candidate = $this->candidate( $copyFile );
		} catch ( Throwable ) {
			$this->diagnose( 'candidate_invalid' );
			return false;
		}
		if ( isset( $this->candidateRoots[ $candidate['source_root'] ] ) ) {
			return true;
		}

		$this->candidateRoots[ $candidate['source_root'] ] = true;
		$this->candidates[] = $candidate;
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
	public function activate( array $environment ): array
	{
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
		$this->state = 'activating';
		if ( ! $this->validEnvironment( $environment ) ) {
			return $this->disable( 'runtime_environment_invalid' );
		}

		try {
			$selected = $this->select( $environment );
		} catch ( Throwable ) {
			return $this->disable( 'runtime_selection_inactive' );
		}

		try {
			$ran_wp_release_updater_selected_state = $this->selectedRuntimeState;
			$handoff = require $selected['runtime_file'];
		} catch ( Throwable ) {
			return $this->disable( 'runtime_load_failed' );
		}
		if (
			! is_object( $handoff )
			|| ! $this->ownedBy( $handoff, $selected['source_root'] )
			|| ! $this->exactPublicMethods( $handoff, array( 'boot', 'registerTarget' ) )
		) {
			return $this->disable( 'runtime_handoff_invalid' );
		}
		$this->handoff = $handoff;
		$this->selectedRoot = $selected['source_root'];
		$batch = array();
		foreach ( $this->submissions as $id => $submission ) {
			$batch[] = array( 'submission_id' => $id, 'declaration' => $submission['declaration'] );
		}
		try {
			$result = $this->handoff->boot( $environment, $batch );
			if (
				! is_array( $result )
				|| ! $this->exactKeys( $result, array( 'accepted', 'code', 'results' ) )
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
				$item = $this->handoff->registerTarget(
					array( 'submission_id' => $id, 'declaration' => $submission['declaration'] )
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
	public function registerTarget( array $declaration ): array
	{
		if ( ! $this->protocolLive() ) {
			return array( 'accepted' => false, 'submission_id' => 0, 'code' => 'protocol_conflict_inactive' );
		}
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return array( 'accepted' => false, 'submission_id' => 0, 'code' => $this->terminalCode ?? 'runtime_selection_inactive' );
		}
		$code = $this->declarationCode( $declaration );
		if ( null !== $code ) {
			return array( 'accepted' => false, 'submission_id' => 0, 'code' => $code );
		}
		$id = $this->nextSubmissionId++;
		$this->submissions[ $id ] = array(
			'declaration' => $declaration,
		);
		if ( 'active' === $this->state && is_object( $this->handoff ) ) {
			try {
				$result = $this->handoff->registerTarget( array( 'submission_id' => $id, 'declaration' => $declaration ) );
				if ( ! is_array( $result ) ) {
					throw new RuntimeException( 'Invalid target result.' );
				}
				$this->applyComposition( $id, $result );
			} catch ( Throwable ) {
				$this->disable( 'runtime_handoff_invalid' );
				return array( 'accepted' => false, 'submission_id' => $id, 'code' => 'runtime_handoff_invalid' );
			}
			$resultCode = $result['code'];
			return array(
				'accepted' => in_array( $resultCode, array( 'target_active', 'target_duplicate', 'declaration_deferred_operation_started' ), true ),
				'submission_id' => $id,
				'code' => $resultCode,
			);
		}
		return array( 'accepted' => true, 'submission_id' => $id, 'code' => 'target_queued' );
	}

	/** @return array<string,mixed> */
	public function targetStatus( int $submissionId ): array
	{
		$this->protocolLive();
		$item = $this->submissions[ $submissionId ] ?? null;
		if ( ! is_array( $item ) ) {
			return $this->status( 'inactive', false, false, 'declaration_invalid' );
		}
		return $this->projectStatus( $item );
	}
	/** @return array<string,mixed> */
	public function targetDiagnostics( int $submissionId ): array
	{
		$this->protocolLive();
		$item = $this->submissions[ $submissionId ] ?? null;
		if ( ! is_array( $item ) ) {
			return array( 'state' => 'inactive', 'diagnostics' => array( array( 'code' => 'declaration_invalid' ) ) );
		}
		$status = $this->projectStatus( $item );
		if ( ! isset( $item['handle'] ) || ! is_object( $item['handle'] ) || 'inactive' === $status['state'] ) {
			return array( 'state' => $status['state'], 'diagnostics' => array( array( 'code' => $status['code'] ) ) );
		}
		try {
			$diagnostics = $item['handle']->diagnostics();
			if ( ! $this->validDiagnostics( $diagnostics, $status['state'] ) ) {
				$this->disable( 'runtime_handoff_invalid' );
				return $this->inactiveDiagnostics( $this->submissions[ $submissionId ] );
			}
			return $diagnostics;
		} catch ( Throwable ) {
			$this->disable( 'runtime_handoff_invalid' );
			return $this->inactiveDiagnostics( $this->submissions[ $submissionId ] );
		}
	}
	public function refreshTarget( int $submissionId ): bool
	{
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
	public function diagnostics(): array
	{
		$this->protocolLive();
		return array(
			'protocol_version' => 2, 'state' => $this->state,
			'activation_attempted' => $this->activationAttempted,
			'candidate_count' => count( $this->candidates ),
			'submission_count' => count( $this->submissions ),
			'logical_target_count' => count( $this->targetHandles ),
			'diagnostics' => $this->diagnostics,
		);
	}

	private function protocolLive(): bool
	{
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
	private function validEnvironment( array $environment ): bool
	{
		if (
			! $this->exactKeys( $environment, array( 'php_version', 'runtime_protocol', 'wordpress_version' ) )
			|| 2 !== $environment['runtime_protocol']
			|| ! is_string( $environment['php_version'] )
			|| ! is_string( $environment['wordpress_version'] )
		) {
			return false;
		}
		try {
			$this->version( $environment['php_version'] );
			$this->wordpressVersion( $environment['wordpress_version'] );
			return true;
		} catch ( Throwable ) {
			return false;
		}
	}

	/** @return array{loaded:bool,state:string,code:string,diagnostics:list<array{code:string}>} */
	private function result( bool $loaded, string $code ): array
	{
		return array( 'loaded' => $loaded, 'state' => $this->state, 'code' => $code, 'diagnostics' => $this->diagnostics );
	}

	/** @param array<string,mixed> $result */
	private function applyComposition( int $expectedId, array $result ): void
	{
		$id = $result['submission_id'] ?? 0;
		if ( ! $this->exactKeys( $result, array( 'submission_id', 'accepted', 'code', 'target_key', 'target_handle' ) )
			|| ! is_int( $id )
			|| $expectedId !== $id
			|| ! isset( $this->submissions[ $id ] )
			|| ! is_bool( $result['accepted'] )
			|| ! is_string( $result['code'] ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$admitted = in_array( $result['code'], array( 'target_active', 'target_duplicate', 'declaration_deferred_operation_started' ), true );
		$validHandle = is_object( $result['target_handle'] )
			&& is_string( $this->selectedRoot )
			&& $this->ownedBy( $result['target_handle'], $this->selectedRoot )
			&& $this->exactPublicMethods( $result['target_handle'], array( 'diagnostics', 'refresh', 'status' ) );
		if ( $admitted && ( true !== $result['accepted']
			|| ! is_string( $result['target_key'] )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $result['target_key'] )
			|| ! $validHandle ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		if ( ! $admitted && ( ! in_array( $result['code'], self::TERMINAL_CODES, true )
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
		$status = $result['target_handle']->status();
		$diagnostics = $result['target_handle']->diagnostics();
		if ( ! $this->validStatus( $status ) || ! $this->validDiagnostics( $diagnostics, $status['state'] ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$invalidActive = 'target_active' === $result['code']
			&& ( 'active' !== $status['state'] || 'target_active' !== $status['code'] );
		$invalidDeferred = 'declaration_deferred_operation_started' === $result['code']
			&& ( 'deferred' !== $status['state'] || 'declaration_deferred_operation_started' !== $status['code'] );
		$invalidDuplicate = 'target_duplicate' === $result['code']
			&& ! (
				( 'active' === $status['state'] && 'target_active' === $status['code'] )
				|| ( 'deferred' === $status['state'] && 'declaration_deferred_operation_started' === $status['code'] )
			);
		if ( $invalidActive || $invalidDeferred || $invalidDuplicate ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$this->submissions[ $id ]['handle'] = $result['target_handle'];
		$this->submissions[ $id ]['key'] = $result['target_key'];
		$this->submissions[ $id ]['last_status'] = $status;
		if ( 'target_duplicate' !== $result['code'] ) {
			$this->targetHandles[ $result['target_key'] ] = $result['target_handle'];
		}
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	private function projectStatus( array $item ): array
	{
		if ( isset( $item['terminal_code'] ) || null !== $this->terminalCode ) {
			$last = $this->lastNativeStatus( $item );
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
			if ( ! $this->validStatus( $status ) ) {
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
	private function lastNativeStatus( array $item ): array
	{
		if ( isset( $item['last_status'] ) && is_array( $item['last_status'] ) ) {
			return $item['last_status'];
		}
		if ( ! isset( $item['handle'] ) || ! is_object( $item['handle'] ) ) {
			return array();
		}
		try {
			$status = $item['handle']->status();
			return $this->validStatus( $status ) ? $status : array();
		} catch ( Throwable ) {
			return array();
		}
	}

	/** @param array<string,mixed> $item @return array{state:string,diagnostics:list<array{code:string}>} */
	private function inactiveDiagnostics( array $item ): array
	{
		$status = $this->projectStatus( $item );
		return array( 'state' => $status['state'], 'diagnostics' => array( array( 'code' => $status['code'] ) ) );
	}

	private function disable( string $code ): array
	{
		$this->diagnose( $code );
		$this->terminalCode = $code;
		$this->state = 'protocol_conflict_inactive' === $code ? 'conflict' : 'inactive';
		return $this->result( false, $code );
	}
	private function ownedBy( object $value, string $root ): bool
	{
		try {
			$file = ( new \ReflectionClass( $value ) )->getFileName();
		} catch ( \ReflectionException ) {
			return false;
		}
		return is_string( $file ) && str_starts_with( $file, $root . DIRECTORY_SEPARATOR );
	}

	private function exactPublicMethods( object $value, array $expected ): bool
	{
		$methods = array();
		foreach ( ( new \ReflectionClass( $value ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( ! $method->isConstructor() ) {
				$methods[] = $method->getName();
			}
		}
		sort( $methods, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $expected === $methods;
	}

	private function terminal( int $id, string $code ): void
	{
		$this->submissions[ $id ]['terminal_code'] = $code;
	}

	/** @return array<string,mixed> */
	private function status( string $state, bool $accepted, bool $hooks, string $code, mixed $native = null ): array
	{
		return array(
			'state' => $state,
			'declaration_accepted' => $accepted,
			'hooks_registered' => $hooks,
			'code' => $code,
			'native' => $native,
		);
	}

	/** @param array<string,mixed> $status */
	private function validStatus( mixed $status ): bool
	{
		if (
			! is_array( $status )
			|| ! $this->exactKeys( $status, array( 'state', 'declaration_accepted', 'hooks_registered', 'code', 'native' ) )
			|| ! is_string( $status['code'] )
			|| ! is_bool( $status['declaration_accepted'] )
			|| ! is_bool( $status['hooks_registered'] )
			|| ! in_array( $status['state'], array( 'new', 'queued', 'composing', 'deferred', 'active', 'inactive' ), true )
		) {
			return false;
		}
		if ( ! $this->validNativeStatus( $status['native'] ) ) {
			return false;
		}

		return match ( $status['state'] ) {
			'new' => false === $status['declaration_accepted']
				&& false === $status['hooks_registered']
				&& 'target_unregistered' === $status['code']
				&& null === $status['native'],
			'queued' => true === $status['declaration_accepted']
				&& false === $status['hooks_registered']
				&& 'target_queued' === $status['code']
				&& null === $status['native'],
			'composing' => true === $status['declaration_accepted']
				&& false === $status['hooks_registered']
				&& 'target_composing' === $status['code']
				&& null === $status['native'],
			'deferred' => true === $status['declaration_accepted']
				&& false === $status['hooks_registered']
				&& 'declaration_deferred_operation_started' === $status['code']
				&& null === $status['native'],
			'active' => true === $status['declaration_accepted']
				&& true === $status['hooks_registered']
				&& 'target_active' === $status['code']
				&& is_array( $status['native'] ),
			'inactive' => in_array( $status['code'], self::TERMINAL_CODES, true ) && ( ! $status['hooks_registered'] || is_array( $status['native'] ) ),
		};
	}

	private function validNativeStatus( mixed $native ): bool
	{
		$keys = array(
			'candidate_header_version',
			'candidate_tag',
			'candidate_validation_code',
			'candidate_version',
			'failure_code',
			'installed_version',
			'last_check',
			'offered_version',
			'relationship',
		);
		if ( null === $native ) {
			return true;
		}
		if ( ! is_array( $native ) || ! $this->exactKeys( $native, $keys ) ) {
			return false;
		}
		foreach ( array( 'candidate_header_version', 'candidate_tag', 'candidate_version', 'installed_version', 'offered_version' ) as $key ) {
			if ( null !== $native[ $key ] && ( ! is_string( $native[ $key ] ) || '' === $native[ $key ] ) ) {
				return false;
			}
		}
		return ( null === $native['candidate_validation_code'] || in_array( $native['candidate_validation_code'], self::CANDIDATE_VALIDATION_CODES, true ) )
			&& ( null === $native['failure_code'] || in_array( $native['failure_code'], self::FAILURE_CODES, true ) )
			&& ( null === $native['last_check'] || ( is_int( $native['last_check'] ) && 0 < $native['last_check'] ) )
			&& ( null === $native['relationship'] || in_array( $native['relationship'], self::RELATIONSHIPS, true ) );
	}

	/** @param array<string,mixed> $diagnostics */
	private function validDiagnostics( mixed $diagnostics, string $state ): bool
	{
		if (
			! is_array( $diagnostics )
			|| ! $this->exactKeys( $diagnostics, array( 'state', 'diagnostics' ) )
			|| $state !== $diagnostics['state']
			|| ! is_array( $diagnostics['diagnostics'] )
			|| self::MAX_DIAGNOSTICS < count( $diagnostics['diagnostics'] )
		) {
			return false;
		}
		foreach ( $diagnostics['diagnostics'] as $diagnostic ) {
			if (
				! is_array( $diagnostic )
				|| ! $this->exactKeys( $diagnostic, array( 'code' ) )
				|| ! is_string( $diagnostic['code'] )
				|| ! $this->validDiagnosticCode( $diagnostic['code'] )
			) {
				return false;
			}
		}
		return true;
	}

	private function validDiagnosticCode( string $code ): bool
	{
		$runtimeCodes = array(
			'activation_in_progress',
			'late_candidate_rejected',
			'runtime_active',
			'declaration_deferred_operation_started',
			'target_composing',
			'target_queued',
			'target_unregistered',
			'update_completed',
		);
		return in_array(
			$code,
			array_merge( self::TERMINAL_CODES, self::CANDIDATE_VALIDATION_CODES, self::FAILURE_CODES, $runtimeCodes ),
			true
		);
	}
	/** @param array<string,mixed> $value */
	private function declarationCode( array $value ): ?string
	{
		$keys = array(
			'target_type',
			'installed_file',
			'provider_code',
			'repository_locator',
			'repository_identity',
			'channel',
			'update_policy',
			'credential_resolver',
			'maximum_artifact_bytes',
		);
		if (
			! $this->exactKeys( $value, $keys )
			|| ! in_array( $value['target_type'], array( 'plugin', 'theme' ), true )
		) {
			return 'declaration_invalid';
		}
		$file = $value['installed_file'];
		$normalized = is_string( $file ) ? str_replace( '\\', '/', $file ) : '';
		$isAbsolute = str_starts_with( $normalized, '/' ) || 1 === preg_match( '/\A[A-Za-z]:\//D', $normalized );
		$isUnc = str_starts_with( $normalized, '//' );
		$uncParts = $isUnc ? explode( '/', substr( $normalized, 2 ) ) : array();
		if (
			! is_string( $file )
			|| '' === $file
			|| 4096 < strlen( $file )
			|| ! $isAbsolute
			|| 1 === preg_match( '/[\x00-\x1f\x7f]/', $file )
			|| ( ! $isUnc && str_contains( $normalized, '//' ) )
			|| ( $isUnc && ( 2 > count( $uncParts ) || in_array( '', $uncParts, true ) ) )
			|| 1 === preg_match( '#/(?:\.?\.?)(?:/|$)#', $isUnc ? substr( $normalized, 1 ) : $normalized )
		) {
			return 'installed_file_invalid';
		}
		if (
			! is_string( $value['provider_code'] )
			|| 1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,31}\z/D', $value['provider_code'] )
		) {
			return 'provider_code_invalid';
		}
		if ( ! $this->opaque( $value['repository_locator'], 255 ) ) {
			return 'repository_locator_invalid';
		}
		if ( ! $this->opaque( $value['repository_identity'], 191 ) ) {
			return 'repository_identity_invalid';
		}
		if ( ! in_array( $value['channel'], array( 'stable', 'prerelease' ), true ) ) {
			return 'release_channel_invalid';
		}
		if ( ! in_array( $value['update_policy'], array( 'disabled', 'forced-off', 'manual', 'automatic' ), true ) ) {
			return 'update_policy_invalid';
		}
		if ( ! is_int( $value['maximum_artifact_bytes'] ) || 0 >= $value['maximum_artifact_bytes'] ) return 'maximum_artifact_bytes_invalid';
		return null === $value['credential_resolver'] || is_callable( $value['credential_resolver'] ) ? null : 'credential_resolver_invalid';
	}

	private function opaque( mixed $value, int $limit ): bool
	{
		return is_string( $value )
			&& '' !== $value
			&& strlen( $value ) <= $limit
			&& 1 === preg_match( '//u', $value )
			&& 1 === preg_match( '/\A[^\p{C}\p{Z}\s]+\z/u', $value );
	}

	private function diagnose( string $code ): void
	{
		if ( self::MAX_DIAGNOSTICS === count( $this->diagnostics ) ) {
			array_shift( $this->diagnostics );
		}
		$this->diagnostics[] = array( 'code' => $code );
	}

	private function diagnoseOnce( string $code ): void
	{
		foreach ( $this->diagnostics as $diagnostic ) {
			if ( $code === $diagnostic['code'] ) {
				return;
			}
		}
		$this->diagnose( $code );
	}

	/** @return array{package_revision:string,package_version:string,php_floor:string,runtime_file:string,source_root:string,wordpress_floor:string} */
	private function candidate( string $copyFile ): array
	{
		$file = 'runtime-copy.json' === basename( $copyFile ) && ! is_link( $copyFile ) && is_file( $copyFile ) ? realpath( $copyFile ) : false;
		$root = false === $file ? false : realpath( dirname( $file ) );
		if ( false === $file || false === $root || $file !== $root . DIRECTORY_SEPARATOR . 'runtime-copy.json' ) {
			throw new RuntimeException( 'Invalid runtime copy.' );
		}
		try {
			$facts = json_decode( $this->read( $file ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			throw new RuntimeException( 'Invalid runtime copy.', 0, $exception );
		}
		if (
			! is_array( $facts )
			|| array_is_list( $facts )
			|| ! $this->exactKeys( $facts, self::COPY_KEYS )
			|| ! is_string( $facts['package_revision'] )
			|| 1 !== preg_match( self::SHA256, $facts['package_revision'] )
			|| ! is_string( $facts['package_version'] )
			|| ! is_string( $facts['php_floor'] )
			|| 'runtime.php' !== $facts['runtime_file']
			|| 2 !== $facts['runtime_protocol']
			|| ! is_string( $facts['wordpress_floor'] )
		) {
			throw new RuntimeException( 'Invalid runtime copy.' );
		}
		$this->version( $facts['package_version'] );
		$this->version( $facts['php_floor'] );
		$this->version( $facts['wordpress_floor'] );
		$runtime = $this->regularFile( $root, 'runtime.php' );
		if ( ! hash_equals( $facts['package_revision'], $this->packageRevision( $root ) ) ) {
			throw new RuntimeException( 'Runtime package identity mismatch.' );
		}
		return array(
			'package_revision' => $facts['package_revision'],
			'package_version' => $facts['package_version'],
			'php_floor' => $facts['php_floor'],
			'runtime_file' => $runtime,
			'source_root' => $root,
			'wordpress_floor' => $facts['wordpress_floor'],
		);
	}

	private function packageRevision( string $root ): string
	{
		$files = array( 'bootstrap.php', 'runtime.php' );
		foreach ( $files as $file ) {
			$this->regularFile( $root, $file );
		}

		$source = $root . DIRECTORY_SEPARATOR . 'src';
		if ( is_link( $source ) || ! is_dir( $source ) || $source !== realpath( $source ) ) {
			throw new RuntimeException( 'Invalid runtime source.' );
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			if ( $entry->isLink() ) {
				throw new RuntimeException( 'Invalid runtime source.' );
			}
			if ( ! $entry->isFile() || 'php' !== $entry->getExtension() ) {
				continue;
			}
			$path = $entry->getPathname();
			$relative = str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );
			$this->regularFile( $root, $relative );
			$files[] = $relative;
		}
		sort( $files, SORT_STRING );
		$payload = '';
		foreach ( $files as $file ) {
			$digest = hash_file( 'sha256', $this->regularFile( $root, $file ) );
			if ( false === $digest ) {
				throw new RuntimeException( 'Unreadable runtime source.' );
			}
			$payload .= $file . "\0" . $digest . "\n";
		}

		return hash( 'sha256', $payload );
	}

	private function regularFile( string $root, string $relative ): string
	{
		if ( '' === $relative || str_contains( $relative, "\0" ) || str_starts_with( $relative, '/' ) || preg_match( '#(?:\\A|/)\.\.(?:/|\\z)#', $relative ) ) {
			throw new RuntimeException( 'Invalid runtime source.' );
		}
		$expected = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		$actual = realpath( $expected );
		if ( is_link( $expected ) || ! is_file( $expected ) || false === $actual || $actual !== $expected ) {
			throw new RuntimeException( 'Invalid runtime source.' );
		}

		return $actual;
	}

	/** @param array<string, mixed> $environment
	 * @return array{package_revision:string,package_version:string,php_floor:string,runtime_file:string,source_root:string,wordpress_floor:string}
	 */
	private function select( array $environment ): array
	{
		if (
			array() === $this->candidates
			|| ! $this->exactKeys( $environment, array( 'php_version', 'runtime_protocol', 'wordpress_version' ) )
			|| 2 !== $environment['runtime_protocol']
			|| ! is_string( $environment['php_version'] )
			|| ! is_string( $environment['wordpress_version'] )
		) {
			throw new RuntimeException( 'Invalid runtime environment.' );
		}
		$this->version( $environment['php_version'] );
		$wordpressVersion = $this->wordpressVersion( $environment['wordpress_version'] );
		$compatible = array_values( array_filter(
			$this->candidates,
			fn ( array $candidate ): bool => $this->compare( $candidate['php_floor'], $environment['php_version'] ) <= 0
				&& $this->compare( $candidate['wordpress_floor'], $wordpressVersion ) <= 0
		) );
		if ( array() === $compatible ) {
			throw new RuntimeException( 'No compatible runtime.' );
		}
		usort(
			$compatible,
			fn ( array $left, array $right ): int => $this->compare( $right['package_version'], $left['package_version'] )
				?: strcmp( $left['source_root'], $right['source_root'] )
		);
		$highest = array_filter(
			$compatible,
			fn ( array $candidate ): bool => 0 === $this->compare( $candidate['package_version'], $compatible[0]['package_version'] )
		);
		if ( 1 !== count( array_unique( array_column( $highest, 'package_revision' ) ) ) ) {
			throw new RuntimeException( 'Equal runtime versions disagree.' );
		}
		return $compatible[0];
	}

	private function wordpressVersion( string $value ): string
	{
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $value ) ) {
			$value .= '.0';
		}
		$this->version( $value );
		return $value;
	}

	/** @return array{core:list<string>,prerelease:list<string>} */
	private function version( string $value ): array
	{
		$pattern = '/\Av?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
			. '(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?'
			. '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/D';
		if ( ! preg_match( $pattern, $value, $match ) ) {
			throw new RuntimeException( 'Invalid runtime version.' );
		}
		$prerelease = isset( $match[4] ) ? explode( '.', $match[4] ) : array();
		foreach ( $prerelease as $identifier ) {
			if ( preg_match( '/\A[0-9]+\z/D', $identifier ) && ! preg_match( '/\A(?:0|[1-9]\d*)\z/D', $identifier ) ) throw new RuntimeException( 'Invalid runtime version.' );
		}
		return array( 'core' => array( $match[1], $match[2], $match[3] ), 'prerelease' => $prerelease );
	}

	private function compare( string $left, string $right ): int
	{
		$left = $this->version( $left );
		$right = $this->version( $right );
		foreach ( array( 0, 1, 2 ) as $index ) {
			$comparison = strlen( $left['core'][ $index ] ) <=> strlen( $right['core'][ $index ] )
				?: strcmp( $left['core'][ $index ], $right['core'][ $index ] );
			if ( 0 !== $comparison ) {
				return $comparison;
			}
		}
		if ( array() === $left['prerelease'] || array() === $right['prerelease'] ) {
			return array() === $left['prerelease'] ? ( array() === $right['prerelease'] ? 0 : 1 ) : -1;
		}
		for ( $index = 0; $index < max( count( $left['prerelease'] ), count( $right['prerelease'] ) ); ++$index ) {
			if ( ! isset( $left['prerelease'][ $index ] ) ) return -1;
			if ( ! isset( $right['prerelease'][ $index ] ) ) return 1;
			$a = $left['prerelease'][ $index ];
			$b = $right['prerelease'][ $index ];
			if ( $a === $b ) continue;
			$aNumeric = 1 === preg_match( '/\A[0-9]+\z/D', $a );
			$bNumeric = 1 === preg_match( '/\A[0-9]+\z/D', $b );
			if ( $aNumeric && $bNumeric ) return strlen( $a ) <=> strlen( $b ) ?: strcmp( $a, $b );
			if ( $aNumeric !== $bNumeric ) return $aNumeric ? -1 : 1;
			return strcmp( $a, $b );
		}
		return 0;
	}

	private function exactKeys( array $value, array $keys ): bool
	{
		return array_keys( $value ) === $keys;
	}
	private function read( string $file ): string
	{
		$content = file_get_contents( $file );
		if ( false === $content ) {
			throw new RuntimeException( 'Unreadable runtime copy.' );
		}
		return $content;
	}
}
