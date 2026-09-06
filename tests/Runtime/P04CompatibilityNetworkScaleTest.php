<?php

declare(strict_types=1);

namespace Tests\Runtime;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class P04CompatibilityNetworkScaleTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname( __DIR__, 2 ) . '/.workspaces/p0.4/php-tmp/' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root, 0700, true );
	}

	public function testCompatibleCopiesSelectOneRuntimeAndOneTargetHookSet(): void
	{
		$first = $this->package( 'a-copy' );
		$second = $this->package( 'z-copy' );
		$plugin = $this->plugin( 'compatible', 'https://github.com/acme/compatible' );

		$result = $this->probe( <<<'PHP'
$registrars = array();
foreach ( $data['copies'] as $copy ) {
	$registrars[] = require $copy . '/bootstrap.php';
}
$first = $registrars[0]->plugin('github', $data['plugin'], 'acme/compatible', '123456789');
$second = $registrars[1]->plugin('github', $data['plugin'], 'acme/compatible', '123456789');
$first->register();
$second->register();
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$activation = $broker->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 2, 'wordpress_version' => '7.0.4'));
$selected = (new ReflectionProperty($broker, 'selectedRoot'))->getValue($broker);
echo json_encode(array(
	'activation' => $activation, 'selected' => $selected,
	'first' => $first->status(), 'second' => $second->status(),
	'hooks' => count($GLOBALS['p04_hooks']),
	'logical' => $broker->diagnostics()['logical_target_count'],
));
PHP, array( 'copies' => array( $second, $first ), 'plugin' => $plugin ) );

		self::assertTrue( $result['activation']['loaded'] );
		self::assertSame( $first, $result['selected'] );
		self::assertSame( 'target_active', $result['first']['code'] );
		self::assertSame( 'target_active', $result['second']['code'] );
		self::assertSame( 1, $result['logical'] );
		self::assertSame( 10, $result['hooks'] );
	}

	public function testMainAndSubsiteDeclarationsShareOneNetworkTargetAndFenceKey(): void
	{
		$plugin = $this->plugin( 'network', 'https://github.com/acme/network' );
		$result = $this->probe( <<<'PHP'
function get_current_network_id(): int { return 17; }
function get_current_blog_id(): int { return $GLOBALS['p04_blog']; }
$GLOBALS['p04_blog'] = 1;
$registrar = require $data['bootstrap'];
$main = $registrar->plugin('github', $data['plugin'], 'acme/network', '123456789');
$main->register();
$GLOBALS['p04_blog'] = 23;
$subsite = $registrar->plugin('github', $data['plugin'], 'acme/network', '123456789');
$subsite->register();
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$broker->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 2, 'wordpress_version' => '7.0.4'));
$mainStatus = $main->status();
$subsiteStatus = $subsite->status();
echo json_encode(array(
	'main' => $mainStatus, 'subsite' => $subsiteStatus,
	'hooks' => count($GLOBALS['p04_hooks']),
	'logical' => $broker->diagnostics()['logical_target_count'],
	'diagnostics' => $broker->diagnostics(),
));
PHP, array( 'bootstrap' => dirname( __DIR__, 2 ) . '/bootstrap.php', 'plugin' => $plugin ) );

		self::assertSame( 'target_active', $result['main']['code'] );
		self::assertSame( 'target_active', $result['subsite']['code'] );
		self::assertSame( 1, $result['logical'] );
		self::assertSame( 10, $result['hooks'] );
		self::assertSame( array(), $result['diagnostics']['diagnostics'] );
		self::assertArrayNotHasKey( 'blog_id', $result['main'] );
		self::assertArrayNotHasKey( 'blog_id', $result['subsite'] );
	}

	/** @dataProvider targetCounts */
	public function testMixedTargetRegistrationStaysWithinTheP0ScaleEnvelope( int $count ): void
	{
		$targets = array();
		for ( $index = 0; $index < $count; ++$index ) {
			$targets[] = array(
				'type' => 0 === $index % 2 ? 'plugin' : 'theme',
				'file' => $this->target( $index, 0 === $index % 2 ? 'plugin' : 'theme' ),
				'repository' => 'acme/scale-' . $index,
				'id' => (string) ( 100000000 + $index ),
			);
		}

		$result = $this->probe( <<<'PHP'
$registrar = require $data['bootstrap'];
$handles = array();
$calls = 0;
$memory = memory_get_usage(true);
$started = hrtime(true);
foreach ($data['targets'] as $target) {
	$handles[] = ('plugin' === $target['type'] ? $registrar->plugin(...) : $registrar->theme(...))(
		'github', $target['file'], $target['repository'], $target['id'], 'stable', 'manual',
		static function () use (&$calls): string { ++$calls; return 'credential'; }
	);
}
foreach ($handles as $handle) { $handle->register(); }
$activation = $GLOBALS['ran_wp_release_updater_v1_broker']->activate(array(
	'php_version' => PHP_VERSION, 'runtime_protocol' => 2, 'wordpress_version' => '7.0.4',
));
$statuses = array_map(static fn(object $handle): array => $handle->status(), $handles);
echo json_encode(array(
	'elapsed_ns' => hrtime(true) - $started,
	'peak_memory_delta_bytes' => memory_get_peak_usage(true) - $memory,
	'hooks' => count($GLOBALS['p04_hooks']),
	'logical' => $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['logical_target_count'],
	'credentials' => $calls, 'http_requests' => 0, 'response_bytes' => 0,
	'archive_acquisitions' => 0, 'activation' => $activation['code'],
	'statuses' => array_column($statuses, 'code'),
));
PHP, array( 'bootstrap' => dirname( __DIR__, 2 ) . '/bootstrap.php', 'targets' => $targets ) );

		self::assertSame( 'runtime_active', $result['activation'] );
		self::assertSame( $count, $result['logical'] );
		self::assertSame(
			10 * (int) ceil( $count / 2 ) + 9 * (int) floor( $count / 2 ),
			$result['hooks']
		);
		self::assertSame( array_fill( 0, $count, 'target_active' ), $result['statuses'] );
		self::assertSame( 0, $result['credentials'] );
		self::assertSame( 0, $result['http_requests'] );
		self::assertSame( 0, $result['response_bytes'] );
		self::assertSame( 0, $result['archive_acquisitions'] );
		self::assertLessThan( 5_000_000_000, $result['elapsed_ns'] );
		self::assertLessThan( 32 * 1024 * 1024, $result['peak_memory_delta_bytes'] );
	}

	/** @return array<string,array{int}> */
	public static function targetCounts(): array
	{
		return array( 'one' => array( 1 ), 'five' => array( 5 ), 'ten' => array( 10 ), 'twenty' => array( 20 ) );
	}

	private function plugin( string $name, string $uri ): string
	{
		return $this->writeTarget(
			$this->root . '/plugins/' . $name . '/main.php',
			"<?php\n/*\nPlugin Name: {$name}\nVersion: 1.0.0\nUpdate URI: {$uri}\n*/\n"
		);
	}

	private function target( int $index, string $type ): string
	{
		$name = sprintf( '%02d', $index );
		if ( 'plugin' === $type ) {
			return $this->writeTarget(
				$this->root . '/plugins/scale-' . $name . '/main.php',
				"<?php\n/*\nPlugin Name: Scale {$name}\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/scale-{$index}\n*/\n"
			);
		}
		return $this->writeTarget(
			$this->root . '/themes/scale-' . $name . '/style.css',
			"/*\nTheme Name: Scale {$name}\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/scale-{$index}\n*/\n"
		);
	}

	private function writeTarget( string $file, string $contents ): string
	{
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0700, true );
		}
		file_put_contents( $file, $contents );
		return $file;
	}

	private function package( string $name ): string
	{
		$source = dirname( __DIR__, 2 );
		$copy = $this->root . '/' . $name;
		mkdir( $copy . '/src', 0700, true );
		foreach ( array( 'bootstrap.php', 'runtime.php' ) as $file ) {
			copy( $source . '/' . $file, $copy . '/' . $file );
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$destination = $copy . '/src/' . substr( $file->getPathname(), strlen( $source . '/src/' ) );
			if ( ! is_dir( dirname( $destination ) ) ) {
				mkdir( dirname( $destination ), 0700, true );
			}
			copy( $file->getPathname(), $destination );
		}
		file_put_contents( $copy . '/runtime-copy.json', json_encode( array(
			'package_revision' => $this->identity( $copy ), 'package_version' => '0.1.0-beta.99',
			'php_floor' => '8.2.0', 'runtime_file' => 'runtime.php', 'runtime_protocol' => 2,
			'wordpress_floor' => '6.5.0',
		), JSON_THROW_ON_ERROR ) );
		return $copy;
	}

	/** @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function probe( string $body, array $data ): array
	{
		$file = $this->root . '/probe-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$prefix = '<?php define("WP_PLUGIN_DIR", ' . var_export( $this->root . '/plugins', true ) . '); '
			. 'function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p04_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} '
			. 'function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p04_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} '
			. '$GLOBALS["p04_hooks"]=array(); $GLOBALS["wpdb"]=new stdClass(); '
			. '$GLOBALS["wp_version"]="7.0.4"; $GLOBALS["wp_theme_directories"]=array('
			. var_export( $this->root . '/themes', true ) . '); $data=' . var_export( $data, true ) . '; ';
		file_put_contents( $file, $prefix . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $this->root ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function identity( string $root ): string
	{
		$files = array( 'bootstrap.php', 'runtime.php' );
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
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
}
