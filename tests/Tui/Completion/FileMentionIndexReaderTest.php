<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Completion;

use Ineersa\Tui\Completion\FileMentionIndexEntryDTO;
use Ineersa\Tui\Completion\FileMentionIndexReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileMentionIndexReader::class)]
final class FileMentionIndexReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/editor09-reader-'.getmypid().'-'.hrtime(true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    #[Test]
    public function missingFileReturnsEmptyEntries(): void
    {
        $reader = new FileMentionIndexReader($this->tmpDir.'/nonexistent.jsonl');

        $this->assertSame([], $reader->getEntries());
        $this->assertSame([], $reader->getPathsLower());
        $this->assertSame([], $reader->getBasenamesLower());
    }

    #[Test]
    public function parsesJsonlAndReturnsEntries(): void
    {
        $path = $this->tmpDir.'/index.jsonl';
        file_put_contents($path, implode("\n", [
            '{"path":"src/foo.php","dir":false}',
            '{"path":"src/bar","dir":true}',
            '',
        ]));

        $reader = new FileMentionIndexReader($path);
        $entries = $reader->getEntries();

        $this->assertCount(2, $entries);
        $this->assertSame('src/foo.php', $entries[0]->path);
        $this->assertFalse($entries[0]->isDirectory);
        $this->assertSame('src/bar', $entries[1]->path);
        $this->assertTrue($entries[1]->isDirectory);
    }

    #[Test]
    public function reloadsWhenMtimeChanges(): void
    {
        $path = $this->tmpDir.'/index.jsonl';
        file_put_contents($path, '{"path":"first.php","dir":false}');

        $reader = new FileMentionIndexReader($path);
        $this->assertCount(1, $reader->getEntries());

        // Wait 1 second to ensure mtime changes (filesystem resolution).
        sleep(1);
        file_put_contents($path, implode("\n", [
            '{"path":"second.php","dir":false}',
            '{"path":"third.php","dir":false}',
        ]));

        $entries = $reader->getEntries();
        $this->assertCount(2, $entries);
    }

    #[Test]
    public function skipsInvalidJsonLines(): void
    {
        $path = $this->tmpDir.'/index.jsonl';
        file_put_contents($path, implode("\n", [
            '{"path":"good.php","dir":false}',
            'not-valid-json',
            '{"path":"also-good.php","dir":false}',
        ]));

        $reader = new FileMentionIndexReader($path);
        $entries = $reader->getEntries();

        $this->assertCount(2, $entries);
        $this->assertSame('good.php', $entries[0]->path);
        $this->assertSame('also-good.php', $entries[1]->path);
    }

    #[Test]
    public function skipsLinesWithoutPathField(): void
    {
        $path = $this->tmpDir.'/index.jsonl';
        file_put_contents($path, implode("\n", [
            '{"path":"good.php","dir":false}',
            '{"not_path":true}',
        ]));

        $reader = new FileMentionIndexReader($path);
        $entries = $reader->getEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('good.php', $entries[0]->path);
    }

    #[Test]
    public function providesChildrenByDirectory(): void
    {
        $path = $this->tmpDir.'/index.jsonl';
        file_put_contents($path, implode("\n", [
            '{"path":"src/Tui/Completion/Foo.php","dir":false}',
            '{"path":"src/Tui/Completion/Bar.php","dir":false}',
            '{"path":"src/Tui/Other.php","dir":false}',
            '{"path":"tests/Test.php","dir":false}',
        ]));

        $reader = new FileMentionIndexReader($path);
        $children = $reader->getChildren('src/Tui/Completion');

        $this->assertCount(2, $children);
        $names = array_map(static fn (FileMentionIndexEntryDTO $e) => $e->path, $children);
        sort($names);
        $this->assertSame(
            ['src/Tui/Completion/Bar.php', 'src/Tui/Completion/Foo.php'],
            $names,
        );
    }

    #[Test]
    public function providesLowercasePathsAndBasenames(): void
    {
        $path = $this->tmpDir.'/index.jsonl';
        file_put_contents($path, '{"path":"src/Tui/FooBar.php","dir":false}');

        $reader = new FileMentionIndexReader($path);
        $pathsLower = $reader->getPathsLower();
        $basenamesLower = $reader->getBasenamesLower();

        $this->assertSame(['src/tui/foobar.php'], $pathsLower);
        $this->assertSame(['foobar.php'], $basenamesLower);
    }

    #[Test]
    public function tracksLoadedState(): void
    {
        $reader = new FileMentionIndexReader($this->tmpDir.'/nonexistent.jsonl');

        $this->assertFalse($reader->isLoaded());
        $this->assertSame(-1, $reader->loadedMtime());

        // Trigger a load
        $reader->getEntries();

        $this->assertTrue($reader->isLoaded());
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
