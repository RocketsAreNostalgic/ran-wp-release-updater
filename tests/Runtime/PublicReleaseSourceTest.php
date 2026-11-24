<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PublicReleaseSourceTest extends TestCase {

	#[Test]
	public function construction_and_invalid_operations_are_inert_and_precede_readiness(): void {
		$result = $this->probe(
			<<<'PHP'
$calls = 0;
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789', 'stable', static function () use (&$calls): string { ++$calls; return 'secret'; });
$early = $source->list();
$conditional = $source->list(array('bad' => 'value'));
$release = $source->inspect(release_id: '', expected_tag: 'v1.0.0');
$tag = $source->inspect('1', "bad\nvalue");
$fingerprint = $source->acquire(release_id: '1', expected_tag: 'v1.0.0', expected_fingerprint: 'v1:wrong');
$GLOBALS['ran_wp_release_updater_v1_broker'] = new stdClass();
$terminal = $source->inspect('', '');
echo json_encode(array('early' => $early, 'conditional' => $conditional, 'release' => $release, 'tag' => $tag, 'fingerprint' => $fingerprint, 'terminal' => $terminal, 'calls' => $calls));
PHP
		);

		self::assertSame( 'runtime_not_ready', $result['early']['code'] );
		self::assertSame( 'invalid_configuration', $result['conditional']['code'] );
		self::assertSame( 'invalid_release', $result['release']['code'] );
		self::assertSame( 'invalid_release', $result['tag']['code'] );
		self::assertSame( 'invalid_release', $result['fingerprint']['code'] );
		self::assertSame( 'runtime_unavailable', $result['terminal']['code'] );
		self::assertSame( 0, $result['calls'] );
	}

	#[Test]
	public function release_only_construction_registers_no_native_hooks(): void {
		$result = $this->probe(
			<<<'PHP'
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'theme', 'acme/example', '123456789');
echo json_encode(array('methods' => get_class_methods($source), 'hooks' => $GLOBALS['release_source_hooks']));
PHP
		);

		$methods = array_values( array_filter( $result['methods'], static fn( string $method ): bool => '__construct' !== $method ) );
		sort( $methods, SORT_STRING );
		self::assertSame( array( 'acquire', 'inspect', 'list' ), $methods );
		self::assertSame( array(), $result['hooks'] );
	}

	#[Test]
	public function a_source_that_was_not_ready_during_activation_can_be_used_after_the_same_broker_loads(): void {
		$result = $this->probe(
			<<<'PHP'
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789');
$before = $source->list();
$activation = $GLOBALS['ran_wp_release_updater_v1_broker']->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 11, 'wordpress_version' => '6.8.0'));
$after = $source->list();
echo json_encode(array('before' => $before, 'activation' => $activation, 'after' => $after, 'hooks' => $GLOBALS['release_source_hooks'], 'filesystem_gate_calls' => $GLOBALS['release_source_filesystem_gate_calls']));
PHP
		);

		self::assertSame( 'runtime_not_ready', $result['before']['code'] );
		self::assertTrue( $result['activation']['loaded'] );
		self::assertNotSame( 'runtime_not_ready', $result['after']['code'] );
		self::assertNotSame( 'runtime_unavailable', $result['after']['code'] );
		self::assertSame( array(), $result['hooks'] );
		self::assertSame( 0, $result['filesystem_gate_calls'] );
	}

	#[Test]
	public function release_only_use_creates_no_native_bindings_and_a_registrar_can_create_it_after_activation(): void {
		$result = $this->probe(
			<<<'PHP'
$registrar = require $data['bootstrap'];
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$before = $broker->diagnostics();
$activation = $broker->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 11, 'wordpress_version' => '6.8.0'));
$source = $registrar->releases('github', 'theme', 'acme/example', '123456789');
$result = $source->list();
$after = $broker->diagnostics();
echo json_encode(array('before' => $before, 'activation' => $activation, 'result' => $result, 'after' => $after, 'hooks' => $GLOBALS['release_source_hooks']));
PHP
		);

		self::assertSame( 0, $result['before']['submission_count'] );
		self::assertSame( 0, $result['before']['logical_target_count'] );
		self::assertTrue( $result['activation']['loaded'] );
		self::assertNotContains( $result['result']['code'], array( 'runtime_not_ready', 'runtime_unavailable' ) );
		self::assertSame( 0, $result['after']['submission_count'] );
		self::assertSame( 0, $result['after']['logical_target_count'] );
		self::assertSame( array(), $result['hooks'] );
	}

	#[Test]
	public function unsupported_filesystem_fails_before_provider_or_credential_use(): void {
		$result = $this->probe(
			<<<'PHP'
$credentials = 0;
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789', 'stable', static function () use (&$credentials): string { ++$credentials; return 'secret'; });
$GLOBALS['ran_wp_release_updater_v1_broker']->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 11, 'wordpress_version' => '6.8.0'));
$inspect = $source->inspect('1', 'v1.0.0');
$acquire = $source->acquire('1', 'v1.0.0', 'v2:' . str_repeat('0', 64));
$beforeList = array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls']);
$list = $source->list();
echo json_encode(array('inspect' => $inspect, 'acquire' => $acquire, 'list' => $list, 'before_list' => $beforeList, 'after_list' => array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls']), 'filesystem_gate_calls' => $GLOBALS['release_source_filesystem_gate_calls'], 'hooks' => $GLOBALS['release_source_hooks']));
PHP,
			array( 'filesystem_method' => 'ftpext' )
		);

		self::assertSame( 'filesystem_unsupported', $result['inspect']['code'] );
		self::assertSame( 'filesystem_unsupported', $result['acquire']['code'] );
		self::assertSame(
			array(
				'credentials' => 0,
				'http_calls'  => 0,
			),
			$result['before_list']
		);
		self::assertNotSame( 'filesystem_unsupported', $result['list']['code'] );
		self::assertSame( 1, $result['after_list']['credentials'] );
		self::assertSame( 1, $result['after_list']['http_calls'] );
		self::assertSame( 0, $result['filesystem_gate_calls'] );
		self::assertSame( array(), $result['hooks'] );
	}

	#[Test]
	public function cached_source_rechecks_an_undefined_filesystem_method_before_every_operation(): void {
		$result = $this->probe(
			<<<'PHP'
$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct();
$credentials = 0;
$registrar = require $data['bootstrap'];
$GLOBALS['ran_wp_release_updater_v1_broker']->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 11, 'wordpress_version' => '6.8.0'));
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789', 'stable', static function () use (&$credentials): string { ++$credentials; return 'secret'; });
$first = $source->inspect('1', 'v1.0.0');
$before = array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls']);
$GLOBALS['wp_filesystem'] = null;
$cleared = array($source->inspect('1', 'v1.0.0'), $source->acquire('1', 'v1.0.0', 'v2:' . str_repeat('0', 64)));
$clearedWork = array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls']);
$clearedList = $source->list();
$afterClearedList = array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls']);
$GLOBALS['wp_filesystem'] = new stdClass();
$replaced = array($source->inspect('1', 'v1.0.0'), $source->acquire('1', 'v1.0.0', 'v2:' . str_repeat('0', 64)));
$replacedWork = array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls']);
$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct();
$restored = $source->inspect('1', 'v1.0.0');
echo json_encode(array('first' => $first, 'cleared' => $cleared, 'cleared_list' => $clearedList, 'after_cleared_list' => $afterClearedList, 'replaced' => $replaced, 'restored' => $restored, 'before' => $before, 'cleared_work' => $clearedWork, 'replaced_work' => $replacedWork, 'after' => array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls'])));
PHP,
			array( 'filesystem_method' => null )
		);

		self::assertSame( 'operation_failed', $result['first']['code'] );
		foreach ( array_merge( $result['cleared'], $result['replaced'] ) as $operation ) {
			self::assertSame( 'filesystem_unsupported', $operation['code'] );
		}
		self::assertSame( $result['before'], $result['cleared_work'] );
		self::assertNotSame( 'filesystem_unsupported', $result['cleared_list']['code'] );
		self::assertSame( $result['after_cleared_list'], $result['replaced_work'] );
		self::assertSame( 'operation_failed', $result['restored']['code'] );
	}

	#[Test]
	public function cached_source_does_not_resume_after_terminal_replacement_even_if_the_original_broker_returns(): void {
		$result = $this->probe(
			<<<'PHP'
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789');
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$broker->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 11, 'wordpress_version' => '6.8.0'));
$first = $source->list();
$GLOBALS['ran_wp_release_updater_v1_broker'] = new stdClass();
$lost = $source->list();
$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
$restored = $source->list();
echo json_encode(array('first' => $first, 'lost' => $lost, 'restored' => $restored, 'filesystem_gate_calls' => $GLOBALS['release_source_filesystem_gate_calls'], 'http_calls' => $GLOBALS['release_source_http_calls']));
PHP
		);

		self::assertNotSame( 'runtime_unavailable', $result['first']['code'] );
		self::assertSame( 'runtime_unavailable', $result['lost']['code'] );
		self::assertSame( 'runtime_unavailable', $result['restored']['code'] );
		self::assertSame( 0, $result['filesystem_gate_calls'] );
	}

	#[Test]
	public function wp_cron_loads_file_php_for_direct_filesystem_detection_in_release_source_and_native_updater(): void {
		$root = dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp/cron-abspath-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create an isolated WordPress ABSPATH tree for WP-Cron filesystem-helper loading tests.
		mkdir( $root . '/wp-admin/includes', 0700, true );
		foreach ( array(
			'direct' => true,
			'ftpext' => false,
		) as $method => $expected_direct ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Write controlled fixture file.php returning the target filesystem method.
			file_put_contents( $root . '/wp-admin/includes/file.php', "<?php\nfunction get_filesystem_method(): string { return " . var_export( $method, true ) . "; }\n" );
			$result = $this->probe(
				<<<'PHP'
define('ABSPATH', $data['abspath'] . '/');
$GLOBALS['wp_filesystem'] = null;
$registrar = require $data['bootstrap'];
$GLOBALS['ran_wp_release_updater_v1_broker']->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 11, 'wordpress_version' => '6.8.0'));
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789');
$beforeLoaded = function_exists('get_filesystem_method');
$inspect = $source->inspect('1', 'v1.0.0');
$afterLoaded = function_exists('get_filesystem_method');
$sharedDirect = RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState::direct_filesystem_available();
echo json_encode(array('before_loaded' => $beforeLoaded, 'after_loaded' => $afterLoaded, 'inspect' => $inspect, 'shared_direct' => $sharedDirect, 'http_calls' => $GLOBALS['release_source_http_calls']));
PHP,
				array(
					'abspath'           => $root,
					'filesystem_method' => null,
				)
			);

			self::assertFalse( $result['before_loaded'], $method );
			self::assertTrue( $result['after_loaded'], $method );
			self::assertSame( $expected_direct, $result['shared_direct'], $method );
			if ( $expected_direct ) {
				self::assertNotSame( 'filesystem_unsupported', $result['inspect']['code'], $method );
				self::assertSame( 1, $result['http_calls'], $method );
			} else {
				self::assertSame( 'filesystem_unsupported', $result['inspect']['code'], $method );
				self::assertSame( 0, $result['http_calls'], $method );
			}
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove temporary fixture file.php after test assertions.
		@unlink( $root . '/wp-admin/includes/file.php' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove temporary fixture directories after test assertions.
		@rmdir( $root . '/wp-admin/includes' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove temporary fixture directories after test assertions.
		@rmdir( $root . '/wp-admin' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove temporary fixture directories after test assertions.
		@rmdir( $root );
	}

	/**
	 * @return array<string,mixed>
	 * @param array<string,mixed> $data
	 */
	private function probe( string $body, array $data = array() ): array {
		$file              = dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp/release-source-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$filesystem_method = array_key_exists( 'filesystem_method', $data ) ? $data['filesystem_method'] : 'direct';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
		$filesystem_definition = null === $filesystem_method ? '' : 'define("FS_METHOD",' . var_export( $filesystem_method, true ) . '); ';
		$filesystem_class      = null === $filesystem_method ? 'class WP_Filesystem_Direct{} ' : '';
		$prefix                = '<?php '
			. '$GLOBALS["release_source_hooks"]=array(); '
			. $filesystem_class
			. 'function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["release_source_hooks"][]=$hook;} '
			. 'function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["release_source_hooks"][]=$hook;} '
			. 'function wp_safe_remote_get():mixed{++$GLOBALS["release_source_http_calls"];return false;} function is_wp_error():bool{return false;} '
			. '$GLOBALS["release_source_filesystem_gate_calls"]=0;$GLOBALS["release_source_http_calls"]=0;$GLOBALS["wp_version"]="6.8.0";' . $filesystem_definition
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
			. '$data=' . var_export( array_merge( array( 'bootstrap' => dirname( __DIR__, 2 ) . '/bootstrap.php' ), $data ), true ) . '; ';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $file, $prefix . $body );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}
}
