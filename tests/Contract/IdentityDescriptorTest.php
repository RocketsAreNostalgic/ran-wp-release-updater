<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Contract;

require_once dirname( __DIR__, 2 ) . '/src/Contract/CanonicalUpdateUri.php';
require_once dirname( __DIR__, 2 ) . '/src/Contract/IdentityDescriptor.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

final class IdentityDescriptorTest extends TestCase {
	public function test_closed_fingerprint_binds_every_release_fact(): void {
		$descriptor = IdentityDescriptor::create( $this->facts() );
		self::assertMatchesRegularExpression( '/\Av1:[a-f0-9]{64}\z/D', $descriptor->fingerprint_value() );
		$snapshot                     = $descriptor->to_array();
		$snapshot['release_identity'] = '43';
		$this->expectException( InvalidArgumentException::class );
		IdentityDescriptor::rehydrate( $snapshot ); }
	public function test_automatic_assurance_facts_remain_closed(): void {
		$snapshot = IdentityDescriptor::create( $this->facts() )->to_array();
		$snapshot['assurance_facts']['publication_immutable'] = false;
		$this->expectException( InvalidArgumentException::class );
		IdentityDescriptor::rehydrate( $snapshot ); }
	public function test_descriptor_facts_reject_target_policy_fields(): void {
		$facts                           = $this->facts();
		$facts['maximum_artifact_bytes'] = 52428800;
		$this->expectException( InvalidArgumentException::class );
		IdentityDescriptor::create( $facts ); }
	public function test_artifact_zip_suffix_preserves_exact_case(): void {
		$facts                      = $this->facts();
		$facts['artifact_filename'] = 'RAN-Booster.ZIP';
		self::assertSame(
			'RAN-Booster.ZIP',
			IdentityDescriptor::create( $facts )->to_array()['artifact_filename']
		);
	}
	public function test_named_parameters_preserve_rehydration_and_length_checks(): void {
		$descriptor = IdentityDescriptor::create( $this->facts() );
		$snapshot   = $descriptor->to_array();
		self::assertSame( $snapshot, IdentityDescriptor::rehydrate( value: $snapshot )->to_array() );
		self::assertTrue( IdentityDescriptor::is_bounded_opaque_identity( value: 'abc', maximum_bytes: 3 ) );
		self::assertFalse( IdentityDescriptor::is_bounded_opaque_identity( value: 'abcd', maximum_bytes: 3 ) );
	}
	/** @return array<string,mixed> */ private function facts(): array {
		return array(
			'artifact_filename'          => 'ran-booster-1.2.3.zip',
			'artifact_identity'          => 'asset:release-42:zip',
			'artifact_sha256'            => str_repeat( 'a', 64 ),
			'artifact_size'              => 123456,
			'assurance_facts'            => array(
				'exact_artifact_identity'       => true,
				'exact_commit_identity'         => true,
				'exact_reacquisition_supported' => true,
				'exact_release_identity'        => true,
				'provenance_verified'           => true,
				'publication_immutable'         => true,
				'repository_identity_stable'    => true,
				'trusted_digest_source'         => true,
			),
			'canonical_update_uri'       => 'https://example.com/owner/ran-booster',
			'channel'                    => 'stable',
			'commit_identity'            => 'sha256:commit/abcdef',
			'installed_package_identity' => 'ran-booster/ran-booster.php',
			'prerelease'                 => false,
			'provider_code'              => 'github',
			'release_identity'           => '42',
			'repository_identity'        => 'github:org/ran-booster',
			'repository_locator'         => 'org/ran-booster',
			'tag'                        => 'v1.2.3',
			'target_type'                => 'plugin',
			'version'                    => '1.2.3',
		); }
}
