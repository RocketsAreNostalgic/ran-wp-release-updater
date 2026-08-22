<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

/** Test-only local discovery source; it has no transport or credential surface. */
final readonly class DeterministicFakeAdapter {

	/** @param array{plugin:list<IdentityDescriptor>,theme:list<IdentityDescriptor>} $candidates */
	public function __construct( private array $candidates ) {}

	/** @return list<IdentityDescriptor> */
	public function discover( string $targetType ): array {
		if ( ! in_array( $targetType, array( 'plugin', 'theme' ), true ) ) {
			throw new InvalidArgumentException( 'The fake adapter target is invalid.' );
		}

		return $this->candidates[ $targetType ];
	}
}
