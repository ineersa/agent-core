<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation
 */
final class TestDirectoryIsolationTest extends TestCase
{
    private string $tempDir = '';

    protected function tearDown(): void
    {
        if ('' !== $this->tempDir && is_dir($this->tempDir)) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
    }

    public function testCreateOsTempDir(): void
    {
        $this->tempDir = TestDirectoryIsolation::createOsTempDir('test-isolation');
        $this->assertDirectoryExists($this->tempDir);
    }

    public function testCreateProjectTempDir(): void
    {
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('test-project');
        $this->assertDirectoryExists($this->tempDir);
        $this->assertStringContainsString('var/tmp', $this->tempDir);
    }

    public function testCreateHatfieldTree(): void
    {
        $this->tempDir = TestDirectoryIsolation::createOsTempDir('test-hatfield');

        TestDirectoryIsolation::createHatfieldTree($this->tempDir);

        $this->assertDirectoryExists($this->tempDir.'/.hatfield');
        $this->assertFileExists($this->tempDir.'/.hatfield/.gitignore');
        $this->assertFileExists($this->tempDir.'/.hatfield/settings.yaml');
        $this->assertDirectoryDoesNotExist($this->tempDir.'/.hatfield/sessions');
    }

    public function testCreateHatfieldTreeWithSessions(): void
    {
        $this->tempDir = TestDirectoryIsolation::createOsTempDir('test-hatfield-sessions');

        TestDirectoryIsolation::createHatfieldTree($this->tempDir, withSessions: true);

        $this->assertDirectoryExists($this->tempDir.'/.hatfield');
        $this->assertDirectoryExists($this->tempDir.'/.hatfield/sessions');
    }

    public function testRemoveDirectory(): void
    {
        $dir = TestDirectoryIsolation::createOsTempDir('test-remove');
        mkdir($dir.'/sub', 0755, true);
        file_put_contents($dir.'/sub/file.txt', 'content');
        file_put_contents($dir.'/root.txt', 'root');

        TestDirectoryIsolation::removeDirectory($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testEnsureDirectoryCreatesNewDirectory(): void
    {
        $this->tempDir = TestDirectoryIsolation::createOsTempDir('test-ensure');
        $sub = $this->tempDir.'/new-sub';

        TestDirectoryIsolation::ensureDirectory($sub);

        $this->assertDirectoryExists($sub);
    }

    public function testEnsureDirectorySkipsExisting(): void
    {
        $this->tempDir = TestDirectoryIsolation::createOsTempDir('test-ensure-existing');

        TestDirectoryIsolation::ensureDirectory($this->tempDir);
        // Should not throw
        $this->assertDirectoryExists($this->tempDir);
    }

    public function testEnsureDirectoryThrowsOnFile(): void
    {
        $this->tempDir = TestDirectoryIsolation::createOsTempDir('test-ensure-file');
        $filePath = $this->tempDir.'/blocker';
        file_put_contents($filePath, 'not-a-dir');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-directory filesystem entry');

        TestDirectoryIsolation::ensureDirectory($filePath);
    }

    public function testRemoveDirectoryDoesNotThrowOnMissing(): void
    {
        // Must not throw
        TestDirectoryIsolation::removeDirectory('/tmp/nonexistent-dir-'.\bin2hex(\random_bytes(4)));
        $this->assertTrue(true);
    }
}
