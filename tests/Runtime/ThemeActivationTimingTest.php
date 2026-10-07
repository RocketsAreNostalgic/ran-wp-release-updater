<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class ThemeActivationTimingTest extends TestCase {

	private string $root;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/theme-activation-timing-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $this->root . '/active-theme', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $this->root . '/inactive-theme', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $this->root . '/active-theme/style.css', "/*\nTheme Name: Active Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/active-theme\n*/\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $this->root . '/inactive-theme/style.css', "/*\nTheme Name: Inactive Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/inactive-theme\n*/\n" );
	}

	public function test_theme_self_registration_before_the_boundary_activates_and_its_native_callback_starts_the_theme_cutoff(): void {
		$result = $this->probe(
			<<<'PHP'
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
PHP
		);

		self::assertSame( 'target_active', $result['before']['code'] );
		self::assertTrue( $result['before']['hooks_registered'] );
		self::assertFalse( $result['answer'] );
		self::assertIsInt( $result['after']['native']['last_check'] );
		self::assertSame( 11, $result['hooks'] );
	}

	public function test_direct_inactive_theme_declaration_after_theme_cutoff_is_deferred_without_native_work_or_hooks(): void {
		$result = $this->probe(
			<<<'PHP'
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
PHP
		);

		self::assertTrue( $result['registered'] );
		self::assertSame( 'deferred', $result['status']['state'] );
		self::assertSame( 'declaration_deferred_operation_started', $result['status']['code'] );
		self::assertFalse( $result['status']['hooks_registered'] );
		self::assertNull( $result['status']['native'] );
		self::assertSame(
			array(
				'state'       => 'deferred',
				'diagnostics' => array( array( 'code' => 'declaration_deferred_operation_started' ) ),
			),
			$result['diagnostics']
		);
		self::assertSame( 0, $result['calls'] );
		self::assertSame( 11, $result['before_hooks'] );
		self::assertSame( $result['before_hooks'], $result['after_hooks'] );
	}

	/** @return array<string,mixed> */
	private function probe( string $body ): array {
		$file = $this->root . '/probe.php';
		$data = array(
			'active'    => $this->root . '/active-theme/style.css',
			'bootstrap' => $this->package_copy() . '/bootstrap.php',
			'hooks'     => dirname( __DIR__ ) . '/Support/WordPressHookFixture.php',
			'inactive'  => $this->root . '/inactive-theme/style.css',
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
		$prefix = '<?php define("WP_PLUGIN_DIR", ' . var_export( $this->root, true ) . '); require ' . var_export( $data['hooks'], true ) . '; $GLOBALS["wpdb"]=new stdClass(); $GLOBALS["wp_theme_directories"]=array(' . var_export( $this->root, true ) . '); $GLOBALS["wp_version"]="6.8.0"; $data=' . var_export( $data, true ) . ';';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $file, $prefix . $body );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $this->root ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function package_copy(): string {
		$copy = $this->root . '/runtime';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for isolated runtime and installed-package fixtures with the specified permissions.
		mkdir( $copy, 0700, true );
		copy( dirname( __DIR__, 2 ) . '/bootstrap.php', $copy . '/bootstrap.php' );
		copy( dirname( __DIR__, 2 ) . '/runtime.php', $copy . '/runtime.php' );
		$this->copy_directory( dirname( __DIR__, 2 ) . '/src', $copy . '/src' );
		$files    = array( 'bootstrap.php', 'runtime.php' );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $copy . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $candidate ) {
			if ( $candidate->isFile() && 'php' === $candidate->getExtension() ) {
				$files[] = str_replace( '\\', '/', substr( $candidate->getPathname(), strlen( $copy ) + 1 ) );
			}
		}
		sort( $files, SORT_STRING );
		$payload = '';
		foreach ( $files as $runtime_file ) {
			$payload .= $runtime_file . "\0" . hash_file( 'sha256', $copy . '/' . $runtime_file ) . "\n";
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for isolated runtime and installed-package fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents(
			$copy . '/runtime-copy.json',
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
			json_encode(
				array(
					'package_revision' => hash( 'sha256', $payload ),
					'package_version'  => '0.1.0-beta.2',
					'php_floor'        => '8.2.0',
					'runtime_file'     => 'runtime.php',
					'runtime_protocol' => 5,
					'wordpress_floor'  => '6.5.0',
				),
				JSON_THROW_ON_ERROR
			)
		);
		return $copy;
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
}
