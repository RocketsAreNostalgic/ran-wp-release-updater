<?php

declare(strict_types=1);

namespace Tests\Dependency;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Dependency\ArchiveSafety;

final class ArchiveSafetyDependencyTest extends TestCase {

	public function testGeneratedScopedDependencyMatchesTheCanonicalFixtureCorpus(): void {
		$fixture = require dirname( __DIR__, 2 ) . '/vendor/ran/package-safety/tests/fixtures/archive-safety.php';
		foreach ( $fixture['paths'] as $name => $case ) {
			[$input, $expected] = $case;
			self::assertSame( $expected, ArchiveSafety::normalizePath( $input ), $name );
		}
		foreach ( $fixture['metadata'] as $name => $case ) {
			[$origin, $attributes, $directory, $expected] = $case;
			self::assertSame( $expected, ArchiveSafety::entryTypeFailure( $origin, $attributes, $directory ), $name );
		}
		foreach ( $fixture['collisions'] as $name => $case ) {
			[$entries, $expected] = $case;
			self::assertSame( $expected, ArchiveSafety::collisionFailure( $entries ), $name );
		}
	}
}
