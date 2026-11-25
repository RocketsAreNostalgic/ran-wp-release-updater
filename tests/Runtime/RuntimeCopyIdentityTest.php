<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Runtime;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RuntimeCopyIdentityTest extends TestCase {

	private string $parent;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
	protected function setUp(): void {
		$this->parent = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/neutral-runtime-copy-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $this->parent, 0700, true );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
	protected function tearDown(): void {
		$this->remove( $this->parent );
	}

	public function test_checked_in_runtime_copy_claims_the_canonical_runtime_content_identity(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for isolated runtime and installed-package fixtures without requiring WordPress filesystem initialization.
		$copy = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );

		self::assertSame( $this->identity( dirname( __DIR__, 2 ) ), $copy['package_revision'] );
	}

	public function test_posix_identity_payload_is_unchanged_by_separator_normalization(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::markTestSkipped( 'Windows paths require separator normalization.' );
		}
		$root       = dirname( __DIR__, 2 );
		$native     = array( 'bootstrap.php', 'runtime.php' );
		$normalized = $native;
		$iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$relative     = substr( $file->getPathname(), strlen( $root ) + 1 );
				$native[]     = $relative;
				$normalized[] = str_replace( '\\', '/', $relative );
			}
		}
		sort( $native, SORT_STRING );
		sort( $normalized, SORT_STRING );
		self::assertSame( $native, $normalized );
		self::assertSame( $this->identity_from_files( $root, $native ), $this->identity_from_files( $root, $normalized ) );
	}

	public function test_equal_version_package_shaped_copies_with_divergent_content_fail_closed(): void {
		$left  = $this->package_copy( 'left' );
		$right = $this->package_copy( 'right' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $right . '/src/Runtime/RequestBroker.php', (string) file_get_contents( $right . '/src/Runtime/RequestBroker.php' ) . "\n// Divergent physical package copy.\n" );
		$this->write_runtime_copy( $right );

		$result = $this->probe(
			'require $data["left"] . "/bootstrap.php"; require $data["right"] . "/bootstrap.php"; $result=$GLOBALS["ran_wp_release_updater_v1_broker"]->activate(array("php_version"=>"8.2.0","runtime_protocol"=>11,"wordpress_version"=>"6.8.0")); echo json_encode($result);',
			array(
				'left'  => $left,
				'right' => $right,
			)
		);

		self::assertFalse( $result['loaded'] );
		self::assertSame( array( 'runtime_selection_inactive' ), array_column( $result['diagnostics'], 'code' ) );
	}

	public function test_selected_runtime_symbols_always_come_from_the_highest_winner_root(): void {
		$copies = array(
			$this->package_copy( 'beta-one', '0.1.0-beta.1' ),
			$this->package_copy( 'beta-two', '0.1.0-beta.2' ),
			$this->package_copy( 'beta-three', '0.1.0-beta.3' ),
		);
		$orders = array(
			$copies,
			array_reverse( $copies ),
			array( $copies[1], $copies[0], $copies[2] ),
		);

		foreach ( $orders as $order ) {
			$result = $this->probe(
				<<<'PHP'
foreach ($data['copies'] as $copy) require $copy . '/bootstrap.php'; $broker=$GLOBALS['ran_wp_release_updater_v1_broker']; $activation=$broker->activate(array('php_version'=>'8.2.0','runtime_protocol' => 11,'wordpress_version'=>'6.8.0')); preg_match_all("/'([^']+)' => '(src\\/[^']+\\.php)'/", file_get_contents($data['winner'] . '/runtime.php'), $matches, PREG_SET_ORDER); $symbols=array(); foreach ($matches as $match) { $symbol=str_replace('\\\\','\\',$match[1]); $reflection=new ReflectionClass($symbol); $symbols[$symbol]=array('expected'=>$data['winner'] . '/' . $match[2],'actual'=>$reflection->getFileName()); } $selected=(new ReflectionProperty($broker,'selected_root'))->getValue($broker); echo json_encode(array('activation'=>$activation,'selected'=>$selected,'symbols'=>$symbols));
PHP,
				array(
					'copies' => $order,
					'winner' => $copies[2],
				)
			);

			self::assertTrue( $result['activation']['loaded'] );
			self::assertSame( $copies[2], $result['selected'] );
			self::assertNotEmpty( $result['symbols'] );
			foreach ( $result['symbols'] as $symbol => $source ) {
				self::assertSame( $source['expected'], $source['actual'], $symbol );
			}
		}
	}

	public function test_selected_runtime_accepts_plugin_and_theme_declarations_after_boot_without_reopening_copy_intake(): void {
		$old       = $this->package_copy( 'old', '0.1.0-beta.1' );
		$new       = $this->package_copy( 'new', '0.1.0-beta.2' );
		$installed = $this->parent . '/installed';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $installed, 0700, true );

		$result = $this->probe(
			'define("WP_PLUGIN_DIR", $data["installed"]); function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p02_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p02_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} $GLOBALS["p02_hooks"]=array(); $GLOBALS["wpdb"]=new stdClass(); $GLOBALS["wp_version"]="6.8.0"; $GLOBALS["wp_theme_directories"]=array($data["installed"]); mkdir($data["installed"] . "/plugin",0700,true); mkdir($data["installed"] . "/theme",0700,true); file_put_contents($data["installed"] . "/plugin/main.php","<?php\\n/*\\nPlugin Name: Selected Plugin\\nVersion: 1.0.0\\nUpdate URI: https://github.com/acme/selected-plugin\\n*/\\n"); file_put_contents($data["installed"] . "/theme/style.css","/*\\nTheme Name: Selected Theme\\nVersion: 1.0.0\\nUpdate URI: https://github.com/acme/selected-theme\\n*/\\n"); $old=require $data["old"] . "/bootstrap.php"; $new=require $data["new"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $activation=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>11,"wordpress_version"=>"6.8.0")); $before=$broker->diagnostics()["candidate_count"]; $plugin=$old->plugin("github",$data["installed"] . "/plugin/main.php","acme/selected-plugin","123456789"); $theme=$new->theme("github",$data["installed"] . "/theme/style.css","acme/selected-theme","987654321"); $plugin->register(); $theme->register(); echo json_encode(array("activation"=>$activation,"before"=>$before,"after"=>$broker->diagnostics()["candidate_count"],"plugin"=>$plugin->status(),"theme"=>$theme->status(),"hooks"=>count($GLOBALS["p02_hooks"])));',
			array(
				'old'       => $old,
				'new'       => $new,
				'installed' => $installed,
			)
		);

		self::assertTrue( $result['activation']['loaded'] );
		self::assertSame( 2, $result['before'] );
		self::assertSame( $result['before'], $result['after'] );
		self::assertSame( 'target_active', $result['plugin']['code'] );
		self::assertSame( 'target_active', $result['theme']['code'] );
		self::assertSame( 29, $result['hooks'] );
	}

	public function test_later_genuine_copy_reuses_verified_broker_provenance_without_rehashing_its_established_root(): void {
		$first     = $this->package_copy( 'first', '0.1.0-beta.1' );
		$second    = $this->package_copy( 'second', '0.1.0-beta.2' );
		$installed = $this->parent . '/installed';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $installed . '/plugin', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $installed . '/plugin/main.php', "<?php\n/*\nPlugin Name: Provenance Probe\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/provenance-probe\n*/\n" );

		$result = $this->probe(
			'define("WP_PLUGIN_DIR", $data["installed"]); function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["provenance_probe_hooks"][]=$hook;} function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["provenance_probe_hooks"][]=$hook;} $GLOBALS["provenance_probe_hooks"]=array(); $GLOBALS["wpdb"]=new stdClass(); $GLOBALS["wp_version"]="6.8.0"; $first=require $data["first"] . "/bootstrap.php"; file_put_contents($data["first"] . "/src/Provider/GitHub/GitHubReleaseAdapter.php", "<?php\\n// The established root no longer matches its full manifest.\\n"); $second=require $data["second"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $activation=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>11,"wordpress_version"=>"6.8.0")); $target=$second->plugin("github",$data["installed"] . "/plugin/main.php","acme/provenance-probe","123456789"); $target->register(); $selected=(new ReflectionProperty($broker,"selected_root"))->getValue($broker); echo json_encode(array("activation"=>$activation,"candidates"=>$broker->diagnostics()["candidate_count"],"selected"=>$selected,"target"=>$target->status(),"hooks"=>count($GLOBALS["provenance_probe_hooks"])));',
			array(
				'first'     => $first,
				'second'    => $second,
				'installed' => $installed,
			)
		);

		self::assertTrue( $result['activation']['loaded'] );
		self::assertSame( 2, $result['candidates'] );
		self::assertSame( $second, $result['selected'] );
		self::assertSame( 'target_active', $result['target']['code'] );
		self::assertSame( 15, $result['hooks'] );
	}

	public function test_bootstrap_and_selection_do_not_hash_source_files_on_request(): void {
		$copy       = $this->package_copy( 'no-per-request-hash' );
		$probe_file = $this->parent . '/hash-probe-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$facts_file = $this->parent . '/hash-facts-' . bin2hex( random_bytes( 6 ) ) . '.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write exact fixture facts for an isolated process; WordPress JSON filters must not alter fixture bytes.
		file_put_contents( $facts_file, json_encode( array( 'copy' => $copy ), JSON_THROW_ON_ERROR ) );
		$fixture = <<<'PHP'
<?php
namespace RAN\WPReleaseUpdater\V1\Runtime {
	function hash_file( string $algorithm, string $filename, bool $binary = false ): string|false {
		++$GLOBALS['ran_runtime_selector_hash_calls'];
		return \hash_file( $algorithm, $filename, $binary );
	}
}
namespace {
	$ran_facts = json_decode( (string) file_get_contents( $argv[1] ), true, 64, JSON_THROW_ON_ERROR );
	$GLOBALS['ran_runtime_selector_hash_calls'] = 0;
	$ran_registrar                              = require $ran_facts['copy'] . '/bootstrap.php';
	$ran_broker                                 = $GLOBALS['ran_wp_release_updater_v1_broker'];
	$ran_activation                             = $ran_broker->activate(
		array(
			'php_version'       => '8.2.0',
			'runtime_protocol'  => 11,
			'wordpress_version' => '6.8.0',
		)
	);
	echo json_encode(
		array(
			'activation' => $ran_activation,
			'hash_calls' => $GLOBALS['ran_runtime_selector_hash_calls'],
			'state'      => $ran_registrar->diagnostics()['state'],
		),
		JSON_THROW_ON_ERROR
	);
}
PHP;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write isolated subprocess script for native hash interception verification.
		file_put_contents( $probe_file, $fixture );
		$output = array();
		$status = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
		exec( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $probe_file ) . ' ' . escapeshellarg( $facts_file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true, 64, JSON_THROW_ON_ERROR );

		self::assertTrue( $result['activation']['loaded'] );
		self::assertSame( 'active', $result['state'] );
		self::assertSame( 0, $result['hash_calls'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local bootstrap source bytes to statically assert no hash_file invocation exists in the global-namespace entrypoint.
		$bootstrap_source = file_get_contents( $copy . '/bootstrap.php' );
		self::assertIsString( $bootstrap_source );
		$hash_file_tokens = array();
		foreach ( token_get_all( $bootstrap_source ) as $token ) {
			if ( is_array( $token ) && T_STRING === $token[0] && 'hash_file' === strtolower( $token[1] ) ) {
				$hash_file_tokens[] = $token[1];
			}
		}
		self::assertSame( array(), $hash_file_tokens );
	}

	public function test_invalid_copy_does_not_invoke_an_autoloader_for_the_broker(): void {
		$copy = $this->package_copy( 'invalid-autoload' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $copy . '/runtime-copy.json', '{' );
		$result = $this->probe(
			'$autoloads=array();spl_autoload_register(static function(string $class)use(&$autoloads):void{$autoloads[]=$class;});$registrar=require $data["copy"]."/bootstrap.php";echo json_encode(array("autoloads"=>$autoloads,"state"=>$registrar->diagnostics()["state"],"published"=>array_key_exists("ran_wp_release_updater_v1_broker",$GLOBALS)));',
			array( 'copy' => $copy )
		);
		self::assertSame( array(), $result['autoloads'] );
		self::assertSame( 'conflict', $result['state'] );
		self::assertFalse( $result['published'] );
	}

	public function test_invalid_first_copy_cannot_define_shared_runtime_classes_before_a_valid_later_copy_boots(): void {
		$invalid = $this->package_copy( 'invalid-first' );
		$valid   = $this->package_copy( 'valid-later' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $invalid . '/runtime-copy.json', '{' );

		$result = $this->probe(
			'$invalid=require $data["invalid"] . "/bootstrap.php"; $afterInvalid=array("broker"=>class_exists("RAN\\WPReleaseUpdater\\V1\\Runtime\\RequestBroker",false),"state"=>class_exists("RAN\\WPReleaseUpdater\\V1\\Runtime\\SelectedRuntimeState",false),"published"=>array_key_exists("ran_wp_release_updater_v1_broker",$GLOBALS),"diagnostics"=>$invalid->diagnostics()); $valid=require $data["valid"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"] ?? null; $activation=is_object($broker) ? $broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>11,"wordpress_version"=>"6.8.0")) : null; echo json_encode(array("after_invalid"=>$afterInvalid,"broker"=>is_object($broker),"activation"=>$activation));',
			array(
				'invalid' => $invalid,
				'valid'   => $valid,
			)
		);

		self::assertFalse( $result['after_invalid']['broker'] );
		self::assertFalse( $result['after_invalid']['state'] );
		self::assertFalse( $result['after_invalid']['published'] );
		self::assertSame( 'conflict', $result['after_invalid']['diagnostics']['state'] );
		self::assertTrue( $result['broker'] );
		self::assertTrue( $result['activation']['loaded'] );
	}

	#[DataProvider( 'malformed_runtime_copy_provider' )]
	public function test_malformed_runtime_copy_is_rejected_before_broker_publication_or_hooks( string $manifest ): void {
		$copy = $this->package_copy( 'malformed-runtime-copy' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $copy . '/runtime-copy.json', $manifest );

		$this->assert_provenance_rejected( $copy );
	}

	public function test_semantically_invalid_runtime_version_is_rejected_before_shared_runtime_classes_load(): void {
		$copy = $this->package_copy( 'invalid-runtime-version' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for isolated runtime and installed-package fixtures without requiring WordPress filesystem initialization.
		$manifest                    = json_decode( (string) file_get_contents( $copy . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );
		$manifest['package_version'] = 'not-a-version';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test. Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
		file_put_contents( $copy . '/runtime-copy.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );

		$this->assert_provenance_rejected( $copy );
	}

	/** @return array<string,array{string}> */
	public static function malformed_runtime_copy_provider(): array {
		return array(
			'invalid JSON'      => array( '{' ),
			'list'              => array( '[]' ),
			'missing fields'    => array( '{"package_revision":"' . str_repeat( 'a', 64 ) . '"}' ),
			'wrong field types' => array( '{"package_revision":"' . str_repeat( 'a', 64 ) . '","package_version":"0.1.0-beta.1","php_floor":"8.2.0","runtime_file":"runtime.php","runtime_protocol":"5","wordpress_floor":"6.5.0"}' ),
		);
	}

	public function test_symlinked_provenance_shapes_are_rejected_before_broker_publication_or_hooks(): void {
		$cases = array(
			'manifest'         => static function ( string $copy, string $outside ): void {
				copy( $copy . '/runtime-copy.json', $outside . '/runtime-copy.json' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
				unlink( $copy . '/runtime-copy.json' );
				symlink( $outside . '/runtime-copy.json', $copy . '/runtime-copy.json' );
			},
			'source directory' => static function ( string $copy, string $outside ): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Move the real fixture entry to exercise replacement identity; an abstract filesystem would change the scenario.
				rename( $copy . '/src', $outside . '/src' );
				symlink( $outside . '/src', $copy . '/src' );
			},
			'source file'      => static function ( string $copy, string $outside ): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Move the real fixture entry to exercise replacement identity; an abstract filesystem would change the scenario.
				rename( $copy . '/src/Runtime/RequestBroker.php', $outside . '/RequestBroker.php' );
				symlink( $outside . '/RequestBroker.php', $copy . '/src/Runtime/RequestBroker.php' );
			},
		);

		foreach ( $cases as $name => $shape ) {
			$copy    = $this->package_copy( 'symlink-' . str_replace( ' ', '-', $name ) );
			$outside = $this->parent . '/outside-' . str_replace( ' ', '-', $name );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
			mkdir( $outside, 0700, true );
			$shape( $copy, $outside );
			if ( ! is_link( $copy . ( 'manifest' === $name ? '/runtime-copy.json' : ( 'source directory' === $name ? '/src' : '/src/Runtime/RequestBroker.php' ) ) ) ) {
				self::markTestSkipped( 'Symlinks are unavailable on this platform.' );
			}
			$this->assert_provenance_rejected( $copy );
		}
	}

	public function test_selected_runtime_rejects_an_interface_loaded_from_another_root_before_require(): void {
		$selected = $this->package_copy( 'selected' );
		$foreign  = $this->parent . '/foreign';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $foreign . '/src/Contract', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $foreign . '/src/Contract/ReleaseAdapter.php', "<?php\nnamespace RAN\\WPReleaseUpdater\\V1\\Contract; interface ReleaseAdapter {}\n" );

		$result = $this->probe(
			'require $data["foreign"] . "/src/Contract/ReleaseAdapter.php"; try { require $data["selected"] . "/runtime.php"; echo json_encode(array("result" => "loaded")); } catch (RuntimeException $error) { echo json_encode(array("result" => "rejected", "message" => $error->getMessage())); }',
			array(
				'foreign'  => $foreign,
				'selected' => $selected,
			)
		);

		self::assertSame( 'rejected', $result['result'] );
		self::assertSame( 'A lifecycle symbol was loaded outside the selected runtime root.', $result['message'] );
	}

	public function test_symlinked_bootstrap_and_runtime_dependencies_are_rejected_before_require(): void {
		$cases = array(
			'runtime directory'      => 'src/Runtime',
			'runtime copy selector'  => 'src/Runtime/RuntimeCopySelector.php',
			'selected runtime state' => 'src/Runtime/SelectedRuntimeState.php',
			'runtime lifecycle file' => 'src/Contract/CanonicalUpdateUri.php',
		);

		foreach ( $cases as $name => $relative ) {
			$slug     = str_replace( ' ', '-', $name );
			$copy     = $this->package_copy( 'symlink-dep-' . $slug );
			$outside  = $this->parent . '/outside-dep-' . $slug;
			$sentinel = $outside . '/executed.marker';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated symlink-rejection fixtures.
			mkdir( $outside, 0700, true );

			if ( 'src/Runtime' === $relative ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Move real directory outside selected root to test symlink rejection before require.
				rename( $copy . '/src/Runtime', $outside . '/Runtime' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Write sentinel payload to prove the symlinked file is never executed.
				file_put_contents( $outside . '/Runtime/RequestBroker.php', '<?php file_put_contents(' . var_export( $sentinel, true ) . ', "executed");' );
				symlink( $outside . '/Runtime', $copy . '/src/Runtime' );
			} else {
				$target = $outside . '/' . basename( $relative );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Write sentinel payload to prove the symlinked file is never executed.
				file_put_contents( $target, '<?php file_put_contents(' . var_export( $sentinel, true ) . ', "executed");' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Replace target file with symlink pointing outside the selected root.
				unlink( $copy . '/' . $relative );
				symlink( $target, $copy . '/' . $relative );
			}

			if ( ! is_link( $copy . '/' . $relative ) ) {
				self::markTestSkipped( 'Symlinks are unavailable on this platform.' );
			}

			if ( 'src/Contract/CanonicalUpdateUri.php' === $relative ) {
				$result = $this->probe(
					'try { require $data["copy"] . "/runtime.php"; echo json_encode(array("result" => "loaded")); } catch (RuntimeException $error) { echo json_encode(array("result" => "rejected", "message" => $error->getMessage())); }',
					array( 'copy' => $copy )
				);
				self::assertSame( 'rejected', $result['result'], $name );
				self::assertSame( 'A lifecycle symbol was loaded outside the selected runtime root.', $result['message'], $name );
			} else {
				$this->assert_provenance_rejected( $copy );
				$selector_result = $this->probe(
					'require $data["selector"]; $s = new \RAN\WPReleaseUpdater\V1\Runtime\RuntimeCopySelector(); try { $s->candidate($data["copy"] . "/runtime-copy.json"); echo json_encode(array("result" => "accepted")); } catch (RuntimeException $error) { echo json_encode(array("result" => "rejected", "message" => $error->getMessage())); }',
					array(
						'copy'     => $copy,
						'selector' => dirname( __DIR__, 2 ) . '/src/Runtime/RuntimeCopySelector.php',
					)
				);
				self::assertSame( 'rejected', $selector_result['result'], $name );
			}
			self::assertFileDoesNotExist( $sentinel, $name );
		}
	}

	public function test_protocol_11_allow_working_copy_interoperability_and_refusal(): void {
		$new_high = $this->package_copy( 'p11-new-high', '1.0.0-beta.24' );
		$new_low  = $this->package_copy( 'p11-new-low', '1.0.0-beta.22' );
		$old_low  = $this->package_copy( 'p11-old-low', '1.0.0-beta.22' );
		$old_high = $this->package_copy( 'p11-old-high', '1.0.0-beta.24' );

		// Simulate an earlier protocol-11 copy whose bootstrap emits allow_working_copy=true and whose validator only accepts true when present.
		foreach ( array( $old_low, $old_high ) as $old_copy ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local fixture bytes to construct an earlier protocol-11 peer copy.
			$bootstrap = (string) file_get_contents( $old_copy . '/bootstrap.php' );
			$bootstrap = str_replace(
				array(
					"unset( \$allow_working_copy );\n\t\treturn \$this->target( 'plugin', \$plugin_file, \$provider, \$repository, \$repository_id, \$channel, \$update_policy, \$credentials, \$maximum_artifact_bytes );",
					"unset( \$allow_working_copy );\n\t\treturn \$this->target( 'theme', \$stylesheet_file, \$provider, \$repository, \$repository_id, \$channel, \$update_policy, \$credentials, \$maximum_artifact_bytes );",
					'private function target( string $type, string $file, string $provider, string $repository, string $repository_id, string $channel, string $policy, ?callable $credentials, mixed $maximum_artifact_bytes ): object {',
					"'descriptor_admission'     => \$this->descriptor_admission,\n\t\t);",
				),
				array(
					"return \$this->target( 'plugin', \$plugin_file, \$provider, \$repository, \$repository_id, \$channel, \$update_policy, \$credentials, \$maximum_artifact_bytes, \$allow_working_copy );",
					"return \$this->target( 'theme', \$stylesheet_file, \$provider, \$repository, \$repository_id, \$channel, \$update_policy, \$credentials, \$maximum_artifact_bytes, \$allow_working_copy );",
					'private function target( string $type, string $file, string $provider, string $repository, string $repository_id, string $channel, string $policy, ?callable $credentials, mixed $maximum_artifact_bytes, bool $allow_working_copy = false ): object {',
					"'descriptor_admission'     => \$this->descriptor_admission,\n\t\t);\n\t\tif ( \$allow_working_copy ) {\n\t\t\t\$declaration['allow_working_copy'] = true;\n\t\t}",
				),
				$bootstrap
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write local earlier-protocol-11 bootstrap fixture.
			file_put_contents( $old_copy . '/bootstrap.php', $bootstrap );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local fixture validator to model pre-PR strict true-only allow_working_copy validation.
			$validator = (string) file_get_contents( $old_copy . '/src/Runtime/RequestProtocolValidator.php' );
			$validator = str_replace( 'if ( ! is_bool( $value[\'allow_working_copy\'] ) )', 'if ( true !== $value[\'allow_working_copy\'] )', $validator );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write local earlier-protocol-11 validator fixture.
			file_put_contents( $old_copy . '/src/Runtime/RequestProtocolValidator.php', $validator );
			$this->write_runtime_copy( $old_copy, $old_copy === $old_high ? '1.0.0-beta.24' : '1.0.0-beta.22' );
		}

		// Direct validator check: optional bool accepted, non-bool rejected.
		$validator_check = $this->probe(
			<<<'PHP'
require $data['validator'];
$base = array(
	'target_type' => 'plugin',
	'installed_file' => '/plugins/example/main.php',
	'provider_code' => 'github',
	'repository_locator' => 'acme/example',
	'repository_identity' => '123',
	'channel' => 'stable',
	'update_policy' => 'manual',
	'credential_resolver' => null,
	'maximum_artifact_bytes' => 52428800,
	'operation_guard' => null,
	'native_mutation_begin' => null,
	'native_mutation_complete' => null,
	'descriptor_admission' => null,
);
echo json_encode( array(
	'omitted' => \RAN\WPReleaseUpdater\V1\Runtime\RequestProtocolValidator::declaration_code( $base ),
	'false'   => \RAN\WPReleaseUpdater\V1\Runtime\RequestProtocolValidator::declaration_code( $base + array( 'allow_working_copy' => false ) ),
	'true'    => \RAN\WPReleaseUpdater\V1\Runtime\RequestProtocolValidator::declaration_code( $base + array( 'allow_working_copy' => true ) ),
	'invalid' => \RAN\WPReleaseUpdater\V1\Runtime\RequestProtocolValidator::declaration_code( $base + array( 'allow_working_copy' => 'yes' ) ),
) );
PHP,
			array( 'validator' => dirname( __DIR__, 2 ) . '/src/Runtime/RequestProtocolValidator.php' )
		);
		self::assertNull( $validator_check['omitted'] );
		self::assertNull( $validator_check['false'] );
		self::assertNull( $validator_check['true'] );
		self::assertSame( 'declaration_invalid', $validator_check['invalid'] );

		// Pair 1 (old registrar -> new broker) and Pair 2 (new registrar -> old protocol-11 broker), each in both load orders.
		$pairs = array(
			'old-first-new-winner' => array( $old_low, $new_high, 'old', 'new' ),
			'new-first-new-winner' => array( $new_high, $old_low, 'new', 'old' ),
			'old-first-old-winner' => array( $old_high, $new_low, 'old', 'new' ),
			'new-first-old-winner' => array( $new_low, $old_high, 'new', 'old' ),
		);
		foreach ( $pairs as $label => $pair ) {
			$installed = $this->parent . '/p11-installed-' . $label;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create isolated installed plugin/theme fixtures with a .git working-copy marker.
			mkdir( $installed . '/plugin/.git', 0700, true );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create isolated installed theme fixture directory.
			mkdir( $installed . '/theme/.git', 0700, true );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write isolated plugin header fixture.
			file_put_contents( $installed . '/plugin/main.php', "<?php\n/*\nPlugin Name: Working Plugin\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/working-plugin\n*/\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write isolated theme header fixture.
			file_put_contents( $installed . '/theme/style.css', "/*\nTheme Name: Working Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/working-theme\n*/\n" );

			$result = $this->probe(
				<<<'PHP'
define('WP_PLUGIN_DIR', $data['installed']);
function add_filter(string $hook, mixed $callback, int $priority, int $arguments): void {
	$GLOBALS['p11_hooks'][$hook][] = $callback;
}
function add_action(string $hook, mixed $callback, int $priority, int $arguments): void {
	$GLOBALS['p11_hooks'][$hook][] = $callback;
}
$GLOBALS['p11_hooks'] = array();
$GLOBALS['wpdb'] = new stdClass();
$GLOBALS['wp_version'] = '6.8.0';
$GLOBALS['wp_theme_directories'] = array($data['installed']);
$first_registrar = require $data['first'] . '/bootstrap.php';
$second_registrar = require $data['second'] . '/bootstrap.php';
$old_registrar = 'old' === $data['first_role'] ? $first_registrar : $second_registrar;
$new_registrar = 'new' === $data['first_role'] ? $first_registrar : $second_registrar;

$from_old_true = $old_registrar->plugin('github', $data['installed'] . '/plugin/main.php', 'acme/working-plugin', '111', allow_working_copy: true);
$from_new_dup = $new_registrar->plugin('github', $data['installed'] . '/plugin/main.php', 'acme/working-plugin', '111', allow_working_copy: false);
$from_new_true = $new_registrar->theme('github', $data['installed'] . '/theme/style.css', 'acme/working-theme', '222', 'stable', 'manual', null, 52428800, true);
$from_old_true->register();
$from_new_dup->register();
$from_new_true->register();

$activation = $GLOBALS['ran_wp_release_updater_v1_broker']->activate(array(
	'php_version' => '8.2.0',
	'runtime_protocol' => 11,
	'wordpress_version' => '6.8.0',
));
$plugin_updater = $GLOBALS['p11_hooks']['update_plugins_github.com'][0][0] ?? null;
$theme_updater = $GLOBALS['p11_hooks']['update_themes_github.com'][0][0] ?? null;
$plugin_pre = is_object($plugin_updater) ? $plugin_updater->filter_pre_download(false, 'invalid', null, array('plugin' => 'plugin/main.php')) : null;
$theme_pre = is_object($theme_updater) ? $theme_updater->filter_pre_download(false, 'invalid', null, array('theme' => 'theme')) : null;

echo json_encode(array(
	'activation' => $activation['loaded'],
	'old_true_code' => $from_old_true->status()['code'],
	'new_dup_code' => $from_new_dup->status()['code'],
	'new_true_code' => $from_new_true->status()['code'],
	'plugin_diag' => is_object($plugin_updater) ? $plugin_updater->diagnostics() : array(),
	'theme_diag' => is_object($theme_updater) ? $theme_updater->diagnostics() : array(),
	'plugin_pre' => $plugin_pre,
	'theme_pre' => $theme_pre,
));
PHP,
				array(
					'first'      => $pair[0],
					'second'     => $pair[1],
					'first_role' => $pair[2],
					'installed'  => $installed,
				)
			);
			self::assertTrue( $result['activation'], $label );
			self::assertSame( 'target_active', $result['old_true_code'], $label );
			self::assertSame( 'target_active', $result['new_dup_code'], $label );
			self::assertSame( 'target_active', $result['new_true_code'], $label );
			self::assertContains( 'target_is_working_copy', $result['plugin_diag'], $label );
			self::assertContains( 'target_is_working_copy', $result['theme_diag'], $label );
		}
	}

	private function package_copy( string $name, ?string $version = null ): string {
		$root = $this->parent . '/' . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $root, 0700, true );
		foreach ( array( 'bootstrap.php', 'runtime.php' ) as $file ) {
			copy( dirname( __DIR__, 2 ) . '/' . $file, $root . '/' . $file );
		}
		$this->copy_directory( dirname( __DIR__, 2 ) . '/src', $root . '/src' );
		$this->write_runtime_copy( $root, $version );

		return $root;
	}

	private function assert_provenance_rejected( string $copy ): void {
		$result = $this->probe( 'function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["provenance_hooks"][]=$hook;} $GLOBALS["provenance_hooks"]=array(); $registrar=require $data["copy"] . "/bootstrap.php"; echo json_encode(array("diagnostics"=>$registrar->diagnostics(),"hooks"=>$GLOBALS["provenance_hooks"],"published"=>array_key_exists("ran_wp_release_updater_v1_broker",$GLOBALS),"provenance"=>array_key_exists("ran_wp_release_updater_v1_broker_provenance",$GLOBALS)));', array( 'copy' => $copy ) );

		self::assertFalse( $result['published'] );
		self::assertFalse( $result['provenance'] );
		self::assertSame( array(), $result['hooks'] );
		self::assertSame( 'conflict', $result['diagnostics']['state'] );
		self::assertSame( array( 'protocol_conflict_inactive' ), array_column( $result['diagnostics']['diagnostics'], 'code' ) );
	}

	private function write_runtime_copy( string $root, ?string $version = null ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for isolated runtime and installed-package fixtures without requiring WordPress filesystem initialization.
		$checked_in = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents(
			$root . '/runtime-copy.json',
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
			json_encode(
				array(
					'package_revision' => $this->identity( $root ),
					'package_version'  => $version ?? $checked_in['package_version'],
					'php_floor'        => '8.2.0',
					'runtime_file'     => 'runtime.php',
					'runtime_protocol' => 11,
					'wordpress_floor'  => '6.5.0',
				),
				JSON_THROW_ON_ERROR
			)
		);
	}

	private function identity( string $root ): string {
		$files    = array( 'bootstrap.php', 'runtime.php' );
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
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

	/** @param list<string> $files */
	private function identity_from_files( string $root, array $files ): string {
		$payload = '';
		foreach ( $files as $file ) {
			$payload .= $file . "\0" . hash_file( 'sha256', $root . '/' . $file ) . "\n";
		}

		return hash( 'sha256', $payload );
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

	/** @param array<string, string|list<string>> $data
	 * @return array<string, mixed>
	 */
	private function probe( string $body, array $data ): array {
		$file = $this->parent . '/probe-' . bin2hex( random_bytes( 4 ) ) . '.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test. Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
		file_put_contents( $file, '<?php $data = ' . var_export( $data, true ) . '; ' . $body );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );

		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function remove( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$fixture_entries = scandir( $path );
		foreach ( $fixture_entries ? $fixture_entries : array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = $path . '/' . $name;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			is_dir( $child ) && ! is_link( $child ) ? $this->remove( $child ) : unlink( $child );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		rmdir( $path );
	}
}
