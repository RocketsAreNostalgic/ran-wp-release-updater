<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Runtime;

/**
 * Request-local ownership of the selected protocol-2 broker.
 */
final class SelectedRuntimeState
{
	/** @var array<string,true> */
	private array $operations = array();

	public function __construct( private ?RequestBroker $broker = null )
	{
	}

	public function bind( RequestBroker $broker ): void
	{
		$this->broker = $broker;
	}

	public function brokerIsLive(): bool
	{
		return null === $this->livenessCode();
	}

	/** @internal */
	public function livenessCode(): ?string
	{
		if (
			! $this->broker instanceof RequestBroker
			|| ( $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null ) !== $this->broker
			|| isset( $GLOBALS['ran_wp_github_release_updater_v1_broker'] )
			|| function_exists( 'ran_wp_github_release_updater_v1_has_registered_target' )
		) {
			return 'protocol_conflict_inactive';
		}

		try {
			$diagnostics = $this->broker->diagnostics();
			if ( 2 !== $this->broker->protocolVersion() ) {
				return 'protocol_conflict_inactive';
			}
			return in_array( $diagnostics['state'] ?? null, array( 'activating', 'active' ), true )
				? null
				: 'runtime_handoff_invalid';
		} catch ( \Throwable ) {
			return 'runtime_handoff_invalid';
		}
	}

	public function activate(): array
	{
		if ( ! $this->broker instanceof RequestBroker ) {
			return array(
				'loaded' => false,
				'state' => 'inactive',
				'code' => 'protocol_conflict_inactive',
				'diagnostics' => array( array( 'code' => 'protocol_conflict_inactive' ) ),
			);
		}
		try {
			if ( 2 !== $this->broker->protocolVersion() ) {
				throw new \RuntimeException( 'Inactive broker.' );
			}
			return $this->broker->activate(
				array(
					'php_version' => PHP_VERSION,
					'runtime_protocol' => 2,
					'wordpress_version' => $GLOBALS['wp_version'] ?? null,
				)
			);
		} catch ( \Throwable ) {
			return array(
				'loaded' => false,
				'state' => 'inactive',
				'code' => 'protocol_conflict_inactive',
				'diagnostics' => array( array( 'code' => 'protocol_conflict_inactive' ) ),
			);
		}
	}

	public function operationStarted( string $type ): bool
	{
		return $this->validType( $type ) && isset( $this->operations[ $type ] );
	}

	public function beginOperation( string $type ): void
	{
		if ( $this->validType( $type ) ) {
			$this->operations[ $type ] = true;
		}
	}

	private function validType( string $type ): bool
	{
		return 'plugin' === $type || 'theme' === $type;
	}
}
