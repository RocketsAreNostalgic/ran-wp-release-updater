<?php
declare(strict_types=1);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing Composer development namespace; this allowance does not apply to production declarations.
namespace Tests\Support;

use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
/** Observable request-local provider double for native lifecycle tests. */
final class ControllableReleaseAdapter implements ReleaseAdapter {
	public int $list_calls    = 0;
	public int $inspect_calls = 0;
	public int $acquire_calls = 0;
	/** @var list<string> */
	public array $acquired_paths = array();
	/** @var array<string,mixed>|null */
	public ?array $list_response = null;
	/** @var array<string,IdentityDescriptor|\Throwable> */
	public array $inspect_outcomes = array();
	/** @var array<string,TemporaryArtifact|\Throwable> */
	public array $acquire_outcomes = array();
	public IdentityDescriptor $inspect_descriptor;
	public function __construct(
		private IdentityDescriptor $descriptor,
		private string $archive,
		private string $temporary_directory
	) {
		$this->inspect_descriptor = $descriptor;
	}
	/** @return array<string,mixed> */
	public function list_releases( array $conditional = array() ): array {
		++$this->list_calls;
		if ( is_array( $this->list_response ) ) {
			return $this->list_response;
		}
		$facts = $this->descriptor->to_array();
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
	public function inspect( string $release_identity, ?string $expected_tag = null ): IdentityDescriptor {
		++$this->inspect_calls;
		if ( array_key_exists( $release_identity, $this->inspect_outcomes ) ) {
			$outcome = $this->inspect_outcomes[ $release_identity ];
			if ( $outcome instanceof \Throwable ) {
				throw $outcome;
			}
			return $outcome;
		}
		return $this->inspect_descriptor;
	}
	public function acquire( IdentityDescriptor $descriptor ): TemporaryArtifact {
		++$this->acquire_calls;
		$release_identity = $descriptor->release_identity();
		if ( array_key_exists( $release_identity, $this->acquire_outcomes ) ) {
			$outcome = $this->acquire_outcomes[ $release_identity ];
			if ( $outcome instanceof \Throwable ) {
				throw $outcome;
			}
			return $outcome;
		}
		$artifact_path = tempnam( $this->temporary_directory, 'ran-native-adapter-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The fake provider copies the real archive and requires private permissions before admitting custody.
		if ( ! is_string( $artifact_path ) || ! copy( $this->archive, $artifact_path ) || ! chmod( $artifact_path, 0600 ) ) {
			throw new \RuntimeException( 'Could not create fake artifact.' );
		}
		$artifact_stat = lstat( $artifact_path );
		if ( ! is_array( $artifact_stat ) ) {
			throw new \RuntimeException( 'Could not stat fake artifact.' );
		}
		$this->acquired_paths[] = $artifact_path;
		return new TemporaryArtifact(
			$artifact_path,
			hash_file( 'sha256', $artifact_path ),
			array(
				'dev'   => $artifact_stat['dev'],
				'ino'   => $artifact_stat['ino'],
				'mode'  => $artifact_stat['mode'],
				'nlink' => $artifact_stat['nlink'],
				'uid'   => $artifact_stat['uid'],
				'gid'   => $artifact_stat['gid'],
				'size'  => $artifact_stat['size'],
				'mtime' => $artifact_stat['mtime'],
				'ctime' => $artifact_stat['ctime'],
			)
		);
	}
}
