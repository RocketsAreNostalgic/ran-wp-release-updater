<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class SelectedRuntimeActivationBoundaryTest extends TestCase
{
	public function testCreatorSchedulesOnceBeforeTheActivationBoundaryAndActivatesAtMaximumPriority(): void
	{
		$result = $this->probe( <<<'PHP'
$registrar = require $data['bootstrap'];
$sameBrokerRegistrar = require $data['bootstrap'];
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$scheduled = isset($GLOBALS['wp_filter']['after_setup_theme']) && $GLOBALS['wp_filter']['after_setup_theme'] instanceof WP_Hook ? count($GLOBALS['wp_filter']['after_setup_theme']->callbacks[PHP_INT_MAX] ?? array()) : 0;
$before = $broker->diagnostics()['state'];
do_action('after_setup_theme');
echo json_encode(array('registrar' => is_object($registrar) && is_object($sameBrokerRegistrar), 'scheduled' => $scheduled, 'before' => $before, 'state' => $broker->diagnostics()['state'], 'code' => $broker->diagnostics()['diagnostics']));
PHP );

		self::assertTrue( $result['registrar'] );
		self::assertSame( 1, $result['scheduled'] );
		self::assertSame( 'collecting', $result['before'] );
		self::assertSame( 'active', $result['state'] );
		self::assertSame( array(), $result['code'] );
	}

	public function testSeparateCleanProtocolTwoRequestSelectsOnlyItsOwnBroker(): void
	{
		$result = $this->probe( <<<'PHP'
$registrar = require $data['bootstrap'];
do_action('after_setup_theme');
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
echo json_encode(array('protocol' => $broker->protocolVersion(), 'state' => $registrar->diagnostics()['state'], 'candidates' => $broker->diagnostics()['candidate_count'], 'legacy' => isset($GLOBALS['ran_wp_github_release_updater_v1_broker'])));
PHP );

		self::assertSame( 2, $result['protocol'] );
		self::assertSame( 'active', $result['state'] );
		self::assertSame( 1, $result['candidates'] );
		self::assertFalse( $result['legacy'] );
	}

	public function testBootstrapDuringALowerPrioritySchedulesForThisHookRun(): void
	{
		$result = $this->probe( <<<'PHP'
add_action('after_setup_theme', static function () use ($data): void { $GLOBALS['p02_registrar'] = require $data['bootstrap']; }, 10, 0);
do_action('after_setup_theme');
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
echo json_encode(array('registrar' => is_object($GLOBALS['p02_registrar'] ?? null), 'state' => $broker->diagnostics()['state'], 'diagnostics' => $broker->diagnostics()['diagnostics']));
PHP );

		self::assertTrue( $result['registrar'] );
		self::assertSame( 'active', $result['state'] );
		self::assertSame( array(), $result['diagnostics'] );
	}

	/** @dataProvider missedBoundaryCases */
	public function testMissedOrMalformedActivationBoundariesFailClosedWithoutScheduling( string $scenario ): void
	{
		$result = $this->probe( match ( $scenario ) {
			'current_maximum' => <<<'PHP'
add_action('after_setup_theme', static function () use ($data): void { $GLOBALS['p02_registrar'] = require $data['bootstrap']; }, PHP_INT_MAX, 0);
do_action('after_setup_theme');
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$callbacks = $GLOBALS['wp_filter']['after_setup_theme']->callbacks[PHP_INT_MAX] ?? array();
echo json_encode(array('state' => $broker->diagnostics()['state'], 'diagnostics' => $broker->diagnostics()['diagnostics'], 'hooks' => 1 < count($callbacks)));
PHP,
			'completed' => <<<'PHP'
do_action('after_setup_theme');
$registrar = require $data['bootstrap'];
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
echo json_encode(array('state' => $broker->diagnostics()['state'], 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'hooks' => isset($GLOBALS['wp_filter']['after_setup_theme'])));
PHP,
			'malformed' => <<<'PHP'
$GLOBALS['wp_current_filter'][] = 'after_setup_theme';
$hook = new stdClass();
$GLOBALS['wp_filter']['after_setup_theme'] = $hook;
$registrar = require $data['bootstrap'];
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
echo json_encode(array('state' => $broker->diagnostics()['state'], 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'hooks' => $hook !== $GLOBALS['wp_filter']['after_setup_theme']));
PHP,
			'non_integer_priority' => <<<'PHP'
final class P02NonIntegerHook { public function current_priority(): string { return '10'; } }
$GLOBALS['wp_current_filter'][] = 'after_setup_theme';
$GLOBALS['wp_filter']['after_setup_theme'] = new P02NonIntegerHook();
$registrar = require $data['bootstrap'];
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
echo json_encode(array('state' => $broker->diagnostics()['state'], 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'hooks' => false));
PHP,
			'throwing_priority' => <<<'PHP'
final class P02ThrowingPriorityHook { public function current_priority(): int { throw new RuntimeException('priority'); } }
$GLOBALS['wp_current_filter'][] = 'after_setup_theme';
$GLOBALS['wp_filter']['after_setup_theme'] = new P02ThrowingPriorityHook();
$registrar = require $data['bootstrap'];
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
echo json_encode(array('state' => $broker->diagnostics()['state'], 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'hooks' => false));
PHP,
		} );

		self::assertSame( 'inactive', $result['state'], $scenario );
		self::assertSame( array( array( 'code' => 'activation_boundary_missed' ) ), $result['diagnostics'], $scenario );
		self::assertFalse( $result['hooks'], $scenario );
	}

	/** @return array<string,array{string}> */
	public static function missedBoundaryCases(): array
	{
		return array(
			'current maximum priority' => array( 'current_maximum' ),
			'completed hook' => array( 'completed' ),
			'malformed running hook' => array( 'malformed' ),
			'non-integer running priority' => array( 'non_integer_priority' ),
			'throwing running priority' => array( 'throwing_priority' ),
		);
	}

	public function testCompatibleExistingBrokerDoesNotScheduleAnotherActivationCallback(): void
	{
		$result = $this->probe( <<<'PHP'
require_once dirname($data['bootstrap']) . '/src/Runtime/RequestBroker.php';
$GLOBALS['ran_wp_release_updater_v1_broker'] = new RAN\WPReleaseUpdater\V1\Runtime\RequestBroker();
$registrar = require $data['bootstrap'];
echo json_encode(array('registrar' => is_object($registrar), 'hooks' => isset($GLOBALS['wp_filter']['after_setup_theme'])));
PHP );

		self::assertTrue( $result['registrar'] );
		self::assertFalse( $result['hooks'] );
	}

	public function testMissedBoundaryMakesTheRegistrarHandleInertWithAnExactDiagnostic(): void
	{
		$result = $this->probe( <<<'PHP'
do_action('after_setup_theme');
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', '/missing.php', 'acme/example', '123');
$registered = $handle->register();
echo json_encode(array('registered' => $registered, 'status' => $handle->status(), 'diagnostics' => $handle->diagnostics()));
PHP );

		self::assertFalse( $result['registered'] );
		self::assertSame( 'inactive', $result['status']['state'] );
		self::assertFalse( $result['status']['declaration_accepted'] );
		self::assertFalse( $result['status']['hooks_registered'] );
		self::assertSame( 'activation_boundary_missed', $result['status']['code'] );
		self::assertSame( array( array( 'code' => 'activation_boundary_missed' ) ), $result['diagnostics']['diagnostics'] );
	}

	/** @dataProvider invalidWordPressVersions */
	public function testMissingOrMalformedWordPressVersionFailsAtScheduledActivation( mixed $version ): void
	{
		$result = $this->probe( <<<'PHP'
if ('missing' === $data['version']) { unset($GLOBALS['wp_version']); } else { $GLOBALS['wp_version'] = $data['version']; }
$registrar = require $data['bootstrap'];
$before = $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['state'];
do_action('after_setup_theme');
echo json_encode(array('before' => $before, 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'state' => $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['state']));
PHP, array( 'version' => $version ) );

		self::assertSame( 'collecting', $result['before'] );
		self::assertSame( 'inactive', $result['state'] );
		self::assertSame( array( array( 'code' => 'runtime_environment_invalid' ) ), $result['diagnostics'] );
	}

	/** @return array<string,array{mixed}> */
	public static function invalidWordPressVersions(): array
	{
		return array(
			'missing' => array( 'missing' ),
			'array' => array( array() ),
		);
	}

	public function testRequestBrokerPublicAbiHasTheFrozenEightMethods(): void
	{
		$result = $this->probe( <<<'PHP'
require_once dirname($data['bootstrap']) . '/src/Runtime/RequestBroker.php';
$methods = get_class_methods(RAN\WPReleaseUpdater\V1\Runtime\RequestBroker::class);
$methods = array_values(array_filter($methods, static fn(string $method): bool => '__construct' !== $method));
sort($methods, SORT_STRING);
echo json_encode($methods);
PHP );

		self::assertSame( array( 'activate', 'diagnostics', 'protocolVersion', 'refreshTarget', 'registerCandidate', 'registerTarget', 'targetDiagnostics', 'targetStatus' ), $result );
	}

	public function testBootstrapStateStaysWithTheFirstProtocolCellWhileTheSelectedRuntimeComesFromTheWinningCopy(): void
	{
		$first = $this->packageCopy( 'first', '0.1.0-beta.1' );
		$winner = $this->packageCopy( 'winner', '0.1.0-beta.2' );
		$result = $this->probe( <<<'PHP'
require $data['first'] . '/bootstrap.php';
require $data['winner'] . '/bootstrap.php';
do_action('after_setup_theme');
$state = new ReflectionClass(RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState::class);
$runtime = new ReflectionClass(RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri::class);
echo json_encode(array('state_file' => $state->getFileName(), 'runtime_file' => $runtime->getFileName(), 'broker_state' => $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['state']));
PHP, array( 'first' => $first, 'winner' => $winner ) );

		self::assertSame( $first . '/src/Runtime/SelectedRuntimeState.php', $result['state_file'] );
		self::assertSame( $winner . '/src/Contract/CanonicalUpdateUri.php', $result['runtime_file'] );
		self::assertSame( 'active', $result['broker_state'] );
	}

	public function testIncompatibleExistingBrokerIsUntouchedAndReturnsAnInactiveConflictRegistrar(): void
	{
		$result = $this->probe( <<<'PHP'
$existing = new stdClass();
$GLOBALS['ran_wp_release_updater_v1_broker'] = $existing;
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', '/missing.php', 'acme/example', '123');
$handle->register();
echo json_encode(array('unchanged' => $existing === $GLOBALS['ran_wp_release_updater_v1_broker'], 'protocol' => $registrar->diagnostics()['protocol_version'], 'state' => $registrar->diagnostics()['state'], 'code' => $handle->status()['code'], 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'hooks' => isset($GLOBALS['wp_filter']['after_setup_theme'])));
PHP );

		self::assertTrue( $result['unchanged'] );
		self::assertSame( 2, $result['protocol'] );
		self::assertSame( 'conflict', $result['state'] );
		self::assertSame( 'protocol_conflict_inactive', $result['code'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['diagnostics'] );
		self::assertFalse( $result['hooks'] );
	}

	public function testLookalikeBrokerWithTheCompleteAbiIsRejectedFailClosed(): void
	{
		$result = $this->probe( <<<'PHP'
$existing = new class {
	public function protocolVersion(): int { return 2; }
	public function registerCandidate(string $copyFile): bool { return true; }
	public function activate(array $environment): array { return array(); }
	public function registerTarget(array $declaration): array { return array(); }
	public function targetStatus(int $id): array { return array(); }
	public function targetDiagnostics(int $id): array { return array(); }
	public function refreshTarget(int $id): bool { return true; }
	public function diagnostics(): array { return array('state' => 'active'); }
};
$GLOBALS['ran_wp_release_updater_v1_broker'] = $existing;
$registrar = require $data['bootstrap'];
$handoff = require dirname($data['bootstrap']) . '/runtime.php';
try { $handoff->boot(array(), array()); $runtime_failed = false; } catch (Throwable) { $runtime_failed = true; }
echo json_encode(array('unchanged' => $existing === $GLOBALS['ran_wp_release_updater_v1_broker'], 'state' => $registrar->diagnostics()['state'], 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'hooks' => isset($GLOBALS['wp_filter']['after_setup_theme']), 'runtime_failed' => $runtime_failed));
PHP );

		self::assertTrue( $result['unchanged'] );
		self::assertSame( 'conflict', $result['state'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['diagnostics'] );
		self::assertFalse( $result['hooks'] );
		self::assertTrue( $result['runtime_failed'] );
	}

	public function testForeignProtocolFirstStaysUntouchedAndScheduledReplacementTerminalizesTheQueuedHandle(): void
	{
		$foreign = $this->probe( <<<'PHP'
final class P02ForeignProtocol { public function protocolVersion(): int { return 1; } }
$foreign = new P02ForeignProtocol();
$GLOBALS['ran_wp_release_updater_v1_broker'] = $foreign;
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', '/foreign.php', 'acme/foreign', '1');
echo json_encode(array('same' => $foreign === $GLOBALS['ran_wp_release_updater_v1_broker'], 'registered' => $handle->register(), 'status' => $handle->status(), 'hooks' => isset($GLOBALS['wp_filter']['after_setup_theme'])));
PHP );
		self::assertTrue( $foreign['same'] );
		self::assertFalse( $foreign['registered'] );
		self::assertSame( 'protocol_conflict_inactive', $foreign['status']['code'] );
		self::assertFalse( $foreign['hooks'] );

		$replacement = $this->probe( <<<'PHP'
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', '/queued.php', 'acme/queued', '2');
$registered = $handle->register();
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$GLOBALS['ran_wp_release_updater_v1_broker'] = new stdClass();
do_action('after_setup_theme');
echo json_encode(array('registered' => $registered, 'state' => $broker->diagnostics()['state'], 'status' => $handle->status(), 'diagnostics' => $handle->diagnostics(), 'again' => $handle->register()));
PHP );
		self::assertTrue( $replacement['registered'] );
		self::assertSame( 'conflict', $replacement['state'] );
		self::assertSame( 'protocol_conflict_inactive', $replacement['status']['code'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $replacement['diagnostics']['diagnostics'] );
		self::assertFalse( $replacement['again'] );
	}

	/** @return array<string,mixed> */
	private function probe( string $body, array $extra = array() ): array
	{
		$root = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/' . bin2hex( random_bytes( 6 ) );
		mkdir( $root, 0700, true );
		$file = $root . '/probe.php';
		$data = array_merge( array( 'bootstrap' => dirname( __DIR__, 2 ) . '/bootstrap.php', 'hooks' => dirname( __DIR__ ) . '/Support/WordPressHookFixture.php' ), $extra );
		$prefix = '<?php require ' . var_export( $data['hooks'], true ) . '; $GLOBALS["wp_version"]="6.8.0"; $data=' . var_export( $data, true ) . ';';
		file_put_contents( $file, $prefix . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function packageCopy( string $name, string $version ): string
	{
		$root = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/' . $name . '-' . bin2hex( random_bytes( 6 ) );
		mkdir( $root, 0700, true );
		copy( dirname( __DIR__, 2 ) . '/bootstrap.php', $root . '/bootstrap.php' );
		copy( dirname( __DIR__, 2 ) . '/runtime.php', $root . '/runtime.php' );
		$this->copyDirectory( dirname( __DIR__, 2 ) . '/src', $root . '/src' );
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
		file_put_contents( $root . '/runtime-copy.json', json_encode( array( 'package_revision' => hash( 'sha256', $payload ), 'package_version' => $version, 'php_floor' => '8.2.0', 'runtime_file' => 'runtime.php', 'runtime_protocol' => 2, 'wordpress_floor' => '6.5.0' ), JSON_THROW_ON_ERROR ) );
		return $root;
	}

	private function copyDirectory( string $source, string $destination ): void
	{
		mkdir( $destination, 0700, true );
		foreach ( scandir( $source ) ?: array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$from = $source . '/' . $name;
			$to = $destination . '/' . $name;
			is_dir( $from ) ? $this->copyDirectory( $from, $to ) : copy( $from, $to );
		}
	}
}
