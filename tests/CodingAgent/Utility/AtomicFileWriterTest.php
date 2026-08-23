<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Utility;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Ineersa\CodingAgent\Utility\AtomicFileWriterException;
use PHPUnit\Framework\TestCase;

final class AtomicFileWriterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('atomic-file-writer');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testWritesExactContentWithModesAndNoTemp(): void
    {
        $path = $this->tmpDir.'/secret-dir/credentials.json';
        $contents = "{\"a\":\"b\"}\n".str_repeat('x', 4096);

        AtomicFileWriter::write($path, $contents, fileMode: 0600, directoryMode: 0700);

        $this->assertSame($contents, file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms($this->tmpDir.'/secret-dir') & 0777);
        $this->assertSame([], glob($this->tmpDir.'/secret-dir/*.tmp.*') ?: [], 'No temp files should remain after success');
    }

    public function testRenameFailureKeepsPriorDestinationIntactAndCleansTemp(): void
    {
        // A directory at the destination makes the atomic rename fail after
        // the temp file was written.
        $dest = $this->tmpDir.'/blocked';
        mkdir($dest, 0755, true);
        file_put_contents($dest.'/keep.txt', 'keep');

        try {
            AtomicFileWriter::write($dest, 'new content');
            $this->fail('Expected AtomicFileWriterException on rename failure');
        } catch (AtomicFileWriterException $exception) {
            $this->assertSame('rename', $exception->stage);
        }

        $this->assertFileExists($dest.'/keep.txt', 'Prior destination must remain intact');
        $this->assertSame([], glob($this->tmpDir.'/*.tmp.*') ?: [], 'Temp file must be cleaned on rename failure');
    }

    public function testOverlongBasenameFailsAtWriteStageWithTypedExceptionAndNoTemp(): void
    {
        // A basename over NAME_MAX (255) fails fopen deterministically.
        $path = $this->tmpDir.'/'.str_repeat('a', 300).'.json';

        try {
            AtomicFileWriter::write($path, 'content');
            $this->fail('Expected AtomicFileWriterException on write failure');
        } catch (AtomicFileWriterException $exception) {
            $this->assertSame('write', $exception->stage);
        }

        $this->assertFileDoesNotExist($path, 'Destination must not be published');
        $this->assertSame([], glob($this->tmpDir.'/*.tmp.*') ?: [], 'No temp file may remain after write failure');
    }
}
