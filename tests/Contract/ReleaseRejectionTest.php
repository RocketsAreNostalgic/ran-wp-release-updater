<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Contract;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseRejection;
use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;

final class ReleaseRejectionTest extends TestCase {

	public function test_every_reason_is_a_stable_bounded_identifier(): void {
		foreach ( array_keys( ReleaseRejection::REASONS ) as $reason ) {
			self::assertMatchesRegularExpression( '/\A[a-z][a-z0-9_]{0,63}\z/D', $reason );
			self::assertTrue( ReleaseRejection::valid( $reason ) );
		}
		self::assertFalse( ReleaseRejection::valid( 'made_up_reason' ) );
	}

	public function test_unknown_reason_keeps_the_generic_failure_and_drops_its_detail(): void {
		$failure = ReleaseFailure::rejected( 'made_up_reason', 'secret' );
		self::assertSame( 'package_incompatible', $failure->release_code );
		self::assertNull( $failure->rejection_reason );
		self::assertNull( $failure->rejection_detail );
		$plain = new ReleaseFailure( 'package_incompatible' );
		self::assertNull( $plain->rejection_reason );
	}

	public function test_reason_is_only_kept_on_package_incompatible(): void {
		$failure = new ReleaseFailure( 'operation_failed', null, 'not_applicable', null, 'no_package_zip' );
		self::assertNull( $failure->rejection_reason );
	}

	public function test_detail_is_dropped_for_a_reason_that_takes_none(): void {
		$failure = ReleaseFailure::rejected( 'no_package_zip', 'unmatched detail' );
		self::assertSame( 'no_package_zip', $failure->rejection_reason );
		self::assertNull( $failure->rejection_detail );
	}

	public function test_detail_is_bounded_printable_and_never_a_digest(): void {
		self::assertSame( 'found 1.2.0, expected 1.2.3', ReleaseRejection::detail( 'header_version_mismatch', "  found 1.2.0, expected 1.2.3\n" ) );
		self::assertSame( 'a?b?c?', ReleaseRejection::detail( 'header_invalid', "a\x00b\u{202E}c\u{00E9}" ) );
		self::assertSame( substr( str_repeat( 'ab ', 200 ), 0, 120 ), ReleaseRejection::detail( 'header_invalid', str_repeat( 'ab ', 200 ) ) );
		self::assertNull( ReleaseRejection::detail( 'header_invalid', "\xB1\xB2" ) );
		self::assertNull( ReleaseRejection::detail( 'header_invalid', '' ) );
		self::assertNull( ReleaseRejection::detail( 'header_invalid', null ) );
		self::assertNull( ReleaseRejection::detail( 'header_invalid', 'sha256:' . str_repeat( 'a', 64 ) ) );
		self::assertNull( ReleaseRejection::detail( 'header_invalid', str_repeat( 'f', 40 ) ) );
		self::assertNull( ReleaseRejection::detail( 'no_package_zip', 'anything' ) );
	}

	public function test_digest_is_screened_before_the_detail_is_bounded(): void {
		$hex = str_repeat( 'f0', 20 );
		self::assertNull( ReleaseRejection::detail( 'tag_not_version', str_repeat( 'z', 100 ) . $hex ) );
		self::assertNull( ReleaseRejection::detail( 'tag_mismatch', 'found v1.2.4' . str_repeat( 'z', 100 ) . $hex . ', expected v1.2.3' ) );
		self::assertNull( ReleaseRejection::detail( 'header_version_mismatch', 'found 1.2.0, expected ' . $hex ) );
	}

	public function test_credential_looking_detail_is_dropped_not_redacted(): void {
		foreach ( array(
			'https://user:password@github.com/owner/repository',
			'https://us%40er@github.com/owner/repository',
			'found access_token, expected 1.2.3',
			'https://github.com/owner/repository?token=1',
			'https://github.com/owner/repository/secret',
			'key=value',
			str_repeat( 'Ab1+', 12 ),
		) as $value ) {
			self::assertNull( ReleaseRejection::detail( 'header_update_uri_mismatch', $value ), $value );
		}
		self::assertSame( 'Author', ReleaseRejection::detail( 'header_duplicate', 'Author' ) );
	}
}
