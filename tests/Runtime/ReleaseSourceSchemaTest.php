<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\ProspectiveReleaseInspection;

final class ReleaseSourceSchemaTest extends TestCase {

	public function testPublicHandleFailsClosedForMalformedSameProtocolThreeResults(): void {
		foreach ( $this->malformedResults() as $name => [$operation, $result] ) {
			$projection = $this->projection( $operation, $result );
			self::assertSame( 'runtime_unavailable', $projection['code'], $name );
			self::assertNull( $projection['value'], $name );
		}
	}

	public function testInspectionFixtureIsExactAndFingerprintLast(): void {
		$inspection = ProspectiveReleaseInspection::create( $this->facts() )->toArray();
		self::assertSame( 'fingerprint', array_key_last( $inspection ) );
		self::assertArrayNotHasKey( 'path', $inspection );
		self::assertArrayNotHasKey( 'provider_payload', $inspection );
	}

	public function testActivatedSyntheticCallbackCanReturnTheExactValidListingShape(): void {
		$result = $this->projection(
			'list',
			$this->envelope(
				'releases_listed',
				array(
					'candidates'       => array(),
					'conditional'      => array(
						'etag'          => null,
						'last_modified' => null,
					),
					'not_modified'     => false,
					'rate_limit'       => array(
						'limited'     => false,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 0,
					),
					'search_exhausted' => true,
				),
				'not_applicable'
			)
		);
		self::assertSame( 'releases_listed', $result['code'] );
		self::assertTrue( $result['ok'] );
	}

	public function testFailureCleanupCombinationsAreOperationScoped(): void {
		$invalid = array(
			'ok'             => false,
			'code'           => 'invalid_configuration',
			'value'          => null,
			'retry_after'    => null,
			'cleanup_status' => 'complete',
		);
		self::assertSame( 'runtime_unavailable', $this->projection( 'inspect', $invalid )['code'] );
		$repository = array(
			'ok'             => false,
			'code'           => 'repository_access_unavailable',
			'value'          => null,
			'retry_after'    => null,
			'cleanup_status' => 'complete',
		);
		$result     = $this->projection( 'inspect', $repository );
		self::assertSame( 'repository_access_unavailable', $result['code'] );
		self::assertSame( 'complete', $result['cleanup_status'] );
	}

