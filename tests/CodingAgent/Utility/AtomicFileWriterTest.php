<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Utility;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Ineersa\CodingAgent\Utility\AtomicFileWriterException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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

    public function testUnlockedReadersSeeOnlyOldOrCompleteContentDuringRepeatedWrites(): void
    {
        $dest = $this->tmpDir.'/big.json';
        $old = "old-complete\n".str_repeat('o', 512);
        $new = str_repeat('x', 4 * 1024 * 1024);
        file_put_contents($dest, $old);

        // 4 MiB payloads keep the write window wide; a temp+rename writer
        // must never expose a partial payload to the subprocess reader.
        $readerScript = <<<'PHP'
$path = $argv[1];
$oldHash = $argv[2];
$newHash = $argv[3];
fwrite(STDOUT, "READY\n");
$reads = 0;
$deadline = microtime(true) + 2.0;
while (microtime(true) < $deadline) {
    $content = @file_get_contents($path);
    if (false === $content) {
        continue;
    }
    $hash = hash('sha256', $content);
    if ($hash !== $oldHash && $hash !== $newHash) {
        fwrite(STDOUT, 'PARTIAL len='.\strlen($content));
        exit(1);
    }
    ++$reads;
}
fwrite(STDOUT, 'OK reads='.$reads);
exit(0);
PHP;

        $process = new Process([\PHP_BINARY, '-r', $readerScript, $dest, hash('sha256', $old), hash('sha256', $new)], timeout: 5.0);
        $process->start();

        // Wait for the reader to signal readiness before replacing content.
        $ready = $process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "READY\n"));
        $this->assertTrue($ready, 'Reader must signal READY before any writes');

        for ($i = 0; $i < 25; ++$i) {
            AtomicFileWriter::write($dest, $new);
        }

        $process->wait();
        $stdout = $process->getOutput();

        $this->assertSame(0, $process->getExitCode(), \sprintf('Reader observed partial content: %s %s', $stdout, $process->getErrorOutput()));
        $this->assertMatchesRegularExpression('/OK reads=[1-9][0-9]*$/', trim($stdout), 'Reader must observe at least one complete payload');
    }
}
