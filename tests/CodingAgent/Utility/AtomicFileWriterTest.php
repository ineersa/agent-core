<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Utility;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Ineersa\CodingAgent\Utility\AtomicFileWriterException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Behavior proof at the shared atomic writer boundary (coding-agent-03).
 *
 * Covers: exact complete content + parent creation + no temp after success,
 * requested pre-publish file/directory modes, fail-closed failure paths with
 * prior destination intact and temp cleanup, and old-or-complete visibility
 * for unlocked readers during repeated large replacements (subprocess reader
 * pattern, bounded like SessionRunStoreTest).
 */
final class AtomicFileWriterTest extends TestCase
{
    private string $tmpDir;
    private AtomicFileWriter $writer;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('atomic-file-writer');
        $this->writer = new AtomicFileWriter(new Filesystem());
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testWritesExactContentCreatesParentAndLeavesNoTemp(): void
    {
        $path = $this->tmpDir.'/nested/dir/file.json';
        $contents = "{\"a\":\"b\"}\n".str_repeat('x', 4096);

        $this->writer->write($path, $contents);

        $this->assertSame($contents, file_get_contents($path));
        $this->assertDirectoryExists($this->tmpDir.'/nested/dir');
        $this->assertSame([$path], glob($this->tmpDir.'/nested/dir/*') ?: [], 'No temp files should remain after success');
    }

    public function testAppliesRequestedFileAndDirectoryModes(): void
    {
        $path = $this->tmpDir.'/secret-dir/credentials.json';

        $this->writer->write($path, '{"token":"x"}', fileMode: 0600, directoryMode: 0700);

        $this->assertSame(0600, fileperms($path) & 0777, 'File must be published with the requested 0600 mode');
        $this->assertSame(0700, fileperms($this->tmpDir.'/secret-dir') & 0777, 'Directory must be created with the requested 0700 mode');
    }

    public function testRenameFailureKeepsPriorDestinationIntactAndCleansTemp(): void
    {
        // Occupy the destination with a directory so the atomic rename
        // deterministically fails after the temp file was written.
        $dest = $this->tmpDir.'/blocked';
        mkdir($dest, 0755, true);
        file_put_contents($dest.'/keep.txt', 'keep');

        try {
            $this->writer->write($dest, 'new content');
            $this->fail('Expected AtomicFileWriterException on rename failure');
        } catch (AtomicFileWriterException $exception) {
            $this->assertSame('rename', $exception->stage);
        }

        $this->assertFileExists($dest.'/keep.txt', 'Prior destination must remain intact');
        $this->assertSame([], glob($this->tmpDir.'/*.tmp.*') ?: [], 'Temp file must be cleaned on rename failure');
    }

    public function testMkdirFailureFailsClosedWithoutTemp(): void
    {
        // Occupy the parent path with a file so directory creation fails.
        $blocker = $this->tmpDir.'/blocker';
        file_put_contents($blocker, 'i am a file');

        try {
            $this->writer->write($blocker.'/file.json', 'content');
            $this->fail('Expected AtomicFileWriterException on mkdir failure');
        } catch (AtomicFileWriterException $exception) {
            $this->assertSame('mkdir', $exception->stage);
        }

        $this->assertSame('i am a file', file_get_contents($blocker), 'Blocker file must remain untouched');
        $this->assertSame([], glob($this->tmpDir.'/*.tmp.*') ?: [], 'No temp file may exist without a created directory');
    }

    /**
     * Unlocked readers must observe only the old or the complete new content
     * during repeated large replacements — never a partial in-place write.
     *
     * Bounded: 25 writes and a reader deadline of 2.5s (same pattern as
     * SessionRunStoreTest::testUnlockedReadersNeverObservePartialState...).
     */
    public function testUnlockedReadersSeeOnlyOldOrCompleteContentDuringRepeatedWrites(): void
    {
        $dest = $this->tmpDir.'/big.json';
        file_put_contents($dest, '{"version":0}');

        // 4 MiB payload keeps the truncate-then-write window wide enough that a
        // tight reader loop reliably catches a partial read on a non-atomic
        // writer; temp-file + rename must never show one.
        $contents = json_encode(['content' => str_repeat('x', 4 * 1024 * 1024)], \JSON_THROW_ON_ERROR);

        $readerScript = <<<'PHP'
$path = $argv[1];
$deadline = microtime(true) + (float) $argv[2];
$reads = 0;
while (microtime(true) < $deadline) {
    $content = @file_get_contents($path);
    if (false === $content || '' === trim($content)) {
        continue; // missing / empty file is a complete "no content yet"
    }
    ++$reads;
    try {
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        fwrite(STDOUT, 'CORRUPT_JSON:' . $e->getMessage() . ':' . substr($content, 0, 200));
        exit(1);
    }
    if (!\is_array($data) || !isset($data['content'])) {
        fwrite(STDOUT, 'INVALID_SHAPE');
        exit(1);
    }
}
fwrite(STDOUT, 'OK reads=' . $reads);
exit(0);
PHP;

        $proc = proc_open(
            [\PHP_BINARY, '-r', $readerScript, $dest, '2.5'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);

        try {
            fclose($pipes[0]);

            for ($i = 1; $i <= 25; ++$i) {
                $this->writer->write($dest, $contents);
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            $proc = null;
        } finally {
            if (\is_resource($proc)) {
                proc_terminate($proc);
                foreach ($pipes as $pipe) {
                    if (\is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($proc);
            }
        }

        $this->assertSame(0, $exit, \sprintf('Unlocked reader observed partial/corrupt content: %s%s', $stdout, $stderr));
        $this->assertMatchesRegularExpression(
            '/^OK reads=[1-9][0-9]*$/',
            trim($stdout),
            \sprintf('Reader must report at least one successful read, got: %s%s', $stdout, $stderr),
        );
    }
}
