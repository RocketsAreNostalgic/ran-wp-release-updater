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
			'require $data["left"] . "/bootstrap.php"; require $data["right"] . "/bootstrap.php"; $result=$GLOBALS["ran_wp_release_updater_v1_broker"]->activate(array("php_version"=>"8.2.0","runtime_protocol"=>5,"wordpress_version"=>"6.8.0")); echo json_encode($result);',
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
foreach ($data['copies'] as $copy) require $copy . '/bootstrap.php'; $broker=$GLOBALS['ran_wp_release_updater_v1_broker']; $activation=$broker->activate(array('php_version'=>'8.2.0','runtime_protocol' => 5,'wordpress_version'=>'6.8.0')); preg_match_all("/'([^']+)' => '(src\\/[^']+\\.php)'/", file_get_contents($data['winner'] . '/runtime.php'), $matches, PREG_SET_ORDER); $symbols=array(); foreach ($matches as $match) { $symbol=str_replace('\\\\','\\',$match[1]); $reflection=new ReflectionClass($symbol); $symbols[$symbol]=array('expected'=>$data['winner'] . '/' . $match[2],'actual'=>$reflection->getFileName()); } $selected=(new ReflectionProperty($broker,'selected_root'))->getValue($broker); echo json_encode(array('activation'=>$activation,'selected'=>$selected,'symbols'=>$symbols));
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
			'define("WP_PLUGIN_DIR", $data["installed"]); function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p02_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p02_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} $GLOBALS["p02_hooks"]=array(); $GLOBALS["wpdb"]=new stdClass(); $GLOBALS["wp_version"]="6.8.0"; $GLOBALS["wp_theme_directories"]=array($data["installed"]); mkdir($data["installed"] . "/plugin",0700,true); mkdir($data["installed"] . "/theme",0700,true); file_put_contents($data["installed"] . "/plugin/main.php","<?php\\n/*\\nPlugin Name: Selected Plugin\\nVersion: 1.0.0\\nUpdate URI: https://github.com/acme/selected-plugin\\n*/\\n"); file_put_contents($data["installed"] . "/theme/style.css","/*\\nTheme Name: Selected Theme\\nVersion: 1.0.0\\nUpdate URI: https://github.com/acme/selected-theme\\n*/\\n"); $old=require $data["old"] . "/bootstrap.php"; $new=require $data["new"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $activation=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>5,"wordpress_version"=>"6.8.0")); $before=$broker->diagnostics()["candidate_count"]; $plugin=$old->plugin("github",$data["installed"] . "/plugin/main.php","acme/selected-plugin","123456789"); $theme=$new->theme("github",$data["installed"] . "/theme/style.css","acme/selected-theme","987654321"); $plugin->register(); $theme->register(); echo json_encode(array("activation"=>$activation,"before"=>$before,"after"=>$broker->diagnostics()["candidate_count"],"plugin"=>$plugin->status(),"theme"=>$theme->status(),"hooks"=>count($GLOBALS["p02_hooks"])));',
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
		self::assertSame( 19, $result['hooks'] );
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
			'define("WP_PLUGIN_DIR", $data["installed"]); function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["provenance_probe_hooks"][]=$hook;} function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["provenance_probe_hooks"][]=$hook;} $GLOBALS["provenance_probe_hooks"]=array(); $GLOBALS["wpdb"]=new stdClass(); $GLOBALS["wp_version"]="6.8.0"; $first=require $data["first"] . "/bootstrap.php"; file_put_contents($data["first"] . "/src/Provider/GitHub/GitHubReleaseAdapter.php", "<?php\\n// The established root no longer matches its full manifest.\\n"); $second=require $data["second"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $activation=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>5,"wordpress_version"=>"6.8.0")); $target=$second->plugin("github",$data["installed"] . "/plugin/main.php","acme/provenance-probe","123456789"); $target->register(); $selected=(new ReflectionProperty($broker,"selected_root"))->getValue($broker); echo json_encode(array("activation"=>$activation,"candidates"=>$broker->diagnostics()["candidate_count"],"selected"=>$selected,"target"=>$target->status(),"hooks"=>count($GLOBALS["provenance_probe_hooks"])));',
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
		self::assertSame( 10, $result['hooks'] );
	}

	public function test_copied_source_change_without_manifest_update_is_rejected_before_selection(): void {
		$copy = $this->package_copy( 'changed-without-manifest' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $copy . '/src/Runtime/RequestBroker.php', (string) file_get_contents( $copy . '/src/Runtime/RequestBroker.php' ) . "\n// Changed without updating the manifest.\n" );

		$result = $this->probe( '$registrar = require $data["copy"] . "/bootstrap.php"; echo json_encode(array("diagnostics" => $registrar->diagnostics(), "published" => array_key_exists("ran_wp_release_updater_v1_broker", $GLOBALS), "broker_class" => class_exists("RAN\\WPReleaseUpdater\\V1\\Runtime\\RequestBroker", false), "state_class" => class_exists("RAN\\WPReleaseUpdater\\V1\\Runtime\\SelectedRuntimeState", false)));', array( 'copy' => $copy ) );

		self::assertFalse( $result['published'] );
		self::assertFalse( $result['broker_class'] );
		self::assertFalse( $result['state_class'] );
		self::assertSame( 'conflict', $result['diagnostics']['state'] );
		self::assertSame( array( 'protocol_conflict_inactive' ), array_column( $result['diagnostics']['diagnostics'], 'code' ) );
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
			'$invalid=require $data["invalid"] . "/bootstrap.php"; $afterInvalid=array("broker"=>class_exists("RAN\\WPReleaseUpdater\\V1\\Runtime\\RequestBroker",false),"state"=>class_exists("RAN\\WPReleaseUpdater\\V1\\Runtime\\SelectedRuntimeState",false),"published"=>array_key_exists("ran_wp_release_updater_v1_broker",$GLOBALS),"diagnostics"=>$invalid->diagnostics()); $valid=require $data["valid"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"] ?? null; $activation=is_object($broker) ? $broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>5,"wordpress_version"=>"6.8.0")) : null; echo json_encode(array("after_invalid"=>$afterInvalid,"broker"=>is_object($broker),"activation"=>$activation));',
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
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
			json_encode(
				array(
					'package_revision' => $this->identity( $root ),
					'package_version'  => $version ?? $checked_in['package_version'],
					'php_floor'        => '8.2.0',
					'runtime_file'     => 'runtime.php',
					'runtime_protocol' => 5,
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
