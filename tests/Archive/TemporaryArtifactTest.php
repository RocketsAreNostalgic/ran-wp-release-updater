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
	public function testInspectRejectsMutation(): void { $artifact = new TemporaryArtifact($this->path, hash_file('sha256', $this->path), $this->identity()); file_put_contents($this->path, 'changed'); $this->expectException(\RuntimeException::class); $artifact->inspect(static fn(string $path): string => $path); }
	/** @return array<string,int> */
	private function identity(): array { $stat = lstat($this->path); self::assertIsArray($stat); return array('dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'], 'mode' => (int) $stat['mode'], 'nlink' => (int) $stat['nlink'], 'uid' => (int) $stat['uid'], 'gid' => (int) $stat['gid'], 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'], 'ctime' => (int) $stat['ctime']); }
}
