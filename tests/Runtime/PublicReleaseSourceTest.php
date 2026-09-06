<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PublicReleaseSourceTest extends TestCase
{
	#[Test]
	public function constructionAndInvalidOperationsAreInertAndPrecedeReadiness(): void
	{
		$result = $this->probe( <<<'PHP'
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
PHP );

		self::assertSame( 'runtime_not_ready', $result['early']['code'] );
		self::assertSame( 'invalid_configuration', $result['conditional']['code'] );
		self::assertSame( 'invalid_release', $result['release']['code'] );
		self::assertSame( 'invalid_release', $result['tag']['code'] );
		self::assertSame( 'invalid_release', $result['fingerprint']['code'] );
		self::assertSame( 'runtime_unavailable', $result['terminal']['code'] );
		self::assertSame( 0, $result['calls'] );
	}

	#[Test]
	public function releaseOnlyConstructionRegistersNoNativeHooks(): void
	{
		$result = $this->probe( <<<'PHP'
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'theme', 'acme/example', '123456789');
echo json_encode(array('methods' => get_class_methods($source), 'hooks' => $GLOBALS['release_source_hooks']));
PHP );

		$methods = array_values( array_filter( $result['methods'], static fn( string $method ): bool => '__construct' !== $method ) );
		sort( $methods, SORT_STRING );
		self::assertSame( array( 'acquire', 'inspect', 'list' ), $methods );
		self::assertSame( array(), $result['hooks'] );
	}

	#[Test]
	public function aSourceThatWasNotReadyDuringActivationCanBeUsedAfterTheSameBrokerLoads(): void
	{
		$result = $this->probe( <<<'PHP'
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789');
$before = $source->list();
$activation = $GLOBALS['ran_wp_release_updater_v1_broker']->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 3, 'wordpress_version' => '6.8.0'));
$after = $source->list();
echo json_encode(array('before' => $before, 'activation' => $activation, 'after' => $after, 'hooks' => $GLOBALS['release_source_hooks'], 'filesystem_gate_calls' => $GLOBALS['release_source_filesystem_gate_calls']));
PHP );

		self::assertSame( 'runtime_not_ready', $result['before']['code'] );
		self::assertTrue( $result['activation']['loaded'] );
		self::assertNotSame( 'runtime_not_ready', $result['after']['code'] );
		self::assertNotSame( 'runtime_unavailable', $result['after']['code'] );
		self::assertSame( array(), $result['hooks'] );
		self::assertSame( 0, $result['filesystem_gate_calls'] );
	}

	#[Test]
	public function releaseOnlyUseCreatesNoNativeBindingsAndARegistrarCanCreateItAfterActivation(): void
	{
		$result = $this->probe( <<<'PHP'
$registrar = require $data['bootstrap'];
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$before = $broker->diagnostics();
$activation = $broker->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 3, 'wordpress_version' => '6.8.0'));
$source = $registrar->releases('github', 'theme', 'acme/example', '123456789');
$result = $source->list();
$after = $broker->diagnostics();
echo json_encode(array('before' => $before, 'activation' => $activation, 'result' => $result, 'after' => $after, 'hooks' => $GLOBALS['release_source_hooks']));
PHP );

		self::assertSame( 0, $result['before']['submission_count'] );
		self::assertSame( 0, $result['before']['logical_target_count'] );
		self::assertTrue( $result['activation']['loaded'] );
		self::assertNotContains( $result['result']['code'], array( 'runtime_not_ready', 'runtime_unavailable' ) );
		self::assertSame( 0, $result['after']['submission_count'] );
		self::assertSame( 0, $result['after']['logical_target_count'] );
		self::assertSame( array(), $result['hooks'] );
	}

	#[Test]
	public function unsupportedFilesystemFailsBeforeProviderOrCredentialUse(): void
	{
		$result = $this->probe( <<<'PHP'
$credentials = 0;
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789', 'stable', static function () use (&$credentials): string { ++$credentials; return 'secret'; });
$GLOBALS['ran_wp_release_updater_v1_broker']->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 3, 'wordpress_version' => '6.8.0'));
$result = $source->list();
echo json_encode(array('result' => $result, 'credentials' => $credentials, 'filesystem_gate_calls' => $GLOBALS['release_source_filesystem_gate_calls'], 'http_calls' => $GLOBALS['release_source_http_calls'], 'hooks' => $GLOBALS['release_source_hooks']));
PHP, array( 'filesystem_method' => 'ftpext' ) );

		self::assertSame( 'filesystem_unsupported', $result['result']['code'] );
		self::assertSame( 0, $result['credentials'] );
		self::assertSame( 0, $result['filesystem_gate_calls'] );
		self::assertSame( 0, $result['http_calls'] );
		self::assertSame( array(), $result['hooks'] );
	}

	#[Test]
	public function cachedSourceRechecksAnUndefinedFilesystemMethodBeforeEveryOperation(): void
	{
		$result = $this->probe( <<<'PHP'
$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct();
$credentials = 0;
$registrar = require $data['bootstrap'];
$GLOBALS['ran_wp_release_updater_v1_broker']->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 3, 'wordpress_version' => '6.8.0'));
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
PHP, array( 'filesystem_method' => null ) );

		self::assertSame( 'repository_access_unavailable', $result['first']['code'] );
		foreach ( array_merge( $result['cleared'], $result['replaced'] ) as $operation ) {
			self::assertSame( 'filesystem_unsupported', $operation['code'] );
		}
		self::assertSame( $result['before'], $result['cleared_work'] );
		self::assertSame( $result['before'], $result['replaced_work'] );
		self::assertSame( 'repository_access_unavailable', $result['restored']['code'] );
	}

	#[Test]
	public function cachedSourceDoesNotResumeAfterTerminalReplacementEvenIfTheOriginalBrokerReturns(): void
	{
		$result = $this->probe( <<<'PHP'
$registrar = require $data['bootstrap'];
$source = $registrar->releases('github', 'plugin', 'acme/example', '123456789');
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$broker->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 3, 'wordpress_version' => '6.8.0'));
$first = $source->list();
$GLOBALS['ran_wp_release_updater_v1_broker'] = new stdClass();
$lost = $source->list();
$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
$restored = $source->list();
echo json_encode(array('first' => $first, 'lost' => $lost, 'restored' => $restored, 'filesystem_gate_calls' => $GLOBALS['release_source_filesystem_gate_calls'], 'http_calls' => $GLOBALS['release_source_http_calls']));
PHP );

		self::assertNotSame( 'runtime_unavailable', $result['first']['code'] );
		self::assertSame( 'runtime_unavailable', $result['lost']['code'] );
		self::assertSame( 'runtime_unavailable', $result['restored']['code'] );
		self::assertSame( 0, $result['filesystem_gate_calls'] );
	}

	#[Test]
	public function standaloneProtocolTwoAndProtocolThreeRejectTheSecondRegistrarBeforeProviderWorkInEitherLoadOrder(): void
	{
		$protocolTwo = $this->protocolTwoFixture();
		$plugin = dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp/protocol-two-plugin-' . bin2hex( random_bytes( 6 ) ) . '.php';
		file_put_contents( $plugin, "<?php\n/*\nPlugin Name: Protocol fixture\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n" );
		foreach ( array( array( 'two', 'three' ), array( 'three', 'two' ) ) as $order ) {
			$result = $this->probe( <<<'PHP'
$registrars = array();
foreach ($data['order'] as $entry) $registrars[$entry] = require $data[$entry];
$second = $registrars[$data['order'][1]];
$credentials = 0;
$handle = $second->plugin('github', $data['plugin'], 'acme/example', '123456789', 'stable', 'manual', static function () use (&$credentials): string { ++$credentials; return 'secret'; });
$registered = $handle->register();
echo json_encode(array('diagnostics' => $second->diagnostics(), 'registered' => $registered, 'status' => $handle->status(), 'credentials' => $credentials, 'http_calls' => $GLOBALS['release_source_http_calls']));
PHP, array( 'two' => $protocolTwo . '/bootstrap.php', 'three' => dirname( __DIR__, 2 ) . '/bootstrap.php', 'order' => $order, 'plugin' => $plugin ) );

			self::assertSame( 'conflict', $result['diagnostics']['state'], implode( ',', $order ) );
			self::assertContains( 'protocol_conflict_inactive', array_column( $result['diagnostics']['diagnostics'], 'code' ), implode( ',', $order ) );
			self::assertFalse( $result['registered'], implode( ',', $order ) );
			self::assertSame( 'protocol_conflict_inactive', $result['status']['code'], implode( ',', $order ) );
			self::assertSame( 0, $result['credentials'], implode( ',', $order ) );
			self::assertSame( 0, $result['http_calls'], implode( ',', $order ) );
		}
	}

	/** @return array<string,mixed> */
	private function probe( string $body, array $data = array() ): array
	{
		$file = dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp/release-source-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$filesystemMethod = array_key_exists( 'filesystem_method', $data ) ? $data['filesystem_method'] : 'direct';
		$filesystemDefinition = null === $filesystemMethod ? '' : 'define("FS_METHOD",' . var_export( $filesystemMethod, true ) . '); ';
		$filesystemClass = null === $filesystemMethod ? 'class WP_Filesystem_Direct{} ' : '';
		$prefix = '<?php '
			. '$GLOBALS["release_source_hooks"]=array(); '
			. $filesystemClass
			. 'function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["release_source_hooks"][]=$hook;} '
			. 'function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["release_source_hooks"][]=$hook;} '
			. 'function wp_safe_remote_get():mixed{++$GLOBALS["release_source_http_calls"];return false;} function is_wp_error():bool{return false;} '
			. '$GLOBALS["release_source_filesystem_gate_calls"]=0;$GLOBALS["release_source_http_calls"]=0;$GLOBALS["release_source_p2_boots"]=0;$GLOBALS["release_source_p2_handoffs"]=0;$GLOBALS["wp_version"]="6.8.0";' . $filesystemDefinition
			. '$data=' . var_export( array_merge( array( 'bootstrap' => dirname( __DIR__, 2 ) . '/bootstrap.php' ), $data ), true ) . '; ';
		file_put_contents( $file, $prefix . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function protocolTwoFixture(): string
	{
		$root = dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp/protocol-two-' . bin2hex( random_bytes( 6 ) );
		$this->copyDirectory( dirname( __DIR__, 2 ) . '/tests/Fixtures/protocol2', $root );
		file_put_contents( $root . '/runtime-copy.json', json_encode( array(
			'package_revision' => $this->fixtureIdentity( $root ), 'package_version' => '0.1.0-beta.2', 'php_floor' => '8.2.0',
			'runtime_file' => 'runtime.php', 'runtime_protocol' => 2, 'wordpress_floor' => '6.5.0',
		), JSON_THROW_ON_ERROR ) );
		return $root;
	}

	private function copyDirectory( string $source, string $destination ): void
	{
		mkdir( $destination, 0700, true );
		foreach ( scandir( $source ) ?: array() as $name ) {
			if ( '.' === $name || '..' === $name ) continue;
			$from = $source . '/' . $name;
			is_dir( $from ) ? $this->copyDirectory( $from, $destination . '/' . $name ) : copy( $from, $destination . '/' . $name );
		}
	}

	private function fixtureIdentity( string $root ): string
	{
		$files = array( 'bootstrap.php', 'runtime.php' );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) if ( $file->isFile() && 'php' === $file->getExtension() ) $files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		sort( $files, SORT_STRING ); $payload = '';
		foreach ( $files as $file ) $payload .= $file . "\0" . hash_file( 'sha256', $root . '/' . $file ) . "\n";
		return hash( 'sha256', $payload );
	}
}
