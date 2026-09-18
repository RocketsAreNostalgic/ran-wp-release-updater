<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PublicReleaseSourceTest extends TestCase {

	#[Test]
	public function constructionAndInvalidOperationsAreInertAndPrecedeReadiness(): void {
		$result = $this->probe(
			<<<'PHP'
$calls = 0;
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789', 'stable', static function () use (&$calls): string { ++$calls; return 'secret'; });
$early = $source->list();
$conditional = $source->list(array('bad' => 'value'));
$release = $source->inspect('', 'v1.0.0');
$tag = $source->inspect('1', "bad\nvalue");
$fingerprint = $source->acquire('1', 'v1.0.0', 'v1:wrong');
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
	public function releaseOnlyConstructionRegistersNoNativeHooks(): void {
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
	public function aSourceThatWasNotReadyDuringActivationCanBeUsedAfterTheSameBrokerLoads(): void {
		$result = $this->probe(
			<<<'PHP'
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789');
$before = $source->list();
$activation = $GLOBALS['ran_wp_release_updater_v1_broker']->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 4, 'wordpress_version' => '6.8.0'));
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
	public function releaseOnlyUseCreatesNoNativeBindingsAndARegistrarCanCreateItAfterActivation(): void {
		$result = $this->probe(
			<<<'PHP'
$registrar = require $data['bootstrap'];
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$before = $broker->diagnostics();
$activation = $broker->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 4, 'wordpress_version' => '6.8.0'));
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
	public function unsupportedFilesystemFailsBeforeProviderOrCredentialUse(): void {
		$result = $this->probe(
			<<<'PHP'
$credentials = 0;
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789', 'stable', static function () use (&$credentials): string { ++$credentials; return 'secret'; });
$GLOBALS['ran_wp_release_updater_v1_broker']->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 4, 'wordpress_version' => '6.8.0'));
$result = $source->list();
echo json_encode(array('result' => $result, 'credentials' => $credentials, 'filesystem_gate_calls' => $GLOBALS['release_source_filesystem_gate_calls'], 'http_calls' => $GLOBALS['release_source_http_calls'], 'hooks' => $GLOBALS['release_source_hooks']));
PHP,
			array( 'filesystem_method' => 'ftpext' )
		);

		self::assertSame( 'filesystem_unsupported', $result['result']['code'] );
		self::assertSame( 0, $result['credentials'] );
		self::assertSame( 0, $result['filesystem_gate_calls'] );
		self::assertSame( 0, $result['http_calls'] );
		self::assertSame( array(), $result['hooks'] );
	}

	#[Test]
	public function cachedSourceRechecksAnUndefinedFilesystemMethodBeforeEveryOperation(): void {
		$result = $this->probe(
			<<<'PHP'
$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct();
$credentials = 0;
$registrar = require $data['bootstrap'];
$GLOBALS['ran_wp_release_updater_v1_broker']->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 4, 'wordpress_version' => '6.8.0'));
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789', 'stable', static function () use (&$credentials): string { ++$credentials; return 'secret'; });
$first = $source->list();
$before = array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls']);
$GLOBALS['wp_filesystem'] = null;
$cleared = array($source->list(), $source->inspect('1', 'v1.0.0'), $source->acquire('1', 'v1.0.0', 'v2:' . str_repeat('0', 64)));
$clearedWork = array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls']);
$GLOBALS['wp_filesystem'] = new stdClass();
$replaced = array($source->list(), $source->inspect('1', 'v1.0.0'), $source->acquire('1', 'v1.0.0', 'v2:' . str_repeat('0', 64)));
$replacedWork = array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls']);
$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct();
$restored = $source->list();
echo json_encode(array('first' => $first, 'cleared' => $cleared, 'replaced' => $replaced, 'restored' => $restored, 'before' => $before, 'cleared_work' => $clearedWork, 'replaced_work' => $replacedWork, 'after' => array('credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls'])));
PHP,
			array( 'filesystem_method' => null )
		);

		self::assertSame( 'operation_failed', $result['first']['code'] );
		foreach ( array_merge( $result['cleared'], $result['replaced'] ) as $operation ) {
			self::assertSame( 'filesystem_unsupported', $operation['code'] );
		}
		self::assertSame( $result['before'], $result['cleared_work'] );
		self::assertSame( $result['before'], $result['replaced_work'] );
		self::assertSame( 'operation_failed', $result['restored']['code'] );
	}

	#[Test]
	public function cachedSourceDoesNotResumeAfterTerminalReplacementEvenIfTheOriginalBrokerReturns(): void {
		$result = $this->probe(
			<<<'PHP'
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789');
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$broker->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 4, 'wordpress_version' => '6.8.0'));
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

	/** @return array<string,mixed> */
	private function probe( string $body, array $data = array() ): array {
		$file                 = dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp/release-source-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$filesystemMethod     = array_key_exists( 'filesystem_method', $data ) ? $data['filesystem_method'] : 'direct';
		$filesystemDefinition = null === $filesystemMethod ? '' : 'define("FS_METHOD",' . var_export( $filesystemMethod, true ) . '); ';
		$filesystemClass      = null === $filesystemMethod ? 'class WP_Filesystem_Direct{} ' : '';
		$prefix               = '<?php '
			. '$GLOBALS["release_source_hooks"]=array(); '
			. $filesystemClass
			. 'function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["release_source_hooks"][]=$hook;} '
			. 'function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["release_source_hooks"][]=$hook;} '
			. 'function wp_safe_remote_get():mixed{++$GLOBALS["release_source_http_calls"];return false;} function is_wp_error():bool{return false;} '
			. '$GLOBALS["release_source_filesystem_gate_calls"]=0;$GLOBALS["release_source_http_calls"]=0;$GLOBALS["wp_version"]="6.8.0";' . $filesystemDefinition
			. '$data=' . var_export( array_merge( array( 'bootstrap' => dirname( __DIR__, 2 ) . '/bootstrap.php' ), $data ), true ) . '; ';
		file_put_contents( $file, $prefix . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}
}
