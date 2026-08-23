<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;

/** Provider boundary for request-local release discovery and exact acquisition. */
interface ReleaseAdapter
{
	/** @return array<string, mixed> */
	public function listReleases( array $conditional = array() ): array;
	public function inspect( string $releaseIdentity, ?string $expectedTag = null ): IdentityDescriptor;
	public function acquire(IdentityDescriptor $descriptor): TemporaryArtifact;
}
