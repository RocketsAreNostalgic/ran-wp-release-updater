<?php

declare(strict_types=1);

namespace Tests\Documentation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ReadmeExamplesTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname( __DIR__, 2 ) . '/.workspaces/p0.5/php-tmp/readme-examples-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/plugins/example-plugin/vendor/ran', 0700, true );
		mkdir( $this->root . '/themes/example-theme/vendor/ran', 0700, true );
		mkdir( $this->root . '/themes/managed-theme', 0700, true );
		symlink( dirname( __DIR__, 2 ), $this->root . '/plugins/example-plugin/vendor/ran/wp-release-updater' );
		symlink( dirname( __DIR__, 2 ), $this->root . '/themes/example-theme/vendor/ran/wp-release-updater' );
		file_put_contents( $this->root . '/plugins/example-plugin/example-plugin.php', "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.2.3\nRequires at least: 6.5\nRequires PHP: 8.2\nUpdate URI: https://github.com/acme/example-plugin\n*/\n" );
		file_put_contents( $this->root . '/themes/example-theme/style.css', "/*\nTheme Name: Example Theme\nVersion: 1.2.3\nRequires at least: 6.5\nRequires PHP: 8.2\nUpdate URI: https://github.com/acme/example-theme\n*/\n" );
		file_put_contents( $this->root . '/themes/managed-theme/style.css', "/*\nTheme Name: Managed Theme\nVersion: 1.2.3\nRequires at least: 6.5\nRequires PHP: 8.2\nUpdate URI: https://github.com/acme/managed-theme\n*/\n" );
	}

	protected function tearDown(): void
	{
		$workspace = dirname( __DIR__, 2 ) . '/.workspaces/p0.5/php-tmp/';
		if ( ! str_starts_with( $this->root, $workspace . 'readme-examples-' ) || ! is_dir( $this->root ) ) {
			return;
		}
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	public function testEveryReadmePhpFenceIsSyntaxValidAndItsRegistrationContractExecutes(): void
	{
		$examples = $this->phpExamples();
		self::assertCount( 6, $examples, 'Update this proof when adding, removing, or materially changing a README PHP example.' );
		self::assertStringContainsString( 'Plugin Name: Example Plugin', $examples[0] );
		self::assertStringContainsString( '$registrar->plugin(', $examples[1] );
		self::assertStringContainsString( '$releaseUpdater->diagnostics()', $examples[2] );
		self::assertStringContainsString( 'EXAMPLE_PLUGIN_GITHUB_TOKEN', $examples[3] );
		self::assertStringContainsString( '$registrar->theme(', $examples[4] );
		self::assertStringContainsString( 'get_theme_root( $stylesheet )', $examples[5] );

		$this->assertScript( 'header.php', $examples[0], 'echo json_encode(["header" => true]);', array( 'header' => true ) );
		$this->assertPlugin( $examples[1], false );
		$this->assertStatusMethods( $examples[1], $examples[2] );
		$this->assertPlugin( $examples[3], true );
		$this->assertTheme( $examples[4] );
		$this->assertManagedTheme( $examples[5] );
	}

	public function testPublicGuidesStayForwardLookingAndLinkTheProviderArchitecture(): void
	{
		$readme = file_get_contents( dirname( __DIR__, 2 ) . '/README.md' );
		$contributing = file_get_contents( dirname( __DIR__, 2 ) . '/CONTRIBUTING.md' );
		$providerArchitecture = file_get_contents( dirname( __DIR__, 2 ) . '/docs/provider-architecture.md' );
		self::assertIsString( $readme );
		self::assertIsString( $contributing );
		self::assertIsString( $providerArchitecture );
		self::assertDoesNotMatchRegularExpression( '/\\bProtocol\\s+\\d+\\b|ran\\/wp-github-release-updater|\\blegacy\\b/i', $readme );
		self::assertDoesNotMatchRegularExpression( '/ran\\/wp-github-release-updater|\\blegacy\\b|adjacent worktree/i', $contributing );
		self::assertDoesNotMatchRegularExpression( '/\\bProtocol\\s+\\d+\\b|ran\\/wp-github-release-updater|\\blegacy\\b/i', $providerArchitecture );
		self::assertStringContainsString( '[Provider architecture](docs/provider-architecture.md)', $readme );
		self::assertFileExists( dirname( __DIR__, 2 ) . '/docs/provider-architecture.md' );
		$registrar = require dirname( __DIR__, 2 ) . '/bootstrap.php';
		foreach ( array( 'plugin', 'theme' ) as $method ) {
			$parameter = ( new \ReflectionMethod( $registrar, $method ) )->getParameters()[7];
			self::assertSame( 'maximumArtifactBytes', $parameter->getName(), $method );
			self::assertTrue( $parameter->isDefaultValueAvailable(), $method );
			self::assertSame( 52_428_800, $parameter->getDefaultValue(), $method );
		}
		self::assertStringContainsString( 'maximumArtifactBytes', $readme );
		self::assertStringContainsString( '52,428,800-byte', $readme );
	}

	/** @return list<string> */
	private function phpExamples(): array
	{
		$readme = file_get_contents( dirname( __DIR__, 2 ) . '/README.md' );
		self::assertIsString( $readme );
		preg_match_all( '/```php\\n(.*?)\\n```/s', $readme, $matches );
		return $matches[1];
	}

	private function assertPlugin( string $example, bool $private ): void
	{
		if ( $private ) {
			$example = str_replace(
				"static fn (): ?string => getenv( 'EXAMPLE_PLUGIN_GITHUB_TOKEN' ) ?: null",
				'static function (): ?string { ++$GLOBALS[\'readme_credential_calls\']; return \'secret\'; }',
				$example
			);
			$example = '$registrar = require __DIR__ . \'/vendor/ran/wp-release-updater/bootstrap.php\';' . "\n\n" . $example;
		}
		$body = $this->pluginHeader() . "\n" . $example . "\n"
			. '$before=readmeSnapshot($registrar);$status=$releaseUpdater->status();$diagnostics=$releaseUpdater->diagnostics();$refresh=$releaseUpdater->refresh();'
			. 'runAfterSetupTheme();echo json_encode(["before"=>$before,"registered"=>$releaseUpdater->register(),"status"=>$releaseUpdater->status(),"diagnostics"=>$releaseUpdater->diagnostics(),"refresh"=>$refresh,"credential_calls"=>$GLOBALS["readme_credential_calls"]]);';
		$result = $this->executeExample( 'example-plugin.php', $body, $this->root . '/plugins/example-plugin' );
		self::assertTrue( $result['registered'] );
		self::assertSame( 'target_active', $result['status']['code'] );
		self::assertSame( 'active', $result['diagnostics']['state'] );
		self::assertIsBool( $result['refresh'] );
		self::assertSame( 0, $result['credential_calls'] );
		$this->assertActivationOrdering( $result, 'plugin', 'example-plugin.php', 'acme/example-plugin' );
	}

	private function assertTheme( string $example ): void
	{
		$body = $example . "\n"
			. '$before=readmeSnapshot($registrar);runAfterSetupTheme();echo json_encode(["before"=>$before,"registered"=>$releaseUpdater->register(),"status"=>$releaseUpdater->status(),"diagnostics"=>$releaseUpdater->diagnostics()]);';
		$result = $this->executeExample( 'active-theme.php', $body, $this->root . '/themes/example-theme' );
		self::assertTrue( $result['registered'] );
		self::assertSame( 'target_active', $result['status']['code'] );
		self::assertSame( 'active', $result['diagnostics']['state'] );
		$this->assertActivationOrdering( $result, 'theme', 'example-theme/style.css', 'acme/example-theme' );
	}

	private function assertStatusMethods( string $registration, string $example ): void
	{
		$body = $this->pluginHeader() . "\n" . $registration . "\n" . $example . "\n"
			. '$before=readmeSnapshot($registrar);runAfterSetupTheme();echo json_encode(["before"=>$before,"status"=>$releaseUpdater->status(),"diagnostics"=>$releaseUpdater->diagnostics(),"refresh"=>$releaseUpdater->refresh()]);';
		$result = $this->executeExample( 'example-plugin.php', $body, $this->root . '/plugins/example-plugin' );
		self::assertSame( 'target_active', $result['status']['code'] );
		self::assertSame( 'active', $result['diagnostics']['state'] );
		self::assertIsBool( $result['refresh'] );
		$this->assertActivationOrdering( $result, 'plugin', 'example-plugin.php', 'acme/example-plugin' );
	}

	private function assertManagedTheme( string $example ): void
	{
		$bootstrap = var_export( $this->root . '/plugins/example-plugin/vendor/ran/wp-release-updater/bootstrap.php', true );
		$body = '$registrar=require ' . $bootstrap . ';' . "\n" . $example . "\n"
			. '$before=readmeSnapshot($registrar);runAfterSetupTheme();echo json_encode(["before"=>$before,"registered"=>$managedThemeUpdater->register(),"status"=>$managedThemeUpdater->status(),"diagnostics"=>$managedThemeUpdater->diagnostics()]);';
		$result = $this->executeExample( 'managed-theme.php', $body, $this->root . '/plugins/example-plugin' );
		self::assertTrue( $result['registered'] );
		self::assertSame( 'target_active', $result['status']['code'] );
		self::assertSame( 'active', $result['diagnostics']['state'] );
		$this->assertActivationOrdering( $result, 'theme', 'managed-theme/style.css', 'acme/managed-theme' );
	}

	/** @param array<string,mixed> $expected */
	private function assertScript( string $name, string $example, string $tail, array $expected ): void
	{
		self::assertSame( $expected, $this->executeExample( $name, $example . "\n" . $tail, $this->root . '/plugins/example-plugin' ) );
	}

	private function pluginHeader(): string
	{
		return '/* Plugin Name: Example Plugin' . "\n" . 'Version: 1.2.3' . "\n" . 'Update URI: https://github.com/acme/example-plugin' . "\n" . '*/';
	}

	/** @param array<string,mixed> $result */
	private function assertActivationOrdering( array $result, string $type, string $file, string $repository ): void
	{
		self::assertSame( 'collecting', $result['before']['broker']['state'] );
		self::assertSame( 1, $result['before']['broker']['submission_count'] );
		self::assertSame( array( array( 'hook' => 'after_setup_theme', 'priority' => PHP_INT_MAX ) ), $result['before']['schedule'] );
		self::assertSame( $type, $result['before']['declaration']['target_type'] );
		self::assertStringEndsWith( '/' . $file, $result['before']['declaration']['installed_file'] );
		self::assertSame( $repository, $result['before']['declaration']['repository_locator'] );
	}

	/** @return array<string,mixed> */
	private function executeExample( string $name, string $body, string $directory ): array
	{
		$file = $directory . '/' . $name;
		$body = str_replace( '<?php', '', $body );
		$prefix = '<?php '
			. 'define("WP_PLUGIN_DIR", ' . var_export( $this->root . '/plugins', true ) . ');'
			. '$GLOBALS["readme_hooks"]=[];$GLOBALS["readme_credential_calls"]=0;$GLOBALS["wpdb"]=new stdClass();$GLOBALS["wp_version"]="6.8.0";$GLOBALS["wp_theme_directories"]=[' . var_export( $this->root . '/themes', true ) . '];'
			. 'function add_filter(string $hook,mixed $callback,int $priority=10,int $arguments=1):void{$GLOBALS["readme_hooks"][]=["hook"=>$hook,"callback"=>$callback,"priority"=>$priority];}'
			. 'function add_action(string $hook,mixed $callback,int $priority=10,int $arguments=1):void{$GLOBALS["readme_hooks"][]=["hook"=>$hook,"callback"=>$callback,"priority"=>$priority];}'
			. 'function doing_action(string $hook):bool{return false;}function did_action(string $hook):int{return 0;}'
			. 'function get_theme_root(string $stylesheet=""):string{return ' . var_export( $this->root . '/themes', true ) . ';}'
			. 'function readmeSnapshot(object $registrar):array{$broker=$registrar->diagnostics();$submissions=(new ReflectionProperty($GLOBALS["ran_wp_release_updater_v1_broker"],"submissions"))->getValue($GLOBALS["ran_wp_release_updater_v1_broker"]);return ["broker"=>$broker,"schedule"=>array_map(static fn(array $hook):array=>["hook"=>$hook["hook"],"priority"=>$hook["priority"]],$GLOBALS["readme_hooks"]),"declaration"=>$submissions[1]["declaration"]??null];}'
			. 'function runAfterSetupTheme():void{foreach($GLOBALS["readme_hooks"] as $registered){if("after_setup_theme"===$registered["hook"]){$registered["callback"]();}}}';
		file_put_contents( $file, $prefix . "\n" . $body );
		$command = escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $this->root ) . ' ' . escapeshellarg( $file );
		exec( $command, $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}
}
