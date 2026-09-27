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
	private array $candidate_roots = array();
	/** @var list<array{code:string}> */
	private array $diagnostics         = array();
	private bool $activation_attempted = false;
	private string $state              = 'collecting';
	private int $next_submission_id    = 1;
	/** @var array<int,array{declaration:array<string,mixed>,key?:string,handle?:object,last_status?:array<string,mixed>,terminal_code?:string}> */
	private array $submissions     = array();
	private ?object $handoff       = null;
	private ?string $selected_root = null;
	/** @var array<string, object> */
	private array $target_handles  = array();
	private ?string $terminal_code = null;
	private RuntimeCopySelector $runtime_copy_selector;

	public function __construct( private bool $activation_boundary_missed = false, private ?SelectedRuntimeState $selected_runtime_state = null ) {
		$this->runtime_copy_selector = new RuntimeCopySelector();
	}

	public function protocol_version(): int {
		return 5;
	}

	/** Register a physical runtime-copy.json without loading its runtime. */
	public function register_candidate( string $copy_file ): bool {
		if ( ! $this->protocol_live() ) {
			return false;
		}
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return false;
		}
		$candidate_root = realpath( dirname( $copy_file ) );
		if ( 'runtime-copy.json' === basename( $copy_file ) && is_string( $candidate_root ) && isset( $this->candidate_roots[ $candidate_root ] ) ) {
			return true;
		}
		if ( 'collecting' !== $this->state ) {
			$this->diagnose_once( 'late_candidate_rejected' );
			return false;
		}

		try {
			$candidate = $this->runtime_copy_selector->candidate( $copy_file );
		} catch ( Throwable ) {
			$this->diagnose( 'candidate_invalid' );
			return false;
		}
		if ( isset( $this->candidate_roots[ $candidate['source_root'] ] ) ) {
			return true;
		}

		$this->candidate_roots[ $candidate['source_root'] ] = true;
		$this->candidates[]                                 = $candidate;
		if ( $this->activation_boundary_missed ) {
			$this->activation_attempted = true;
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
		if ( ! $this->protocol_live() ) {
			return $this->result( false, 'protocol_conflict_inactive' );
		}
		if ( 'active' === $this->state ) {
			return $this->result( true, 'runtime_active' );
		}
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return $this->result( false, $this->terminal_code ?? 'runtime_selection_inactive' );
		}
		if ( $this->activation_attempted ) {
			return $this->result( false, 'activation_in_progress' );
		}
		$this->activation_attempted = true;
		$this->state                = 'activating';
		if ( ! $this->valid_environment( $environment ) ) {
			return $this->disable( 'runtime_environment_invalid' );
		}

		try {
			$selected = $this->runtime_copy_selector->select( $this->candidates, $environment );
		} catch ( Throwable ) {
			return $this->disable( 'runtime_selection_inactive' );
		}

		try {
			$ran_wp_release_updater_selected_state = $this->selected_runtime_state;
			$handoff                               = require $selected['runtime_file'];
		} catch ( Throwable ) {
			return $this->disable( 'runtime_load_failed' );
		}
		if (
			! is_object( $handoff )
			|| ! RequestProtocolValidator::owned_by( $handoff, $selected['source_root'] )
			|| ! RequestProtocolValidator::exact_public_methods( $handoff, array( 'boot', 'register_target', 'release_source' ) )
		) {
			return $this->disable( 'runtime_handoff_invalid' );
		}
		$this->handoff       = $handoff;
		$this->selected_root = $selected['source_root'];
		$batch               = array();
		foreach ( $this->submissions as $id => $submission ) {
			$batch[] = array(
				'submission_id' => $id,
				'declaration'   => $submission['declaration'],
			);
		}
		try {
			if ( ! is_object( $this->handoff ) || ! is_callable( array( $this->handoff, 'boot' ) ) ) {
				throw new RuntimeException( 'Invalid runtime handoff.' );
			}
			$result = $this->handoff->boot( $environment, $batch );
			if (
				! is_array( $result )
				|| ! RequestProtocolValidator::exact_keys( $result, array( 'accepted', 'code', 'results' ) )
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
				$this->apply_composition( $id, $item );
				$drained[ $id ] = true;
			}
			while ( true ) {
				$id = null;
				foreach ( array_keys( $this->submissions ) as $submission_id ) {
					if ( ! isset( $drained[ $submission_id ] ) ) {
						$id = $submission_id;
						break;
					}
				}
				if ( ! is_int( $id ) ) {
					break;
				}
				$submission = $this->submissions[ $id ];
				if ( ! is_object( $this->handoff ) || ! is_callable( array( $this->handoff, 'register_target' ) ) ) {
					throw new RuntimeException( 'Invalid runtime handoff.' );
				}
				$item = $this->handoff->register_target(
					array(
						'submission_id' => $id,
						'declaration'   => $submission['declaration'],
					)
				);
				if ( ! is_array( $item ) ) {
					throw new RuntimeException( 'Invalid target result.' );
				}
				$this->apply_composition( $id, $item );
				$drained[ $id ] = true;
			}
		} catch ( Throwable ) {
			return $this->disable( 'runtime_handoff_invalid' );
		}
		$this->state = 'active';
		return $this->result( true, 'runtime_active' );
	}

	/**
	 * @param array<string,mixed> $declaration
	 * @return array{accepted:bool,submission_id:int,code:string}
	 */
	public function register_target( array $declaration ): array {
		if ( ! $this->protocol_live() ) {
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
				'code'          => $this->terminal_code ?? 'runtime_selection_inactive',
			);
		}
		$code = $this->declaration_code( $declaration );
		if ( null !== $code ) {
			return array(
				'accepted'      => false,
				'submission_id' => 0,
				'code'          => $code,
			);
		}
		$id                       = $this->next_submission_id++;
		$this->submissions[ $id ] = array(
			'declaration' => $declaration,
		);
		if ( 'active' === $this->state && is_object( $this->handoff ) ) {
			try {
				if ( ! is_object( $this->handoff ) || ! is_callable( array( $this->handoff, 'register_target' ) ) ) {
					throw new RuntimeException( 'Invalid runtime handoff.' );
				}
				$result = $this->handoff->register_target(
					array(
						'submission_id' => $id,
						'declaration'   => $declaration,
					)
				);
				if ( ! is_array( $result ) ) {
					throw new RuntimeException( 'Invalid target result.' );
				}
				$this->apply_composition( $id, $result );
			} catch ( Throwable ) {
				$this->disable( 'runtime_handoff_invalid' );
				return array(
					'accepted'      => false,
					'submission_id' => $id,
					'code'          => 'runtime_handoff_invalid',
				);
			}
			$result_code = $result['code'];
			return array(
				'accepted'      => in_array( $result_code, array( 'target_active', 'target_duplicate', 'declaration_deferred_operation_started' ), true ),
				'submission_id' => $id,
				'code'          => $result_code,
			);
		}
		return array(
			'accepted'      => true,
			'submission_id' => $id,
			'code'          => 'target_queued',
		);
	}

	/**
	 * @param array<string,mixed> $declaration
	 * @return array{accepted:bool,code:string,source_handle:object|null}
	 */
	public function release_source( array $declaration ): array {
		$this->protocol_live();
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return $this->release_failure( 'runtime_unavailable' );
		}
		if ( null !== RequestProtocolValidator::release_declaration_code( $declaration ) ) {
			return $this->release_failure( 'invalid_configuration' );
		}
		if ( 'active' !== $this->state || ! is_object( $this->handoff ) ) {
			return $this->release_failure( 'runtime_not_ready' );
		}
		try {
			if ( ! is_object( $this->handoff ) || ! is_callable( array( $this->handoff, 'release_source' ) ) ) {
				throw new RuntimeException( 'Invalid runtime handoff.' );
			}
			$result = $this->handoff->release_source( $declaration );
		} catch ( Throwable ) {
			$this->disable( 'runtime_handoff_invalid' );
			return $this->release_failure( 'runtime_unavailable' );
		}
		if ( ! is_array( $result ) || ! RequestProtocolValidator::exact_keys( $result, array( 'accepted', 'code', 'source_handle' ) ) || ! is_bool( $result['accepted'] ) || ! is_string( $result['code'] ) ) {
			$this->disable( 'runtime_handoff_invalid' );
			return $this->release_failure( 'runtime_unavailable' );
		}
		$valid_handle = is_object( $result['source_handle'] ) && is_string( $this->selected_root )
			&& RequestProtocolValidator::owned_by( $result['source_handle'], $this->selected_root )
			&& RequestProtocolValidator::exact_public_methods( $result['source_handle'], array( 'acquire', 'inspect', 'list' ) );
		if ( true === $result['accepted'] && 'release_source_ready' === $result['code'] && $valid_handle && is_object( $result['source_handle'] ) ) {
			return $result;
		}
		if ( false === $result['accepted'] && null === $result['source_handle'] && in_array( $result['code'], array( 'provider_unavailable', 'filesystem_unsupported', 'invalid_configuration', 'runtime_not_ready', 'runtime_unavailable' ), true ) ) {
			return $result;
		}
		$this->disable( 'runtime_handoff_invalid' );
		return $this->release_failure( 'runtime_unavailable' );
	}

	/** @return array<string,mixed> */
	public function target_status( int $submission_id ): array {
		$this->protocol_live();
		$item = $this->submissions[ $submission_id ] ?? null;
		if ( ! is_array( $item ) ) {
			return $this->status( 'inactive', false, false, 'declaration_invalid' );
		}
		return $this->project_status( $item );
	}
	/** @return array<string,mixed> */
	public function target_diagnostics( int $submission_id ): array {
		$this->protocol_live();
		$item = $this->submissions[ $submission_id ] ?? null;
		if ( ! is_array( $item ) ) {
			return array(
				'state'       => 'inactive',
				'diagnostics' => array( array( 'code' => 'declaration_invalid' ) ),
			);
		}
		$status = $this->project_status( $item );
		if ( ! isset( $item['handle'] ) || ! is_object( $item['handle'] ) || 'inactive' === $status['state'] ) {
			return array(
				'state'       => $status['state'],
				'diagnostics' => array( array( 'code' => $status['code'] ) ),
			);
		}
		try {
			if ( ! is_callable( array( $item['handle'], 'diagnostics' ) ) ) {
				throw new RuntimeException( 'Invalid target result.' );
			}
			$diagnostics = $item['handle']->diagnostics();
			if ( ! RequestProtocolValidator::valid_diagnostics( $diagnostics, $status['state'] ) ) {
				$this->disable( 'runtime_handoff_invalid' );
				return $this->inactive_diagnostics( $this->submissions[ $submission_id ] );
			}
			return $diagnostics;
		} catch ( Throwable ) {
			$this->disable( 'runtime_handoff_invalid' );
			return $this->inactive_diagnostics( $this->submissions[ $submission_id ] );
		}
	}
	public function refresh_target( int $submission_id ): bool {
		if ( ! $this->protocol_live() ) {
			return false;
		}
		$item = $this->submissions[ $submission_id ] ?? null;
		if ( ! is_array( $item ) || 'active' !== $this->project_status( $item )['state'] || ! is_object( $item['handle'] ?? null ) ) {
			return false;
		}
		try {
			if ( ! is_callable( array( $item['handle'], 'refresh' ) ) ) {
				throw new RuntimeException( 'Invalid target result.' );
			}
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
		$this->protocol_live();
		return array(
			'protocol_version'     => 5,
			'state'                => $this->state,
			'activation_attempted' => $this->activation_attempted,
			'candidate_count'      => count( $this->candidates ),
			'submission_count'     => count( $this->submissions ),
			'logical_target_count' => count( $this->target_handles ),
			'diagnostics'          => $this->diagnostics,
		);
	}

	private function protocol_live(): bool {
		if ( in_array( $this->state, array( 'inactive', 'conflict' ), true ) ) {
			return true;
		}
		$conflict = null !== $this->selected_runtime_state
			&& ( $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null ) !== $this;
		if ( $conflict && 'protocol_conflict_inactive' !== $this->terminal_code ) {
			$this->disable( 'protocol_conflict_inactive' );
		}
		return ! $conflict;
	}

	/** @param array<string,mixed> $environment */
	private function valid_environment( array $environment ): bool {
		return $this->runtime_copy_selector->valid_environment( $environment );
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
	private function apply_composition( int $expected_id, array $result ): void {
		$id = $result['submission_id'] ?? 0;
		if ( ! RequestProtocolValidator::exact_keys( $result, array( 'submission_id', 'accepted', 'code', 'target_key', 'target_handle' ) )
			|| ! is_int( $id )
			|| $expected_id !== $id
			|| ! isset( $this->submissions[ $id ] )
			|| ! is_bool( $result['accepted'] )
			|| ! is_string( $result['code'] ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$admitted     = in_array( $result['code'], array( 'target_active', 'target_duplicate', 'declaration_deferred_operation_started' ), true );
		$valid_handle = is_object( $result['target_handle'] )
			&& is_string( $this->selected_root )
			&& RequestProtocolValidator::owned_by( $result['target_handle'], $this->selected_root )
			&& RequestProtocolValidator::exact_public_methods( $result['target_handle'], array( 'diagnostics', 'refresh', 'status' ) );
		if ( $admitted && ( true !== $result['accepted']
			|| ! is_string( $result['target_key'] )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $result['target_key'] )
			|| ! $valid_handle ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		if ( ! $admitted && ( ! RequestProtocolValidator::is_terminal_code( $result['code'] )
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
			$canonical = $this->target_handles[ $result['target_key'] ] ?? null;
			if ( ! is_object( $canonical ) || $canonical !== $result['target_handle'] ) {
				throw new RuntimeException( 'Invalid target result.' );
			}
		} elseif ( isset( $this->target_handles[ $result['target_key'] ] ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		if ( ! is_callable( array( $result['target_handle'], 'status' ) ) || ! is_callable( array( $result['target_handle'], 'diagnostics' ) ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$status      = $result['target_handle']->status();
		$diagnostics = $result['target_handle']->diagnostics();
		if ( ! RequestProtocolValidator::valid_status( $status ) || ! RequestProtocolValidator::valid_diagnostics( $diagnostics, $status['state'] ) ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$invalid_active    = 'target_active' === $result['code']
			&& ( 'active' !== $status['state'] || 'target_active' !== $status['code'] );
		$invalid_deferred  = 'declaration_deferred_operation_started' === $result['code']
			&& ( 'deferred' !== $status['state'] || 'declaration_deferred_operation_started' !== $status['code'] );
		$invalid_duplicate = 'target_duplicate' === $result['code']
			&& ! (
				( 'active' === $status['state'] && 'target_active' === $status['code'] )
				|| ( 'deferred' === $status['state'] && 'declaration_deferred_operation_started' === $status['code'] )
			);
		if ( $invalid_active || $invalid_deferred || $invalid_duplicate ) {
			throw new RuntimeException( 'Invalid target result.' );
		}
		$this->submissions[ $id ]['handle']      = $result['target_handle'];
		$this->submissions[ $id ]['key']         = $result['target_key'];
		$this->submissions[ $id ]['last_status'] = $status;
		if ( 'target_duplicate' !== $result['code'] ) {
			$this->target_handles[ $result['target_key'] ] = $result['target_handle'];
		}
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	private function project_status( array $item ): array {
		if ( isset( $item['terminal_code'] ) || null !== $this->terminal_code ) {
			$last = $this->last_native_status( $item );
			if ( is_array( $last['native'] ?? null ) ) {
				$last['native']['offered_release_identity'] = null;
				$last['native']['offered_version']          = null;
			}
			return $this->status(
				'inactive',
				true,
				true === ( $last['hooks_registered'] ?? false ),
				$item['terminal_code'] ?? $this->terminal_code ?? 'runtime_handoff_invalid',
				$last['native'] ?? null
			);
		}
		if ( ! isset( $item['handle'] ) || ! is_object( $item['handle'] ) ) {
			return $this->status( 'queued', true, false, 'target_queued' );
		}
		try {
			if ( ! is_callable( array( $item['handle'], 'status' ) ) ) {
				throw new RuntimeException( 'Invalid target result.' );
			}
			$status = $item['handle']->status();
			if ( ! RequestProtocolValidator::valid_status( $status ) ) {
				$this->disable( 'runtime_handoff_invalid' );
				return $this->project_status( $item );
			}
			return $status;
		} catch ( Throwable ) {
			$this->disable( 'runtime_handoff_invalid' );
			return $this->project_status( $item );
		}
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	private function last_native_status( array $item ): array {
		if ( isset( $item['last_status'] ) && is_array( $item['last_status'] ) ) {
			return $item['last_status'];
		}
		if ( ! isset( $item['handle'] ) || ! is_object( $item['handle'] ) ) {
			return array();
		}
		try {
			if ( ! is_callable( array( $item['handle'], 'status' ) ) ) {
				throw new RuntimeException( 'Invalid target result.' );
			}
			$status = $item['handle']->status();
			return RequestProtocolValidator::valid_status( $status ) ? $status : array();
		} catch ( Throwable ) {
			return array();
		}
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array{state:string,diagnostics:list<array{code:string}>}
	 */
	private function inactive_diagnostics( array $item ): array {
		$status = $this->project_status( $item );
		return array(
			'state'       => $status['state'],
			'diagnostics' => array( array( 'code' => $status['code'] ) ),
		);
	}

	/** @return array{loaded:bool,state:string,code:string,diagnostics:list<array{code:string}>} */
	private function disable( string $code ): array {
		$this->diagnose( $code );
		$this->terminal_code = $code;
		$this->state         = 'protocol_conflict_inactive' === $code ? 'conflict' : 'inactive';
		return $this->result( false, $code );
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

	// @phpstan-ignore method.unused (ConciseRegistrarTest invokes this validation seam through Reflection.)
	private function valid_native_status( mixed $native ): bool {
		return RequestProtocolValidator::valid_native_status( $native );
	}

	/** @param array<string,mixed> $value */
	private function declaration_code( array $value ): ?string {
		return RequestProtocolValidator::declaration_code( $value );
	}

	/** @return array{accepted:false,code:string,source_handle:null} */
	private function release_failure( string $code ): array {
		return array(
			'accepted'      => false,
			'code'          => $code,
			'source_handle' => null,
		);
	}

	private function diagnose( string $code ): void {
		if ( self::MAX_DIAGNOSTICS === count( $this->diagnostics ) ) {
			array_shift( $this->diagnostics );
		}
		$this->diagnostics[] = array( 'code' => $code );
	}

	private function diagnose_once( string $code ): void {
		foreach ( $this->diagnostics as $diagnostic ) {
			if ( $code === $diagnostic['code'] ) {
				return;
			}
		}
		$this->diagnose( $code );
	}
}
