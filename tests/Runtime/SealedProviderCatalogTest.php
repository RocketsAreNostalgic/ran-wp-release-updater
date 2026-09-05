<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class SealedProviderCatalogTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname( __DIR__, 2 )
			. '/.workspaces/p0.2/php-tmp/sealed-catalog-'
			. bin2hex( random_bytes( 6 ) );
		mkdir( $this->root, 0700, true );
	}

	public function testRuntimeShipsOnlyThePrivateGithubCatalogAndNoCatalogExtensionSeam(): void
	{
		$runtime = (string) file_get_contents( dirname( __DIR__, 2 ) . '/runtime.php' );

		self::assertStringContainsString( "'github' => static function", $runtime );
		self::assertStringContainsString( 'private array $providerCatalog', $runtime );
		self::assertStringNotContainsString( "'synthetic'", $runtime );
		$seams = array(
			'registerProvider', 'setProvider', 'providerCatalog=', 'apply_filters',
			'do_action', 'spl_autoload_register', 'getenv(',
			'RAN_WP_RELEASE_UPDATER_PROVIDER',
		);
		foreach ( $seams as $seam ) {
			self::assertStringNotContainsString( $seam, $runtime );
		}
	}

	public function testHigherVersionSyntheticCatalogWinsInEitherPhysicalLoadOrderAndOwnsOnlyItsResolver(): void
	{
		$shipped = $this->package( 'shipped', '0.1.0-beta.98', false );
		$synthetic = $this->package( 'synthetic', '0.1.0-beta.99', true );
		$github = $this->plugin( 'github', 'https://github.com/acme/github' );
		$syntheticPlugin = $this->plugin( 'synthetic', 'https://synthetic.invalid/acme/synthetic' );

		foreach ( array( array( $shipped, $synthetic ), array( $synthetic, $shipped ) ) as $copies ) {
			$result = $this->probe( $this->syntheticProbe(), array(
				'copies' => $copies,
				'github' => $github,
				'synthetic' => $syntheticPlugin,
			) );

			self::assertSame( 'runtime_active', $result['activation'] );
			self::assertSame( 'target_active', $result['github']['code'] );
			self::assertSame( 'target_declaration_conflict', $result['conflict']['code'] );
			self::assertSame( 'target_active', $result['synthetic']['code'] );
			self::assertSame( 2, $result['candidate_count'] );
			self::assertSame( $synthetic, $result['selected'] );
			self::assertSame( 0, $result['github_calls'] );
			self::assertSame( 1, $result['synthetic_calls'] );
			self::assertContains( 'update_plugins_synthetic.invalid', $result['hooks'] );
			self::assertContains( 'update_plugins_github.com', $result['hooks'] );
			self::assertSame( array(), $result['github_diagnostics']['diagnostics'] );
			self::assertSame( array(), $result['synthetic_diagnostics']['diagnostics'] );
			self::assertIsInt( $result['synthetic']['native']['last_check'] );
			self::assertGreaterThan( 0, $result['synthetic']['native']['last_check'] );
			self::assertNull( $result['synthetic']['native']['candidate_validation_code'] );
		}
	}

	public function testShippedCatalogRejectsSyntheticBeforeAndAfterSameTypeCutoffAndInFreshProcess(): void
	{
		$shipped = $this->package( 'shipped-only', '0.1.0-beta.98', false );
		$plugin = $this->plugin( 'synthetic', 'https://synthetic.invalid/acme/synthetic' );

		foreach ( array( false, true ) as $cutoff ) {
			$result = $this->probe( $this->shippedProbe(), array(
				'copy' => $shipped,
				'plugin' => $plugin,
				'cutoff' => $cutoff,
			) );

			self::assertSame( 'unsupported_provider', $result['before']['code'] );
			self::assertSame( 'unsupported_provider', $result['after']['code'] );
			self::assertSame( 0, $result['calls'] );
			self::assertSame( array(), $result['hooks'] );
		}
	}

	private function syntheticProbe(): string
	{
		return <<<'PHP'
$githubCalls = 0;
$syntheticCalls = 0;
$registrars = array();
foreach ( $data['copies'] as $copy ) {
	$registrars[] = require $copy . '/bootstrap.php';
}
$githubResolver = static function () use ( &$githubCalls ): string {
	++$githubCalls;
	return 'github-secret';
};
$syntheticResolver = static function () use ( &$syntheticCalls ): string {
	++$syntheticCalls;
	return 'synthetic-secret';
};
$github = $registrars[0]->plugin(
	'github', $data['github'], 'acme/github', '123456789',
	'stable', 'manual', $githubResolver
);
$conflict = $registrars[1]->plugin(
	'synthetic', $data['github'], 'acme/synthetic', '987654321',
	'stable', 'manual', $syntheticResolver
);
$synthetic = $registrars[1]->plugin(
	'synthetic', $data['synthetic'], 'acme/synthetic', '987654321',
	'stable', 'manual', $syntheticResolver
);
$github->register();
$conflict->register();
$synthetic->register();
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$activation = $broker->activate( array( 'php_version' => PHP_VERSION, 'runtime_protocol' => 2, 'wordpress_version' => '6.8.0' ) );
$before = $broker->diagnostics();
foreach ( $GLOBALS['p0_2_hooks'] as $hook ) {
	if ( 'update_plugins_synthetic.invalid' === $hook['hook'] ) {
		( $hook['callback'] )(
			false,
			array( 'Version' => '1.0.0', 'UpdateURI' => 'https://synthetic.invalid/acme/synthetic' ),
			'installed-synthetic/plugin.php',
			array()
		);
	}
}
$selected = ( new ReflectionProperty( $broker, 'selectedRoot' ) )->getValue( $broker );
echo json_encode( array(
	'activation' => $activation['code'],
	'github' => $github->status(),
	'conflict' => $conflict->status(),
	'synthetic' => $synthetic->status(),
	'candidate_count' => $before['candidate_count'],
	'selected' => $selected,
	'hooks' => array_values( array_unique( array_column( $GLOBALS['p0_2_hooks'], 'hook' ) ) ),
	'github_calls' => $githubCalls,
	'synthetic_calls' => $syntheticCalls,
	'github_diagnostics' => $github->diagnostics(),
	'synthetic_diagnostics' => $synthetic->diagnostics(),
) );
PHP;
	}

	private function shippedProbe(): string
	{
		return <<<'PHP'
$calls = 0;
$registrar = require $data['copy'] . '/bootstrap.php';
$resolver = static function () use ( &$calls ): string {
	++$calls;
	return 'secret';
};
$before = $registrar->plugin(
	'synthetic', $data['plugin'], 'acme/synthetic', '987654321',
	'stable', 'manual', $resolver
);
$before->register();
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$broker->activate( array( 'php_version' => PHP_VERSION, 'runtime_protocol' => 2, 'wordpress_version' => '6.8.0' ) );
if ( $data['cutoff'] ) {
	foreach ( $GLOBALS['p0_2_hooks'] as $hook ) {
		if ( 'upgrader_package_options' === $hook['hook'] ) {
				( $hook['callback'] )( array(
					'hook_extra' => array(
						'plugin' => 'installed-synthetic/plugin.php',
						'action' => 'update',
						'type' => 'plugin',
					),
				) );
		}
	}
}
$after = $registrar->plugin(
	'synthetic', $data['plugin'], 'acme/synthetic', '987654321',
	'stable', 'manual', $resolver
);
$after->register();
echo json_encode( array( 'before' => $before->status(), 'after' => $after->status(), 'calls' => $calls, 'hooks' => $GLOBALS['p0_2_hooks'] ) );
PHP;
	}

	private function plugin( string $name, string $uri ): string
	{
		$directory = $this->root . '/installed-' . $name;
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0700, true );
		}
		$file = $directory . '/plugin.php';
		file_put_contents( $file, "<?php\n/*\nPlugin Name: {$name}\nVersion: 1.0.0\nUpdate URI: {$uri}\n*/\n" );
		return $file;
	}

	private function package( string $name, string $version, bool $synthetic ): string
	{
		$source = dirname( __DIR__, 2 );
		$root = $this->root . '/' . $name;
		mkdir( $root . '/src', 0700, true );
		copy( $source . '/bootstrap.php', $root . '/bootstrap.php' );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source . '/src', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $source . '/src/' ) );
			$destination = $root . '/src/' . $relative;
			if ( ! is_dir( dirname( $destination ) ) ) {
				mkdir( dirname( $destination ), 0700, true );
			}
			copy( $file->getPathname(), $destination );
		}

		$runtime = (string) file_get_contents( $source . '/runtime.php' );
		if ( $synthetic ) {
			$needle = "\n);\n\nreturn new class(";
			$replacement = "\n" . $this->syntheticCatalogEntry() . $needle;
			$runtime = str_replace( $needle, $replacement, $runtime, $count );
			self::assertSame( 1, $count, 'The synthetic fixture must replace exactly one catalog literal.' );
		}

		file_put_contents( $root . '/runtime.php', $runtime );
		$manifest = array(
			'package_revision' => $this->identity( $root ),
			'package_version' => $version,
			'php_floor' => '8.2.0',
			'runtime_file' => 'runtime.php',
			'runtime_protocol' => 2,
			'wordpress_floor' => '6.5.0',
		);
		file_put_contents( $root . '/runtime-copy.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
		return $root;
	}

	private function syntheticCatalogEntry(): string
	{
		return <<<'PHP'
	'synthetic' => static function( array $d, array $resolved, array $headers, string $identity, int $networkId, mixed $selectedRuntimeState ): array {
		$uri = \RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri::canonicalize( 'https://synthetic.invalid/' . $d['repository_locator'] );
		if ( $uri !== \RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri::canonicalize( $headers['UpdateURI'] ) ) {
			return array( 'native' => null, 'code' => 'installed_update_uri_mismatch' );
		}
		$binding = \RAN\WPReleaseUpdater\V1\Contract\BindingRecord::create( array(
			'canonical_repository_locator' => $d['repository_locator'],
			'canonical_update_uri' => $uri,
			'installed_package_identity' => $identity,
			'maximum_artifact_bytes' => $d['maximum_artifact_bytes'],
			'network_id' => $networkId,
			'php_runtime_version' => PHP_VERSION,
			'provider_code' => 'synthetic',
			'release_channel' => $d['channel'],
			'stable_repository_identity' => $d['repository_identity'],
			'target_type' => $d['target_type'],
			'update_policy' => $d['update_policy'],
			'wordpress_runtime_version' => $GLOBALS['wp_version'],
		) );
		$config = array(
			'headers' => array(
				'Author' => '',
				'Description' => '',
				'Name' => $headers['Name'],
				'PluginURI' => '',
				'RequiresPHP' => '',
				'RequiresWP' => '',
				'UpdateURI' => $uri,
				'Version' => $headers['Version'],
			),
			'installed_package_identity' => $identity,
			'policy' => $d['update_policy'],
			'target_type' => 'plugin',
			'update_uri' => $uri,
		);
		$policy = array(
			'archive_root' => $resolved['archive_root'],
			'configuration_update_uri' => $uri,
			'header_file' => $resolved['header_file'],
			'installed_package_identity' => $identity,
			'metadata_name' => $headers['Name'],
			'offer_update_uri' => $uri,
			'php_runtime_version' => PHP_VERSION,
			'provider_code' => 'synthetic',
			'repository_identity' => $d['repository_identity'],
			'repository_locator' => $d['repository_locator'],
			'staged_package_update_uri' => $uri,
			'target_type' => 'plugin',
			'wordpress_runtime_version' => $GLOBALS['wp_version'],
		);
		$adapter = new class( $d['credential_resolver'] ) implements \RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter {
			public function __construct( private mixed $resolver ) {}
			public function listReleases( array $conditional = array() ): array {
				unset( $conditional );
				if ( is_callable( $this->resolver ) ) {
					( $this->resolver )();
				}
				return array( 'candidates' => array() );
			}
			public function inspect( string $releaseIdentity, ?string $expectedTag = null ): \RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor {
				throw new \RuntimeException( 'Synthetic inspection must not run.' );
			}
			public function acquire( \RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor $descriptor ): \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact {
				throw new \RuntimeException( 'Synthetic acquisition must not run.' );
			}
		};
		$native = \RAN\WPReleaseUpdater\V1\WordPress\NativePluginUpdater::fromConfiguration(
			$config,
			$binding,
			$adapter,
			$GLOBALS['wpdb'],
			$policy,
			null,
			$selectedRuntimeState
		);
		if ( $native instanceof \RAN\WPReleaseUpdater\V1\WordPress\NativePluginUpdater ) {
			$native->register();
		}
		return array( 'native' => $native, 'code' => 'target_composition_failed' );
	},
PHP;
	}

	private function identity( string $root ): string
	{
		$files = array( 'bootstrap.php', 'runtime.php' );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}
		sort( $files, SORT_STRING );
		$payload = '';
		foreach ( $files as $file ) {
			$payload .= $file . "\0" . hash_file( 'sha256', $root . '/' . $file ) . "\n";
		}
		return hash( 'sha256', $payload );
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	private function probe( string $body, array $data ): array
	{
		$file = $this->root . '/probe-' . bin2hex( random_bytes( 4 ) ) . '.php';
		$prefix = <<<'PHP'
<?php
define( 'WP_PLUGIN_DIR', __ROOT__ );
require_once __FAKE_DATABASE__;
function add_filter( string $hook, mixed $callback, int $priority, int $arguments ): void {
	$GLOBALS['p0_2_hooks'][] = array( 'hook' => $hook, 'callback' => $callback );
}
function add_action( string $hook, mixed $callback, int $priority, int $arguments ): void {
	$GLOBALS['p0_2_hooks'][] = array( 'hook' => $hook, 'callback' => $callback );
}
$GLOBALS['p0_2_hooks'] = array();
$GLOBALS['wpdb'] = new \Tests\Support\FakeOptionDatabase( 100 );
$GLOBALS['wp_version'] = '6.8.0';
function get_filesystem_method(): string { return 'direct'; }
$data = __DATA__;
PHP;
		$prefix = str_replace( '__ROOT__', var_export( $this->root, true ), $prefix, $count );
		self::assertSame( 1, $count );
		$prefix = str_replace( '__FAKE_DATABASE__', var_export( dirname( __DIR__ ) . '/Support/FakeOptionDatabase.php', true ), $prefix, $count );
		self::assertSame( 1, $count );
		$prefix = str_replace( '__DATA__', var_export( $data, true ), $prefix, $count );
		self::assertSame( 1, $count );
		file_put_contents( $file, $prefix . "\n" . $body );

		$temporary = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp';
		$command = 'TMPDIR=' . escapeshellarg( $temporary )
			. ' TMP=' . escapeshellarg( $temporary )
			. ' TEMP=' . escapeshellarg( $temporary )
			. ' PHPRC=/dev/null PHP_INI_SCAN_DIR= '
			. escapeshellarg( PHP_BINARY )
			. ' -n -d sys_temp_dir=' . escapeshellarg( $temporary )
			. ' ' . escapeshellarg( $file );
		exec( $command, $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}
}
