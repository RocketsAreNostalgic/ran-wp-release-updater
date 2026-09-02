<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use RuntimeException;

/** A GitHub release read could not authenticate, reach WordPress HTTP, or access GitHub. */
final class GitHubReleaseReadUnavailable extends RuntimeException
{
}
