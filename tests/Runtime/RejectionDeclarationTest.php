<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseService;
use RAN\WPReleaseUpdater\V1\Runtime\RequestProtocolValidator;
use RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState;

final class RejectionDeclarationTest extends TestCase {

	/** @return array<string,mixed> */
	private function declaration(): array {
		return array(
			'provider_code'          => 'github',
			'target_type'            => 'plugin',
			'repository_locator'     => 'acme/example',
			'repository_identity'    => '99',
			'channel'                => 'stable',
			'credential_resolver'    => null,
			'maximum_artifact_bytes' => 52428800,
			'operation_guard'        => null,
		);
	}

	/** @return array<string,array{mixed,bool}> */
	public static function flag_provider(): array {
		return array(
			'true'   => array( true, true ),
			'false'  => array( false, false ),
			'one'    => array( 1, false ),
			'string' => array( 'true', false ),
			'null'   => array( null, false ),
		);
	}

	public function test_an_absent_flag_is_accepted(): void {
		self::assertNull( RequestProtocolValidator::release_declaration_code( $this->declaration() ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'flag_provider' )]
	public function test_the_broker_validator_accepts_only_literal_true( mixed $flag, bool $accepted ): void {
		$declaration                      = $this->declaration();
		$declaration['rejection_reasons'] = $flag;
		self::assertSame( $accepted ? null : 'invalid_configuration', RequestProtocolValidator::release_declaration_code( $declaration ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'flag_provider' )]
	public function test_the_github_composition_accepts_only_literal_true( mixed $flag, bool $accepted ): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the WordPress version consumed by the composition under test.
		$GLOBALS['wp_version']            = '6.8.0';
		$declaration                      = $this->declaration();
		$declaration['rejection_reasons'] = $flag;
		$state                            = ( new \ReflectionClass( SelectedRuntimeState::class ) )->newInstanceWithoutConstructor();
		if ( ! $accepted ) {
			$this->expectException( \InvalidArgumentException::class );
		}
		self::assertInstanceOf( GitHubReleaseService::class, GitHubReleaseService::from_release_declaration( $declaration, $state ) );
	}
}
