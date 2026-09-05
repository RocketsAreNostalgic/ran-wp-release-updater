<?php

declare(strict_types=1);

namespace Tests\Contract;

require_once dirname(__DIR__, 2) . '/src/Contract/CanonicalUpdateUri.php';
require_once dirname(__DIR__, 2) . '/src/Contract/IdentityDescriptor.php';
require_once dirname(__DIR__, 2) . '/src/Contract/BindingRecord.php';
require_once dirname(__DIR__, 2) . '/src/Archive/ValidatedPackage.php';
require_once dirname(__DIR__, 2) . '/src/Archive/PackageIdentityValidator.php';
require_once dirname(__DIR__, 2) . '/src/Contract/AcquisitionReceipt.php';
require_once dirname(__DIR__, 2) . '/src/WordPress/ReleaseOperationCoordinator.php';
require_once dirname(__DIR__) . '/Support/FakeOptionDatabase.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Archive\ValidatedPackage;
use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
use RAN\WPReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;
use Tests\Support\FakeOptionDatabase;

final class AcquisitionReceiptTest extends TestCase {

	/** @var list<string> */
	private array $archives = array();
	protected function tearDown(): void { foreach ( $this->archives as $archive ) if ( is_file( $archive ) ) unlink( $archive ); parent::tearDown(); }

	public function testOnlyValidatorIssuedPackagesMintIdenticalOneUseReceipts(): void {
		list( $validator, $descriptor, $state, $firstPackage ) = $this->ready();
		$secondPackage = $validator->validate( $descriptor, $this->policy(), $this->archives[0] );
		$first = AcquisitionReceipt::issue( $state, $descriptor, $validator, $firstPackage, 10 );
		$second = AcquisitionReceipt::issue( $state, $descriptor, $validator, $secondPackage, 10 );
		$manifest = $firstPackage->toArray(); self::assertSame( $first, AcquisitionReceipt::assertArchiveManifest( $first, $state, $descriptor, 10, $manifest['manifest_hash'], $manifest['manifest_entry_count'], $manifest['manifest_expanded_bytes'] ) );
		try { AcquisitionReceipt::assertArchiveManifest( $first, $state, $descriptor, 10, str_repeat( 'f', 64 ), $manifest['manifest_entry_count'], $manifest['manifest_expanded_bytes'] ); self::fail( 'A changed archive manifest was accepted.' ); } catch ( InvalidArgumentException ) { self::addToAssertionCount( 1 ); }
		try { AcquisitionReceipt::acceptFresh( clone $first, $state, $descriptor, 10 ); self::fail( 'Clone was accepted.' ); } catch ( InvalidArgumentException ) { self::addToAssertionCount( 1 ); }
		self::assertSame( $first, AcquisitionReceipt::acceptFresh( $first, $state, $descriptor, 10 ) );
		self::assertSame( $second, AcquisitionReceipt::acceptFresh( $second, $state, $descriptor, 10 ) );
	}

