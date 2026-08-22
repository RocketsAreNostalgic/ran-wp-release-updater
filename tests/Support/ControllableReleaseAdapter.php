<?php
declare(strict_types=1);
namespace Tests\Support;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
/** Observable request-local provider double for native lifecycle tests. */
final class ControllableReleaseAdapter implements ReleaseAdapter {
	public int $listCalls = 0;
	public int $inspectCalls = 0;
	public int $acquireCalls = 0;
	/** @var list<string> */
	public array $acquiredPaths = array();
	public IdentityDescriptor $inspectDescriptor;
	public function __construct(
		private IdentityDescriptor $descriptor,
		private string $archive
	) {
		$this->inspectDescriptor = $descriptor;
	}
	/** @return array{candidates:list<array{release_identity:string,tag:string,version:string}>} */
	public function listReleases( array $conditional = array() ): array {
		++$this->listCalls;
		$facts = $this->descriptor->toArray();
		return array(
			'candidates' => array(
				array(
					'release_identity' => $facts['release_identity'],
					'tag'              => $facts['tag'],
					'version'          => $facts['version'],
				),
			),
		);
	}
	public function inspect( string $releaseIdentity, ?string $expectedTag = null ): IdentityDescriptor {
		++$this->inspectCalls;
		return $this->inspectDescriptor;
	}
	public function acquire( IdentityDescriptor $descriptor ): TemporaryArtifact {
		++$this->acquireCalls;
		$artifactPath = tempnam( sys_get_temp_dir(), 'ran-native-adapter-' );
		if ( ! is_string( $artifactPath ) || ! copy( $this->archive, $artifactPath ) || ! chmod( $artifactPath, 0600 ) ) {
			throw new \RuntimeException( 'Could not create fake artifact.' );
		}
		$artifactStat = lstat( $artifactPath );
		if ( ! is_array( $artifactStat ) ) {
			throw new \RuntimeException( 'Could not stat fake artifact.' );
		}
		$this->acquiredPaths[] = $artifactPath;
		return new TemporaryArtifact(
			$artifactPath,
			hash_file( 'sha256', $artifactPath ),
			array(
				'dev'   => $artifactStat['dev'],
				'ino'   => $artifactStat['ino'],
				'mode'  => $artifactStat['mode'],
				'nlink' => $artifactStat['nlink'],
				'uid'   => $artifactStat['uid'],
				'gid'   => $artifactStat['gid'],
				'size'  => $artifactStat['size'],
				'mtime' => $artifactStat['mtime'],
				'ctime' => $artifactStat['ctime'],
			)
		);
	}
}
