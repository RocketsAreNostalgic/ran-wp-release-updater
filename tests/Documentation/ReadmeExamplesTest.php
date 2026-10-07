<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Documentation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ReadmeExamplesTest extends TestCase {

	private string $root;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 ) . '/.workspaces/p0.5/php-tmp/readme-examples-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for executable documentation fixtures with the specified permissions.
		mkdir( $this->root . '/plugins/example-plugin/vendor/ran', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for executable documentation fixtures with the specified permissions.
		mkdir( $this->root . '/themes/example-theme/vendor/ran', 0700, true );
		symlink( dirname( __DIR__, 2 ), $this->root . '/plugins/example-plugin/vendor/ran/wp-release-updater' );
		symlink( dirname( __DIR__, 2 ), $this->root . '/themes/example-theme/vendor/ran/wp-release-updater' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for executable documentation fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $this->root . '/plugins/example-plugin/example-plugin.php', "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.2.3\nRequires at least: 6.5\nRequires PHP: 8.2\nUpdate URI: https://github.com/acme/example-plugin\n*/\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for executable documentation fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $this->root . '/themes/example-theme/style.css', "/*\nTheme Name: Example Theme\nVersion: 1.2.3\nRequires at least: 6.5\nRequires PHP: 8.2\nUpdate URI: https://github.com/acme/example-theme\n*/\n" );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
	protected function tearDown(): void {
		$workspace = dirname( __DIR__, 2 ) . '/.workspaces/p0.5/php-tmp/';
		if ( ! str_starts_with( $this->root, $workspace . 'readme-examples-' ) || ! is_dir( $this->root ) ) {
			return;
		}
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		rmdir( $this->root );
	}

	public function test_public_documentation_php_examples_execute(): void {
		$examples = $this->php_examples();
		self::assertCount( 5, $examples, 'Update this proof when materially changing the README public examples.' );
		self::assertStringContainsString( 'Plugin Name: Example Plugin', $examples[0] );
		self::assertStringContainsString( '$registrar->plugin(', $examples[1] );
		self::assertStringContainsString( '$release_updater->diagnostics()', $examples[2] );
		self::assertStringContainsString( 'EXAMPLE_PLUGIN_GITHUB_TOKEN', $examples[3] );
		self::assertStringContainsString( '$registrar->releases(', $examples[4] );
		self::assertStringContainsString( 'instanceof \\WP_Filesystem_Direct', $examples[4] );

		$this->assert_script( 'header.php', $examples[0], 'echo json_encode(["header" => true]);', array( 'header' => true ) );
		$this->assert_plugin( $examples[1], false );
		$this->assert_status_methods( $examples[1], $examples[2] );
		$this->assert_plugin( $examples[3], true );
		$this->assert_release_source_fence( 'plugin', 'fence-list', $examples[4] );

		$integration = $this->php_fences( dirname( __DIR__, 2 ) . '/docs/integration.md' );
		self::assertCount( 4, $integration, 'Integration guide examples changed without updating their executable proof.' );
		self::assertStringContainsString( '$registrar->plugin(', $integration[0] );
		self::assertStringContainsString( '$registrar->theme(', $integration[1] );
		self::assertStringContainsString( 'EXAMPLE_PLUGIN_GITHUB_TOKEN', $integration[2] );
		self::assertStringContainsString( '$release_updater->status()', $integration[3] );
		$this->assert_plugin( $integration[0], false );
		$this->assert_theme( $integration[1] );
		$this->assert_plugin( $integration[2], true );
		$this->assert_status_methods( $integration[0], $integration[3] );
	}

	public function test_public_guides_stay_forward_looking_and_link_the_durable_documentation_set(): void {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for executable documentation fixtures without requiring WordPress filesystem initialization.
		$readme = file_get_contents( $root . '/README.md' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for executable documentation fixtures without requiring WordPress filesystem initialization.
		$contributing = file_get_contents( $root . '/CONTRIBUTING.md' );
		$guides       = array(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for executable documentation fixtures without requiring WordPress filesystem initialization.
			'architecture'    => file_get_contents( $root . '/docs/architecture.md' ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for executable documentation fixtures without requiring WordPress filesystem initialization.
			'integration'     => file_get_contents( $root . '/docs/integration.md' ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for executable documentation fixtures without requiring WordPress filesystem initialization.
			'release-sources' => file_get_contents( $root . '/docs/release-sources.md' ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for executable documentation fixtures without requiring WordPress filesystem initialization.
			'testing'         => file_get_contents( $root . '/docs/testing.md' ),
		);
		self::assertIsString( $readme );
		self::assertIsString( $contributing );
		foreach ( $guides as $guide ) {
			self::assertIsString( $guide );
		}
		self::assertDoesNotMatchRegularExpression( '/\\bProtocol\\s+\\d+\\b|ran\\/wp-github-release-updater|\\blegacy\\b/i', $readme );
		self::assertDoesNotMatchRegularExpression( '/ran\\/wp-github-release-updater|\\blegacy\\b|adjacent worktree/i', $contributing );
		foreach ( $guides as $guide ) {
			self::assertDoesNotMatchRegularExpression( '/\\bProtocol\\s+\\d+\\b|ran\\/wp-github-release-updater|adjacent worktree/i', $guide );
		}
		foreach ( array( 'integration', 'release-sources', 'architecture', 'testing' ) as $name ) {
			self::assertStringContainsString( 'docs/' . $name . '.md', $readme );
			self::assertFileExists( $root . '/docs/' . $name . '.md' );
		}
		foreach ( array( 'provider-architecture.md', 'release-management.md', 'runtime-compatibility.md', 'wordpress-integration.md', 'updater-family-architecture.md' ) as $obsolete ) {
			self::assertFileDoesNotExist( $root . '/docs/' . $obsolete );
		}
		$registrar = require $root . '/bootstrap.php';
		foreach ( array( 'plugin', 'theme' ) as $method ) {
			$parameter = ( new \ReflectionMethod( $registrar, $method ) )->getParameters()[7];
			self::assertSame( 'maximum_artifact_bytes', $parameter->getName(), $method );
			self::assertTrue( $parameter->isDefaultValueAvailable(), $method );
			self::assertSame( 52_428_800, $parameter->getDefaultValue(), $method );
		}
		self::assertStringContainsString( 'maximum_artifact_bytes', $readme );
		self::assertStringContainsString( '52,428,800 bytes', $readme );
	}

	public function test_release_source_examples_execute_through_the_public_bootstrap(): void {
		$script = dirname( __DIR__, 2 ) . '/tests/Integration/release-source-consumer-proof.php';
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( 'happy', 'liveness', 'discard' ) as $scenario ) {
				$command = $this->child_php_command( $script, array( $type, $scenario ) );
				$output  = array();
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
				exec( $command, $output, $status );
				self::assertSame( 0, $status, implode( "\n", $output ) );
				$result = json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
				self::assertSame( $type, $result['type'] );
				self::assertSame( $scenario, $result['scenario'] );
				self::assertSame( 'runtime_not_ready', $result['before'] );
				self::assertSame( 3, $result['credential_operations'] );
				self::assertSame(
					array(
						'requests' => 1,
						'zips'     => 0,
					),
					$result['http']['list']
				);
				self::assertSame(
					array(
						'requests' => 6,
						'zips'     => 1,
					),
					$result['http']['inspect']
				);
				self::assertSame(
					array(
						'requests' => 6,
						'zips'     => 1,
					),
					$result['http']['acquire']
				);
			}
		}
	}

	public function test_release_source_acquisition_guide_executes_against_the_public_source(): void {
		$fences  = $this->php_fences( dirname( __DIR__, 2 ) . '/docs/release-sources.md' );
		$matches = array_values(
			array_filter(
				$fences,
				static fn( string $fence ): bool => str_contains( $fence, '$source->acquire(' )
			)
		);
		self::assertCount( 1, $matches, 'The release-source guide must have one executable acquisition example.' );
		self::assertStringContainsString( 'new \\RuntimeException', $matches[0] );
		self::assertStringContainsString( 'catch (\\Throwable', $matches[0] );
		$this->assert_release_source_fence( 'plugin', 'fence-acquire', $matches[0] );
	}

	public function test_release_fence_cannot_pass_when_its_callback_does_no_operation(): void {
		$script = dirname( __DIR__, 2 ) . '/tests/Integration/release-source-consumer-proof.php';
		$fence  = '$source = $registrar->releases(provider: "github", package_type: "plugin", repository: "acme/consumer", repository_id: "99"); add_action("init", static function (): void {});';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Carry exact repository documentation-fence bytes through the escaped CLI argument; this is a controlled test payload.
		$command = $this->child_php_command( $script, array( 'plugin', 'fence-list', base64_encode( $fence ) ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
		exec( $command, $output, $status );
		self::assertNotSame( 0, $status, 'A release fence without an operation must fail its proof.' );
	}

	/** @return list<string> */
	private function php_examples(): array {
		return $this->php_fences( dirname( __DIR__, 2 ) . '/README.md' );
	}

	/** @return list<string> */
	private function php_fences( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for executable documentation fixtures without requiring WordPress filesystem initialization.
		$contents = file_get_contents( $path );
		self::assertIsString( $contents );
		preg_match_all( '/```php\\n(.*?)\\n```/s', $contents, $matches );
		return $matches[1];
	}

	private function assert_release_source_fence( string $type, string $scenario, string $fence ): void {
		$script = dirname( __DIR__, 2 ) . '/tests/Integration/release-source-consumer-proof.php';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Carry exact repository documentation-fence bytes through the escaped CLI argument; this is a controlled test payload.
		$command = $this->child_php_command( $script, array( $type, $scenario, base64_encode( $fence ) ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
		exec( $command, $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $scenario, $result['scenario'] );
	}

	/** @param list<string> $arguments */
	private function child_php_command( string $script, array $arguments ): string {
		$command             = escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $this->root );
		$extension_directory = ini_get( 'extension_dir' );
		$zip_library         = is_string( $extension_directory ) ? $extension_directory . DIRECTORY_SEPARATOR . ( DIRECTORY_SEPARATOR === '\\' ? 'php_zip.dll' : 'zip.so' ) : '';
		if ( extension_loaded( 'zip' ) && is_file( $zip_library ) ) {
			$command .= ' -d extension=' . escapeshellarg( $zip_library );
		}
		$command .= ' ' . escapeshellarg( $script );
		foreach ( $arguments as $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}
		return $command;
	}

	private function assert_plugin( string $example, bool $is_private ): void {
		if ( $is_private ) {
			$needle            = "static fn (): ?string => getenv('EXAMPLE_PLUGIN_GITHUB_TOKEN') ?: null";
			$replacement_count = 0;
			$instrumented      = str_replace(
				$needle,
				'static function (): ?string { ++$GLOBALS[\'readme_credential_calls\']; return \'secret\'; }',
				$example,
				$replacement_count
			);
			self::assertSame( 1, $replacement_count, 'Credential example instrumentation must match exactly once.' );
			$example = '$registrar = require __DIR__ . \'/vendor/ran/wp-release-updater/bootstrap.php\';' . "\n\n" . $instrumented;
		}
		$body   = $this->plugin_header() . "\n" . $example . "\n"
			. '$before=readmeSnapshot($registrar);$status=$release_updater->status();$diagnostics=$release_updater->diagnostics();$refresh=$release_updater->refresh();'
			. 'runAfterSetupTheme();echo json_encode(["before"=>$before,"registered"=>$release_updater->register(),"status"=>$release_updater->status(),"diagnostics"=>$release_updater->diagnostics(),"refresh"=>$refresh,"credential_calls"=>$GLOBALS["readme_credential_calls"]]);';
		$result = $this->execute_example( 'example-plugin.php', $body, $this->root . '/plugins/example-plugin' );
		self::assertTrue( $result['registered'] );
		self::assertSame( 'target_active', $result['status']['code'] );
		self::assertSame( 'active', $result['diagnostics']['state'] );
		self::assertIsBool( $result['refresh'] );
		self::assertSame( 0, $result['credential_calls'] );
		$this->assert_activation_ordering( $result, 'plugin', 'example-plugin.php', 'acme/example-plugin' );
	}

	private function assert_theme( string $example ): void {
		$body   = $example . "\n"
			. '$before=readmeSnapshot($registrar);runAfterSetupTheme();echo json_encode(["before"=>$before,"registered"=>$release_updater->register(),"status"=>$release_updater->status(),"diagnostics"=>$release_updater->diagnostics()]);';
		$result = $this->execute_example( 'active-theme.php', $body, $this->root . '/themes/example-theme' );
		self::assertTrue( $result['registered'] );
		self::assertSame( 'target_active', $result['status']['code'] );
		self::assertSame( 'active', $result['diagnostics']['state'] );
		$this->assert_activation_ordering( $result, 'theme', 'example-theme/style.css', 'acme/example-theme' );
	}

	private function assert_status_methods( string $registration, string $example ): void {
		$body   = $this->plugin_header() . "\n" . $registration . "\n" . $example . "\n"
			. '$before=readmeSnapshot($registrar);runAfterSetupTheme();echo json_encode(["before"=>$before,"status"=>$release_updater->status(),"diagnostics"=>$release_updater->diagnostics(),"refresh"=>$release_updater->refresh()]);';
		$result = $this->execute_example( 'example-plugin.php', $body, $this->root . '/plugins/example-plugin' );
		self::assertSame( 'target_active', $result['status']['code'] );
		self::assertSame( 'active', $result['diagnostics']['state'] );
		self::assertIsBool( $result['refresh'] );
		$this->assert_activation_ordering( $result, 'plugin', 'example-plugin.php', 'acme/example-plugin' );
	}


	/** @param array<string,mixed> $expected */
	private function assert_script( string $name, string $example, string $tail, array $expected ): void {
		self::assertSame( $expected, $this->execute_example( $name, $example . "\n" . $tail, $this->root . '/plugins/example-plugin' ) );
	}

	private function plugin_header(): string {
		return '/* Plugin Name: Example Plugin' . "\n" . 'Version: 1.2.3' . "\n" . 'Update URI: https://github.com/acme/example-plugin' . "\n" . '*/';
	}

	/** @param array<string,mixed> $result */
	private function assert_activation_ordering( array $result, string $type, string $file, string $repository ): void {
		self::assertSame( 'collecting', $result['before']['broker']['state'] );
		self::assertSame( 1, $result['before']['broker']['submission_count'] );
		self::assertSame(
			array(
				array(
					'hook'     => 'after_setup_theme',
					'priority' => PHP_INT_MAX,
				),
			),
			$result['before']['schedule']
		);
		self::assertSame( $type, $result['before']['declaration']['target_type'] );
		self::assertStringEndsWith( '/' . $file, $result['before']['declaration']['installed_file'] );
		self::assertSame( $repository, $result['before']['declaration']['repository_locator'] );
	}

	/** @return array<string,mixed> */
	private function execute_example( string $name, string $body, string $directory ): array {
		$file   = $directory . '/' . $name;
		$body   = str_replace( '<?php', '', $body );
		$prefix = '<?php '
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
			. 'define("WP_PLUGIN_DIR", ' . var_export( $this->root . '/plugins', true ) . ');'
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
			. '$GLOBALS["readme_hooks"]=[];$GLOBALS["readme_credential_calls"]=0;$GLOBALS["wpdb"]=new stdClass();$GLOBALS["wp_version"]="6.8.0";$GLOBALS["wp_theme_directories"]=[' . var_export( $this->root . '/themes', true ) . '];'
			. 'function add_filter(string $hook,mixed $callback,int $priority=10,int $arguments=1):void{$GLOBALS["readme_hooks"][]=["hook"=>$hook,"callback"=>$callback,"priority"=>$priority];}'
			. 'function add_action(string $hook,mixed $callback,int $priority=10,int $arguments=1):void{$GLOBALS["readme_hooks"][]=["hook"=>$hook,"callback"=>$callback,"priority"=>$priority];}'
			. 'function doing_action(string $hook):bool{return false;}function did_action(string $hook):int{return 0;}'
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
			. 'function get_theme_root(string $stylesheet=""):string{return ' . var_export( $this->root . '/themes', true ) . ';}'
			. 'function readmeSnapshot(object $registrar):array{$broker=$registrar->diagnostics();$submissions=(new ReflectionProperty($GLOBALS["ran_wp_release_updater_v1_broker"],"submissions"))->getValue($GLOBALS["ran_wp_release_updater_v1_broker"]);return ["broker"=>$broker,"schedule"=>array_map(static fn(array $hook):array=>["hook"=>$hook["hook"],"priority"=>$hook["priority"]],$GLOBALS["readme_hooks"]),"declaration"=>$submissions[1]["declaration"]??null];}'
			. 'function runAfterSetupTheme():void{foreach($GLOBALS["readme_hooks"] as $registered){if("after_setup_theme"===$registered["hook"]){$registered["callback"]();}}}';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for executable documentation fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $file, $prefix . "\n" . $body );
		$command = escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $this->root ) . ' ' . escapeshellarg( $file );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
		exec( $command, $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}
}