	public function testPublicReadyBlockedAndClonePackagesCannotMintAndFlagsAreNotInputs(): void {
		list( $validator, $descriptor, $state, $package ) = $this->ready();
		foreach ( array( ValidatedPackage::ready( $package->toArray() ), ValidatedPackage::blocked( 'blocked' ), clone $package ) as $forged ) {
			try { AcquisitionReceipt::issue( $state, $descriptor, $validator, $forged, 10 ); self::fail( 'Forged package minted a receipt.' ); } catch ( InvalidArgumentException ) { self::addToAssertionCount( 1 ); }
		}
		try { AcquisitionReceipt::issue( array( 'archive_identity_verified' => false ), $state, $descriptor, $validator, $package, 10 ); self::fail( 'Caller receipt flags were accepted.' ); } catch ( \TypeError ) { self::addToAssertionCount( 1 ); }
		AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, 10 );
		try { AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, 10 ); self::fail( 'Package proof minted twice.' ); } catch ( InvalidArgumentException ) { self::addToAssertionCount( 1 ); }
	}

	public function testReceiptIsBoundToClaimIncarnationAndExpiry(): void {
		list( $validator, $descriptor, $state, $package ) = $this->ready();
		$receipt = AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, 10 );
		$successor = BindingState::create( $state->binding(), str_repeat( 'b', 64 ), 30, $state->bindingGeneration() + 1, $state->fenceEpoch() + 1 );
		try { AcquisitionReceipt::acceptFresh( $receipt, $successor, $descriptor, 21 ); self::fail( 'Old claim receipt was accepted.' ); } catch ( InvalidArgumentException ) { self::addToAssertionCount( 1 ); }
		$fresh = $validator->validate( $descriptor, $this->policy(), $this->archives[0] );
		self::assertInstanceOf( AcquisitionReceipt::class, AcquisitionReceipt::acceptFresh( AcquisitionReceipt::issue( $successor, $descriptor, $validator, $fresh, 22 ), $successor, $descriptor, 22 ) );
		$expired = $validator->validate( $descriptor, $this->policy(), $this->archives[0] );
		try { AcquisitionReceipt::issue( $state, $descriptor, $validator, $expired, 21 ); self::fail( 'Expired lease minted receipt.' ); } catch ( InvalidArgumentException ) { self::addToAssertionCount( 1 ); }
	}

	public function testCompletionRechecksAConcurrentRebindAfterConsumingTheReceipt(): void {
		list( $validator, $descriptor, $prototype, $package ) = $this->ready();
		$database = new FakeOptionDatabase( 10 );
		$claimed = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $prototype->binding(), str_repeat( 'a', 64 ), 10 );
		self::assertSame( 'claimed', $claimed['result'] );
		$state = $claimed['current'];
		$claim = $this->claim( $state );
		$receipt = AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, 10 );
		$next = BindingRecord::create( array_merge( $this->bindingFacts(), array( 'update_policy' => 'automatic' ) ) );
		$rebound = null;
		$database->mutateOnTimeRead( 1, static function ( FakeOptionDatabase $database ) use ( $state, $claim, $next, &$rebound ): void {
			$rebound = ReleaseOperationCoordinator::persistPersistentBindingState( $database, $state, $claim, $next );
		} );

		$completed = ReleaseOperationCoordinator::completePersistentInstall( $database, $state, $claim, $receipt, $descriptor );
		self::assertSame( 'rebound', $rebound['result'] );
		self::assertSame( 'binding_fence_lost', $completed['result'] );
		try {
			AcquisitionReceipt::acceptFresh( $receipt, $state, $descriptor, 10 );
			self::fail( 'The receipt was not consumed before the completion race was detected.' );
		} catch ( InvalidArgumentException ) {
			self::addToAssertionCount( 1 );
		}
	}

	/** @return array{PackageIdentityValidator,IdentityDescriptor,BindingState,ValidatedPackage} */
	private function ready(): array { $path = $this->archive(); $descriptor = $this->descriptor( $path ); $validator = new PackageIdentityValidator(); $package = $validator->validate( $descriptor, $this->policy(), $path ); self::assertTrue( $package->isValid() ); return array( $validator, $descriptor, BindingState::create( BindingRecord::create( $this->bindingFacts() ), str_repeat( 'a', 64 ), 20 ), $package ); }
	private function archive(): string { $path = tempnam( sys_get_temp_dir(), 'ran-receipt-' ); self::assertIsString( $path ); $this->archives[] = $path; $zip = new \ZipArchive(); self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ); self::assertTrue( $zip->addFromString( 'x/x.php', "<?php\n/*\nPlugin Name: X\nVersion: 1.0.0\nUpdate URI: https://example.com/owner/repo\n*/" ) ); $zip->close(); return $path; }
	private function descriptor( string $path ): IdentityDescriptor { $facts = $this->descriptorFacts(); $facts['artifact_sha256'] = hash_file( 'sha256', $path ); $facts['artifact_size'] = filesize( $path ); return IdentityDescriptor::create( $facts ); }
	/** @return array<string,string> */
	private function policy(): array { return array( 'archive_root' => 'x', 'configuration_update_uri' => 'https://example.com/owner/repo', 'header_file' => 'x.php', 'installed_package_identity' => 'x/x.php', 'maximum_artifact_bytes' => 52_428_800, 'metadata_name' => 'X', 'offer_update_uri' => 'https://example.com/owner/repo', 'php_runtime_version' => '8.2', 'provider_code' => 'github', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/repo', 'staged_package_update_uri' => 'https://example.com/owner/repo', 'target_type' => 'plugin', 'theme_template' => '', 'wordpress_runtime_version' => '6.8' ); }
	/** @return array<string,mixed> */
	private function claim( BindingState $state ): array { return array( 'binding_generation' => $state->bindingGeneration(), 'binding_hash' => $state->binding()->bindingHash(), 'lease_deadline' => $state->leaseDeadline(), 'owner_token' => $state->ownerToken() ); }
	/** @return array<string,mixed> */
	private function bindingFacts(): array { return array( 'canonical_repository_locator' => 'owner/repo', 'canonical_update_uri' => 'https://example.com/owner/repo', 'installed_package_identity' => 'x/x.php', 'maximum_artifact_bytes' => 52_428_800, 'network_id' => 1, 'php_runtime_version' => '8.2', 'provider_code' => 'github', 'release_channel' => 'stable', 'stable_repository_identity' => 'repo:1', 'target_type' => 'plugin', 'theme_template' => '', 'update_policy' => 'manual', 'wordpress_runtime_version' => '6.8' ); }
	/** @return array<string,mixed> */
	private function descriptorFacts(): array { return array( 'artifact_filename' => 'x.zip', 'artifact_identity' => 'asset:1', 'artifact_sha256' => str_repeat( 'a', 64 ), 'artifact_size' => 1, 'assurance_facts' => array( 'exact_artifact_identity' => true, 'exact_commit_identity' => true, 'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true, 'publication_immutable' => false, 'repository_identity_stable' => true, 'trusted_digest_source' => true ), 'canonical_update_uri' => 'https://example.com/owner/repo', 'channel' => 'stable', 'commit_identity' => 'commit:1', 'installed_package_identity' => 'x/x.php', 'prerelease' => false, 'provider_code' => 'github', 'release_identity' => '42', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/repo', 'tag' => 'v1', 'target_type' => 'plugin', 'version' => '1.0.0' ); }
}
