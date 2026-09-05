<?php

declare(strict_types=1);

namespace Tests\Runtime;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RuntimeCopyIdentityTest extends TestCase
{
	private string $parent;

	protected function setUp(): void
	{
		$this->parent = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/neutral-runtime-copy-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->parent, 0700, true );
	}

	protected function tearDown(): void
	{
		$this->remove( $this->parent );
	}

	public function testCheckedInRuntimeCopyClaimsTheCanonicalRuntimeContentIdentity(): void
	{
		$copy = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );

		self::assertSame( $this->identity( dirname( __DIR__, 2 ) ), $copy['package_revision'] );
	}

	public function testPosixIdentityPayloadIsUnchangedBySeparatorNormalization(): void
	{
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::markTestSkipped( 'Windows paths require separator normalization.' );
		}
		$root = dirname( __DIR__, 2 );
		$native = array( 'bootstrap.php', 'runtime.php' );
		$normalized = $native;
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
				$native[] = $relative;
				$normalized[] = str_replace( '\\', '/', $relative );
			}
		}
		sort( $native, SORT_STRING );
		sort( $normalized, SORT_STRING );
		self::assertSame( $native, $normalized );
		self::assertSame( $this->identityFromFiles( $root, $native ), $this->identityFromFiles( $root, $normalized ) );
	}

	public function testEqualVersionPackageShapedCopiesWithDivergentContentFailClosed(): void
	{
		$left  = $this->packageCopy( 'left' );
		$right = $this->packageCopy( 'right' );
		file_put_contents( $right . '/src/Runtime/RequestBroker.php', (string) file_get_contents( $right . '/src/Runtime/RequestBroker.php' ) . "\n// Divergent physical package copy.\n" );
		$this->writeRuntimeCopy( $right );

		$result = $this->probe( 'require $data["left"] . "/bootstrap.php"; require $data["right"] . "/bootstrap.php"; $result=$GLOBALS["ran_wp_release_updater_v1_broker"]->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); echo json_encode($result);', array( 'left' => $left, 'right' => $right ) );

		self::assertFalse( $result['loaded'] );
		self::assertSame( array( 'runtime_selection_inactive' ), array_column( $result['diagnostics'], 'code' ) );
	}

	public function testSelectedRuntimeSymbolsAlwaysComeFromTheHighestWinnerRoot(): void
	{
		$copies = array(
			$this->packageCopy( 'beta-one', '0.1.0-beta.1' ),
			$this->packageCopy( 'beta-two', '0.1.0-beta.2' ),
			$this->packageCopy( 'beta-three', '0.1.0-beta.3' ),
		);
		$orders = array(
			$copies,
			array_reverse( $copies ),
			array( $copies[1], $copies[0], $copies[2] ),
		);

		foreach ( $orders as $order ) {
			$result = $this->probe(
				<<<'PHP'
foreach ($data['copies'] as $copy) require $copy . '/bootstrap.php'; $broker=$GLOBALS['ran_wp_release_updater_v1_broker']; $activation=$broker->activate(array('php_version'=>'8.2.0','runtime_protocol'=>2,'wordpress_version'=>'6.8.0')); preg_match_all("/'([^']+)' => '(src\\/[^']+\\.php)'/", file_get_contents($data['winner'] . '/runtime.php'), $matches, PREG_SET_ORDER); $symbols=array(); foreach ($matches as $match) { $symbol=str_replace('\\\\','\\',$match[1]); $reflection=new ReflectionClass($symbol); $symbols[$symbol]=array('expected'=>$data['winner'] . '/' . $match[2],'actual'=>$reflection->getFileName()); } $selected=(new ReflectionProperty($broker,'selectedRoot'))->getValue($broker); echo json_encode(array('activation'=>$activation,'selected'=>$selected,'symbols'=>$symbols));
PHP,
				array( 'copies' => $order, 'winner' => $copies[2] )
			);

			self::assertTrue( $result['activation']['loaded'] );
			self::assertSame( $copies[2], $result['selected'] );
			self::assertNotEmpty( $result['symbols'] );
			foreach ( $result['symbols'] as $symbol => $source ) {
				self::assertSame( $source['expected'], $source['actual'], $symbol );
			}
		}
	}

	public function testSelectedRuntimeAcceptsPluginAndThemeDeclarationsAfterBootWithoutReopeningCopyIntake(): void
	{
		$old = $this->packageCopy( 'old', '0.1.0-beta.1' );
		$new = $this->packageCopy( 'new', '0.1.0-beta.2' );
		$installed = $this->parent . '/installed';
		mkdir( $installed, 0700, true );

		$result = $this->probe(
			'define("WP_PLUGIN_DIR", $data["installed"]); function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p02_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p02_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} $GLOBALS["p02_hooks"]=array(); $GLOBALS["wpdb"]=new stdClass(); $GLOBALS["wp_version"]="6.8.0"; $GLOBALS["wp_theme_directories"]=array($data["installed"]); mkdir($data["installed"] . "/plugin",0700,true); mkdir($data["installed"] . "/theme",0700,true); file_put_contents($data["installed"] . "/plugin/main.php","<?php\\n/*\\nPlugin Name: Selected Plugin\\nVersion: 1.0.0\\nUpdate URI: https://github.com/acme/selected-plugin\\n*/\\n"); file_put_contents($data["installed"] . "/theme/style.css","/*\\nTheme Name: Selected Theme\\nVersion: 1.0.0\\nUpdate URI: https://github.com/acme/selected-theme\\n*/\\n"); $old=require $data["old"] . "/bootstrap.php"; $new=require $data["new"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $activation=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>2,"wordpress_version"=>"6.8.0")); $before=$broker->diagnostics()["candidate_count"]; $plugin=$old->plugin("github",$data["installed"] . "/plugin/main.php","acme/selected-plugin","123456789"); $theme=$new->theme("github",$data["installed"] . "/theme/style.css","acme/selected-theme","987654321"); $plugin->register(); $theme->register(); echo json_encode(array("activation"=>$activation,"before"=>$before,"after"=>$broker->diagnostics()["candidate_count"],"plugin"=>$plugin->status(),"theme"=>$theme->status(),"hooks"=>count($GLOBALS["p02_hooks"])));',
			array( 'old' => $old, 'new' => $new, 'installed' => $installed )
		);

		self::assertTrue( $result['activation']['loaded'] );
		self::assertSame( 2, $result['before'] );
		self::assertSame( $result['before'], $result['after'] );
		self::assertSame( 'target_active', $result['plugin']['code'] );
		self::assertSame( 'target_active', $result['theme']['code'] );
		self::assertSame( 19, $result['hooks'] );
	}

	public function testCopiedSourceChangeWithoutManifestUpdateIsRejectedBeforeSelection(): void
	{
		$copy = $this->packageCopy( 'changed-without-manifest' );
		file_put_contents( $copy . '/src/Runtime/RequestBroker.php', (string) file_get_contents( $copy . '/src/Runtime/RequestBroker.php' ) . "\n// Changed without updating the manifest.\n" );

		$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; echo json_encode($GLOBALS["ran_wp_release_updater_v1_broker"]->diagnostics());', array( 'copy' => $copy ) );

		self::assertSame( 0, $result['candidate_count'] );
		self::assertSame( array( 'candidate_invalid' ), array_column( $result['diagnostics'], 'code' ) );
	}

	public function testSelectedRuntimeRejectsAnInterfaceLoadedFromAnotherRootBeforeRequire(): void
	{
		$selected = $this->packageCopy( 'selected' );
		$foreign = $this->parent . '/foreign';
		mkdir( $foreign . '/src/Contract', 0700, true );
		file_put_contents( $foreign . '/src/Contract/ReleaseAdapter.php', "<?php\nnamespace RAN\\WPReleaseUpdater\\V1\\Contract; interface ReleaseAdapter {}\n" );

		$result = $this->probe( 'require $data["foreign"] . "/src/Contract/ReleaseAdapter.php"; try { require $data["selected"] . "/runtime.php"; echo json_encode(array("result" => "loaded")); } catch (RuntimeException $error) { echo json_encode(array("result" => "rejected", "message" => $error->getMessage())); }', array( 'foreign' => $foreign, 'selected' => $selected ) );

		self::assertSame( 'rejected', $result['result'] );
		self::assertSame( 'A lifecycle symbol was loaded outside the selected runtime root.', $result['message'] );
	}

	private function packageCopy( string $name, ?string $version = null ): string
	{
		$root = $this->parent . '/' . $name;
		mkdir( $root, 0700, true );
		foreach ( array( 'bootstrap.php', 'runtime.php' ) as $file ) {
			copy( dirname( __DIR__, 2 ) . '/' . $file, $root . '/' . $file );
		}
		$this->copyDirectory( dirname( __DIR__, 2 ) . '/src', $root . '/src' );
		$this->writeRuntimeCopy( $root, $version );

		return $root;
	}

	private function writeRuntimeCopy( string $root, ?string $version = null ): void
	{
		$checkedIn = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );
		file_put_contents( $root . '/runtime-copy.json', json_encode( array( 'package_revision' => $this->identity( $root ), 'package_version' => $version ?? $checkedIn['package_version'], 'php_floor' => '8.2.0', 'runtime_file' => 'runtime.php', 'runtime_protocol' => 2, 'wordpress_floor' => '6.5.0' ), JSON_THROW_ON_ERROR ) );
	}

	private function identity( string $root ): string
	{
		$files = array( 'bootstrap.php', 'runtime.php' );
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
	private function identityFromFiles( string $root, array $files ): string
	{
		$payload = '';
		foreach ( $files as $file ) {
			$payload .= $file . "\0" . hash_file( 'sha256', $root . '/' . $file ) . "\n";
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
			$from = $source . '/' . $name;
			$to   = $destination . '/' . $name;
			is_dir( $from ) ? $this->copyDirectory( $from, $to ) : copy( $from, $to );
		}
	}

	/** @param array<string, string> $data
	 * @return array<string, mixed>
	 */
	private function probe( string $body, array $data ): array
	{
		$file = $this->parent . '/probe-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents( $file, '<?php $data = ' . var_export( $data, true ) . '; ' . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp' ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );

		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function remove( string $path ): void
	{
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = $path . '/' . $name;
			is_dir( $child ) && ! is_link( $child ) ? $this->remove( $child ) : unlink( $child );
		}
		rmdir( $path );
	}
}
