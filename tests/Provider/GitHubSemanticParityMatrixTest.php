<?php

declare(strict_types=1);

namespace Tests\Provider;

use PHPUnit\Framework\TestCase;

final class GitHubSemanticParityMatrixTest extends TestCase
{
	private const AREAS = array(
		'archive',
		'artifact',
		'automatic',
		'cache',
		'coordination',
		'diagnostics',
		'discovery',
		'failure',
		'identity',
		'inspection',
		'native',
		'performance',
		'policy',
		'prospective',
		'refresh',
		'runtime',
		'transport',
	);

	private const DISPOSITIONS = array(
		'deferred_3_3',
		'intentionally_removed',
		'narrowed',
		'preserved',
		'strengthened',
	);

	public function testEveryRequiredSemanticAreaHasAnExactClosedDisposition(): void
	{
		$matrix = $this->matrix();
		self::assertSame(
			'4586ffe4105565cf19f8b2e842fb78bd2e96d304',
			$matrix['legacy_source_sha']
		);
		self::assertSame(
			'b526119e1789a007cb810246d42520204cc8ed24',
			$matrix['neutral_source_sha']
		);
		self::assertSame(
			'tests/Integration/paired-github-provider-proof.php',
			$matrix['executable_proof']
		);
		self::assertSame('composer check:phase3', $matrix['executable_gate']);
		self::assertFileExists(
			dirname(__DIR__) . '/Integration/paired-github-provider-proof.php'
		);

		$ids = array();
		$areas = array();
		foreach ($matrix['rows'] as $row) {
			self::assertSame(
				array(
					'area',
					'difference',
					'disposition',
					'id',
					'legacy_evidence',
					'legacy_outcome',
					'neutral_evidence',
					'neutral_outcome',
				),
				$this->sortedKeys($row),
				(string) ($row['id'] ?? 'unknown')
			);
			self::assertMatchesRegularExpression('/\A[a-z0-9-]{3,64}\z/D', $row['id']);
			self::assertArrayNotHasKey($row['id'], $ids);
			self::assertContains($row['area'], self::AREAS);
			self::assertContains($row['disposition'], self::DISPOSITIONS);
			self::assertNotSame(array(), $row['legacy_evidence']);
			self::assertIsString($row['legacy_outcome']);
			self::assertIsString($row['neutral_outcome']);
			if ('intentionally_removed' !== $row['disposition']) {
				self::assertNotSame(array(), $row['neutral_evidence']);
			}
			$ids[$row['id']] = true;
			$areas[$row['area']] = true;

			if ('preserved' === $row['disposition']) {
				self::assertSame($row['legacy_outcome'], $row['neutral_outcome']);
				self::assertSame('', $row['difference']);
			} else {
				self::assertNotSame('', $row['difference']);
			}
			if ('intentionally_removed' === $row['disposition']) {
				self::assertSame('absent', $row['neutral_outcome']);
			}
			if ('deferred_3_3' === $row['disposition']) {
				self::assertSame('uncomposed', $row['neutral_outcome']);
			}
		}

		$actualAreas = array_keys($areas);
		sort($actualAreas, SORT_STRING);
		self::assertSame(self::AREAS, $actualAreas);
	}

	public function testEveryClaimedNeutralFixtureIsRunnableAndDeferredRowsStayVisible(): void
	{
		$testFiles = array(
			'Tests\\Archive\\PackageIdentityValidatorTest' => dirname(__DIR__)
				. '/Archive/PackageIdentityValidatorTest.php',
			'Tests\\Provider\\GitHubReleaseAdapterTest' => __DIR__
				. '/GitHubReleaseAdapterTest.php',
			'Tests\\Runtime\\RequestBrokerTest' => dirname(__DIR__)
				. '/Runtime/RequestBrokerTest.php',
			'Tests\\WordPress\\NativePluginUpdaterTest' => dirname(__DIR__)
				. '/WordPress/NativePluginUpdaterTest.php',
			'Tests\\WordPress\\ReleaseOperationCoordinatorTest' => dirname(__DIR__)
				. '/WordPress/ReleaseOperationCoordinatorTest.php',
		);
		$deferred = array();
		$removed = array();
		foreach ($this->matrix()['rows'] as $row) {
			foreach ($row['neutral_evidence'] as $evidence) {
				list($class, $method) = explode('::', $evidence, 2);
				self::assertArrayHasKey($class, $testFiles, $evidence);
				$source = file_get_contents($testFiles[$class]);
				self::assertIsString($source, $evidence);
				self::assertMatchesRegularExpression(
					'/public function ' . preg_quote($method, '/') . '\s*\(/',
					$source,
					$evidence
				);
			}
			if ('deferred_3_3' === $row['disposition']) {
				$deferred[] = $row['id'];
			}
			if ('intentionally_removed' === $row['disposition']) {
				$removed[] = $row['id'];
			}
		}

		self::assertSame(
			array(
				'native-wordpress-lifecycle',
				'automatic-wordpress-run',
				'selected-runtime-composition',
			),
			$deferred
		);
		self::assertSame(
			array('provider-cache', 'prospective-product-api'),
			$removed
		);
	}

	/** @return array<string,mixed> */
	private function matrix(): array
	{
		$contents = file_get_contents(__DIR__ . '/github-semantic-parity.json');
		self::assertIsString($contents);
		$matrix = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
		self::assertIsArray($matrix);
		self::assertSame(
			array(
				'executable_gate',
				'executable_proof',
				'legacy_source_sha',
				'neutral_source_sha',
				'rows',
			),
			$this->sortedKeys($matrix)
		);
		self::assertIsArray($matrix['rows']);
		return $matrix;
	}

	/** @param array<string,mixed> $value
	 * @return list<string>
	 */
	private function sortedKeys(array $value): array
	{
		$keys = array_keys($value);
		sort($keys, SORT_STRING);
		return $keys;
	}
}
