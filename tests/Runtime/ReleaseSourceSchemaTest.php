<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\ProspectiveReleaseInspection;

final class ReleaseSourceSchemaTest extends TestCase {

	public function test_public_handle_fails_closed_for_malformed_same_protocol_results(): void {
		foreach ( $this->malformed_results() as $name => [$operation, $result] ) {
			$projection = $this->projection( $operation, $result );
			self::assertSame( 'runtime_unavailable', $projection['code'], $name );
			self::assertNull( $projection['value'], $name );
		}
	}

	public function test_inspection_fixture_is_exact_and_fingerprint_last(): void {
		$inspection = ProspectiveReleaseInspection::create( $this->facts() )->to_array();
		self::assertSame( 'fingerprint', array_key_last( $inspection ) );
		self::assertArrayNotHasKey( 'path', $inspection );
		self::assertArrayNotHasKey( 'provider_payload', $inspection );
	}

	public function test_activated_synthetic_callback_can_return_the_exact_valid_listing_shape(): void {
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

	public function test_failure_cleanup_combinations_are_operation_scoped(): void {
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

	public function test_malformed_acquisition_cleans_the_real_owned_artifact_before_return(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array(
			'nested' => 'runtime_unavailable',
			'outer'  => 'operation_failed',
		) as $mutation => $expected_code ) {
			$directory = $root . '/.workspaces/p0.3/php-tmp/schema-artifact-' . bin2hex( random_bytes( 6 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
			self::assertTrue( mkdir( $directory, 0700, true ) );
			$file   = $directory . '/proof.php';
			$script = '<?php define("FS_METHOD","direct");$GLOBALS["wp_version"]="6.8.0";$GLOBALS["wpdb"]=new stdClass();function add_action(string $h,mixed $c,int $p=10,int $a=1):void{}function add_filter(string $h,mixed $c,int $p=10,int $a=1):void{}'
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
			. '$root=' . var_export( $root, true ) . ';$mutation=' . var_export( $mutation, true ) . ';$dir=' . var_export( $directory, true ) . ';$path=$dir."/owned.zip";file_put_contents($path,"owned");chmod($path,0600);$stat=lstat($path);$registrar=require $root."/bootstrap.php";$broker=$GLOBALS["ran_wp_release_updater_v1_broker"];$broker->activate(["php_version"=>PHP_VERSION,"runtime_protocol"=>7,"wordpress_version"=>"6.8.0"]);$state=(new ReflectionProperty($broker,"selected_runtime_state"))->getValue($broker);$artifact=new \\RAN\\WPReleaseUpdater\\V1\\Archive\\TemporaryArtifact($path,hash_file("sha256",$path),["dev"=>$stat["dev"],"ino"=>$stat["ino"],"mode"=>$stat["mode"],"nlink"=>$stat["nlink"],"uid"=>$stat["uid"],"gid"=>$stat["gid"],"size"=>$stat["size"],"mtime"=>$stat["mtime"],"ctime"=>$stat["ctime"]]);$facts=' . var_export( ProspectiveReleaseInspection::create( $this->facts() )->to_array(), true ) . ';$payload=["inspection"=>$facts,"artifact"=>$artifact];if("nested"===$mutation){$payload["inspection"]["extra"]=true;}else{$payload["extra"]=true;}$calls=0;$service=new class($payload,$calls){public function __construct(private array $payload,private int &$calls){}public function acquire(string $a,string $b,string $c):array{$this->calls++;return $this->payload;}};$real=new \\RAN\\WPReleaseUpdater\\V1\\Runtime\\ReleaseSource($service,$state);$public=$registrar->releases("github","plugin","acme/example","99");(new ReflectionProperty($public,"selected"))->setValue($public,$real);$result=$public->acquire("7","v1.2.3",$facts["fingerprint"]);echo json_encode(["result"=>$result,"calls"=>$calls,"exists"=>file_exists($path)]);';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $file, $script );
			$output = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
			exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $root . '/.workspaces/p0.3/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			@unlink( $file );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			@rmdir( $directory );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			$result = json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( 1, $result['calls'] );
			self::assertSame( $expected_code, $result['result']['code'] );
			self::assertSame( 'complete', $result['result']['cleanup_status'] );
			self::assertFalse( $result['exists'] );
		}
	}

	/** @return array<string,array{string,array<string,mixed>}> */
	private function malformed_results(): array {
		$inspection    = ProspectiveReleaseInspection::create( $this->facts() )->to_array();
		$short_version = $inspection;
		unset( $short_version['fingerprint'] );
		$short_version['version'] = '1.2';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
		$short_version['fingerprint'] = 'v2:' . hash( 'sha256', json_encode( $short_version, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return array(
			'package-version-short'         => array( 'inspect', $this->envelope( 'release_inspected', $short_version, 'complete' ) ),
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

	public function test_known_rejection_is_built_by_the_selected_runtime_without_an_autoloader(): void {
		$root = dirname( __DIR__, 2 );
		if ( ! is_dir( $root . '/.workspaces/p0.3/php-tmp' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
			mkdir( $root . '/.workspaces/p0.3/php-tmp', 0700, true );
		}
		$file   = $root . '/.workspaces/p0.3/php-tmp/rejection-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$script = '<?php define("FS_METHOD","direct");$GLOBALS["wp_version"]="6.8.0";$GLOBALS["wpdb"]=new stdClass();'
			. 'function add_action(string $h,mixed $c,int $p=10,int $a=1):void{} function add_filter(string $h,mixed $c,int $p=10,int $a=1):void{} '
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode a controlled fixture path as a PHP literal for the isolated child script; this is not debug output.
			. '$registrar=require ' . var_export( $root . '/bootstrap.php', true ) . ';'
			. '$activation=$GLOBALS["ran_wp_release_updater_v1_broker"]->activate(["php_version"=>PHP_VERSION,"runtime_protocol"=>7,"wordpress_version"=>"6.8.0"]);'
			. '$failure=\\RAN\\WPReleaseUpdater\\V1\\Runtime\\ReleaseFailure::rejected("header_invalid","Version");'
			. 'echo json_encode([$activation["loaded"],$failure->rejection_reason,$failure->rejection_detail]);';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $file, $script );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $root . '/.workspaces/p0.3/php-tmp' ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		@unlink( $file );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertSame( '[true,"header_invalid","Version"]', implode( "\n", $output ) );
	}

	public function test_undeclared_source_treats_a_rejection_key_as_malformed(): void {
		$result = $this->projection( 'inspect', $this->rejected( 'no_package_zip', null ) );
		self::assertSame( 'runtime_unavailable', $result['code'] );
		self::assertSame( array( 'ok', 'code', 'value', 'retry_after', 'cleanup_status' ), array_keys( $result ) );
	}

	public function test_declared_source_passes_a_valid_rejection_through(): void {
		foreach ( array(
			array( 'multiple_package_zips', '2 ZIP assets' ),
			array( 'a_reason_added_later', null ),
		) as [$reason, $detail] ) {
			$result = $this->projection( 'inspect', $this->rejected( $reason, $detail ), true );
			self::assertSame( 'package_incompatible', $result['code'], $reason );
			self::assertSame(
				array(
					'reason' => $reason,
					'detail' => $detail,
				),
				$result['rejection']
			);
		}
	}

	public function test_declared_source_rejects_malformed_rejections(): void {
		$base = $this->rejected( 'no_package_zip', null );
		foreach ( array(
			'missing key'    => array_diff_key( $base, array( 'rejection' => 1 ) ),
			'bad reason'     => $this->rejected( 'Not A Reason', null ),
			'newline detail' => $this->rejected( 'header_invalid', "a\nb" ),
			'long detail'    => $this->rejected( 'header_invalid', str_repeat( 'a', 121 ) ),
			'empty detail'   => $this->rejected( 'header_invalid', '' ),
			'extra field'    => array_replace(
				$base,
				array(
					'rejection' => array(
						'reason' => 'x',
						'detail' => null,
						'url'    => 'https://example.test',
					),
				)
			),
			'wrong code'     => array_replace( $base, array( 'code' => 'operation_failed' ) ),
			'on success'     => array_replace(
				$this->envelope( 'release_inspected', array(), 'complete' ),
				array(
					'rejection' => array(
						'reason' => 'no_package_zip',
						'detail' => null,
					),
				)
			),
		) as $name => $malformed ) {
			self::assertSame( 'runtime_unavailable', $this->projection( 'inspect', $malformed, true )['code'], $name );
		}
	}

	/** @return array<string,mixed> */
	private function rejected( string $reason, ?string $detail ): array {
		return array(
			'ok'             => false,
			'code'           => 'package_incompatible',
			'value'          => null,
			'retry_after'    => null,
			'cleanup_status' => 'complete',
			'rejection'      => array(
				'reason' => $reason,
				'detail' => $detail,
			),
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

	/**
	 * @return array<string,mixed>
	 * @param array<string,mixed> $result
	 */
	private function projection( string $operation, array $result, bool $rejection_reasons = false ): array {
		$root = dirname( __DIR__, 2 );
		if ( ! is_dir( $root . '/.workspaces/p0.3/php-tmp' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
			mkdir( $root . '/.workspaces/p0.3/php-tmp', 0700, true );
		}
		$file   = $root . '/.workspaces/p0.3/php-tmp/schema-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$script = '<?php '
			. 'define("FS_METHOD","direct");$GLOBALS["wp_version"]="6.8.0";$GLOBALS["wpdb"]=new stdClass();'
			. 'function add_action(string $h,mixed $c,int $p=10,int $a=1):void{} function add_filter(string $h,mixed $c,int $p=10,int $a=1):void{} '
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
			. '$operation=' . var_export( $operation, true ) . ';$result=' . var_export( $result, true ) . ';$registrar=require ' . var_export( $root . '/bootstrap.php', true ) . ';'
			. '$broker=$GLOBALS["ran_wp_release_updater_v1_broker"];$activation=$broker->activate(["php_version"=>PHP_VERSION,"runtime_protocol"=>7,"wordpress_version"=>"6.8.0"]);'
			. '$source=$registrar->releases("github","plugin","acme/example","99"' . ( $rejection_reasons ? ',"stable",null,52428800,true' : '' ) . ');$property=new ReflectionProperty($source,"selected");'
			. '$property->setValue($source,new class($result){public function __construct(private array $result){} public function list(array $c=[]):array{return $this->result;} public function inspect(string $i,string $t):array{return $this->result;} public function acquire(string $i,string $t,string $f):array{return $this->result;}});'
			. '$projected=match($operation){"list"=>$source->list(),"acquire"=>$source->acquire("7","v1.2.3","v2:".str_repeat("a",64)),default=>$source->inspect("7","v1.2.3")};echo json_encode(["activation"=>$activation,"projected"=>$projected]);';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $file, $script );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $root . '/.workspaces/p0.3/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
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
