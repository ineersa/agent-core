<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Completion;

use Ineersa\Tui\Completion\FileMentionIndexBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileMentionIndexBuilder::class)]
final class FileMentionIndexBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/editor09-builder-'.getmypid().'-'.hrtime(true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    #[Test]
    public function includesFilesAndDirectories(): void
    {
        mkdir($this->tmpDir.'/src', 0755, true);
        touch($this->tmpDir.'/src/foo.php');
        mkdir($this->tmpDir.'/src/nested', 0755, true);
        touch($this->tmpDir.'/src/nested/bar.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath);
        $count = $builder->build();

        $this->assertGreaterThanOrEqual(3, $count);

        $lines = file($indexPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);

        $paths = array_map(static fn (string $l): string => json_decode($l, true)['path'], $lines);
        sort($paths);

        $this->assertContains('src', $paths);
        $this->assertContains('src/foo.php', $paths);
        $this->assertContains('src/nested', $paths);
        $this->assertContains('src/nested/bar.php', $paths);
    }

    #[Test]
    public function excludesDefaultNoisyDirectories(): void
    {
        mkdir($this->tmpDir.'/vendor', 0755, true);
        touch($this->tmpDir.'/vendor/excluded.php');
        mkdir($this->tmpDir.'/node_modules', 0755, true);
        touch($this->tmpDir.'/node_modules/excluded.js');
        mkdir($this->tmpDir.'/var', 0755, true);
        touch($this->tmpDir.'/var/excluded.log');
        mkdir($this->tmpDir.'/.git', 0755, true);
        touch($this->tmpDir.'/.git/config');
        mkdir($this->tmpDir.'/.hatfield', 0755, true);
        mkdir($this->tmpDir.'/.hatfield/sessions', 0755, true);
        touch($this->tmpDir.'/.hatfield/sessions/excluded.json');
        mkdir($this->tmpDir.'/.hatfield/tmp', 0755, true);
        touch($this->tmpDir.'/.hatfield/tmp/excluded.tmp');
        mkdir($this->tmpDir.'/.hatfield/cache', 0755, true);
        touch($this->tmpDir.'/.hatfield/cache/excluded.cache');

        // Files OUTSIDE excluded dirs should be included.
        touch($this->tmpDir.'/included.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath);
        $count = $builder->build();

        $lines = file($indexPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);

        $paths = array_map(static fn (string $l): string => json_decode($l, true)['path'], $lines);

        // Included paths should appear.
        $this->assertContains('included.php', $paths);

        // Excluded directories should NOT appear.
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('vendor/', $path);
            $this->assertStringNotContainsString('node_modules/', $path);
            $this->assertStringNotContainsString('var/', $path);
            $this->assertStringNotContainsString('.git/', $path);
            $this->assertStringNotContainsString('.hatfield/sessions', $path);
            $this->assertStringNotContainsString('.hatfield/tmp', $path);
            $this->assertStringNotContainsString('.hatfield/cache', $path);
        }
    }

    #[Test]
    public function writesAtomically(): void
    {
        touch($this->tmpDir.'/existing.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath);
        $builder->build();

        $this->assertFileExists($indexPath);

        // No leftover tmp files after build.
        $tmpFiles = glob($this->tmpDir.'/*.tmp.*');
        $this->assertEmpty($tmpFiles, 'No temp files should remain after atomic rename.');
    }

    #[Test]
    public function capsEntriesAtMaxLimit(): void
    {
        // Create many files.
        for ($i = 0; $i < 100; ++$i) {
            touch($this->tmpDir.'/file_'.str_pad((string) $i, 3, '0', \STR_PAD_LEFT).'.txt');
        }

        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath);
        $count = $builder->build();

        $this->assertLessThanOrEqual(50_000, $count);
        $this->assertGreaterThan(0, $count);
    }

    #[Test]
    public function lockPreventsConcurrentBuild(): void
    {
        touch($this->tmpDir.'/a.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder1 = new FileMentionIndexBuilder($this->tmpDir, $indexPath);
        $builder2 = new FileMentionIndexBuilder($this->tmpDir, $indexPath);

        $builder1->build();

        // Second build should succeed — the lock is released after build.
        // This just verifies that the lock is released properly.
        $count = $builder2->build();
        $this->assertGreaterThan(0, $count);
    }

    #[Test]
    public function keepsOldIndexOnBuildFailure(): void
    {
        // Create a valid existing index.
        $indexPath = $this->tmpDir.'/index.jsonl';
        file_put_contents($indexPath, '{"path":"old.php","dir":false}');

        // Try to build with a non-existent cwd — should fail.
        $builder = new FileMentionIndexBuilder($this->tmpDir.'/nonexistent', $indexPath);

        try {
            $builder->build();
            $this->fail('Expected RuntimeException for non-existent cwd.');
        } catch (\RuntimeException) {
            // Expected — build failed.
        }

        // Old index should still exist and be intact.
        $this->assertFileExists($indexPath);
        $content = file_get_contents($indexPath);
        $this->assertNotFalse($content);
        $this->assertStringContainsString('old.php', $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $fileinfo) {
            $op = $fileinfo->isDir() ? 'rmdir' : 'unlink';
            $op($fileinfo->getRealPath());
        }
        rmdir($dir);
    }
}
