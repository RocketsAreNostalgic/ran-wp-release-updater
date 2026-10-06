<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing Composer development namespace; this allowance does not apply to production declarations.
namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class SelectedRuntimeActivationBoundaryTest extends TestCase {

	public function test_creator_schedules_once_before_the_activation_boundary_and_activates_at_maximum_priority(): void {
		$result = $this->probe(
			<<<'PHP'
$registrar = require $data['bootstrap'];
$sameBrokerRegistrar = require $data['bootstrap'];
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$scheduled = isset($GLOBALS['wp_filter']['after_setup_theme']) && $GLOBALS['wp_filter']['after_setup_theme'] instanceof WP_Hook ? count($GLOBALS['wp_filter']['after_setup_theme']->callbacks[PHP_INT_MAX] ?? array()) : 0;
$before = $broker->diagnostics()['state'];
do_action('after_setup_theme');
echo json_encode(array('registrar' => is_object($registrar) && is_object($sameBrokerRegistrar), 'scheduled' => $scheduled, 'before' => $before, 'state' => $broker->diagnostics()['state'], 'code' => $broker->diagnostics()['diagnostics']));
PHP
		);

		self::assertTrue( $result['registrar'] );
		self::assertSame( 1, $result['scheduled'] );
		self::assertSame( 'collecting', $result['before'] );
		self::assertSame( 'active', $result['state'] );
		self::assertSame( array(), $result['code'] );
	}

	public function test_separate_clean_protocol_four_request_selects_only_its_own_broker(): void {
		$result = $this->probe(
			<<<'PHP'
$registrar = require $data['bootstrap'];
do_action('after_setup_theme');
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
echo json_encode(array('protocol' => $broker->protocol_version(), 'state' => $registrar->diagnostics()['state'], 'candidates' => $broker->diagnostics()['candidate_count']));
PHP
		);

		self::assertSame( 5, $result['protocol'] );
		self::assertSame( 'active', $result['state'] );
		self::assertSame( 1, $result['candidates'] );
	}

	public function test_bootstrap_during_a_lower_priority_schedules_for_this_hook_run(): void {
		$result = $this->probe(
			<<<'PHP'
add_action('after_setup_theme', static function () use ($data): void { $GLOBALS['p02_registrar'] = require $data['bootstrap']; }, 10, 0);
do_action('after_setup_theme');
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
echo json_encode(array('registrar' => is_object($GLOBALS['p02_registrar'] ?? null), 'state' => $broker->diagnostics()['state'], 'diagnostics' => $broker->diagnostics()['diagnostics']));
PHP
		);

		self::assertTrue( $result['registrar'] );
		self::assertSame( 'active', $result['state'] );
		self::assertSame( array(), $result['diagnostics'] );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'missed_boundary_cases' )]
	public function test_missed_or_malformed_activation_boundaries_fail_closed_without_scheduling( string $scenario ): void {
		$result = $this->probe(
			match ( $scenario ) {
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
			}
		);

		self::assertSame( 'inactive', $result['state'], $scenario );
		self::assertSame( array( array( 'code' => 'activation_boundary_missed' ) ), $result['diagnostics'], $scenario );
		self::assertFalse( $result['hooks'], $scenario );
	}

	/** @return array<string,array{string}> */
	public static function missed_boundary_cases(): array {
		return array(
			'current maximum priority'     => array( 'current_maximum' ),
			'completed hook'               => array( 'completed' ),
			'malformed running hook'       => array( 'malformed' ),
			'non-integer running priority' => array( 'non_integer_priority' ),
			'throwing running priority'    => array( 'throwing_priority' ),
		);
	}

	public function test_compatible_existing_broker_does_not_schedule_another_activation_callback(): void {
		$result = $this->probe(
			<<<'PHP'
require_once dirname($data['bootstrap']) . '/src/Runtime/RequestBroker.php';
$GLOBALS['ran_wp_release_updater_v1_broker'] = new RAN\WPReleaseUpdater\V1\Runtime\RequestBroker();
$registrar = require $data['bootstrap'];
echo json_encode(array('registrar' => is_object($registrar), 'hooks' => isset($GLOBALS['wp_filter']['after_setup_theme'])));
PHP
		);

		self::assertTrue( $result['registrar'] );
		self::assertFalse( $result['hooks'] );
	}

	public function test_missed_boundary_makes_the_registrar_handle_inert_with_an_exact_diagnostic(): void {
		$result = $this->probe(
			<<<'PHP'
do_action('after_setup_theme');
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', '/missing.php', 'acme/example', '123');
$registered = $handle->register();
echo json_encode(array('registered' => $registered, 'status' => $handle->status(), 'diagnostics' => $handle->diagnostics()));
PHP
		);

		self::assertFalse( $result['registered'] );
		self::assertSame( 'inactive', $result['status']['state'] );
		self::assertFalse( $result['status']['declaration_accepted'] );
		self::assertFalse( $result['status']['hooks_registered'] );
		self::assertSame( 'activation_boundary_missed', $result['status']['code'] );
		self::assertSame( array( array( 'code' => 'activation_boundary_missed' ) ), $result['diagnostics']['diagnostics'] );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_word_press_versions' )]
	public function test_missing_or_malformed_word_press_version_fails_at_scheduled_activation( mixed $version ): void {
		$result = $this->probe(
			<<<'PHP'
if ('missing' === $data['version']) { unset($GLOBALS['wp_version']); } else { $GLOBALS['wp_version'] = $data['version']; }
$registrar = require $data['bootstrap'];
$before = $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['state'];
do_action('after_setup_theme');
echo json_encode(array('before' => $before, 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'state' => $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['state']));
PHP,
			array( 'version' => $version )
		);

		self::assertSame( 'collecting', $result['before'] );
		self::assertSame( 'inactive', $result['state'] );
		self::assertSame( array( array( 'code' => 'runtime_environment_invalid' ) ), $result['diagnostics'] );
	}

	/** @return array<string,array{mixed}> */
	public static function invalid_word_press_versions(): array {
		return array(
			'missing'                         => array( 'missing' ),
			'array'                           => array( array() ),
			'unrecognized development suffix' => array( '6.9-beta1-60740-unsafe' ),
			'overlong'                        => array( str_repeat( '1', 101 ) ),
		);
	}

	public function test_word_press_version_normalizer_accepts_only_supported_core_and_existing_sem_ver_forms(): void {
		require_once dirname( __DIR__, 2 ) . '/src/Runtime/RequestBroker.php';
		require_once dirname( __DIR__, 2 ) . '/src/Runtime/SelectedRuntimeState.php';
		foreach ( array(
			'6.9-beta1-60740'     => '6.9.0-beta.1',
			'6.9-beta1'           => '6.9.0-beta.1',
			'6.9-alpha-60740-src' => '6.9.0-alpha.0',
			'6.9-RC1-src'         => '6.9.0-rc.1',
			'6.9-rc1'             => '6.9.0-rc.1',
			'6.9.1-rc1-src'       => '6.9.1-rc.1',
			'6.9.3-src'           => '6.9.3-src',
			'6.9.0-beta.1'        => '6.9.0-beta.1',
		) as $input => $expected ) {
			self::assertSame( $expected, \RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState::normalize_word_press_version( $input ), $input );
		}
		self::assertNull( \RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState::normalize_word_press_version( '6.9-beta1-60740-unsafe' ) );
		self::assertNull( \RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState::normalize_word_press_version( str_repeat( '1', 101 ) ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'supported_word_press_development_versions' )]
	public function test_scheduled_activation_normalizes_supported_word_press_development_versions( string $version ): void {
		$result = $this->probe(
			<<<'PHP'
$GLOBALS['wp_version'] = $data['version'];
$registrar = require $data['bootstrap'];
do_action('after_setup_theme');
echo json_encode(array('diagnostics' => $registrar->diagnostics()['diagnostics'], 'state' => $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['state']));
PHP,
			array( 'version' => $version )
		);

		self::assertSame( 'active', $result['state'], $version );
		self::assertSame( array(), $result['diagnostics'], $version );
	}

	/** @return array<string,array{string}> */
	public static function supported_word_press_development_versions(): array {
		return array(
			'beta revision'                  => array( '6.9-beta1-60740' ),
			'beta package'                   => array( '6.9-beta1' ),
			'alpha source build'             => array( '6.9-alpha-60740-src' ),
			'release candidate source build' => array( '6.9-RC1-src' ),
			'full SemVer prerelease'         => array( '6.9.0-beta.1' ),
		);
	}

	public function test_scheduled_activation_keeps_word_press_release_candidate_below_the_stable_floor(): void {
		$result = $this->probe(
			<<<'PHP'
$GLOBALS['wp_version'] = '6.5-RC1-60740';
$registrar = require $data['bootstrap'];
do_action('after_setup_theme');
echo json_encode(array('diagnostics' => $registrar->diagnostics()['diagnostics'], 'state' => $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['state']));
PHP
		);

		self::assertSame( 'inactive', $result['state'] );
		self::assertSame( array( array( 'code' => 'runtime_selection_inactive' ) ), $result['diagnostics'] );
	}

	public function test_scheduled_activation_registers_a_target_on_a_word_press_beta_build(): void {
		$result = $this->probe(
			<<<'PHP'
$plugins = dirname(__FILE__) . '/plugins';
mkdir($plugins . '/example', 0700, true);
define('WP_PLUGIN_DIR', $plugins);
$file = $plugins . '/example/example.php';
file_put_contents($file, "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/owner/example\n*/\n");
$GLOBALS['wpdb'] = new stdClass();
$GLOBALS['wp_version'] = '6.9-beta1-60740';
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', $file, 'owner/example', '123');
$queued = $handle->register();
do_action('after_setup_theme');
echo json_encode(array('queued' => $queued, 'state' => $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['state'], 'status' => $handle->status()));
PHP
		);

		self::assertTrue( $result['queued'] );
		self::assertSame( 'active', $result['state'] );
		self::assertSame( 'target_active', $result['status']['code'] );
	}

	public function test_request_broker_public_abi_has_current_methods(): void {
		$result = $this->probe(
			<<<'PHP'
require_once dirname($data['bootstrap']) . '/src/Runtime/RequestBroker.php';
$methods = get_class_methods(RAN\WPReleaseUpdater\V1\Runtime\RequestBroker::class);
$methods = array_values(array_filter($methods, static fn(string $method): bool => '__construct' !== $method));
sort($methods, SORT_STRING);
echo json_encode($methods);
PHP
		);

		self::assertSame( array( 'activate', 'diagnostics', 'protocol_version', 'refresh_target', 'register_candidate', 'register_target', 'release_source', 'target_diagnostics', 'target_status' ), $result );
	}

	public function test_bootstrap_state_stays_with_the_first_protocol_cell_while_the_selected_runtime_comes_from_the_winning_copy(): void {
		$first  = $this->package_copy( 'first', '0.1.0-beta.1' );
		$winner = $this->package_copy( 'winner', '0.1.0-beta.2' );
		$result = $this->probe(
			<<<'PHP'
require $data['first'] . '/bootstrap.php';
require $data['winner'] . '/bootstrap.php';
do_action('after_setup_theme');
$state = new ReflectionClass(RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState::class);
$runtime = new ReflectionClass(RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri::class);
echo json_encode(array('state_file' => $state->getFileName(), 'runtime_file' => $runtime->getFileName(), 'broker_state' => $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['state']));
PHP,
			array(
				'first'  => $first,
				'winner' => $winner,
			)
		);

		self::assertSame( $first . '/src/Runtime/SelectedRuntimeState.php', $result['state_file'] );
		self::assertSame( $winner . '/src/Contract/CanonicalUpdateUri.php', $result['runtime_file'] );
		self::assertSame( 'active', $result['broker_state'] );
	}

	public function test_incompatible_existing_broker_is_untouched_and_returns_an_inactive_conflict_registrar(): void {
		$result = $this->probe(
			<<<'PHP'
$existing = new stdClass();
$GLOBALS['ran_wp_release_updater_v1_broker'] = $existing;
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', '/missing.php', 'acme/example', '123');
$handle->register();
echo json_encode(array('unchanged' => $existing === $GLOBALS['ran_wp_release_updater_v1_broker'], 'protocol' => $registrar->diagnostics()['protocol_version'], 'state' => $registrar->diagnostics()['state'], 'code' => $handle->status()['code'], 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'hooks' => isset($GLOBALS['wp_filter']['after_setup_theme'])));
PHP
		);

		self::assertTrue( $result['unchanged'] );
		self::assertSame( 5, $result['protocol'] );
		self::assertSame( 'conflict', $result['state'] );
		self::assertSame( 'protocol_conflict_inactive', $result['code'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['diagnostics'] );
		self::assertFalse( $result['hooks'] );
	}

	public function test_lookalike_broker_with_the_complete_abi_is_rejected_fail_closed(): void {
		$result = $this->probe(
			<<<'PHP'
$foreignRoot = dirname(__FILE__) . '/foreign-package';
mkdir($foreignRoot . '/src/Runtime', 0700, true);
file_put_contents($foreignRoot . '/src/Runtime/RequestBroker.php', <<<'FOREIGN'
<?php
namespace RAN\WPReleaseUpdater\V1\Runtime;
final class RequestBroker {
	private function called(string $method): void { $GLOBALS['p02_foreign_calls'][$method] = ($GLOBALS['p02_foreign_calls'][$method] ?? 0) + 1; }
	public function protocol_version(): int { $this->called(__FUNCTION__); return 3; }
	public function register_candidate(string $copyFile): bool { $this->called(__FUNCTION__); return true; }
	public function activate(array $environment): array { $this->called(__FUNCTION__); return array(); }
	public function register_target(array $declaration): array { $this->called(__FUNCTION__); return array(); }
	public function release_source(array $declaration): array { $this->called(__FUNCTION__); return array(); }
	public function target_status(int $id): array { $this->called(__FUNCTION__); return array(); }
	public function target_diagnostics(int $id): array { $this->called(__FUNCTION__); return array(); }
	public function refresh_target(int $id): bool { $this->called(__FUNCTION__); return true; }
	public function diagnostics(): array { $this->called(__FUNCTION__); return array('state' => 'active'); }
}
FOREIGN
);
require $foreignRoot . '/src/Runtime/RequestBroker.php';
$existing = new RAN\WPReleaseUpdater\V1\Runtime\RequestBroker();
$GLOBALS['ran_wp_release_updater_v1_broker'] = $existing;
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', '/missing.php', 'acme/example', '123');
$registered = $handle->register();
$handoff = require dirname($data['bootstrap']) . '/runtime.php';
try { $handoff->boot(array(), array()); $runtime_failed = false; } catch (Throwable) { $runtime_failed = true; }
echo json_encode(array('unchanged' => $existing === $GLOBALS['ran_wp_release_updater_v1_broker'], 'registered' => $registered, 'state' => $registrar->diagnostics()['state'], 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'hooks' => isset($GLOBALS['wp_filter']['after_setup_theme']), 'runtime_failed' => $runtime_failed, 'foreign_calls' => $GLOBALS['p02_foreign_calls'] ?? array()));
PHP
		);

		self::assertTrue( $result['unchanged'] );
		self::assertFalse( $result['registered'] );
		self::assertSame( 'conflict', $result['state'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['diagnostics'] );
		self::assertFalse( $result['hooks'] );
		self::assertTrue( $result['runtime_failed'] );
		self::assertSame( array(), $result['foreign_calls'] );
	}

	public function test_preloaded_foreign_broker_class_cannot_create_the_shared_broker(): void {
		$result = $this->probe(
			<<<'PHP'
$foreign = dirname(__FILE__) . '/foreign-broker-class.php';
file_put_contents($foreign, "<?php\nnamespace RAN\\WPReleaseUpdater\\V1\\Runtime;\nfinal class RequestBroker {}\n");
require $foreign;
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', '/missing.php', 'acme/example', '123');
$registered = $handle->register();
try { $handoff = require dirname($data['bootstrap']) . '/runtime.php'; $handoff->boot(array(), array()); $runtime_failed = false; } catch (Throwable) { $runtime_failed = true; }
echo json_encode(array('broker_created' => array_key_exists('ran_wp_release_updater_v1_broker', $GLOBALS), 'registered' => $registered, 'state' => $registrar->diagnostics()['state'], 'code' => $handle->status()['code'], 'diagnostics' => $registrar->diagnostics()['diagnostics'], 'hooks' => isset($GLOBALS['wp_filter']['after_setup_theme']), 'runtime_failed' => $runtime_failed));
PHP
		);

		self::assertFalse( $result['broker_created'] );
		self::assertFalse( $result['registered'] );
		self::assertSame( 'conflict', $result['state'] );
		self::assertSame( 'protocol_conflict_inactive', $result['code'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['diagnostics'] );
		self::assertFalse( $result['hooks'] );
		self::assertTrue( $result['runtime_failed'] );
	}

	public function test_foreign_protocol_first_stays_untouched_and_scheduled_replacement_terminalizes_the_queued_handle(): void {
		$foreign = $this->probe(
			<<<'PHP'
final class P02ForeignProtocol { public function protocol_version(): int { return 1; } }
$foreign = new P02ForeignProtocol();
$GLOBALS['ran_wp_release_updater_v1_broker'] = $foreign;
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', '/foreign.php', 'acme/foreign', '1');
echo json_encode(array('same' => $foreign === $GLOBALS['ran_wp_release_updater_v1_broker'], 'registered' => $handle->register(), 'status' => $handle->status(), 'hooks' => isset($GLOBALS['wp_filter']['after_setup_theme'])));
PHP
		);
		self::assertTrue( $foreign['same'] );
		self::assertFalse( $foreign['registered'] );
		self::assertSame( 'protocol_conflict_inactive', $foreign['status']['code'] );
		self::assertFalse( $foreign['hooks'] );

		$replacement = $this->probe(
			<<<'PHP'
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin('github', '/queued.php', 'acme/queued', '2');
$registered = $handle->register();
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$GLOBALS['ran_wp_release_updater_v1_broker'] = new stdClass();
do_action('after_setup_theme');
echo json_encode(array('registered' => $registered, 'state' => $broker->diagnostics()['state'], 'status' => $handle->status(), 'diagnostics' => $handle->diagnostics(), 'again' => $handle->register()));
PHP
		);
		self::assertTrue( $replacement['registered'] );
		self::assertSame( 'conflict', $replacement['state'] );
		self::assertSame( 'protocol_conflict_inactive', $replacement['status']['code'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $replacement['diagnostics']['diagnostics'] );
		self::assertFalse( $replacement['again'] );
	}

	/** @return array<string,mixed> */
	private function probe( string $body, array $extra = array() ): array {
		$root = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $root, 0700, true );
		$file = $root . '/probe.php';
		$data = array_merge(
			array(
				'bootstrap' => dirname( __DIR__, 2 ) . '/bootstrap.php',
				'hooks'     => dirname( __DIR__ ) . '/Support/WordPressHookFixture.php',
			),
			$extra
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
		$prefix = '<?php require ' . var_export( $data['hooks'], true ) . '; $GLOBALS["wp_version"]="6.8.0"; $data=' . var_export( $data, true ) . ';';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $file, $prefix . $body );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function package_copy( string $name, string $version ): string {
		$root = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/' . $name . '-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $root, 0700, true );
		copy( dirname( __DIR__, 2 ) . '/bootstrap.php', $root . '/bootstrap.php' );
		copy( dirname( __DIR__, 2 ) . '/runtime.php', $root . '/runtime.php' );
		$this->copy_directory( dirname( __DIR__, 2 ) . '/src', $root . '/src' );
		$files    = array( 'bootstrap.php', 'runtime.php' );
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents(
			$root . '/runtime-copy.json',
			json_encode(
				array(
					'package_revision' => hash( 'sha256', $payload ),
					'package_version'  => $version,
					'php_floor'        => '8.2.0',
					'runtime_file'     => 'runtime.php',
					'runtime_protocol' => 5,
					'wordpress_floor'  => '6.5.0',
				),
				JSON_THROW_ON_ERROR
			)
		);
		return $root;
	}

	private function copy_directory( string $source, string $destination ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $destination, 0700, true );
		$fixture_entries = scandir( $source );
		foreach ( $fixture_entries ? $fixture_entries : array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$from = $source . '/' . $name;
			$to   = $destination . '/' . $name;
			is_dir( $from ) ? $this->copy_directory( $from, $to ) : copy( $from, $to );
		}
	}
}
