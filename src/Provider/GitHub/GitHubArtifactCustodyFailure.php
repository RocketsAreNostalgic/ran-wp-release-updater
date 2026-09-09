<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use RuntimeException;

/** Internal artifact-allocation failure with explicit cleanup outcome. */
final class GitHubArtifactCustodyFailure extends RuntimeException {

	public function __construct( public readonly bool $cleanupComplete, string $message ) {
		parent::__construct( $message );
	}
}
