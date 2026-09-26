<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use RuntimeException;

/** Internal artifact-allocation failure with explicit cleanup outcome. */
final class GitHubArtifactCustodyFailure extends RuntimeException {

	public function __construct( public readonly bool $cleanup_complete, string $message ) {
		parent::__construct( $message );
	}
}
