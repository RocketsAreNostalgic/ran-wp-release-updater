<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowContractTest extends TestCase {

	public function testReleaseWorkflowAndJsonVersionUpdaterAreExact(): void {
		$workflow = (string) file_get_contents( dirname( __DIR__ ) . '/.github/workflows/release-please.yml' );
		$ci       = (string) file_get_contents( dirname( __DIR__ ) . '/.github/workflows/ci.yml' );
		$config   = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/release-please-config.json' ), true, 512, JSON_THROW_ON_ERROR );

		self::assertStringContainsString( 'workflow_run:', $workflow );
		self::assertStringContainsString( "workflow_run.event == 'push'", $workflow );
		self::assertStringContainsString( 'workflow_run.head_repository.id == github.repository_id', $workflow );
		self::assertStringContainsString( 'permissions: {}', $workflow );
		self::assertStringContainsString( 'group: updater-exact-release-publisher', $workflow );
		self::assertStringContainsString( 'timeout-minutes: 15', $workflow );
		self::assertStringContainsString( 'RAN_RELEASE_PUBLISHER_MUTATE: \'1\'', $workflow );
		self::assertStringContainsString( 'actions/setup-node@48b55a011bda9f5d6aeb4c2d9c7362e8dae4041e # v6.4.0', $ci );
		self::assertStringContainsString( "node-version: '24.11.0'", $ci );
		self::assertSame( 'json', $config['packages']['.']['extra-files'][0]['type'] );
		self::assertSame( 'runtime-copy.json', $config['packages']['.']['extra-files'][0]['path'] );
		self::assertSame( '$.package_version', $config['packages']['.']['extra-files'][0]['jsonpath'] );
	}

	public function testQualityFanInRequiresEveryVerificationLane(): void {
		$ci = (string) file_get_contents( dirname( __DIR__ ) . '/.github/workflows/ci.yml' );

		$required_needs = <<<'YAML'
  quality:
    if: ${{ always() }}
    needs:
      - baseline
      - javascript
      - mysql-cas
      - windows-portability
      - wordpress-installed-integration
YAML;

		self::assertStringContainsString( $required_needs, $ci );

		foreach (
			array(
				'BASELINE_RESULT: ${{ needs.baseline.result }}' => 'test "$BASELINE_RESULT" = success',
				'JAVASCRIPT_RESULT: ${{ needs.javascript.result }}' => 'test "$JAVASCRIPT_RESULT" = success',
				'MYSQL_CAS_RESULT: ${{ needs.mysql-cas.result }}' => 'test "$MYSQL_CAS_RESULT" = success',
				'WINDOWS_RESULT: ${{ needs.windows-portability.result }}' => 'test "$WINDOWS_RESULT" = success',
				'WORDPRESS_RESULT: ${{ needs.wordpress-installed-integration.result }}' => 'test "$WORDPRESS_RESULT" = success',
			) as $result_binding => $success_guard
		) {
			self::assertStringContainsString( $result_binding, $ci );
			self::assertStringContainsString( $success_guard, $ci );
		}
	}

	public function testBootstrapAndArchiveContractsRemainReleaseSafe(): void {
		$config     = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/release-please-config.json' ), true, 512, JSON_THROW_ON_ERROR );
		$manifest   = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/.release-please-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		$copy       = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );
		$attributes = (string) file_get_contents( dirname( __DIR__ ) . '/.gitattributes' );

		self::assertMatchesRegularExpression( '/^[a-f0-9]{40}$/', $config['bootstrap-sha'] );
		self::assertSame( '0.1.0-beta.1', $config['packages']['.']['initial-version'] );
		self::assertSame( $manifest['.'], $copy['package_version'] );
		foreach ( array( '/.github export-ignore', '/scripts export-ignore', '/tests export-ignore', '/release-please-config.json export-ignore' ) as $rule ) {
			self::assertStringContainsString( $rule, $attributes );
		}
		foreach ( array( '/composer.json export-ignore', '/bootstrap.php export-ignore', '/runtime.php export-ignore', '/src export-ignore' ) as $rule ) {
			self::assertStringNotContainsString( $rule, $attributes );
		}
	}
}
