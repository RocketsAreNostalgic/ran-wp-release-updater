<?php

declare(strict_types=1);

use RAN\WPReleaseUpdater\V1\Runtime\RequestBroker;

if ( ! class_exists( RequestBroker::class, false ) ) {
	require_once __DIR__ . '/src/Runtime/RequestBroker.php';
}

$ran_wp_release_updater_broker = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
if ( null === $ran_wp_release_updater_broker ) {
	$ran_wp_release_updater_broker = new RequestBroker();
	$GLOBALS['ran_wp_release_updater_v1_broker'] = $ran_wp_release_updater_broker;
}

$ran_wp_release_updater_broker_compatible = is_object( $ran_wp_release_updater_broker )
	&& RequestBroker::class === get_class( $ran_wp_release_updater_broker )
	&& is_callable( array( $ran_wp_release_updater_broker, 'protocolVersion' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'registerCandidate' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'activate' ) )
	&& is_callable( array( $ran_wp_release_updater_broker, 'diagnostics' ) );
if ( $ran_wp_release_updater_broker_compatible ) {
	try {
		$ran_wp_release_updater_broker_compatible = 1 === $ran_wp_release_updater_broker->protocolVersion();
	} catch ( Throwable ) {
		$ran_wp_release_updater_broker_compatible = false;
	}
}

if ( ! $ran_wp_release_updater_broker_compatible ) {
	$ran_wp_release_updater_broker = new class() {
		/** @var list<array{code:string}> */
		private array $diagnostics = array( array( 'code' => 'broker_protocol_conflict_inactive' ) );

		public function protocolVersion(): int
		{
			return 0;
		}

		public function registerCandidate( string $copyFile ): bool
		{
			unset( $copyFile );
			return false;
		}

		/** @return array{loaded:false,diagnostics:list<array{code:string}>} */
		public function activate( array $environment ): array
		{
			unset( $environment );
			return array( 'loaded' => false, 'diagnostics' => $this->diagnostics );
		}

		/** @return array{activation_attempted:bool,candidate_count:int,diagnostics:list<array{code:string}>} */
		public function diagnostics(): array
		{
			return array( 'activation_attempted' => true, 'candidate_count' => 0, 'diagnostics' => $this->diagnostics );
		}
	};
	$GLOBALS['ran_wp_release_updater_v1_broker'] = $ran_wp_release_updater_broker;
	return;
}

$ran_wp_release_updater_broker->registerCandidate( __DIR__ . '/runtime-copy.json' );
