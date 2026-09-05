<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class WindowsPortabilityProofTest extends TestCase
{
	private string $workspace;

	protected function setUp(): void
	{
		$this->workspace = dirname( __DIR__, 2 ) . '/.workspaces/windows-portability-proof-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->workspace . '/plugins/example', 0700, true );
		mkdir( $this->workspace . '/themes/example', 0700, true );
		mkdir( $this->workspace . '/php-tmp', 0700, true );
	}

	protected function tearDown(): void
	{
		if ( isset( $this->workspace ) ) {
			$this->remove( $this->workspace );
		}
	}

	public function testCheckedInRuntimeIdentityActivatesAndResolvesPluginAndThemeOnDrivePaths(): void
	{
		$plugin = $this->workspace . '/plugins/example/main.php';
		$theme = $this->workspace . '/themes/example/style.css';
		file_put_contents( $plugin, "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example-plugin\n*/\n" );
		file_put_contents( $theme, "/*\nTheme Name: Example Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example-theme\n*/\n" );

		$result = $this->probe( array(
			'package' => dirname( __DIR__, 2 ),
			'plugin' => $plugin,
			'plugins' => $this->workspace . '/plugins',
			'theme' => $theme,
			'themes' => $this->workspace . '/themes',
		) );

		$package = str_replace( '\\', '/', $result['package'] );
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::assertMatchesRegularExpression( '/\A[A-Za-z]:\//', $package );
		} else {
			self::assertStringStartsWith( '/', $package );
		}
		self::assertTrue(
			$result['activation']['loaded'],
			json_encode( $result['activation'], JSON_THROW_ON_ERROR )
		);
		self::assertSame( 'target_active', $result['plugin']['code'] );
		self::assertSame( 'target_active', $result['theme']['code'] );
		self::assertSame( 19, $result['hooks'] );
	}

	public function testCopiedPackageBootstrapVerifiesProvenanceOnNativePaths(): void
	{
		$copy = $this->packageCopy();
		$plugin = $this->workspace . '/plugins/example/main.php';
		$theme = $this->workspace . '/themes/example/style.css';
		file_put_contents( $plugin, "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example-plugin\n*/\n" );
		file_put_contents( $theme, "/*\nTheme Name: Example Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example-theme\n*/\n" );

		$result = $this->probe( array(
			'package' => $copy,
			'plugin' => $plugin,
			'plugins' => $this->workspace . '/plugins',
			'theme' => $theme,
			'themes' => $this->workspace . '/themes',
		) );

		self::assertTrue( $result['activation']['loaded'], json_encode( $result['activation'], JSON_THROW_ON_ERROR ) );
		self::assertSame( 'target_active', $result['plugin']['code'] );
		self::assertSame( 'target_active', $result['theme']['code'] );
	}

	private function packageCopy(): string
	{
		$root = $this->workspace . '/package';
		mkdir( $root, 0700, true );
		$source = dirname( __DIR__, 2 );
		foreach ( array( 'bootstrap.php', 'runtime.php' ) as $file ) {
			copy( $source . DIRECTORY_SEPARATOR . $file, $root . DIRECTORY_SEPARATOR . $file );
		}
		$this->copyDirectory( $source . DIRECTORY_SEPARATOR . 'src', $root . DIRECTORY_SEPARATOR . 'src' );
		$checkedIn = json_decode( (string) file_get_contents( $source . DIRECTORY_SEPARATOR . 'runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );
		file_put_contents( $root . DIRECTORY_SEPARATOR . 'runtime-copy.json', json_encode( array( 'package_revision' => $this->identity( $root ), 'package_version' => $checkedIn['package_version'], 'php_floor' => '8.2.0', 'runtime_file' => 'runtime.php', 'runtime_protocol' => 2, 'wordpress_floor' => '6.5.0' ), JSON_THROW_ON_ERROR ) );

		return $root;
	}

	private function identity( string $root ): string
	{
		$files = array( 'bootstrap.php', 'runtime.php' );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . DIRECTORY_SEPARATOR . 'src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			}
		}
		sort( $files, SORT_STRING );
		$payload = '';
		foreach ( $files as $file ) {
			$payload .= $file . "\0" . hash_file( 'sha256', $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $file ) ) . "\n";
		}

		return hash( 'sha256', $payload );
	}

	private function copyDirectory( string $source, string $destination ): void
	{
		mkdir( $destination, 0700, true );
		foreach ( scandir( $source ) ?: array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$from = $source . DIRECTORY_SEPARATOR . $name;
			$to = $destination . DIRECTORY_SEPARATOR . $name;
			is_dir( $from ) ? $this->copyDirectory( $from, $to ) : copy( $from, $to );
		}
	}

	/** @param array<string,string> $data @return array{activation:array<string,mixed>,hooks:int,package:string,plugin:array<string,mixed>,theme:array<string,mixed>} */
	private function probe( array $data ): array
	{
		$probe = $this->workspace . '/proof.php';
		file_put_contents( $probe, '<?php $data = ' . var_export( $data, true ) . <<<'PHP'
;
define('WP_PLUGIN_DIR', $data['plugins']);
function add_filter(string $hook, mixed $callback, int $priority, int $arguments): void { $GLOBALS['windows_portability_hooks'][] = $hook; }
function add_action(string $hook, mixed $callback, int $priority, int $arguments): void { $GLOBALS['windows_portability_hooks'][] = $hook; }
$GLOBALS['windows_portability_hooks'] = array();
$GLOBALS['wpdb'] = new stdClass();
$GLOBALS['wp_theme_directories'] = array($data['themes']);
$GLOBALS['wp_version'] = '6.8.0';
$registrar = require $data['package'] . '/bootstrap.php';
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$activation = $broker->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 2, 'wordpress_version' => '6.8.0'));
$plugin = $registrar->plugin('github', $data['plugin'], 'acme/example-plugin', '123456789');
$theme = $registrar->theme('github', $data['theme'], 'acme/example-theme', '987654321');
$plugin->register();
$theme->register();
echo json_encode(array('activation' => $activation, 'hooks' => count($GLOBALS['windows_portability_hooks']), 'package' => $data['package'], 'plugin' => $plugin->status(), 'theme' => $theme->status()), JSON_THROW_ON_ERROR);
PHP
);
		$command = escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $this->workspace . '/php-tmp' ) . ' ' . escapeshellarg( $probe );
		exec( $command, $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );

		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function remove( string $path ): void
	{
		if ( ! is_dir( $path ) ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
			return;
		}
		foreach ( scandir( $path ) ?: array() as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$child = $path . '/' . $name;
				is_dir( $child ) && ! is_link( $child ) ? $this->remove( $child ) : unlink( $child );
			}
		}
		rmdir( $path );
	}
}
