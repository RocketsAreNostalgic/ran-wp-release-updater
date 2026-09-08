<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class NativeOfferProtocolCompatibilityTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp/native-offer-protocol-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root, 0700, true );
	}

	#[Test]
	public function releasedProtocolThreeAndProtocolFourRejectTheLaterNativeDeclarationAfterActivationInEitherBootstrapOrder(): void {
		$released = $this->releasedProtocolThreeFixture();
		$current  = dirname( __DIR__, 2 );

		foreach ( array( array( 'released', 'current' ), array( 'current', 'released' ) ) as $order ) {
			$result = $this->probe(
				<<<'PHP'
$first = require $data[ $data['order'][0] ];
$firstBroker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$activation = $firstBroker->activate( array( 'php_version' => PHP_VERSION, 'runtime_protocol' => $data['first_protocol'], 'wordpress_version' => '6.8.0' ) );
$before = $firstBroker->diagnostics();
$second = require $data[ $data['order'][1] ];
$credentialCalls = 0;
$handle = $second->plugin( 'github', $data['plugin'], 'acme/example', '123456789', 'stable', 'manual', static function () use ( &$credentialCalls ): string { ++$credentialCalls; return 'secret'; } );
$registered = $handle->register();
echo json_encode( array(
	'activation' => $activation,
	'before' => $before,
	'after_first' => $firstBroker->diagnostics(),
	'second' => $second->diagnostics(),
	'registered' => $registered,
	'status' => $handle->status(),
	'credential_calls' => $credentialCalls,
	'http_calls' => $GLOBALS['native_offer_http_calls'],
) );
PHP,
				array(
					'current'        => $current . '/bootstrap.php',
					'released'       => $released . '/bootstrap.php',
					'first_protocol' => 'current' === $order[0] ? 4 : 3,
					'order'          => $order,
					'plugin'         => $this->plugin(),
				)
			);

			self::assertTrue( $result['activation']['loaded'], implode( ',', $order ) );
			self::assertSame( 'active', $result['before']['state'], implode( ',', $order ) );
			self::assertSame( 'conflict', $result['second']['state'], implode( ',', $order ) );
			self::assertSame( 0, $result['second']['candidate_count'], implode( ',', $order ) );
			self::assertSame( 0, $result['second']['submission_count'], implode( ',', $order ) );
			self::assertSame( 0, $result['second']['logical_target_count'], implode( ',', $order ) );
			self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['second']['diagnostics'], implode( ',', $order ) );
			self::assertFalse( $result['registered'], implode( ',', $order ) );
			self::assertSame( 'protocol_conflict_inactive', $result['status']['code'], implode( ',', $order ) );
			self::assertFalse( $result['status']['hooks_registered'], implode( ',', $order ) );
			self::assertSame( 0, $result['credential_calls'], implode( ',', $order ) );
			self::assertSame( 0, $result['http_calls'], implode( ',', $order ) );
			self::assertSame( $result['before']['candidate_count'], $result['after_first']['candidate_count'], implode( ',', $order ) );
			self::assertSame( $result['before']['submission_count'], $result['after_first']['submission_count'], implode( ',', $order ) );
		}
	}

	#[Test]
	public function releasedProtocolThreeAndProtocolFourFailClosedBeforeActivationInEitherBootstrapOrder(): void {
		$released = $this->releasedProtocolThreeFixture();
		$current  = dirname( __DIR__, 2 );

		foreach ( array( array( 'released', 'current' ), array( 'current', 'released' ) ) as $order ) {
			$result = $this->probe(
				<<<'PHP'
$first = require $data[ $data['order'][0] ];
$firstBroker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$before = $firstBroker->diagnostics();
$second = require $data[ $data['order'][1] ];
$credentialCalls = 0;
$handle = $second->plugin( 'github', $data['plugin'], 'acme/example', '123456789', 'stable', 'manual', static function () use ( &$credentialCalls ): string { ++$credentialCalls; return 'secret'; } );
$registered = $handle->register();
$activation = $firstBroker->activate( array( 'php_version' => PHP_VERSION, 'runtime_protocol' => $data['first_protocol'], 'wordpress_version' => '6.8.0' ) );
echo json_encode( array(
	'before' => $before,
	'after_first' => $firstBroker->diagnostics(),
	'activation' => $activation,
	'second' => $second->diagnostics(),
	'registered' => $registered,
	'status' => $handle->status(),
	'credential_calls' => $credentialCalls,
	'http_calls' => $GLOBALS['native_offer_http_calls'],
) );
PHP,
				array(
					'current'        => $current . '/bootstrap.php',
					'released'       => $released . '/bootstrap.php',
					'first_protocol' => 'current' === $order[0] ? 4 : 3,
					'order'          => $order,
					'plugin'         => $this->plugin(),
				)
			);

			self::assertFalse( $result['before']['activation_attempted'], implode( ',', $order ) );
			self::assertSame( 'collecting', $result['before']['state'], implode( ',', $order ) );
			self::assertFalse( $result['activation']['loaded'], implode( ',', $order ) );
			self::assertSame( 'inactive', $result['activation']['state'], implode( ',', $order ) );
			self::assertSame( 'runtime_handoff_invalid', $result['activation']['code'], implode( ',', $order ) );
			self::assertSame( 'inactive', $result['after_first']['state'], implode( ',', $order ) );
			self::assertSame( array( array( 'code' => 'runtime_handoff_invalid' ) ), $result['after_first']['diagnostics'], implode( ',', $order ) );
			self::assertSame( 'conflict', $result['second']['state'], implode( ',', $order ) );
			self::assertSame( 0, $result['second']['candidate_count'], implode( ',', $order ) );
			self::assertSame( 0, $result['second']['submission_count'], implode( ',', $order ) );
			self::assertSame( 0, $result['second']['logical_target_count'], implode( ',', $order ) );
			self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['second']['diagnostics'], implode( ',', $order ) );
			self::assertFalse( $result['registered'], implode( ',', $order ) );
			self::assertSame( 'protocol_conflict_inactive', $result['status']['code'], implode( ',', $order ) );
			self::assertFalse( $result['status']['hooks_registered'], implode( ',', $order ) );
			self::assertSame( 0, $result['credential_calls'], implode( ',', $order ) );
			self::assertSame( 0, $result['http_calls'], implode( ',', $order ) );
			self::assertSame( $result['before']['candidate_count'], $result['after_first']['candidate_count'], implode( ',', $order ) );
			self::assertSame( $result['before']['submission_count'], $result['after_first']['submission_count'], implode( ',', $order ) );
		}
	}

	/** @return array<string,mixed> */
	private function probe( string $body, array $data ): array {
		$file   = $this->root . '/probe-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$prefix = '<?php '
			. '$GLOBALS["native_offer_hooks"]=array(); $GLOBALS["native_offer_http_calls"]=0; '
			. 'function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["native_offer_hooks"][]=$hook;} '
			. 'function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["native_offer_hooks"][]=$hook;} '
			. 'function wp_safe_remote_get():mixed{++$GLOBALS["native_offer_http_calls"];return false;} function is_wp_error():bool{return false;} '
			. '$GLOBALS["wp_version"]="6.8.0"; $data=' . var_export( $data, true ) . '; ';
		file_put_contents( $file, $prefix . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( dirname( __DIR__, 2 ) . '/.workspaces/p0.3/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function releasedProtocolThreeFixture(): string {
		$root    = $this->root . '/released-protocol-three';
		$archive = dirname( __DIR__, 2 ) . '/tests/Fixtures/protocol3-beta3-52078f1.zip';
		self::assertSame( 'de9b104e34fe6864ae797ab2b5562bbbe4177d3001e3412cbb2301a302f31189', hash_file( 'sha256', $archive ) );
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $archive ) );
		self::assertTrue( $zip->extractTo( $root ) );
		$zip->close();
		self::assertSame(
			array(
				'bootstrap.php'                        => '74e8dcf0dc9062d093c491d8b08413aa7c5a8514185ccc590f79b4758624251b',
				'runtime.php'                          => '08cc1149a204d47cb7e00ae46c5c58868b8baeb54698429b7318247c171dc97f',
				'src/Runtime/RequestBroker.php'        => '07fc48ed02c66e1dce1f55f608145ae62d033b0e311193b03e9cb9ba15971971',
				'src/Runtime/SelectedRuntimeState.php' => '10a368395edaf76af1f1cf7384f5d4abfc5eb418f86028f63f03b9bd4b743f96',
			),
			$this->hashes( $root )
		);
		self::assertSame(
			array(
				'package_revision' => '6e305b46ba99898b51bded7266aa9964d88665744928b11162919bf51398ec91',
				'package_version'  => '0.1.0-beta.3',
				'php_floor'        => '8.2.0',
				'runtime_file'     => 'runtime.php',
				'runtime_protocol' => 3,
				'wordpress_floor'  => '6.5.0',
			),
			json_decode( (string) file_get_contents( $root . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR )
		);
		return $root;
	}

	private function plugin(): string {
		$file = $this->root . '/plugin.php';
		file_put_contents( $file, "<?php\n/*\nPlugin Name: Protocol compatibility fixture\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n" );
		return $file;
	}

	/** @return array<string,string> */
	private function hashes( string $root ): array {
		$hashes = array();
		foreach ( array( 'bootstrap.php', 'runtime.php', 'src/Runtime/RequestBroker.php', 'src/Runtime/SelectedRuntimeState.php' ) as $file ) {
			$hashes[ $file ] = hash_file( 'sha256', $root . '/' . $file );
		}
		return $hashes;
	}
}
