<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;

/**
 * Provider boundary for request-local release discovery and exact acquisition.
 *
 * @throws ReleaseFailure Neutral provider facts. Discovery may reject only
 * release_unavailable or package_incompatible candidates when cleanup is
 * not_applicable or complete; the caller stops for every other failure.
 */
interface ReleaseAdapter {

	/**
	 * @param array<string,mixed> $conditional
	 * @return array<string,mixed>
	 */
	public function list_releases( array $conditional = array() ): array;
	public function inspect( string $release_identity, ?string $expected_tag = null ): IdentityDescriptor;
	public function acquire( IdentityDescriptor $descriptor ): TemporaryArtifact;
}
