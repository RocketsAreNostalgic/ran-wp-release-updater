<?php

declare(strict_types=1);

namespace Tests\Archive;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\ArchiveScanResult;

final class ArchiveScanResultTest extends TestCase {
	public function testNamedReadyFactoryPreservesScanFacts(): void {
		$entries = array(
			array(
				'name'            => 'plugin/main.php',
				'path'            => 'plugin/main.php',
				'directory'       => false,
				'size'            => 15,
				'compressed_size' => 10,
			),
		);
		$result  = ArchiveScanResult::ready( root: 'plugin', entries: $entries, expanded_bytes: 15 );
		self::assertTrue( $result->is_valid() );
		self::assertNull( $result->failure_code() );
		self::assertSame( 'plugin', $result->root() );
		self::assertSame( $entries, $result->entries() );
		self::assertSame( 15, $result->expanded_bytes() );
	}

	public function testNamedBlockedFactoryRetainsOnlyFailureCode(): void {
		$result = ArchiveScanResult::blocked( failure_code: 'archive_size_limit' );
		self::assertFalse( $result->is_valid() );
		self::assertSame( 'archive_size_limit', $result->failure_code() );
		self::assertNull( $result->root() );
		self::assertSame( array(), $result->entries() );
		self::assertSame( 0, $result->expanded_bytes() );
	}
}
