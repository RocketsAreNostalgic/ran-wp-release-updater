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
		self::assertSame( 'found 1.2.0, expected 1.2.3', ReleaseRejection::detail( 'header_version_mismatch', '  found 1.2.0, expected 1.2.3  ' ) );
		self::assertNull( ReleaseRejection::detail( 'header_version_mismatch', "found 1.2.0, expected 1.2.3\n" ) );
		self::assertNull( ReleaseRejection::detail( 'header_invalid', "a\x00b\u{202E}c\u{00E9}" ) );
		self::assertSame( rtrim( substr( str_repeat( 'xy ', 200 ), 0, 120 ) ), ReleaseRejection::detail( 'header_invalid', str_repeat( 'xy ', 200 ) ) );
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
			'found access_token=abc, expected 1.2.3',
			'https://github.com/owner/repository?token=1',
			'key=value',
			str_repeat( 'Ab1+', 12 ),
		) as $value ) {
			self::assertNull( ReleaseRejection::detail( 'header_update_uri_mismatch', $value ), $value );
		}
		self::assertSame( 'Author', ReleaseRejection::detail( 'header_duplicate', 'Author' ) );
	}

	/** @return array<string,array{string}> */
	public static function unsafe_detail_provider(): array {
		$sha256 = hash( 'sha256', 'a' );
		return array(
			'credential url'    => array( 'https://user:pw@github.com/o/r/ghp_' . str_repeat( 'a', 36 ) . '?access=1' ),
			'filesystem path'   => array( '/Users/me/Local Sites/x/wp-content' ),
			'home path'         => array( '~/secrets' ),
			'drive path'        => array( 'C:\\Users\\me' ),
			'markup'            => array( '<img src=x onerror=alert(1)>' ),
			'base64 sha256'     => array( 'ypeBEsobvcr6wjGzmiPcTaeG7/gUfE5yuYB3ha/uSLs=' ),
			'base64 sha512'     => array( 'H0D8ktokFpR1CXnubPWC8tXX0o4YM13gWrxU0FYOD1MChgxlK/CNVgJSql50IQVG82n7u86MEs/HlXsmUv6adQ==' ),
			'dashed hex'        => array( implode( '-', str_split( $sha256, 8 ) ) ),
			'colon hex'         => array( implode( ':', str_split( $sha256, 2 ) ) ),
			'sha1 prefix'       => array( 'sha1:da39a3ee' ),
			'sha512 prefix'     => array( 'sha512:cf83e135' ),
			'39 hex'            => array( substr( $sha256, 0, 39 ) ),
			'digest past limit' => array( str_repeat( 'x ', 70 ) . $sha256 ),
			'split by limit'    => array( str_repeat( 'x ', 59 ) . substr( $sha256, 0, 40 ) ),
			'password text'     => array( 'password: hunter2' ),
		) + self::evasion_cases();
	}

	/** @return array<string,array{string}> */
	private static function evasion_cases(): array {
		$sha256 = hash( 'sha256', 'a' );
		$cases  = array(
			'ghp bare'         => 'ghp_',
			'gho bare'         => 'found gho_x',
			'ghu ghs ghr'      => 'ghs_1',
			'github pat'       => 'github_pat_1',
			'gitlab pat'       => 'glpat-1',
			'slack token'      => 'xoxb-1',
			'aws akia'         => 'AKIA1',
			'aws asia'         => 'ASIA1',
			'jwt start'        => 'eyJhbGciOi',
			'npm token'        => 'npm_x',
			'stripe live'      => 'sk_live_1',
			'stripe test'      => 'sk_test_1',
			'basic auth'       => 'Basic dXNlcjpw',
			'bearer auth'      => 'Bearer abc.def',
			'dot hex'          => implode( '.', str_split( $sha256, 8 ) ),
			'comma hex'        => implode( ',', str_split( $sha256, 8 ) ),
			'slash hex'        => implode( '/', str_split( $sha256, 8 ) ),
			'question hex'     => implode( '?', str_split( $sha256, 8 ) ),
			'space hex'        => implode( ' ', str_split( $sha256, 8 ) ),
			'zero width hex'   => implode( "\u{200B}", str_split( $sha256, 8 ) ),
			'non-ascii hex'    => implode( "\u{00E9}", str_split( $sha256, 4 ) ),
			'dotted base64'    => 'Ab1Cd2Ef3Gh4.Ij5Kl6Mn7Op8.Qr9St0Uv1Wx2Yz3',
			'assign path'      => 'x=/etc/passwd',
			'colon path'       => 'at:/etc/passwd',
			'bracket path'     => '[/etc/passwd]',
			'pipe path'        => 'x|/etc/passwd',
			'file url'         => 'file:///etc/passwd',
			'parent segment'   => 'a/../b',
			'leading parent'   => '../x',
			'relative path'    => 'var/www/html/wp-config.php',
			'one slash file'   => 'html/wp-config.php',
			'other url'        => 'ftp://example.test/x',
			'prose password'   => 'the password is hunter2',
			'pwd word'         => 'pwd hunter2',
			'secret value'     => 'secret value abc',
			'api key words'    => 'api key abc',
			'apikey word'      => 'apikey abc',
			'authorization'    => 'authorization granted',
			'word before'      => 'hunter2 is the password',
			'british spelling' => 'authorisation hunter2',
			'british before'   => 'hunter2 authorisation',
			'british header'   => 'Authorisation: abc',
			'token prose'      => 'token abc',
			'american header'  => 'Authorization: abc',
		);
		return array_map( static fn ( string $value ): array => array( $value ), $cases );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unsafe_detail_provider' )]
	public function test_unsafe_detail_shapes_are_dropped_whole( string $value ): void {
		self::assertNull( ReleaseRejection::detail( 'tag_not_version', $value ) );
	}

	public function test_canonical_update_uri_echo_and_plain_text_survive_the_screen(): void {
		self::assertSame( 'https://updates.example.test/owner/other', ReleaseRejection::detail( 'header_update_uri_mismatch', 'https://updates.example.test/owner/other' ) );
		self::assertNull( ReleaseRejection::detail( 'tag_not_version', 'release/v1.2.3' ) );
		self::assertSame( 'Author', ReleaseRejection::detail( 'header_duplicate', 'Author' ) );
		self::assertSame( 'Requires PHP', ReleaseRejection::detail( 'header_invalid', 'Requires PHP' ) );
	}

	public function test_update_uri_exemption_is_only_the_validated_canonical_form(): void {
		$hex = str_repeat( 'f0', 20 );
		foreach ( array(
			'https://user@updates.example.test/owner/other',
			'https://user:pw@updates.example.test/owner/other',
			'https://updates.example.test/owner/other?a=1',
			'https://updates.example.test/owner/other#frag',
			'https://updates.example.test:8443/owner/other',
			'http://updates.example.test/owner/other',
			'https://UPDATES.example.test/owner/other',
			'https://updates.example.test/owner/other/',
			'https://updates.example.test/owner/../other',
			'https://updates.example.test/owner/' . $hex,
			'https://updates.example.test/owner/ghp_abcdef',
			'https://updates.example.test/owner/secret/hunter2',
			'https://a0123456789abcdef0123456789abcdef.example.com/o',
			'https://secret-hunter2.example.com/o/r',
			'https://token.ghp-abcdefghij0123456789abcdef0123456789.example.com/o',
			'https://updates.example.test/' . str_repeat( 'owner/', 15 ) . 'owner',
			'https://updates.example.test/' . implode( '/', str_split( $hex, 8 ) ),
			'https://updates.example.test/owner/authorisation/hunter2',
			'https://updates.example.test/' . str_repeat( 'a', 120 ),
			'https://updates.example.test/owner/other found',
		) as $value ) {
			self::assertNull( ReleaseRejection::detail( 'header_update_uri_mismatch', $value ), $value );
		}
		foreach ( array( 'https://github.com/owner/repo', 'https://bitbucket.org/my-ws/my-repo', 'https://raw.githubusercontent.com/o/r/main' ) as $kept ) {
			self::assertSame( $kept, ReleaseRejection::detail( 'header_update_uri_mismatch', $kept ) );
		}
		self::assertNull( ReleaseRejection::detail( 'tag_not_version', 'https://updates.example.test/owner/other' ) );
		self::assertNull( ReleaseRejection::detail( 'header_invalid', 'https://updates.example.test/owner/other' ) );
	}

	public function test_ordinary_text_with_credential_like_words_is_kept(): void {
		self::assertSame( 'tokenizer-1', ReleaseRejection::detail( 'tag_not_version', 'tokenizer-1' ) );
		self::assertSame( 'found v1.2.4, expected v1.2.3', ReleaseRejection::detail( 'tag_mismatch', 'found v1.2.4, expected v1.2.3' ) );
	}
}
