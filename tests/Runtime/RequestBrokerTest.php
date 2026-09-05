<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class RequestBrokerTest extends TestCase
{
	private string $parent;

	protected function setUp(): void
	{
		$this->parent = dirname( __DIR__, 2 ) . '/.workspaces/p0.1/request-broker-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->parent, 0700, true );
	}

	protected function tearDown(): void
	{
		$this->remove( $this->parent );
	}

	public function testDeclarationPathsAcceptOnlyPosixOrDriveQualifiedAbsoluteForms(): void
	{
		$broker = new \RAN\WPReleaseUpdater\V1\Runtime\RequestBroker();
		$method = new \ReflectionMethod( $broker, 'declarationCode' );
		$declaration = array(
			'target_type' => 'plugin',
			'installed_file' => '/plugins/example/example.php',
			'provider_code' => 'github',
			'repository_locator' => 'acme/example',
			'repository_identity' => '123456789',
			'channel' => 'stable',
			'update_policy' => 'manual',
			'credential_resolver' => null,
			'maximum_artifact_bytes' => 52_428_800,
		);

		foreach ( array( '/plugins/example/example.php', 'C:/plugins/example/example.php', 'D:\\plugins\\example\\example.php' ) as $path ) {
			$declaration['installed_file'] = $path;
			self::assertNull( $method->invoke( $broker, $declaration ), $path );
		}

		foreach ( array( 'relative/example.php', 'C:relative/example.php', '//server/share/example.php', 'C://plugins/example.php', 'C:/plugins/../example.php', '/plugins//example.php' ) as $path ) {
			$declaration['installed_file'] = $path;
			self::assertSame( 'installed_file_invalid', $method->invoke( $broker, $declaration ), $path );
		}
	}

	public function testDifferentPhysicalCopiesUseOneBrokerAndLoadOnlyTheHighestCompatibleRuntime(): void
	{
		$old = $this->copy( 'old', '0.1.0-beta.1', 'a' );
		$new = $this->copy( 'new', '0.1.0-beta.2', 'b' );
		foreach ( array( array( $old, $new ), array( $new, $old ) ) as $order ) {
			$result = $this->probe( 'require $data["first"] . "/bootstrap.php"; require $data["second"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $result=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); echo json_encode(array("protocol"=>$broker->protocolVersion(),"result"=>$result,"marker"=>file_get_contents($data["marker"])));', array( 'first' => $order[0], 'second' => $order[1], 'marker' => $this->parent . '/selected.txt' ) );
			self::assertSame( 2, $result['protocol'] );
			self::assertTrue( $result['result']['loaded'] );
			self::assertSame( array(), $result['result']['diagnostics'] );
			self::assertSame( 'new', $result['marker'] );
			@unlink( $this->parent . '/selected.txt' );
		}
	}

	public function testThreeOrMoreCompatibleCopiesSelectOneHighestRuntimeInEveryLoadOrder(): void
	{
		$copies = array(
			$this->copy( 'beta-one', '0.1.0-beta.1', 'a' ),
			$this->copy( 'beta-two', '0.1.0-beta.2', 'b' ),
			$this->copy( 'beta-three', '0.1.0-beta.3', 'c' ),
			$this->copy( 'beta-four', '0.1.0-beta.4', 'd' ),
		);
		$orders = array(
			$copies,
			array_reverse( $copies ),
			array( $copies[1], $copies[3], $copies[0], $copies[2] ),
		);

		foreach ( $orders as $order ) {
			$result = $this->probe(
				'foreach ($data["copies"] as $copy) require $copy . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $activation=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); echo json_encode(array("activation"=>$activation,"candidates"=>$broker->diagnostics()["candidate_count"],"marker"=>file_get_contents($data["marker"])));',
				array( 'copies' => $order, 'marker' => $this->parent . '/selected.txt' )
			);

			self::assertTrue( $result['activation']['loaded'] );
			self::assertSame( 4, $result['candidates'] );
			self::assertSame( 'beta-four', $result['marker'] );
			@unlink( $this->parent . '/selected.txt' );
		}
	}

	public function testMoreThanEightUniqueCopiesAreAdmittedAndOnlyTheHighestRuntimeLoads(): void
	{
		$copies = array();
		for ( $index = 1; $index <= 9; ++$index ) {
			$copies[] = $this->copy( 'copy-' . $index, '0.1.0-beta.' . $index, 'a' );
		}
		$result = $this->probe( 'foreach ($data["copies"] as $copy) require $copy . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $result=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); echo json_encode(array("result"=>$result,"diagnostics"=>$broker->diagnostics(),"marker"=>file_get_contents($data["marker"])));', array( 'copies' => $copies, 'marker' => $this->parent . '/selected.txt' ) );
		self::assertTrue( $result['result']['loaded'] );
		self::assertSame( array(), $result['result']['diagnostics'] );
		self::assertSame( 9, $result['diagnostics']['candidate_count'] );
		self::assertSame( 'copy-9', $result['marker'] );
	}

	public function testTwoPartWordPressRuntimeVersionSelectsACompatibleCopy(): void
	{
		$copy = $this->copy( 'copy', '0.1.0-beta.2', 'a' );
		$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $result=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"7.0")); echo json_encode(array("result"=>$result,"marker"=>file_exists($data["marker"])));', array( 'copy' => $copy, 'marker' => $this->parent . '/selected.txt' ) );

		self::assertTrue( $result['result']['loaded'] );
		self::assertSame( array(), $result['result']['diagnostics'] );
		self::assertTrue( $result['marker'] );
	}

	public function testEqualVersionDifferentRevisionFailsClosedWithoutLoadingEitherRuntime(): void
	{
		$left = $this->copy( 'left', '0.1.0-beta.2', 'a' );
		$right = $this->copy( 'right', '0.1.0-beta.2', 'b' );
		foreach ( array( array( $left, $right ), array( $right, $left ) ) as $order ) {
			$result = $this->probe( 'require $data["first"] . "/bootstrap.php"; require $data["second"] . "/bootstrap.php"; $result=$GLOBALS["ran_wp_release_updater_v1_broker"]->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); echo json_encode(array("result"=>$result,"marker"=>file_exists($data["marker"])));', array( 'first' => $order[0], 'second' => $order[1], 'marker' => $this->parent . '/selected.txt' ) );
			self::assertFalse( $result['result']['loaded'] );
			self::assertSame( array( 'runtime_selection_inactive' ), array_column( $result['result']['diagnostics'], 'code' ) );
			self::assertFalse( $result['marker'] );
		}
	}

	public function testLegacyConflictInvalidAndLateCopiesRemainPassiveAndOneShot(): void
	{
		$copy = $this->copy( 'copy', '0.1.0-beta.2', 'a' );
		$invalid = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $duplicate=$broker->registerCandidate($data["copy"] . "/runtime-copy.json"); $bad=$broker->registerCandidate($data["copy"] . "/wrong.json"); $first=$broker->activate(array("php_version"=>"8.0.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); $late=$broker->registerCandidate($data["copy"] . "/runtime-copy.json"); $second=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); echo json_encode(array("duplicate"=>$duplicate,"bad"=>$bad,"late"=>$late,"first"=>$first,"second"=>$second));', array( 'copy' => $copy ) );
		self::assertTrue( $invalid['duplicate'] ); self::assertFalse( $invalid['bad'] ); self::assertFalse( $invalid['late'] );
		self::assertFalse( $invalid['first']['loaded'] );
		self::assertContains( 'runtime_selection_inactive', array_column( $invalid['first']['diagnostics'], 'code' ) );
		self::assertContains( 'runtime_selection_inactive', array_column( $invalid['second']['diagnostics'], 'code' ) );

		$legacy = $this->probe( '$GLOBALS["ran_wp_github_release_updater_v1_broker"]=new stdClass(); require $data["copy"] . "/bootstrap.php"; $result=$GLOBALS["ran_wp_release_updater_v1_broker"]->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); echo json_encode($result);', array( 'copy' => $copy ) );
		self::assertFalse( $legacy['loaded'] );
		self::assertSame( array( 'protocol_conflict_inactive' ), array_column( $legacy['diagnostics'], 'code' ) );
	}

	public function testDiagnosticsAreBoundedForRepeatedInvalidAndLateRegistrations(): void
	{
		$copy = $this->copy( 'copy', '0.1.0-beta.2', 'a' );
		$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; for ($index=0; $index<17; ++$index) $broker->registerCandidate($data["copy"] . "/wrong.json"); $invalid=$broker->diagnostics(); $broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); for ($index=0; $index<17; ++$index) $broker->registerCandidate($data["copy"] . "/runtime-copy.json"); echo json_encode(array("invalid"=>$invalid,"late"=>$broker->diagnostics()));', array( 'copy' => $copy ) );
		self::assertCount( 16, $result['invalid']['diagnostics'] );
		self::assertSame( array( 'candidate_invalid' ), array_values( array_unique( array_column( $result['invalid']['diagnostics'], 'code' ) ) ) );
		self::assertSame( $result['invalid']['diagnostics'], $result['late']['diagnostics'] );
	}

	public function testForeignBrokerWithAnIncompleteShapeIsUntouchedAndReturnsAConflictRegistrar(): void
	{
		$copy = $this->copy( 'copy', '0.1.0-beta.2', 'a' );
		$foreign = $this->parent . '/foreign';
		mkdir( $foreign, 0700, true );
		file_put_contents( $foreign . '/bootstrap.php', <<<'PHP'
<?php
namespace RAN\WPReleaseUpdater\V1\Runtime {
	final class RequestBroker { public function protocolVersion(): int { return 1; } }
}
namespace { $GLOBALS['ran_wp_release_updater_v1_broker'] = new \RAN\WPReleaseUpdater\V1\Runtime\RequestBroker(); }
PHP );
		$result = $this->probe( 'require $data["foreign"] . "/bootstrap.php"; $foreign=$GLOBALS["ran_wp_release_updater_v1_broker"]; $registrar=require $data["copy"] . "/bootstrap.php"; $handle=$registrar->plugin("github","/missing.php","acme/example","123"); $handle->register(); echo json_encode(array("unchanged"=>$foreign===$GLOBALS["ran_wp_release_updater_v1_broker"],"protocol"=>$registrar->diagnostics()["protocol_version"],"state"=>$registrar->diagnostics()["state"],"code"=>$handle->status()["code"]));', array( 'copy' => $copy, 'foreign' => $foreign ) );
		self::assertTrue( $result['unchanged'] );
		self::assertSame( 2, $result['protocol'] );
		self::assertSame( 'conflict', $result['state'] );
		self::assertSame( 'protocol_conflict_inactive', $result['code'] );
	}

	public function testThrownRuntimeLoadAndInvalidRuntimeHandoffsFailClosedWithDistinctCodes(): void
	{
		$throwing = $this->copy( 'throwing', '0.1.0-beta.2', 'a' );
		$this->replaceRuntime( $throwing, "<?php\nthrow new RuntimeException('load failure');\n" );
		$thrown = $this->activate( $throwing );
		self::assertFalse( $thrown['loaded'] );
		self::assertSame( 'runtime_load_failed', $thrown['code'] );

		$malformed = $this->copy( 'malformed', '0.1.0-beta.2', 'b' );
		$this->replaceRuntime( $malformed, "<?php\nreturn new class { public function boot(array \$environment, array \$submissions): array { return array(); } };\n" );
		$invalid = $this->activate( $malformed );
		self::assertFalse( $invalid['loaded'] );
		self::assertSame( 'runtime_handoff_invalid', $invalid['code'] );

		$foreignFile = $this->parent . '/foreign-handoff.php';
		file_put_contents( $foreignFile, "<?php class P0ForeignHandoff { public function boot(array \$environment, array \$submissions): array { return array('accepted'=>true,'code'=>'runtime_active','results'=>array()); } public function registerTarget(array \$submission): array { return array(); } }\n" );
		$foreign = $this->copy( 'foreign-handoff', '0.1.0-beta.2', 'c' );
		$this->replaceRuntime( $foreign, "<?php\nrequire_once " . var_export( $foreignFile, true ) . ";\nreturn new P0ForeignHandoff();\n" );
		$wrongOrigin = $this->activate( $foreign );
		self::assertFalse( $wrongOrigin['loaded'] );
		self::assertSame( 'runtime_handoff_invalid', $wrongOrigin['code'] );
	}

	public function testWrongOriginTargetHandleDuringBootInvalidatesTheHandoff(): void
	{
		$foreignFile = $this->parent . '/foreign-target.php';
		file_put_contents( $foreignFile, "<?php class P0ForeignTarget { public function status(): array { return array(); } public function diagnostics(): array { return array(); } public function refresh(): bool { return true; } }\n" );
		$copy = $this->copy( 'foreign-target', '0.1.0-beta.2', 'a' );
		$this->replaceRuntime( $copy, "<?php\nrequire_once " . var_export( $foreignFile, true ) . ";\nreturn new class { public function boot(array \$environment, array \$submissions): array { return array('accepted'=>true,'code'=>'runtime_active','results'=>array_map(static fn(array \$submission): array => array('submission_id'=>\$submission['submission_id'],'accepted'=>true,'code'=>'target_active','target_key'=>str_repeat('a',64),'target_handle'=>new P0ForeignTarget()), \$submissions)); } public function registerTarget(array \$submission): array { return array('submission_id'=>\$submission['submission_id'],'accepted'=>true,'code'=>'target_active','target_key'=>str_repeat('a',64),'target_handle'=>new P0ForeignTarget()); } };\n" );
		$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $broker->registerTarget(array("target_type"=>"plugin","installed_file"=>"/registered.php","provider_code"=>"github","repository_locator"=>"acme/example","repository_identity"=>"123","channel"=>"stable","update_policy"=>"manual","credential_resolver"=>null,"maximum_artifact_bytes"=>52428800)); echo json_encode($broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")));', array( 'copy' => $copy ) );
		self::assertFalse( $result['loaded'] );
		self::assertSame( 'runtime_handoff_invalid', $result['code'] );
	}

	public function testInheritedExtraMethodsAndMagicCallsAreRejected(): void
	{
		foreach ( array(
			'inherited' => 'class P0BaseTarget { public function extra(): void {} } class P0Target extends P0BaseTarget { public function status(): array { return array(); } public function diagnostics(): array { return array(); } public function refresh(): bool { return true; } }',
			'magic' => 'class P0Target { public function status(): array { return array(); } public function diagnostics(): array { return array(); } public function refresh(): bool { return true; } public function __call(string $name, array $arguments): mixed { return null; } }',
		) as $mode => $target ) {
			$copy = $this->copy( 'reject-' . $mode, '0.1.0-beta.2', substr( $mode, 0, 1 ) );
			$this->replaceRuntime( $copy, "<?php\n" . $target . "\nreturn new class { public function boot(array \$environment, array \$submissions): array { return array('accepted' => true, 'code' => 'runtime_active', 'results' => array_map(static fn(array \$submission): array => array('submission_id' => \$submission['submission_id'], 'accepted' => true, 'code' => 'target_active', 'target_key' => str_repeat('a', 64), 'target_handle' => new P0Target()), \$submissions)); } public function registerTarget(array \$submission): array { return array('submission_id' => \$submission['submission_id'], 'accepted' => true, 'code' => 'target_active', 'target_key' => str_repeat('a', 64), 'target_handle' => new P0Target()); } };\n" );
			$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $broker->registerTarget(array("target_type"=>"plugin","installed_file"=>"/registered.php","provider_code"=>"github","repository_locator"=>"acme/example","repository_identity"=>"123","channel"=>"stable","update_policy"=>"manual","credential_resolver"=>null,"maximum_artifact_bytes"=>52428800)); echo json_encode($broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")));', array( 'copy' => $copy ) );
			self::assertFalse( $result['loaded'], $mode );
			self::assertSame( 'runtime_handoff_invalid', $result['code'], $mode );
		}
	}

	public function testImmediateAcceptedResultIncludesDuplicateAndDeferredCompositions(): void
	{
		$copy = $this->copy( 'accepted-deferred', '0.1.0-beta.2', 'd' );
		$this->replaceRuntime( $copy, <<<'PHP'
<?php
return new class {
	private function result(array $submission): array {
		$handle = new class {
			public function status(): array { return array('state' => 'deferred', 'declaration_accepted' => true, 'hooks_registered' => false, 'code' => 'declaration_deferred_operation_started', 'native' => null); }
			public function diagnostics(): array { return array('state' => 'deferred', 'diagnostics' => array()); }
			public function refresh(): bool { return true; }
		};
		return array('submission_id' => $submission['submission_id'], 'accepted' => true, 'code' => 'declaration_deferred_operation_started', 'target_key' => str_repeat('a', 64), 'target_handle' => $handle);
	}
	public function boot(array $environment, array $submissions): array { return array('accepted' => true, 'code' => 'runtime_active', 'results' => array_map(fn(array $submission): array => $this->result($submission), $submissions)); }
	public function registerTarget(array $submission): array { return $this->result($submission); }
};
PHP );
		$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); echo json_encode($broker->registerTarget(array("target_type"=>"plugin","installed_file"=>"/registered.php","provider_code"=>"github","repository_locator"=>"acme/example","repository_identity"=>"123","channel"=>"stable","update_policy"=>"manual","credential_resolver"=>null,"maximum_artifact_bytes"=>52428800)));', array( 'copy' => $copy ) );
		self::assertTrue( $result['accepted'] );
		self::assertSame( 'declaration_deferred_operation_started', $result['code'] );
	}

	public function testSelectedRuntimeHandoffRejectsAllLegacyAndBrokerLivenessChanges(): void
	{
		foreach ( array( 'replacement', 'protocol', 'legacy_broker', 'legacy_marker' ) as $mode ) {
			$result = $this->probe( '$broker=new class { public int $protocol=2; public function protocolVersion(): int { return $this->protocol; } }; $GLOBALS["ran_wp_release_updater_v1_broker"]=$broker; $handoff=require $data["runtime"]; if ("replacement"===$data["mode"]) $GLOBALS["ran_wp_release_updater_v1_broker"]=new stdClass(); if ("protocol"===$data["mode"]) $broker->protocol=1; if ("legacy_broker"===$data["mode"]) $GLOBALS["ran_wp_github_release_updater_v1_broker"]=new stdClass(); if ("legacy_marker"===$data["mode"]) eval("function ran_wp_github_release_updater_v1_has_registered_target(){}"); try { $handoff->boot(array(),array()); echo json_encode(array("failed"=>false)); } catch (Throwable) { echo json_encode(array("failed"=>true)); }', array( 'runtime' => dirname( __DIR__, 2 ) . '/runtime.php', 'mode' => $mode ) );
			self::assertTrue( $result['failed'], $mode );
		}
	}

	public function testInvalidActiveCompositionResultFailsClosedAndTerminatesTheHandle(): void
	{
		$copy = $this->copy( 'invalid-active-result', '0.1.0-beta.2', 'a' );
		$this->replaceRuntime( $copy, "<?php\nreturn new class { public function boot(array \$environment,array \$submissions): array { return array('accepted'=>true,'code'=>'runtime_active','results'=>array()); } public function registerTarget(array \$submission): array { return array(); } };\n" );
		$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $active=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); $registered=$broker->registerTarget(array("target_type"=>"plugin","installed_file"=>"/registered.php","provider_code"=>"github","repository_locator"=>"acme/example","repository_identity"=>"123","channel"=>"stable","update_policy"=>"manual","credential_resolver"=>null,"maximum_artifact_bytes"=>52428800)); $status=$broker->targetStatus($registered["submission_id"]); $again=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); echo json_encode(array("active"=>$active,"registered"=>$registered,"status"=>$status,"again"=>$again));', array( 'copy' => $copy ) );

		self::assertTrue( $result['active']['loaded'] );
		self::assertSame( 'runtime_handoff_invalid', $result['registered']['code'] );
		self::assertSame( 'inactive', $result['status']['state'] );
		self::assertSame( 'runtime_handoff_invalid', $result['status']['code'] );
		self::assertFalse( $result['again']['loaded'] );
		self::assertSame( 'runtime_handoff_invalid', $result['again']['code'] );
	}

	public function testProjectsRuntimeLivenessLossStatusAndDiagnosticsFromAnActiveTarget(): void
	{
		$copy = $this->copy( 'runtime-liveness-lost', '0.1.0-beta.2', 'a' );
		$this->replaceRuntime( $copy, <<<'PHP'
<?php
return new class {
	private function result(array $submission): array {
		$handle = new class {
			public function status(): array { return array('state' => 'active', 'declaration_accepted' => true, 'hooks_registered' => true, 'code' => 'target_active', 'native' => array('candidate_header_version' => null, 'candidate_tag' => null, 'candidate_validation_code' => null, 'candidate_version' => null, 'failure_code' => 'runtime_liveness_lost', 'installed_version' => null, 'last_check' => null, 'offered_version' => null, 'relationship' => null)); }
			public function diagnostics(): array { return array('state' => 'active', 'diagnostics' => array(array('code' => 'runtime_liveness_lost'))); }
			public function refresh(): bool { return true; }
		};
		return array('submission_id' => $submission['submission_id'], 'accepted' => true, 'code' => 'target_active', 'target_key' => str_repeat('a', 64), 'target_handle' => $handle);
	}
	public function boot(array $environment, array $submissions): array { return array('accepted' => true, 'code' => 'runtime_active', 'results' => array_map(fn(array $submission): array => $this->result($submission), $submissions)); }
	public function registerTarget(array $submission): array { return $this->result($submission); }
};
PHP );
		$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); $registered=$broker->registerTarget(array("target_type"=>"plugin","installed_file"=>"/registered.php","provider_code"=>"github","repository_locator"=>"acme/example","repository_identity"=>"123","channel"=>"stable","update_policy"=>"manual","credential_resolver"=>null,"maximum_artifact_bytes"=>52428800)); echo json_encode(array("registered"=>$registered,"status"=>$broker->targetStatus($registered["submission_id"]),"diagnostics"=>$broker->targetDiagnostics($registered["submission_id"])));', array( 'copy' => $copy ) );

		self::assertTrue( $result['registered']['accepted'] );
		self::assertSame( 'runtime_liveness_lost', $result['status']['native']['failure_code'] );
		self::assertSame( array( 'runtime_liveness_lost' ), array_column( $result['diagnostics']['diagnostics'], 'code' ) );
	}

	public function testPostBootstrapCompositionCannotSubmitTheWrongSubmissionId(): void
	{
		$copy = $this->copy( 'wrong-submission', '0.1.0-beta.2', 'a' );
		$this->replaceRuntime( $copy, <<<'PHP'
<?php
return new class {
	public function boot(array $environment, array $submissions): array { return array('accepted' => true, 'code' => 'runtime_active', 'results' => array()); }
	public function registerTarget(array $submission): array { return array('submission_id' => $submission['submission_id'] + 1, 'accepted' => false, 'code' => 'target_composition_failed', 'target_key' => null, 'target_handle' => null); }
};
PHP );
		$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $active=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); $registered=$broker->registerTarget(array("target_type"=>"plugin","installed_file"=>"/registered.php","provider_code"=>"github","repository_locator"=>"acme/example","repository_identity"=>"123","channel"=>"stable","update_policy"=>"manual","credential_resolver"=>null,"maximum_artifact_bytes"=>52428800)); echo json_encode(array("active"=>$active,"registered"=>$registered));', array( 'copy' => $copy ) );
		self::assertTrue( $result['active']['loaded'] );
		self::assertSame( 'runtime_handoff_invalid', $result['registered']['code'] );
	}

	/** @param array<string, string> $data
	 * @return array<string, mixed>
	 */
	private function probe( string $body, array $data ): array
	{
		$file = $this->parent . '/probe-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents( $file, '<?php $data = ' . var_export( $data, true ) . '; ' . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $this->parent ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function copy( string $name, string $version, string $revision ): string
	{
		$root = $this->parent . '/' . $name;
		mkdir( $root . '/src/Runtime', 0700, true );
		foreach ( array( 'bootstrap.php', 'src/Runtime/RequestBroker.php', 'src/Runtime/SelectedRuntimeState.php' ) as $file ) copy( dirname( __DIR__, 2 ) . '/' . $file, $root . '/' . $file );
		file_put_contents( $root . '/runtime.php', "<?php\n// " . $revision . "\nfile_put_contents('" . addslashes( $this->parent . '/selected.txt' ) . "', basename(__DIR__));\nreturn new class { public function boot(array \$environment,array \$submissions): array { unset( \$environment ); return array('accepted'=>true,'code'=>'runtime_active','results'=>array_map(static fn(array \$submission): array => array('submission_id'=>\$submission['submission_id'],'accepted'=>false,'code'=>'target_composition_failed','target_key'=>null,'target_handle'=>null), \$submissions)); } public function registerTarget(array \$submission): array { return array('submission_id'=>\$submission['submission_id'],'accepted'=>false,'code'=>'target_composition_failed','target_key'=>null,'target_handle'=>null); } };\n" );
		file_put_contents( $root . '/runtime-copy.json', json_encode( array( 'package_revision' => $this->identity( $root ), 'package_version' => $version, 'php_floor' => '8.2.0', 'runtime_file' => 'runtime.php', 'runtime_protocol' => 2, 'wordpress_floor' => '6.5.0' ), JSON_THROW_ON_ERROR ) );
		return $root;
	}

	/** Replace a copied runtime and bind its manifest to its new production bytes. */
	private function replaceRuntime( string $root, string $source ): void
	{
		file_put_contents( $root . '/runtime.php', $source );
		$copy = json_decode( (string) file_get_contents( $root . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );
		$copy['package_revision'] = $this->identity( $root );
		file_put_contents( $root . '/runtime-copy.json', json_encode( $copy, JSON_THROW_ON_ERROR ) );
	}

	/** @return array<string,mixed> */
	private function activate( string $copy ): array
	{
		return $this->probe( 'require $data["copy"] . "/bootstrap.php"; echo json_encode($GLOBALS["ran_wp_release_updater_v1_broker"]->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")));', array( 'copy' => $copy ) );
	}

	private function identity( string $root ): string
	{
		$files = array( 'bootstrap.php', 'runtime.php' );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			}
		}
		sort( $files, SORT_STRING );
		$payload = '';
		foreach ( $files as $file ) {
			$payload .= $file . "\0" . hash_file( 'sha256', $root . '/' . $file ) . "\n";
		}

		return hash( 'sha256', $payload );
	}

	private function remove( string $path ): void
	{
		if ( ! is_dir( $path ) ) return;
		foreach ( scandir( $path ) ?: array() as $name ) {
			if ( '.' === $name || '..' === $name ) continue;
			$child = $path . '/' . $name;
			is_dir( $child ) && ! is_link( $child ) ? $this->remove( $child ) : unlink( $child );
		}
		rmdir( $path );
	}
}
