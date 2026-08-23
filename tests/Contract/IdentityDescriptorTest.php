<?php

declare(strict_types=1);

namespace Tests\Contract;

require_once dirname(__DIR__, 2) . '/src/Contract/CanonicalUpdateUri.php';
require_once dirname(__DIR__, 2) . '/src/Contract/IdentityDescriptor.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

final class IdentityDescriptorTest extends TestCase {
	public function testClosedFingerprintBindsEveryReleaseFact(): void { $descriptor = IdentityDescriptor::create( $this->facts() ); self::assertMatchesRegularExpression( '/\Av1:[a-f0-9]{64}\z/D', $descriptor->fingerprintValue() ); $snapshot = $descriptor->toArray(); $snapshot['release_identity'] = '43'; $this->expectException( InvalidArgumentException::class ); IdentityDescriptor::rehydrate( $snapshot ); }
	public function testExactTargetBindingRejectsProviderRepositoryAndUriSwitches(): void { $descriptor = IdentityDescriptor::create( $this->facts() ); foreach ( array( array( 'provider_code' => 'gitlab' ), array( 'repository_identity' => 'repo:2' ), array( 'repository_locator' => 'other/repo' ) ) as $replacement ) { try { IdentityDescriptor::assertTargetBinding( $descriptor, array_merge( $this->target(), $replacement ) ); self::fail( 'Switched target was accepted.' ); } catch ( InvalidArgumentException ) { self::addToAssertionCount( 1 ); } } }
	public function testAutomaticAssuranceFactsRemainClosed(): void { $snapshot = IdentityDescriptor::create( $this->facts() )->toArray(); $snapshot['assurance_facts']['publication_immutable'] = false; $this->expectException( InvalidArgumentException::class ); IdentityDescriptor::rehydrate( $snapshot ); }
	public function testArtifactZipSuffixPreservesExactCase(): void {
		$facts = $this->facts();
		$facts['artifact_filename'] = 'RAN-Booster.ZIP';
		self::assertSame(
			'RAN-Booster.ZIP',
			IdentityDescriptor::create( $facts )->toArray()['artifact_filename']
		);
	}
	/** @return array<string,mixed> */ private function facts(): array { return array( 'artifact_filename' => 'ran-booster-1.2.3.zip', 'artifact_identity' => 'asset:release-42:zip', 'artifact_sha256' => str_repeat( 'a', 64 ), 'artifact_size' => 123456, 'assurance_facts' => array( 'exact_artifact_identity' => true, 'exact_commit_identity' => true, 'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true, 'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true ), 'canonical_update_uri' => 'https://example.com/owner/ran-booster', 'channel' => 'stable', 'commit_identity' => 'sha256:commit/abcdef', 'installed_package_identity' => 'ran-booster/ran-booster.php', 'prerelease' => false, 'provider_code' => 'github', 'release_identity' => '42', 'repository_identity' => 'github:org/ran-booster', 'repository_locator' => 'org/ran-booster', 'tag' => 'v1.2.3', 'target_type' => 'plugin', 'version' => '1.2.3' ); }
	/** @return array<string,string> */ private function target(): array { return array( 'installed_package_identity' => 'ran-booster/ran-booster.php', 'provider_code' => 'github', 'repository_identity' => 'github:org/ran-booster', 'repository_locator' => 'org/ran-booster', 'target_type' => 'plugin' ); }
}
