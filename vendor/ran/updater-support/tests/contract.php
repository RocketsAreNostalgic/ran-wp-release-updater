<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/ArchiveSafety.php';

use RAN\UpdaterSupport\V1\ArchiveSafety;

$fixture = require __DIR__ . '/fixtures/archive-safety.php';
foreach ( $fixture['paths'] as $name => $case ) {
	[$input, $expected] = $case;
	if ( $expected !== ArchiveSafety::normalizePath( $input ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "path fixture failed: {$name}" );
	}
}
foreach ( $fixture['metadata'] as $name => $case ) {
	[$origin, $attributes, $directory, $expected] = $case;
	if ( $expected !== ArchiveSafety::entryTypeFailure( $origin, $attributes, $directory ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "metadata fixture failed: {$name}" );
	}
}
foreach ( $fixture['collisions'] as $name => $case ) {
	[$entries, $expected] = $case;
	if ( $expected !== ArchiveSafety::collisionFailure( $entries ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "collision fixture failed: {$name}" );
	}
}
