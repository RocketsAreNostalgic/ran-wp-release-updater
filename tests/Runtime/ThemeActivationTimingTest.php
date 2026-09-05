<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class ThemeActivationTimingTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/theme-activation-timing-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/active-theme', 0700, true );
		mkdir( $this->root . '/inactive-theme', 0700, true );
		file_put_contents( $this->root . '/active-theme/style.css', "/*\nTheme Name: Active Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/active-theme\n*/\n" );
		file_put_contents( $this->root . '/inactive-theme/style.css', "/*\nTheme Name: Inactive Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/inactive-theme\n*/\n" );
	}

	public function testThemeSelfRegistrationBeforeTheBoundaryActivatesAndItsNativeCallbackStartsTheThemeCutoff(): void
	{
		$result = $this->probe( <<<'PHP'
$active = null;
add_action('after_setup_theme', static function () use ($data, &$active): void {
	$registrar = require $data['bootstrap'];
	$active = $registrar->theme('github', $data['active'], 'acme/active-theme', '123456789', 'stable', 'disabled');
	$active->register();
}, 10, 0);
do_action('after_setup_theme');
$before = $active->status();
$answer = apply_filters('update_themes_github.com', 'unchanged', array('Version' => '1.0.0', 'UpdateURI' => 'https://github.com/acme/active-theme'), 'active-theme', array());
$after = $active->status();
$hookCount = 0;
foreach ($GLOBALS['wp_filter'] as $hook) foreach ($hook->callbacks as $callbacks) $hookCount += count($callbacks);
echo json_encode(array('before' => $before, 'after' => $after, 'answer' => $answer, 'hooks' => $hookCount));
PHP );

		self::assertSame( 'target_active', $result['before']['code'] );
		self::assertTrue( $result['before']['hooks_registered'] );
		self::assertFalse( $result['answer'] );
		self::assertIsInt( $result['after']['native']['last_check'] );
		self::assertSame( 11, $result['hooks'] );
	}

	public function testDirectInactiveThemeDeclarationAfterThemeCutoffIsDeferredWithoutNativeWorkOrHooks(): void
	{
		$result = $this->probe( <<<'PHP'
$active = null;
add_action('after_setup_theme', static function () use ($data, &$active): void {
	$registrar = require $data['bootstrap'];
	$active = $registrar->theme('github', $data['active'], 'acme/active-theme', '123456789', 'stable', 'disabled');
	$active->register();
}, 10, 0);
do_action('after_setup_theme');
apply_filters('update_themes_github.com', false, array('Version' => '1.0.0', 'UpdateURI' => 'https://github.com/acme/active-theme'), 'active-theme', array());
$beforeHooks = 0;
foreach ($GLOBALS['wp_filter'] as $hook) foreach ($hook->callbacks as $callbacks) $beforeHooks += count($callbacks);
$calls = 0;
$inactive = (require $data['bootstrap'])->theme('github', $data['inactive'], 'acme/inactive-theme', '987654321', 'stable', 'manual', static function () use (&$calls): string { ++$calls; return 'secret'; });
$registered = $inactive->register();
$afterHooks = 0;
foreach ($GLOBALS['wp_filter'] as $hook) foreach ($hook->callbacks as $callbacks) $afterHooks += count($callbacks);
echo json_encode(array('registered' => $registered, 'status' => $inactive->status(), 'diagnostics' => $inactive->diagnostics(), 'calls' => $calls, 'before_hooks' => $beforeHooks, 'after_hooks' => $afterHooks));
PHP );

		self::assertTrue( $result['registered'] );
		self::assertSame( 'deferred', $result['status']['state'] );
		self::assertSame( 'declaration_deferred_operation_started', $result['status']['code'] );
		self::assertFalse( $result['status']['hooks_registered'] );
		self::assertNull( $result['status']['native'] );
		self::assertSame( array( 'state' => 'deferred', 'diagnostics' => array( array( 'code' => 'declaration_deferred_operation_started' ) ) ), $result['diagnostics'] );
		self::assertSame( 0, $result['calls'] );
		self::assertSame( 11, $result['before_hooks'] );
		self::assertSame( $result['before_hooks'], $result['after_hooks'] );
	}

	/** @return array<string,mixed> */
	private function probe( string $body ): array
	{
		$file = $this->root . '/probe.php';
		$data = array(
			'active' => $this->root . '/active-theme/style.css',
			'bootstrap' => $this->packageCopy() . '/bootstrap.php',
			'inactive' => $this->root . '/inactive-theme/style.css',
		);
		$prefix = '<?php define("WP_PLUGIN_DIR", ' . var_export( $this->root, true ) . '); require ' . var_export( dirname( __DIR__, 5 ) . '/wp-includes/plugin.php', true ) . '; $GLOBALS["wpdb"]=new stdClass(); $GLOBALS["wp_theme_directories"]=array(' . var_export( $this->root, true ) . '); $GLOBALS["wp_version"]="6.8.0"; $data=' . var_export( $data, true ) . ';';
		file_put_contents( $file, $prefix . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $this->root ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function packageCopy(): string
	{
		$copy = $this->root . '/runtime';
		mkdir( $copy, 0700, true );
		copy( dirname( __DIR__, 2 ) . '/bootstrap.php', $copy . '/bootstrap.php' );
		copy( dirname( __DIR__, 2 ) . '/runtime.php', $copy . '/runtime.php' );
		$this->copyDirectory( dirname( __DIR__, 2 ) . '/src', $copy . '/src' );
		$files = array( 'bootstrap.php', 'runtime.php' );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $copy . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $candidate ) {
			if ( $candidate->isFile() && 'php' === $candidate->getExtension() ) {
				$files[] = substr( $candidate->getPathname(), strlen( $copy ) + 1 );
			}
		}
		sort( $files, SORT_STRING );
		$payload = '';
		foreach ( $files as $runtimeFile ) {
			$payload .= $runtimeFile . "\0" . hash_file( 'sha256', $copy . '/' . $runtimeFile ) . "\n";
		}
		file_put_contents( $copy . '/runtime-copy.json', json_encode( array( 'package_revision' => hash( 'sha256', $payload ), 'package_version' => '0.1.0-beta.2', 'php_floor' => '8.2.0', 'runtime_file' => 'runtime.php', 'runtime_protocol' => 2, 'wordpress_floor' => '6.5.0' ), JSON_THROW_ON_ERROR ) );
		return $copy;
	}

	private function copyDirectory( string $source, string $destination ): void
	{
		mkdir( $destination, 0700, true );
		foreach ( scandir( $source ) ?: array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$from = $source . '/' . $name;
			$to = $destination . '/' . $name;
			is_dir( $from ) ? $this->copyDirectory( $from, $to ) : copy( $from, $to );
		}
	}
}
