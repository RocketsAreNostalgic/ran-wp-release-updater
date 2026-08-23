<?php
declare(strict_types=1);

namespace Tests\Archive;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;

final class TemporaryArtifactTest extends TestCase
{
	private string $path;
	protected function setUp(): void { $this->path = tempnam(sys_get_temp_dir(), 'ran-core-artifact-'); chmod($this->path, 0600); file_put_contents($this->path, 'artifact'); }
	protected function tearDown(): void { @unlink($this->path); }
	public function testCoreUpdateIsBoundAndSingleUse(): void { $artifact = TemporaryArtifact::forCoreUpdate($this->path, hash_file('sha256', $this->path), 'plugin', 'package/package.php', '2.0.0'); self::assertSame('2.0.0', $artifact->acceptCoreUpdate('plugin', 'package/package.php', 'update', $this->path)); $this->expectException(\RuntimeException::class); $artifact->acceptCoreUpdate('plugin', 'package/package.php', 'update', $this->path); }
	public function testCoreUpdateRejectsMismatchAndMutation(): void { $artifact = TemporaryArtifact::forCoreUpdate($this->path, hash_file('sha256', $this->path), 'theme', 'package', '2.0.0'); try { $artifact->acceptCoreUpdate('theme', 'other', 'update', $this->path); self::fail('mismatch accepted'); } catch (\RuntimeException) {} file_put_contents($this->path, 'changed'); $this->expectException(\RuntimeException::class); $artifact->acceptCoreUpdate('theme', 'package', 'update', $this->path); }
}