	public function testMalformedAcquisitionCleansTheRealOwnedArtifactBeforeReturn(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array(
			'nested' => 'runtime_unavailable',
			'outer'  => 'operation_failed',
		) as $mutation => $expectedCode ) {
			$directory = $root . '/.workspaces/p0.3/php-tmp/schema-artifact-' . bin2hex( random_bytes( 6 ) );
			self::assertTrue( mkdir( $directory, 0700, true ) );
			$file   = $directory . '/proof.php';
			$script = '<?php define("FS_METHOD","direct");$GLOBALS["wp_version"]="6.8.0";$GLOBALS["wpdb"]=new stdClass();function add_action(string $h,mixed $c,int $p=10,int $a=1):void{}function add_filter(string $h,mixed $c,int $p=10,int $a=1):void{}'
			. '$root=' . var_export( $root, true ) . ';$mutation=' . var_export( $mutation, true ) . ';$dir=' . var_export( $directory, true ) . ';$path=$dir."/owned.zip";file_put_contents($path,"owned");chmod($path,0600);$stat=lstat($path);$registrar=require $root."/bootstrap.php";$broker=$GLOBALS["ran_wp_release_updater_v1_broker"];$broker->activate(["php_version"=>PHP_VERSION,"runtime_protocol"=>4,"wordpress_version"=>"6.8.0"]);$state=(new ReflectionProperty($broker,"selectedRuntimeState"))->getValue($broker);$artifact=new \\RAN\\WPReleaseUpdater\\V1\\Archive\\TemporaryArtifact($path,hash_file("sha256",$path),["dev"=>$stat["dev"],"ino"=>$stat["ino"],"mode"=>$stat["mode"],"nlink"=>$stat["nlink"],"uid"=>$stat["uid"],"gid"=>$stat["gid"],"size"=>$stat["size"],"mtime"=>$stat["mtime"],"ctime"=>$stat["ctime"]]);$facts=' . var_export( ProspectiveReleaseInspection::create( $this->facts() )->toArray(), true ) . ';$payload=["inspection"=>$facts,"artifact"=>$artifact];if("nested"===$mutation){$payload["inspection"]["extra"]=true;}else{$payload["extra"]=true;}$calls=0;$service=new class($payload,$calls){public function __construct(private array $payload,private int &$calls){}public function acquire(string $a,string $b,string $c):array{$this->calls++;return $this->payload;}};$real=new \\RAN\\WPReleaseUpdater\\V1\\Runtime\\ReleaseSource($service,$state);$public=$registrar->releases("github","plugin","acme/example","99");(new ReflectionProperty($public,"selected"))->setValue($public,$real);$result=$public->acquire("7","v1.2.3",$facts["fingerprint"]);echo json_encode(["result"=>$result,"calls"=>$calls,"exists"=>file_exists($path)]);';
			file_put_contents( $file, $script );
			$output = array();
			exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $root . '/.workspaces/p0.3/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
			@unlink( $file );
			@rmdir( $directory );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			$result = json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( 1, $result['calls'] );
			self::assertSame( $expectedCode, $result['result']['code'] );
			self::assertSame( 'complete', $result['result']['cleanup_status'] );
			self::assertFalse( $result['exists'] );
		}
	}

	/** @return array<string,array{string,array<string,mixed>}> */
	private function malformedResults(): array {
		$inspection   = ProspectiveReleaseInspection::create( $this->facts() )->toArray();
		$shortVersion = $inspection;
		unset( $shortVersion['fingerprint'] );
		$shortVersion['version']     = '1.2';
		$shortVersion['fingerprint'] = 'v2:' . hash( 'sha256', json_encode( $shortVersion, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return array(
			'package-version-short'         => array( 'inspect', $this->envelope( 'release_inspected', $shortVersion, 'complete' ) ),
			'changed-without-proof-cleanup' => array(
				'acquire',
				array(
					'ok'             => false,
					'code'           => 'release_changed',
					'value'          => null,
					'retry_after'    => null,
					'cleanup_status' => 'not_applicable',
				),
			),
			'extra-listing-path'            => array(
				'list',
				$this->envelope(
					'releases_listed',
					array(
						'candidates'       => array(),
						'conditional'      => array(
							'etag'          => null,
							'last_modified' => null,
						),
						'not_modified'     => false,
						'rate_limit'       => array(
							'limited'     => false,
							'remaining'   => null,
							'reset_at'    => null,
							'retry_after' => 0,
						),
						'search_exhausted' => true,
						'secret_path'      => '/private',
					),
					'not_applicable'
				),
			),
			'provider-payload'              => array( 'inspect', $this->envelope( 'release_inspected', array_merge( $inspection, array( 'provider_payload' => array( 'token' => 'never-exported' ) ) ), 'complete' ) ),
			'wrong-primitive'               => array( 'list', $this->envelope( 'releases_listed', 'not-an-array', 'not_applicable' ) ),
			'operation-code-mismatch'       => array( 'inspect', $this->envelope( 'releases_listed', $inspection, 'not_applicable' ) ),
			'invalid-cleanup'               => array( 'inspect', $this->envelope( 'release_inspected', $inspection, 'retained' ) ),
			'invalid-utf8'                  => array( 'inspect', $this->envelope( 'release_inspected', array_replace( $inspection, array( 'package_root' => "\xB1" ) ), 'complete' ) ),
		);
	}

	/** @return array<string,mixed> */
	private function envelope( string $code, mixed $value, string $cleanup ): array {
		return array(
			'ok'             => true,
			'code'           => $code,
			'value'          => $value,
			'retry_after'    => null,
			'cleanup_status' => $cleanup,
		);
	}

	/** @return array<string,mixed> */
	private function projection( string $operation, array $result ): array {
		$root = dirname( __DIR__, 2 );
		if ( ! is_dir( $root . '/.workspaces/p0.3/php-tmp' ) ) {
			mkdir( $root . '/.workspaces/p0.3/php-tmp', 0700, true );
		}
		$file   = $root . '/.workspaces/p0.3/php-tmp/schema-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$script = '<?php '
			. 'define("FS_METHOD","direct");$GLOBALS["wp_version"]="6.8.0";$GLOBALS["wpdb"]=new stdClass();'
			. 'function add_action(string $h,mixed $c,int $p=10,int $a=1):void{} function add_filter(string $h,mixed $c,int $p=10,int $a=1):void{} '
			. '$operation=' . var_export( $operation, true ) . ';$result=' . var_export( $result, true ) . ';$registrar=require ' . var_export( $root . '/bootstrap.php', true ) . ';'
			. '$broker=$GLOBALS["ran_wp_release_updater_v1_broker"];$activation=$broker->activate(["php_version"=>PHP_VERSION,"runtime_protocol"=>4,"wordpress_version"=>"6.8.0"]);'
			. '$source=$registrar->releases("github","plugin","acme/example","99");$property=new ReflectionProperty($source,"selected");'
			. '$property->setValue($source,new class($result){public function __construct(private array $result){} public function list(array $c=[]):array{return $this->result;} public function inspect(string $i,string $t):array{return $this->result;} public function acquire(string $i,string $t,string $f):array{return $this->result;}});'
			. '$projected=match($operation){"list"=>$source->list(),"acquire"=>$source->acquire("7","v1.2.3","v2:".str_repeat("a",64)),default=>$source->inspect("7","v1.2.3")};echo json_encode(["activation"=>$activation,"projected"=>$projected]);';
		file_put_contents( $file, $script );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $root . '/.workspaces/p0.3/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		@unlink( $file );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		$decoded = json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
		self::assertTrue( $decoded['activation']['loaded'], 'The synthetic callback must run after real activation.' );
		return $decoded['projected'];
	}

	/** @return array<string,mixed> */
	private function facts(): array {
		return array(
			'artifact_filename'         => 'example.zip',
			'artifact_identity'         => '8',
			'artifact_sha256'           => str_repeat( 'a', 64 ),
			'artifact_size'             => 12,
			'assurance_facts'           => array(
				'exact_artifact_identity'       => true,
				'exact_commit_identity'         => true,
				'exact_reacquisition_supported' => true,
				'exact_release_identity'        => true,
				'provenance_verified'           => true,
				'publication_immutable'         => true,
				'repository_identity_stable'    => true,
				'trusted_digest_source'         => true,
			),
			'canonical_update_uri'      => 'https://github.com/acme/example',
			'channel'                   => 'stable',
			'commit_identity'           => str_repeat( 'b', 40 ),
			'main_file'                 => 'example.php',
			'maximum_artifact_bytes'    => 100,
			'package_root'              => 'example',
			'php_runtime_version'       => '8.2.0',
			'provider_code'             => 'github',
			'release_identity'          => '7',
			'repository_identity'       => '99',
			'repository_locator'        => 'acme/example',
			'tag'                       => 'v1.2.3',
			'target_type'               => 'plugin',
			'version'                   => '1.2.3',
			'wordpress_runtime_version' => '6.8.0',
		);
	}
}
