<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class RequestBrokerTest extends TestCase
{
	private string $parent;

	protected function setUp(): void
	{
		$this->parent = sys_get_temp_dir() . '/neutral-request-broker-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->parent, 0700, true );
	}

	protected function tearDown(): void
	{
		$this->remove( $this->parent );
	}

	public function testDifferentPhysicalCopiesUseOneBrokerAndLoadOnlyTheHighestCompatibleRuntime(): void
	{
		$old = $this->copy( 'old', '0.1.0-beta.1', 'a' );
		$new = $this->copy( 'new', '0.1.0-beta.2', 'b' );
		foreach ( array( array( $old, $new ), array( $new, $old ) ) as $order ) {
			$result = $this->probe( 'require $data["first"] . "/bootstrap.php"; require $data["second"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $result=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>1,"wordpress_version"=>"6.8.0")); echo json_encode(array("protocol"=>$broker->protocolVersion(),"result"=>$result,"marker"=>file_get_contents($data["marker"])));', array( 'first' => $order[0], 'second' => $order[1], 'marker' => $this->parent . '/selected.txt' ) );
			self::assertSame( 1, $result['protocol'] );
			self::assertTrue( $result['result']['loaded'] );
			self::assertSame( array(), $result['result']['diagnostics'] );
			self::assertSame( 'new', $result['marker'] );
			@unlink( $this->parent . '/selected.txt' );
		}
	}

	public function testMoreThanEightUniqueCopiesAreAdmittedAndOnlyTheHighestRuntimeLoads(): void
	{
		$copies = array();
		for ( $index = 1; $index <= 9; ++$index ) {
			$copies[] = $this->copy( 'copy-' . $index, '0.1.0-beta.' . $index, 'a' );
		}
		$result = $this->probe( 'foreach ($data["copies"] as $copy) require $copy . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $result=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>1,"wordpress_version"=>"6.8.0")); echo json_encode(array("result"=>$result,"diagnostics"=>$broker->diagnostics(),"marker"=>file_get_contents($data["marker"])));', array( 'copies' => $copies, 'marker' => $this->parent . '/selected.txt' ) );
		self::assertTrue( $result['result']['loaded'] );
		self::assertSame( array(), $result['result']['diagnostics'] );
		self::assertSame( 9, $result['diagnostics']['candidate_count'] );
		self::assertSame( 'copy-9', $result['marker'] );
	}

	public function testTwoPartWordPressRuntimeVersionSelectsACompatibleCopy(): void
	{
		$copy = $this->copy( 'copy', '0.1.0-beta.2', 'a' );
		$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $result=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>1,"wordpress_version"=>"7.0")); echo json_encode(array("result"=>$result,"marker"=>file_exists($data["marker"])));', array( 'copy' => $copy, 'marker' => $this->parent . '/selected.txt' ) );

		self::assertTrue( $result['result']['loaded'] );
		self::assertSame( array(), $result['result']['diagnostics'] );
		self::assertTrue( $result['marker'] );
	}

	public function testEqualVersionDifferentRevisionFailsClosedWithoutLoadingEitherRuntime(): void
	{
		$left = $this->copy( 'left', '0.1.0-beta.2', 'a' );
		$right = $this->copy( 'right', '0.1.0-beta.2', 'b' );
		$result = $this->probe( 'require $data["left"] . "/bootstrap.php"; require $data["right"] . "/bootstrap.php"; $result=$GLOBALS["ran_wp_release_updater_v1_broker"]->activate(array("php_version"=>"8.2.0","runtime_protocol"=>1,"wordpress_version"=>"6.8.0")); echo json_encode(array("result"=>$result,"marker"=>file_exists($data["marker"])));', array( 'left' => $left, 'right' => $right, 'marker' => $this->parent . '/selected.txt' ) );
		self::assertFalse( $result['result']['loaded'] );
		self::assertSame( array( 'runtime_selection_inactive' ), array_column( $result['result']['diagnostics'], 'code' ) );
		self::assertFalse( $result['marker'] );
	}

	public function testLegacyConflictInvalidAndLateCopiesRemainPassiveAndOneShot(): void
	{
		$copy = $this->copy( 'copy', '0.1.0-beta.2', 'a' );
		$invalid = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $duplicate=$broker->registerCandidate($data["copy"] . "/runtime-copy.json"); $bad=$broker->registerCandidate($data["copy"] . "/wrong.json"); $first=$broker->activate(array("php_version"=>"8.0.0","runtime_protocol"=>1,"wordpress_version"=>"6.8.0")); $late=$broker->registerCandidate($data["copy"] . "/runtime-copy.json"); $second=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>1,"wordpress_version"=>"6.8.0")); echo json_encode(array("duplicate"=>$duplicate,"bad"=>$bad,"late"=>$late,"first"=>$first,"second"=>$second));', array( 'copy' => $copy ) );
		self::assertFalse( $invalid['duplicate'] ); self::assertFalse( $invalid['bad'] ); self::assertFalse( $invalid['late'] );
		self::assertFalse( $invalid['first']['loaded'] );
		self::assertContains( 'runtime_selection_inactive', array_column( $invalid['first']['diagnostics'], 'code' ) );
		self::assertContains( 'activation_already_attempted', array_column( $invalid['second']['diagnostics'], 'code' ) );

		$legacy = $this->probe( '$GLOBALS["ran_wp_github_release_updater_v1_broker"]=new stdClass(); require $data["copy"] . "/bootstrap.php"; $result=$GLOBALS["ran_wp_release_updater_v1_broker"]->activate(array("php_version"=>"8.2.0","runtime_protocol"=>1,"wordpress_version"=>"6.8.0")); echo json_encode($result);', array( 'copy' => $copy ) );
		self::assertFalse( $legacy['loaded'] );
		self::assertSame( array( 'legacy_conflict_inactive' ), array_column( $legacy['diagnostics'], 'code' ) );
	}

	public function testDiagnosticsAreBoundedForRepeatedInvalidAndLateRegistrations(): void
	{
		$copy = $this->copy( 'copy', '0.1.0-beta.2', 'a' );
		$result = $this->probe( 'require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; for ($index=0; $index<17; ++$index) $broker->registerCandidate($data["copy"] . "/wrong.json"); $invalid=$broker->diagnostics(); $broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>1,"wordpress_version"=>"6.8.0")); for ($index=0; $index<17; ++$index) $broker->registerCandidate($data["copy"] . "/runtime-copy.json"); echo json_encode(array("invalid"=>$invalid,"late"=>$broker->diagnostics()));', array( 'copy' => $copy ) );
		self::assertCount( 16, $result['invalid']['diagnostics'] );
		self::assertSame( array( 'candidate_invalid' ), array_values( array_unique( array_column( $result['invalid']['diagnostics'], 'code' ) ) ) );
		self::assertCount( 16, $result['late']['diagnostics'] );
		self::assertSame( array( 'late_candidate_rejected' ), array_values( array_unique( array_column( $result['late']['diagnostics'], 'code' ) ) ) );
	}

	public function testSameProtocolForeignBrokerWithAnIncompleteShapeIsReplacedFailClosed(): void
	{
		$copy = $this->copy( 'copy', '0.1.0-beta.2', 'a' );
		$foreign = $this->parent . '/foreign';
		mkdir( $foreign, 0700, true );
		file_put_contents( $foreign . '/bootstrap.php', <<<'PHP'
<?php
namespace RAN\WPReleaseUpdater\V1\Runtime {
	final class RequestBroker { public function protocolVersion(): int { return 1; } }
}
namespace { $GLOBALS['ran_wp_release_updater_v1_broker'] = new \RAN\WPReleaseUpdater\V1\Runtime\RequestBroker(); }
PHP );
		$result = $this->probe( 'require $data["foreign"] . "/bootstrap.php"; require $data["copy"] . "/bootstrap.php"; $broker=$GLOBALS["ran_wp_release_updater_v1_broker"]; $result=$broker->activate(array("php_version"=>"8.2.0","runtime_protocol"=>1,"wordpress_version"=>"6.8.0")); echo json_encode(array("protocol"=>$broker->protocolVersion(),"candidate"=>$broker->registerCandidate($data["copy"] . "/runtime-copy.json"),"result"=>$result));', array( 'copy' => $copy, 'foreign' => $foreign ) );
		self::assertSame( 0, $result['protocol'] );
		self::assertFalse( $result['candidate'] );
		self::assertFalse( $result['result']['loaded'] );
		self::assertContains( 'broker_protocol_conflict_inactive', array_column( $result['result']['diagnostics'], 'code' ) );
	}

	/** @param array<string, string> $data
	 * @return array<string, mixed>
	 */
	private function probe( string $body, array $data ): array
	{
		$file = $this->parent . '/probe-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents( $file, '<?php $data = ' . var_export( $data, true ) . '; ' . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function copy( string $name, string $version, string $revision ): string
	{
		$root = $this->parent . '/' . $name;
		mkdir( $root . '/src/Runtime', 0700, true );
		foreach ( array( 'bootstrap.php', 'src/Runtime/RequestBroker.php' ) as $file ) copy( dirname( __DIR__, 2 ) . '/' . $file, $root . '/' . $file );
		file_put_contents( $root . '/runtime.php', "<?php\n// " . $revision . "\nfile_put_contents('" . addslashes( $this->parent . '/selected.txt' ) . "', basename(__DIR__));\n" );
		file_put_contents( $root . '/runtime-copy.json', json_encode( array( 'package_revision' => $this->identity( $root ), 'package_version' => $version, 'php_floor' => '8.2.0', 'runtime_file' => 'runtime.php', 'runtime_protocol' => 1, 'wordpress_floor' => '6.5.0' ), JSON_THROW_ON_ERROR ) );
		return $root;
	}

	private function identity( string $root ): string
	{
		$files = array( 'bootstrap.php', 'runtime.php' );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
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

	private function remove( string $path ): void
	{
		if ( ! is_dir( $path ) ) return;
		foreach ( scandir( $path ) ?: array() as $name ) {
			if ( '.' === $name || '..' === $name ) continue;
			$child = $path . '/' . $name;
			is_dir( $child ) && ! is_link( $child ) ? $this->remove( $child ) : unlink( $child );
		}
		rmdir( $path );
	}
}
