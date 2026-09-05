<?php

declare(strict_types=1);

namespace Tests\Runtime;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RequestBrokerActivationDrainTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/request-broker-activation-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root, 0700, true );
	}

	public function testReentrantActivationAndAppendedInitialBootSubmissionDrainExactlyOnce(): void
	{
		$copy = $this->package( 'drain', <<<'PHP'
<?php
return new class {
	private function result(array $submission): array { return array('submission_id'=>$submission['submission_id'],'accepted'=>false,'code'=>'target_composition_failed','target_key'=>null,'target_handle'=>null); }
	public function boot(array $environment, array $submissions): array { $GLOBALS['p02_inner']=$GLOBALS['ran_wp_release_updater_v1_broker']->activate($environment); $GLOBALS['p02_boot_ids']=array_column($submissions,'submission_id'); $GLOBALS['ran_wp_release_updater_v1_broker']->registerTarget(array('target_type'=>'plugin','installed_file'=>'/during.php','provider_code'=>'github','repository_locator'=>'acme/during','repository_identity'=>'2','channel'=>'stable','update_policy'=>'manual','credential_resolver'=>null,'maximum_artifact_bytes'=>52428800)); return array('accepted'=>true,'code'=>'runtime_active','results'=>array_map(fn(array $submission): array=>$this->result($submission),$submissions)); }
	public function registerTarget(array $submission): array { $GLOBALS['p02_target_ids'][]=$submission['submission_id']; return $this->result($submission); }
};
PHP );
		$result = $this->probe( <<<'PHP'
require $data['copy'] . '/bootstrap.php';
$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];
$GLOBALS['p02_target_ids']=array();
$first=$broker->registerTarget(array('target_type'=>'plugin','installed_file'=>'/first.php','provider_code'=>'github','repository_locator'=>'acme/first','repository_identity'=>'1','channel'=>'stable','update_policy'=>'manual','credential_resolver'=>null,'maximum_artifact_bytes'=>52428800));
$outer=$broker->activate(array('php_version'=>'8.2.0','runtime_protocol'=>2,'wordpress_version'=>'6.8.0'));
$after=$broker->registerTarget(array('target_type'=>'plugin','installed_file'=>'/after.php','provider_code'=>'github','repository_locator'=>'acme/after','repository_identity'=>'3','channel'=>'stable','update_policy'=>'manual','credential_resolver'=>null,'maximum_artifact_bytes'=>52428800));
$again=$broker->activate(array('php_version'=>'8.2.0','runtime_protocol'=>2,'wordpress_version'=>'6.8.0'));
echo json_encode(array('first'=>$first,'inner'=>$GLOBALS['p02_inner'],'outer'=>$outer,'after'=>$after,'again'=>$again,'boot'=>$GLOBALS['p02_boot_ids'],'targets'=>$GLOBALS['p02_target_ids'],'submissions'=>$broker->diagnostics()['submission_count']));
PHP, array( 'copy' => $copy ) );

		self::assertTrue( $result['first']['accepted'] );
		self::assertFalse( $result['inner']['loaded'] );
		self::assertSame( 'activating', $result['inner']['state'] );
		self::assertSame( 'activation_in_progress', $result['inner']['code'] );
		self::assertTrue( $result['outer']['loaded'] );
		self::assertSame( array( 1 ), $result['boot'] );
		self::assertSame( array( 2, 3 ), $result['targets'] );
		self::assertSame( 3, $result['submissions'] );
		self::assertFalse( $result['after']['accepted'] );
		self::assertSame( 'target_composition_failed', $result['after']['code'] );
		self::assertTrue( $result['again']['loaded'] );
		self::assertSame( 'runtime_active', $result['again']['code'] );
	}

	public function testActiveCandidateIdempotencyAndTerminalCandidateTargetAndActivationRemainPassive(): void
	{
		$copy = $this->package( 'active', $this->runtime() );
		$unseen = $this->package( 'unseen', $this->runtime() );
		$result = $this->probe( <<<'PHP'
require $data['copy'] . '/bootstrap.php';
$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];
$environment=array('php_version'=>'8.2.0','runtime_protocol'=>2,'wordpress_version'=>'6.8.0');
$active=$broker->activate($environment);
$candidateCountBefore=$broker->diagnostics()['candidate_count'];
$known=$broker->registerCandidate($data['copy'].'/runtime-copy.json');
$unseen=$broker->registerCandidate($data['unseen'].'/runtime-copy.json');
$unseenAgain=$broker->registerCandidate($data['unseen'].'/runtime-copy.json');
$activeDiagnostics=$broker->diagnostics();
$inactive=new RAN\WPReleaseUpdater\V1\Runtime\RequestBroker();
$inactive->registerCandidate($data['copy'].'/runtime-copy.json');
$firstInactive=$inactive->activate(array('php_version'=>array(),'runtime_protocol'=>2,'wordpress_version'=>'6.8.0'));
$knownInactive=$inactive->registerCandidate($data['copy'].'/runtime-copy.json');
$targetInactive=$inactive->registerTarget(array('target_type'=>'plugin','installed_file'=>'/inactive.php','provider_code'=>'github','repository_locator'=>'acme/inactive','repository_identity'=>'4','channel'=>'stable','update_policy'=>'manual','credential_resolver'=>null,'maximum_artifact_bytes'=>52428800));
$againInactive=$inactive->activate($environment);
$conflict=new RAN\WPReleaseUpdater\V1\Runtime\RequestBroker();
$conflict->registerCandidate($data['copy'].'/runtime-copy.json');
$GLOBALS['ran_wp_github_release_updater_v1_broker']=new stdClass();
$firstConflict=$conflict->activate($environment);
$knownConflict=$conflict->registerCandidate($data['copy'].'/runtime-copy.json');
$targetConflict=$conflict->registerTarget(array('target_type'=>'plugin','installed_file'=>'/conflict.php','provider_code'=>'github','repository_locator'=>'acme/conflict','repository_identity'=>'6','channel'=>'stable','update_policy'=>'manual','credential_resolver'=>null,'maximum_artifact_bytes'=>52428800));
$againConflict=$conflict->activate($environment);
echo json_encode(array('active'=>$active,'known'=>$known,'unseen'=>$unseen,'unseen_again'=>$unseenAgain,'candidate_count_before'=>$candidateCountBefore,'candidate_count_after'=>$activeDiagnostics['candidate_count'],'active_diagnostics'=>$activeDiagnostics['diagnostics'],'first_inactive'=>$firstInactive,'known_inactive'=>$knownInactive,'target_inactive'=>$targetInactive,'again_inactive'=>$againInactive,'inactive_diagnostics'=>$inactive->diagnostics()['diagnostics'],'first_conflict'=>$firstConflict,'known_conflict'=>$knownConflict,'target_conflict'=>$targetConflict,'again_conflict'=>$againConflict,'conflict_diagnostics'=>$conflict->diagnostics()['diagnostics']));
PHP, array( 'copy' => $copy, 'unseen' => $unseen ) );

		self::assertTrue( $result['active']['loaded'] );
		self::assertTrue( $result['known'] );
		self::assertFalse( $result['unseen'] );
		self::assertFalse( $result['unseen_again'] );
		self::assertSame( $result['candidate_count_before'], $result['candidate_count_after'] );
		self::assertSame( array( array( 'code' => 'late_candidate_rejected' ) ), $result['active_diagnostics'] );
		self::assertSame( 'runtime_environment_invalid', $result['first_inactive']['code'] );
		self::assertFalse( $result['known_inactive'] );
		self::assertFalse( $result['target_inactive']['accepted'] );
		self::assertSame( 'runtime_environment_invalid', $result['target_inactive']['code'] );
		self::assertFalse( $result['again_inactive']['loaded'] );
		self::assertSame( 'runtime_environment_invalid', $result['again_inactive']['code'] );
		self::assertSame( array( array( 'code' => 'runtime_environment_invalid' ) ), $result['inactive_diagnostics'] );
		self::assertSame( 'protocol_conflict_inactive', $result['first_conflict']['code'] );
		self::assertFalse( $result['known_conflict'] );
		self::assertFalse( $result['target_conflict']['accepted'] );
		self::assertSame( 'protocol_conflict_inactive', $result['target_conflict']['code'] );
		self::assertFalse( $result['again_conflict']['loaded'] );
		self::assertSame( 'protocol_conflict_inactive', $result['again_conflict']['code'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['conflict_diagnostics'] );
	}

	public function testRuntimeLoadFailureAndProtocolConflictHaveStableTerminalStateAndProjection(): void
	{
		$load = $this->package( 'load-failure', "<?php\nthrow new RuntimeException('load');\n" );
		$conflict = $this->package( 'conflict', $this->runtime() );
		$result = $this->probe( <<<'PHP'
require $data['load'] . '/bootstrap.php';
$load=$GLOBALS['ran_wp_release_updater_v1_broker'];
$queued=$load->registerTarget(array('target_type'=>'plugin','installed_file'=>'/queued.php','provider_code'=>'github','repository_locator'=>'acme/queued','repository_identity'=>'7','channel'=>'stable','update_policy'=>'manual','credential_resolver'=>null,'maximum_artifact_bytes'=>52428800));
$environment=array('php_version'=>'8.2.0','runtime_protocol'=>2,'wordpress_version'=>'6.8.0');
$firstLoad=$load->activate($environment);
$statusLoad=$load->targetStatus($queued['submission_id']);
$againLoad=$load->activate($environment);
$GLOBALS['ran_wp_release_updater_v1_broker']=null;
require $data['conflict'] . '/bootstrap.php';
$conflict=$GLOBALS['ran_wp_release_updater_v1_broker'];
$GLOBALS['ran_wp_github_release_updater_v1_broker']=new stdClass();
$firstConflict=$conflict->activate($environment);
$stateConflict=$conflict->diagnostics()['state'];
$againConflict=$conflict->activate($environment);
echo json_encode(array('first_load'=>$firstLoad,'status_load'=>$statusLoad,'again_load'=>$againLoad,'first_conflict'=>$firstConflict,'state_conflict'=>$stateConflict,'again_conflict'=>$againConflict,'conflict_diagnostics'=>$conflict->diagnostics()['diagnostics']));
PHP, array( 'load' => $load, 'conflict' => $conflict ) );

		self::assertSame( 'runtime_load_failed', $result['first_load']['code'] );
		self::assertSame( 'runtime_load_failed', $result['status_load']['code'] );
		self::assertSame( 'runtime_load_failed', $result['again_load']['code'] );
		self::assertSame( 'protocol_conflict_inactive', $result['first_conflict']['code'] );
		self::assertSame( 'conflict', $result['state_conflict'] );
		self::assertSame( 'conflict', $result['again_conflict']['state'] );
		self::assertSame( 'protocol_conflict_inactive', $result['again_conflict']['code'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['conflict_diagnostics'] );
	}

	public function testQueuedRegistrarHandleDoesNotResubmitAfterTerminalCompositionFailure(): void
	{
		$copy = $this->package( 'terminal-handle', $this->runtime() );
		$result = $this->probe( <<<'PHP'
$registrar=require $data['copy'] . '/bootstrap.php';
$handle=$registrar->plugin('github','/terminal.php','acme/terminal','8');
$first=$handle->register();
$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];
$broker->activate(array('php_version'=>'8.2.0','runtime_protocol'=>2,'wordpress_version'=>'6.8.0'));
$second=$handle->register();
echo json_encode(array('first'=>$first,'second'=>$second,'status'=>$handle->status(),'submissions'=>$broker->diagnostics()['submission_count']));
PHP, array( 'copy' => $copy ) );

		self::assertTrue( $result['first'] );
		self::assertFalse( $result['second'] );
		self::assertSame( 'target_composition_failed', $result['status']['code'] );
		self::assertSame( 1, $result['submissions'] );
	}

	public function testRegistrarHandleRegistersOnlyOneSubmission(): void
	{
		$result = $this->probe( <<<'PHP'
$registrar=require $data['bootstrap'];
$handle=$registrar->plugin('github','/single.php','acme/single','5');
$first=$handle->register();
$second=$handle->register();
echo json_encode(array('first'=>$first,'second'=>$second,'submissions'=>$GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['submission_count']));
PHP, array( 'bootstrap' => dirname( __DIR__, 2 ) . '/bootstrap.php' ) );

		self::assertTrue( $result['first'] );
		self::assertTrue( $result['second'] );
		self::assertSame( 1, $result['submissions'] );
	}

	public function testRetainedBrokerAndHandleArePassiveAfterReplacementAndOtherTerminalStatesStayTruthful(): void
	{
		$active = $this->package( 'retained-active', $this->activeRuntime() );
		$unseen = $this->package( 'retained-unseen', $this->runtime() );
		$load = $this->package( 'retained-load', "<?php\nthrow new RuntimeException('load');\n" );
		$composition = $this->package( 'retained-composition', $this->runtime() );
		$result = $this->probe( <<<'PHP'
$environment=array('php_version'=>'8.2.0','runtime_protocol'=>2,'wordpress_version'=>'6.8.0');
$registrar=require $data['active'] . '/bootstrap.php';$handle=$registrar->plugin('github','/active.php','acme/active','1');$handle->register();$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$broker->activate($environment);$before=$broker->diagnostics();$GLOBALS['ran_wp_release_updater_v1_broker']=new stdClass();$stale=array('known'=>$broker->registerCandidate($data['active'].'/runtime-copy.json'),'unseen'=>$broker->registerCandidate($data['unseen'].'/runtime-copy.json'),'target'=>$broker->registerTarget(array('target_type'=>'plugin','installed_file'=>'/late.php','provider_code'=>'github','repository_locator'=>'acme/late','repository_identity'=>'2','channel'=>'stable','update_policy'=>'manual','credential_resolver'=>null)),'refresh'=>$handle->refresh(),'status'=>$handle->status(),'diagnostics'=>$handle->diagnostics(),'again'=>$broker->activate($environment),'counts'=>$broker->diagnostics());
$GLOBALS['ran_wp_release_updater_v1_broker']=null;$loadRegistrar=require $data['load'].'/bootstrap.php';$loadHandle=$loadRegistrar->plugin('github','/load.php','acme/load','3');$loadHandle->register();$loadBroker=$GLOBALS['ran_wp_release_updater_v1_broker'];$firstLoad=$loadBroker->activate($environment);$loadTerminal=array('candidate'=>$loadBroker->registerCandidate($data['load'].'/runtime-copy.json'),'target'=>$loadBroker->registerTarget(array('target_type'=>'plugin','installed_file'=>'/after-load.php','provider_code'=>'github','repository_locator'=>'acme/after-load','repository_identity'=>'4','channel'=>'stable','update_policy'=>'manual','credential_resolver'=>null)),'again'=>$loadBroker->activate($environment),'status'=>$loadHandle->status(),'counts'=>$loadBroker->diagnostics());
$GLOBALS['ran_wp_release_updater_v1_broker']=null;$compositionRegistrar=require $data['composition'].'/bootstrap.php';$compositionHandle=$compositionRegistrar->plugin('github','/composition.php','acme/composition','5');$compositionHandle->register();$compositionBroker=$GLOBALS['ran_wp_release_updater_v1_broker'];$compositionBroker->activate($environment);$compositionTerminal=array('again'=>$compositionHandle->register(),'status'=>$compositionHandle->status(),'diagnostics'=>$compositionHandle->diagnostics(),'candidate'=>$compositionBroker->registerCandidate($data['composition'].'/runtime-copy.json'),'activation'=>$compositionBroker->activate($environment),'counts'=>$compositionBroker->diagnostics());
echo json_encode(array('before'=>$before,'stale'=>$stale,'load'=>$loadTerminal,'composition'=>$compositionTerminal));
PHP, array( 'active' => $active, 'unseen' => $unseen, 'load' => $load, 'composition' => $composition ) );

		self::assertTrue( $result['before']['activation_attempted'] );
		self::assertFalse( $result['stale']['known'] );
		self::assertFalse( $result['stale']['unseen'] );
		self::assertFalse( $result['stale']['target']['accepted'] );
		self::assertSame( 'protocol_conflict_inactive', $result['stale']['target']['code'] );
		self::assertFalse( $result['stale']['refresh'] );
		self::assertSame( 'protocol_conflict_inactive', $result['stale']['status']['code'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['stale']['diagnostics']['diagnostics'] );
		self::assertSame( 'protocol_conflict_inactive', $result['stale']['again']['code'] );
		self::assertSame( $result['before']['candidate_count'], $result['stale']['counts']['candidate_count'] );
		self::assertSame( $result['before']['submission_count'], $result['stale']['counts']['submission_count'] );
		self::assertSame( 'runtime_load_failed', $result['load']['status']['code'] );
		self::assertFalse( $result['load']['candidate'] );
		self::assertSame( 'runtime_load_failed', $result['load']['target']['code'] );
		self::assertSame( 'runtime_load_failed', $result['load']['again']['code'] );
		self::assertSame( 'target_composition_failed', $result['composition']['status']['code'] );
		self::assertFalse( $result['composition']['again'] );
		self::assertTrue( $result['composition']['candidate'] );
		self::assertTrue( $result['composition']['activation']['loaded'] );
	}

	public function testActiveHandleProjectsUpdateCompletedDiagnosticsWithoutTerminalisingTheBroker(): void
	{
		$copy = $this->package( 'update-completed-diagnostics', $this->updateCompletedRuntime() );
		$result = $this->probe( <<<'PHP'
$registrar = require $data['copy'] . '/bootstrap.php';
$handle = $registrar->plugin( 'github', '/completed.php', 'acme/completed', '9' );
$handle->register();
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$broker->activate( array( 'php_version' => '8.2.0', 'runtime_protocol' => 2, 'wordpress_version' => '6.8.0' ) );
$diagnostics = $handle->diagnostics();
$status = $handle->status();
$brokerDiagnostics = $broker->diagnostics();
echo json_encode( array( 'diagnostics' => $diagnostics, 'status' => $status, 'broker' => $brokerDiagnostics ) );
PHP, array( 'copy' => $copy ) );

		self::assertSame( array( array( 'code' => 'update_completed' ) ), $result['diagnostics']['diagnostics'] );
		self::assertSame( 'active', $result['status']['state'] );
		self::assertSame( 'target_active', $result['status']['code'] );
		self::assertSame( 'active', $result['broker']['state'] );
		self::assertNotContains( array( 'code' => 'runtime_handoff_invalid' ), $result['broker']['diagnostics'] );
	}

	private function activeRuntime(): string
	{
		return <<<'PHP'
<?php
final class P02ActiveHandle { public function status(): array { return array('state'=>'active','declaration_accepted'=>true,'hooks_registered'=>true,'code'=>'target_active','native'=>array('candidate_header_version'=>null,'candidate_tag'=>null,'candidate_validation_code'=>null,'candidate_version'=>null,'failure_code'=>null,'installed_version'=>null,'last_check'=>null,'offered_version'=>null,'relationship'=>null)); } public function diagnostics(): array { return array('state'=>'active','diagnostics'=>array()); } public function refresh(): bool { return true; } }
return new class { private function result(array $s): array { return array('submission_id'=>$s['submission_id'],'accepted'=>true,'code'=>'target_active','target_key'=>str_repeat('a',64),'target_handle'=>new P02ActiveHandle()); } public function boot(array $e,array $s): array { return array('accepted'=>true,'code'=>'runtime_active','results'=>array_map(fn(array $v): array=>$this->result($v),$s)); } public function registerTarget(array $s): array { return $this->result($s); } };
PHP;
	}

	private function updateCompletedRuntime(): string
	{
		return <<<'PHP'
<?php
final class P02UpdateCompletedHandle
{
	public function status(): array
	{
		return array(
			'state' => 'active',
			'declaration_accepted' => true,
			'hooks_registered' => true,
			'code' => 'target_active',
			'native' => array(
				'candidate_header_version' => null,
				'candidate_tag' => null,
				'candidate_validation_code' => null,
				'candidate_version' => null,
				'failure_code' => null,
				'installed_version' => null,
				'last_check' => null,
				'offered_version' => null,
				'relationship' => null,
			),
		);
	}

	public function diagnostics(): array
	{
		return array( 'state' => 'active', 'diagnostics' => array( array( 'code' => 'update_completed' ) ) );
	}

	public function refresh(): bool
	{
		return true;
	}
}

return new class {
	private function result( array $submission ): array
	{
		return array(
			'submission_id' => $submission['submission_id'],
			'accepted' => true,
			'code' => 'target_active',
			'target_key' => str_repeat( 'b', 64 ),
			'target_handle' => new P02UpdateCompletedHandle(),
		);
	}

	public function boot( array $environment, array $submissions ): array
	{
		unset( $environment );
		return array( 'accepted' => true, 'code' => 'runtime_active', 'results' => array_map( fn( array $submission ): array => $this->result( $submission ), $submissions ) );
	}

	public function registerTarget( array $submission ): array
	{
		return $this->result( $submission );
	}
};
PHP;
	}

	private function runtime(): string
	{
		return <<<'PHP'
<?php
return new class {
	private function result(array $submission): array { return array('submission_id'=>$submission['submission_id'],'accepted'=>false,'code'=>'target_composition_failed','target_key'=>null,'target_handle'=>null); }
	public function boot(array $environment, array $submissions): array { unset($environment); return array('accepted'=>true,'code'=>'runtime_active','results'=>array_map(fn(array $submission): array=>$this->result($submission),$submissions)); }
	public function registerTarget(array $submission): array { return $this->result($submission); }
};
PHP;
	}

	private function package( string $name, string $runtime ): string
	{
		$root = $this->root . '/' . $name;
		mkdir( $root . '/src/Runtime', 0700, true );
		copy( dirname( __DIR__, 2 ) . '/bootstrap.php', $root . '/bootstrap.php' );
		copy( dirname( __DIR__, 2 ) . '/src/Runtime/RequestBroker.php', $root . '/src/Runtime/RequestBroker.php' );
		copy( dirname( __DIR__, 2 ) . '/src/Runtime/SelectedRuntimeState.php', $root . '/src/Runtime/SelectedRuntimeState.php' );
		file_put_contents( $root . '/runtime.php', $runtime );
		file_put_contents( $root . '/runtime-copy.json', json_encode( array( 'package_revision' => $this->identity( $root ), 'package_version' => '0.1.0-beta.2', 'php_floor' => '8.2.0', 'runtime_file' => 'runtime.php', 'runtime_protocol' => 2, 'wordpress_floor' => '6.5.0' ), JSON_THROW_ON_ERROR ) );
		return $root;
	}

	/** @param array<string,mixed> $data */
	private function probe( string $body, array $data ): array
	{
		$file = $this->root . '/probe-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents( $file, '<?php $data=' . var_export( $data, true ) . ';' . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function identity( string $root ): string
	{
		$files = array( 'bootstrap.php', 'runtime.php', 'src/Runtime/RequestBroker.php', 'src/Runtime/SelectedRuntimeState.php' );
		sort( $files, SORT_STRING );
		$payload = '';
		foreach ( $files as $file ) {
			$payload .= $file . "\0" . hash_file( 'sha256', $root . '/' . $file ) . "\n";
		}
		return hash( 'sha256', $payload );
	}
}
