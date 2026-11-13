<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Contract;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseRejection;
use RAN\WPReleaseUpdater\V1\Dependency\FailureText;

/** The shared `ran/updater-support` failure-text corpus, run through the updater's own entry point. */
final class ReleaseRejectionSharedScreenerTest extends TestCase {

	/** @return array<string,array<string,string>> */
	public static function corpus(): array {
		$path = dirname( __DIR__, 2 ) . '/vendor/ran/updater-support/tests/fixtures/failure-text-corpus.php';
		self::assertFileExists( $path, 'The locked ran/updater-support dist must carry the shared failure-text corpus.' );
		$corpus = require $path;
		self::assertIsArray( $corpus );
		/** @var array<string,array<string,string>> $corpus */
		return $corpus;
	}

	public function test_generated_runtime_copy_is_the_locked_shared_screener(): void {
		$source = dirname( __DIR__, 2 ) . '/vendor/ran/updater-support/src/FailureText.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes to compare the generated runtime copy with the locked source.
		$canonical = (string) file_get_contents( $source );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes to compare the generated runtime copy with the locked source.
		$generated = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Dependency/FailureText.php' );
		self::assertSame( str_replace( 'RAN\\UpdaterSupport\\V1', 'RAN\\WPReleaseUpdater\\V1\\Dependency', $canonical ), $generated );
		self::assertSame( 120, ReleaseRejection::MAX_DETAIL );
	}

	public function test_every_hostile_and_strict_only_entry_is_dropped_for_every_detail_reason(): void {
		$corpus  = self::corpus();
		$checked = 0;
		$skipped = 0;
		$total   = 0;
		foreach ( array( 'hostile', 'strict_only' ) as $group ) {
			self::assertNotEmpty( $corpus[ $group ] );
			foreach ( $corpus[ $group ] as $label => $value ) {
				foreach ( array_keys( array_filter( ReleaseRejection::REASONS ) ) as $reason ) {
					++$total;
					if ( 'header_update_uri_mismatch' === $reason && 1 === preg_match( '#\Ahttps://[a-z0-9.-]+(?:/[A-Za-z0-9._~-]+)*\z#', $value ) ) {
						++$skipped; // The validated canonical Update URI echo is the one documented exemption.
						continue;
					}
					self::assertNull( ReleaseRejection::detail( $reason, $value ), $group . ' ' . $label . ' ' . $reason );
					++$checked;
				}
			}
		}
		self::assertSame( 1, $skipped );
		self::assertSame( $total, $checked + $skipped );
		self::assertGreaterThan( 3000, $checked );
	}

	public function test_every_benign_entry_survives_as_its_bounded_prefix(): void {
		$corpus = self::corpus();
		self::assertNotEmpty( $corpus['benign'] );
		foreach ( $corpus['benign'] as $label => $value ) {
			$expected = rtrim( substr( $value, 0, ReleaseRejection::MAX_DETAIL ) );
			self::assertSame( $expected, FailureText::strict( $value, ReleaseRejection::MAX_DETAIL ), $label );
			foreach ( array_keys( array_filter( ReleaseRejection::REASONS ) ) as $reason ) {
				self::assertSame( $expected, ReleaseRejection::detail( $reason, $value ), $label . ' ' . $reason );
			}
		}
	}

	public function test_a_reason_that_takes_no_detail_drops_even_a_benign_entry(): void {
		foreach ( array_keys( array_filter( ReleaseRejection::REASONS, static fn ( bool $takes ): bool => ! $takes ) ) as $reason ) {
			self::assertNull( ReleaseRejection::detail( $reason, 'Version' ), $reason );
		}
	}

	public function test_every_detail_the_updater_produces_survives_the_strict_screen(): void {
		$produced = array(
			'tag_not_version'            => array( 'v1.2.3', 'nightly', 'v1.2.3-rc.1+build.5', '2026.11.13' ),
			'tag_mismatch'               => array( 'found v1.2.4, expected v1.2.3', 'found v1.0.0-beta.17, expected v1.0.0-beta.18' ),
			'prerelease_not_allowed'     => array( 'v1.2.3-beta.1' ),
			'multiple_package_zips'      => array( '2 ZIP assets' ),
			'asset_not_uploaded'         => array( 'state new', 'state starter' ),
			'asset_too_large'            => array( '52428801 bytes, limit 52428800', '123456789012 bytes, limit 123456789013' ),
			'download_size_mismatch'     => array( 'downloaded 123456 bytes, expected 123457' ),
			'header_invalid'             => array( 'Author', 'Description', 'Name', 'PackageURI', 'RequiresPHP', 'RequiresWP', 'Template', 'UpdateURI', 'Version', 'Plugin Name', 'Requires at least', 'Update URI' ),
			'header_duplicate'           => array( 'Name', 'Version', 'Requires PHP' ),
			'header_version_mismatch'    => array( 'found 1.2.0, expected 1.2.3', 'found 2.0.0-RC1, expected 2.0.0' ),
			'header_update_uri_mismatch' => array( 'https://github.com/owner/other', 'https://updates.example.test/owner/v1.2.3' ),
			'header_requires_php_unmet'  => array( '9.0', '8.3.1' ),
			'header_requires_wp_unmet'   => array( '7.9', '6.5.0' ),
		);
		foreach ( $produced as $reason => $values ) {
			foreach ( $values as $value ) {
				self::assertSame( $value, ReleaseRejection::detail( $reason, $value ), $reason . ' ' . $value );
			}
		}
	}
}
