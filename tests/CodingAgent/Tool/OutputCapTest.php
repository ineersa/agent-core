<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tool\OutputCap;
use PHPUnit\Framework\TestCase;

final class OutputCapTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield-output-cap-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    /* ───────── Small / at-cap output ───────── */

    public function testSmallTextReturnsUnchanged(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, defaultCap: 20000);
        $text = 'Hello, world!';

        $this->assertSame($text, $cap->process($text));
    }

    public function testEmptyTextReturnsUnchanged(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir);
        $text = '';

        $this->assertSame('', $cap->process($text));
    }

    public function testTextExactlyAtCapBoundaryReturnsUnchanged(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, defaultCap: 10);
        $text = '1234567890'; // 10 chars

        $this->assertSame($text, $cap->process($text));
    }

    /* ───────── Capping behaviour ───────── */

    public function testOversizedTextReturnsCappedNotice(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, defaultCap: 10);
        $text = '12345678901'; // 11 chars — 1 over

        $result = $cap->process($text);

        $this->assertStringContainsString('Output capped', $result);
        $this->assertStringNotContainsString($text, $result);
    }

    public function testCappedNoticeContainsCharCountAndTokenEstimate(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, defaultCap: 10);
        $text = str_repeat('a', 100); // 100 chars

        $result = $cap->process($text);

        // 100 chars, ~25 tokens (ceil(100/4))
        $this->assertStringContainsString('100', $result);
        $this->assertStringContainsString('25', $result);
    }

    public function testCappedNoticeContainsSavedPath(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, defaultCap: 10);
        $text = str_repeat('a', 100);

        $result = $cap->process($text);

        // Should contain the storage dir path
        $this->assertStringContainsString($this->tmpDir, $result);
        // Should contain head/grep hints
        $this->assertStringContainsString('head -50', $result);
        $this->assertStringContainsString('grep', $result);
    }

    /* ───────── Persistence ───────── */

    public function testOversizedTextIsPersistedToDisk(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, defaultCap: 10);
        $text = str_repeat('x', 500);

        $result = $cap->process($text);

        // Extract the saved path from the notice
        $savedPath = $this->extractPathFromNotice($result);
        $this->assertNotNull($savedPath, 'Capped notice should contain a saved path');
        $this->assertFileExists($savedPath);
        $this->assertStringEqualsFile($savedPath, $text);
    }

    public function testPersistCreatesFileWithCorrectContent(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir);
        $text = 'persist test content';

        $path = $cap->persist($text);

        $this->assertFileExists($path);
        $this->assertStringEqualsFile($path, $text);
    }

    public function testPersistReturnsAbsolutePath(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir);
        $path = $cap->persist('hello');

        $this->assertTrue(str_starts_with($path, '/'));
        $this->assertStringContainsString($this->tmpDir, $path);
    }

    public function testPersistCreatesParentDirectories(): void
    {
        $nestedDir = $this->tmpDir.'/nested/subdir';
        $cap = new OutputCap(storageDir: $nestedDir);

        $path = $cap->persist('nested test');

        $this->assertFileExists($path);
        $this->assertDirectoryExists($nestedDir);
    }

    /* ───────── Doc cap ───────── */

    public function testDocLikePathUsesDocCap(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, defaultCap: 50, docCap: 100);

        // Text exceeding default cap but within doc cap
        $text = str_repeat('a', 75);

        // With a non-doc path it should be capped
        $resultCode = $cap->process($text, '/path/to/file.php');
        $this->assertStringContainsString('Output capped', $resultCode, 'Non-doc path should cap at 50');

        // With a doc path it should pass through (75 < 100)
        $resultDoc = $cap->process($text, '/path/to/file.md');
        $this->assertSame($text, $resultDoc, 'Doc path should use 100-char cap');
    }

    /** @return array<string, array{string}> */
    public static function provideDocExtensions(): array
    {
        return [
            'markdown' => ['.md'],
            'text' => ['.txt'],
            'toon' => ['.toon'],
            'uppercase' => ['.MD'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideDocExtensions')]
    public function testDocExtensionsAreRecognised(string $ext): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, defaultCap: 50, docCap: 100);
        $text = str_repeat('b', 75);

        // Should not cap because doc cap (100) > text length (75)
        $result = $cap->process($text, "/path/to/file{$ext}");
        $this->assertSame($text, $result, "Extension {$ext} should be treated as doc-like");
    }

    public function testNullPathUsesDefaultCap(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, defaultCap: 50, docCap: 200);
        $text = str_repeat('c', 75);

        // 75 > 50 → capped
        $result = $cap->process($text, null);
        $this->assertStringContainsString('Output capped', $result);
    }

    /* ───────── Cleanup ───────── */

    public function testCleanupDeletesStaleFiles(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, retentionSeconds: 3600);
        $text = 'stale content';

        // Create a file that appears older than retention
        $oldPath = $cap->persist($text);
        touch($oldPath, time() - 7200); // 2 hours ago

        $this->assertFileExists($oldPath);

        $cap->cleanup();

        $this->assertFileDoesNotExist($oldPath);
    }

    public function testCleanupPreservesRecentFiles(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, retentionSeconds: 3600);
        $text = 'fresh content';

        $freshPath = $cap->persist($text);
        // File was just created — within retention

        $cap->cleanup();

        $this->assertFileExists($freshPath);
    }

    public function testCleanupDoesNotThrowOnMissingDirectory(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir.'/nonexistent');

        // Should not throw
        $cap->cleanup();
        $this->assertTrue(true);
    }

    public function testCleanupPreservesMixedAges(): void
    {
        $cap = new OutputCap(storageDir: $this->tmpDir, retentionSeconds: 3600);

        $fresh = $cap->persist('fresh');
        $stalePath = $cap->persist('stale');
        touch($stalePath, time() - 7200);

        $cap->cleanup();

        $this->assertFileExists($fresh);
        $this->assertFileDoesNotExist($stalePath);
    }

    /* ───────── Default storage dir ───────── */

    public function testDefaultStorageDirUsesCwdDotHatfield(): void
    {
        $cap = new OutputCap();

        // persist() returns path containing the storage dir
        $path = $cap->persist('test');

        $this->assertStringContainsString('/.hatfield/tmp/output-cap', $path);
        $this->assertStringStartsNotWith($this->tmpDir, $path); // not our test tmp dir
    }

    /* ───────── Helpers ───────── */

    /**
     * Extract the saved file path from a capped notice.
     */
    private function extractPathFromNotice(string $notice): ?string
    {
        // The notice contains "Full output saved to: <path>"
        if (preg_match('/Full output saved to: (.+\.txt)/', $notice, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($dir);
    }
}
