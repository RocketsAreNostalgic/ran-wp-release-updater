<?php

declare(strict_types=1);

namespace Tests\Provider;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\ProspectiveReleaseInspection;

final class ProspectiveReleaseInspectionSchemaTest extends TestCase
{
	public function testWireSchemaHasFingerprintLastAndItsDigestBindsEveryFact(): void
	{
		$inspection = ProspectiveReleaseInspection::create($this->facts());
		$wire = $inspection->toArray();
		self::assertSame('fingerprint', array_key_last($wire));
		self::assertSame(array(
			'artifact_filename', 'artifact_identity', 'artifact_sha256', 'artifact_size', 'assurance_facts', 'canonical_update_uri', 'channel', 'commit_identity', 'main_file', 'maximum_artifact_bytes', 'package_root', 'php_runtime_version', 'provider_code', 'release_identity', 'repository_identity', 'repository_locator', 'tag', 'target_type', 'version', 'wordpress_runtime_version', 'fingerprint',
		), array_keys($wire));

		$facts = $this->facts();
		foreach ($facts as $key => $value) {
			if ('assurance_facts' === $key) {
				foreach ($value as $assurance => $bool) {
					$changed = $facts;
					$changed['assurance_facts'][$assurance] = ! $bool;
					self::assertNotSame($inspection->fingerprintValue(), ProspectiveReleaseInspection::create($changed)->fingerprintValue(), $assurance);
				}
				continue;
			}
			if ('target_type' === $key) {
				$changed = $facts;
				$changed['target_type'] = 'theme';
				$changed['main_file'] = 'style.css';
				self::assertNotSame($inspection->fingerprintValue(), ProspectiveReleaseInspection::create($changed)->fingerprintValue(), $key);
				continue;
			}
			$changed = $facts;
			$changed[$key] = $this->changed($key, $value);
			self::assertNotSame($inspection->fingerprintValue(), ProspectiveReleaseInspection::create($changed)->fingerprintValue(), $key);
		}

		$reordered = array_reverse($facts, true);
		$reordered['assurance_facts'] = array_reverse($facts['assurance_facts'], true);
		self::assertSame($inspection->fingerprintValue(), ProspectiveReleaseInspection::create($reordered)->fingerprintValue());
	}

	/** @return array<string,mixed> */
	private function facts(): array
	{
		return array('artifact_filename' => 'example.zip', 'artifact_identity' => '8', 'artifact_sha256' => str_repeat('a', 64), 'artifact_size' => 12, 'assurance_facts' => array('exact_artifact_identity' => true, 'exact_commit_identity' => true, 'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true, 'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true), 'canonical_update_uri' => 'https://github.com/acme/example', 'channel' => 'stable', 'commit_identity' => str_repeat('b', 40), 'main_file' => 'example.php', 'maximum_artifact_bytes' => 100, 'package_root' => 'example', 'php_runtime_version' => '8.2.0', 'provider_code' => 'github', 'release_identity' => '7', 'repository_identity' => '99', 'repository_locator' => 'acme/example', 'tag' => 'v1.2.3', 'target_type' => 'plugin', 'version' => '1.2.3', 'wordpress_runtime_version' => '6.8.0');
	}

	private function changed(string $key, mixed $value): mixed
	{
		return match ($key) { 'artifact_size' => 13, 'maximum_artifact_bytes' => 101, 'artifact_sha256' => str_repeat('c', 64), 'channel' => 'prerelease', 'main_file' => 'other.php', 'canonical_update_uri' => 'https://github.com/acme/changed', 'php_runtime_version' => '8.3.0', 'wordpress_runtime_version' => '6.9.0', 'version' => '1.2.4', 'tag' => 'v1.2.4', 'artifact_filename' => 'changed.zip', default => (string) $value . 'x' };
	}
}
