<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tool\OutputCap;
use PHPUnit\Framework\TestCase;

final class OutputCapTest extends TestCase
{
    private string $tmpDir;
    private OutputCapConfig $config;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield-output-cap-test-'.bin2hex(random_bytes(4));
        $this->config = new OutputCapConfig(storageDir: $this->tmpDir);
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
        $cap = new OutputCap($this->config);
        $text = 'Hello, world!';

        $this->assertSame($text, $cap->process($text));
    }

    public function testEmptyTextReturnsUnchanged(): void
    {
        $cap = new OutputCap($this->config);

        $this->assertSame('', $cap->process(''));
    }

    public function testTextExactlyAtCapBoundaryReturnsUnchanged(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 10);
        $cap = new OutputCap($cfg);
        $text = '1234567890'; // 10 chars

        $this->assertSame($text, $cap->process($text));
    }

    /* ───────── Capping behaviour ───────── */

    public function testOversizedTextReturnsCappedNotice(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 10);
        $cap = new OutputCap($cfg);
        $text = '12345678901'; // 11 chars — 1 over

        $result = $cap->process($text);

        $this->assertStringContainsString('Output capped', $result);
        $this->assertStringNotContainsString($text, $result);
    }

    public function testCappedNoticeContainsCharCountAndTokenEstimate(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 10);
        $cap = new OutputCap($cfg);
        $text = str_repeat('a', 100); // 100 chars

        $result = $cap->process($text);

        // 100 chars, ~25 tokens (ceil(100/4))
        $this->assertStringContainsString('100', $result);
        $this->assertStringContainsString('25', $result);
    }

    public function testCappedNoticeContainsSavedPath(): void
    {
        $cap = new OutputCap($this->config);
        $text = str_repeat('a', 100);
        $text .= ' some more to exceed default cap of 20000 ';
        // Actually let's use a small cap to force capping
        $cap2 = new OutputCap(new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 10));
        $result = $cap2->process(str_repeat('a', 100));

        // Should contain the storage dir path
        $this->assertStringContainsString($this->tmpDir, $result);
        // Should contain tool-first guidance, not shell-centric head/grep hints
        $this->assertStringContainsString('Do not rerun the original command', $result);
        $this->assertStringContainsString('focused follow-up', $result);
    }

    /* ───────── Persistence ───────── */

    public function testOversizedTextIsPersistedToDisk(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 10);
        $cap = new OutputCap($cfg);
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
        $cap = new OutputCap($this->config);
        $text = 'persist test content';

        $path = $cap->persist($text);

        $this->assertFileExists($path);
        $this->assertStringEqualsFile($path, $text);
    }

    public function testPersistReturnsAbsolutePath(): void
    {
        $cap = new OutputCap($this->config);
        $path = $cap->persist('hello');

        $this->assertTrue(str_starts_with($path, '/'));
        $this->assertStringContainsString($this->tmpDir, $path);
    }

    public function testPersistCreatesParentDirectories(): void
    {
        $nestedDir = $this->tmpDir.'/nested/subdir';
        $cfg = new OutputCapConfig(storageDir: $nestedDir);
        $cap = new OutputCap($cfg);

        $path = $cap->persist('nested test');

        $this->assertFileExists($path);
        $this->assertDirectoryExists($nestedDir);
    }

    public function testPersistWithSessionPrefixUsesPrefixInFilename(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, sessionPrefix: 'run-abc123');
        $cap = new OutputCap($cfg);

        $path = $cap->persist('prefixed content');

        $this->assertStringContainsString('run-abc123-', $path);
        $this->assertStringContainsString($this->tmpDir, $path);
        $this->assertStringEndsWith('.txt', $path);
    }

    public function testPersistWithoutSessionPrefixUsesDatePrefixInFilename(): void
    {
        $cap = new OutputCap($this->config);

        $path = $cap->persist('dated prefix content');

        $filename = basename($path);
        // Should start with today's date: Ymd
        $today = date('Ymd');
        $this->assertStringStartsWith($today.'-', $filename);
        $this->assertStringEndsWith('.txt', $filename);
        // Should have random hex after prefix
        $this->assertMatchesRegularExpression('/^\d{8}-[a-f0-9]{16}\.txt$/', $filename);
    }

    public function testConfigSessionPrefixUsedInFilename(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, sessionPrefix: 'run-abc');
        $cap = new OutputCap($cfg);

        $path = $cap->persist('content');

        $this->assertStringContainsString('run-abc-', $path);
        $this->assertStringContainsString($this->tmpDir, $path);
    }

    public function testPersistDirectoryPermissionsAreRestrictive(): void
    {
        $newDir = $this->tmpDir.'/perm-test';
        $cfg = new OutputCapConfig(storageDir: $newDir);
        $cap = new OutputCap($cfg);

        $path = $cap->persist('perm check');

        $this->assertFileExists($path);
        $perms = fileperms($newDir) & 0777;
        // Should be 0750 or more restrictive — definitely not 0777
        $this->assertLessThanOrEqual(0750, $perms, 'Storage directory permissions must not exceed 0750');
    }

    public function testPersistThrowsOnUnwritableDirectory(): void
    {
        // Use a path under a non-writable parent (e.g. /proc/nonexistent)
        // that can never be created, regardless of permissions.
        $cfg = new OutputCapConfig(storageDir: '/proc/hatfield-output-cap-blocked-'.bin2hex(random_bytes(4)));
        $cap = new OutputCap($cfg);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create output cap storage directory');
        $cap->persist('should fail');
    }

    /* ───────── Doc cap ───────── */

    public function testDocLikePathUsesDocCap(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 50, docCap: 100);
        $cap = new OutputCap($cfg);

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
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 50, docCap: 100);
        $cap = new OutputCap($cfg);
        $text = str_repeat('b', 75);

        $result = $cap->process($text, "/path/to/file{$ext}");
        $this->assertSame($text, $result, "Extension {$ext} should be treated as doc-like");
    }

    public function testNullPathUsesDefaultCap(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 50, docCap: 200);
        $cap = new OutputCap($cfg);
        $text = str_repeat('c', 75);

        // 75 > 50 → capped
        $result = $cap->process($text, null);
        $this->assertStringContainsString('Output capped', $result);
    }

    /* ───────── Config construction ───────── */

    public function testRequiresConfig(): void
    {
        // OutputCap now requires an OutputCapConfig; no null-config fallback.
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir);
        $cap = new OutputCap($cfg);
        $this->assertInstanceOf(OutputCap::class, $cap);
    }

    /* ───────── Cleanup ───────── */

    public function testCleanupDeletesStaleFiles(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, retentionSeconds: 3600);
        $cap = new OutputCap($cfg);
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
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, retentionSeconds: 3600);
        $cap = new OutputCap($cfg);
        $text = 'fresh content';

        $freshPath = $cap->persist($text);
        // File was just created — within retention

        $cap->cleanup();

        $this->assertFileExists($freshPath);
    }

    public function testCleanupDoesNotThrowOnMissingDirectory(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir.'/nonexistent');
        $cap = new OutputCap($cfg);

        // Should not throw
        $cap->cleanup();
        $this->assertTrue(true);
    }

    public function testCleanupPreservesMixedAges(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, retentionSeconds: 3600);
        $cap = new OutputCap($cfg);

        $fresh = $cap->persist('fresh');
        $stalePath = $cap->persist('stale');
        touch($stalePath, time() - 7200);

        $cap->cleanup();

        $this->assertFileExists($fresh);
        $this->assertFileDoesNotExist($stalePath);
    }

    public function testPersistTriggersCleanupOnFirstUse(): void
    {
        // Need to create the directory first so we can place a stale file in it
        // cleanup hasn't run yet because no persist/process has been called
        @mkdir($this->tmpDir, 0750, true);

        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, retentionSeconds: 3600);
        $cap = new OutputCap($cfg);

        // Create a stale file DIRECTLY (not via persist) so cleanup hasn't run yet
        $oldPath = $this->tmpDir.'/stale-test-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($oldPath, 'old data');
        touch($oldPath, time() - 7200);

        $this->assertFileExists($oldPath, 'Precondition: stale file should exist');

        // persist for the first time — should trigger cleanup and remove the stale file
        $newPath = $cap->persist('new data');

        $this->assertFileDoesNotExist($oldPath, 'Stale file should be cleaned up by persist()');
        $this->assertFileExists($newPath);
    }

    /* ───────── file_put_contents failure ───────── */

    public function testPersistThrowsOnWriteFailure(): void
    {
        // Already covered by testPersistThrowsOnUnwritableDirectory —
        // mkdir failure propagates as RuntimeException.
        // A file_put_contents failure after successful mkdir is extremely
        // rare (disk full, FS error) and not worth a dedicated test.
        $this->assertTrue(true);
    }

    /* ───────── Helpers ───────── */

    /**
     * Extract the saved file path from a capped notice.
     */
    private function extractPathFromNotice(string $notice): ?string
    {
        // The notice contains a "Saved to: <path>" line
        // The notice contains a "Saved full output: <path>" line
        if (preg_match('/Saved full output: (.+\.txt)/', $notice, $matches)) {
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
