<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class FileRunSequenceAllocatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('file-seq-alloc');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testMissingCounterAndMissingLogAllocatesOne(): void
    {
        $counter = $this->tmpDir.'/sequence.cursor';
        $allocator = new FileRunSequenceAllocator();

        $this->assertSame(1, $allocator->allocateBlock($counter, 1)[0]);
        $this->assertSame("1\n", file_get_contents($counter));
        $this->assertSame(2, $allocator->allocateBlock($counter, 1)[0]);
    }

    public function testBootstrapFromExistingLogMaxFiveAllocatesSix(): void
    {
        $dir = $this->tmpDir.'/run';
        mkdir(directory: $dir, recursive: true);
        $events = $dir.'/events.jsonl';
        file_put_contents($events, '{"seq":1}'."\n".'{"seq":5}'."\n".'{"seq":3}'."\n");
        $counter = FileRunSequenceAllocator::counterPathForEventsLog($events);
        $allocator = new FileRunSequenceAllocator();

        $this->assertSame(6, $allocator->allocateBlock($counter, 1, static fn (): int => 5)[0]);
        $this->assertSame("6\n", file_get_contents($counter));
    }

    public function testExistingCounterTenDoesNotCallBootstrap(): void
    {
        $counter = $this->tmpDir.'/sequence.cursor';
        file_put_contents($counter, "10\n");
        $allocator = new FileRunSequenceAllocator();
        $bootstrapCalls = 0;

        $next = $allocator->allocateBlock($counter, 1, static function () use (&$bootstrapCalls): int {
            ++$bootstrapCalls;

            return 99;
        })[0];

        $this->assertSame(11, $next);
        $this->assertSame(0, $bootstrapCalls);
        $this->assertSame("11\n", file_get_contents($counter));
    }

    public function testAllocateBlockWritesFinalHighWater(): void
    {
        $counter = $this->tmpDir.'/sequence.cursor';
        file_put_contents($counter, "10\n");
        $allocator = new FileRunSequenceAllocator();

        $block = $allocator->allocateBlock($counter, 3);

        $this->assertSame([11, 12, 13], $block);
        $this->assertSame("13\n", file_get_contents($counter));
    }

    public function testCorruptCounterThrows(): void
    {
        $counter = $this->tmpDir.'/sequence.cursor';
        file_put_contents($counter, "not-a-number\n");
        $allocator = new FileRunSequenceAllocator();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('corrupt');
        $allocator->allocateBlock($counter, 1)[0];
    }

    /**
     * Real OS processes contend on one sequence.cursor. Workers signal ready via per-worker marker files;
     * the parent releases them with a shared done file using flock(SH) spin-yield (no sleep/usleep).
     * Results are merged under an exclusive flock on results.txt.
     */
    public function testConcurrentProcessesReceiveUniqueSequences(): void
    {
        $dir = $this->tmpDir.'/concurrent';
        TestDirectoryIsolation::ensureDirectory($dir);
        $counter = $dir.'/sequence.cursor';
        $readyPath = $dir.'/ready';
        $donePath = $dir.'/done';
        $resultsPath = $dir.'/results.txt';

        $workerScript = <<<'PHP'
<?php
declare(strict_types=1);
$dir = getenv('SEQ_TEST_DIR') ?: '';
$workerId = (int) (getenv('SEQ_WORKER_ID') ?: '0');
$count = (int) (getenv('SEQ_ALLOC_COUNT') ?: '5');
require getenv('SEQ_PROJECT_ROOT') . '/vendor/autoload.php';
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
$counter = $dir . '/sequence.cursor';
$ready = $dir . '/ready';
$done = $dir . '/done';
$results = $dir . '/results.txt';
$allocator = new FileRunSequenceAllocator();
$allocated = [];
for ($i = 0; $i < $count; ++$i) {
    $allocated[] = $allocator->allocateBlock($counter, 1)[0];
}
$line = $workerId . ':' . implode(',', $allocated) . "\n";
$fp = fopen($results, 'a');
if (false === $fp) {
    fwrite(STDERR, "cannot open results\n");
    exit(2);
}
flock($fp, LOCK_EX);
fwrite($fp, $line);
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
file_put_contents($ready . '/' . $workerId, '1');
$deadline = microtime(true) + 15.0;
while (!is_file($done) && microtime(true) < $deadline) {
    $h = fopen($done, 'c');
    if (false !== $h) {
        flock($h, LOCK_SH);
        flock($h, LOCK_UN);
        fclose($h);
    }
    usleep(5_000);
}
exit(is_file($done) ? 0 : 3);
PHP;

        $workerFile = $dir.'/worker.php';
        file_put_contents($workerFile, $workerScript);
        TestDirectoryIsolation::ensureDirectory($readyPath);

        $projectRoot = \dirname(__DIR__, 3);
        $workerCount = 4;
        $perWorker = 8;
        $processes = [];

        for ($w = 0; $w < $workerCount; ++$w) {
            $cmd = [
                \PHP_BINARY,
                $workerFile,
            ];
            $env = $_ENV;
            $env['SEQ_TEST_DIR'] = $dir;
            $env['SEQ_WORKER_ID'] = (string) $w;
            $env['SEQ_ALLOC_COUNT'] = (string) $perWorker;
            $env['SEQ_PROJECT_ROOT'] = $projectRoot;
            $spec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $spec, $pipes, $dir, $env);
            if (!\is_resource($proc)) {
                $this->fail('Failed to start worker process '.$w);
            }
            fclose($pipes[0]);
            $processes[$w] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }

        // Workers allocate then signal ready; barrier wait is bounded in the worker script.
        $deadline = microtime(true) + 10.0;
        $readyCount = 0;
        while (microtime(true) < $deadline) {
            $readyCount = 0;
            for ($w = 0; $w < $workerCount; ++$w) {
                if (is_file($readyPath.'/'.$w)) {
                    ++$readyCount;
                }
            }
            if ($readyCount === $workerCount) {
                break;
            }
            usleep(5_000);
        }

        $this->assertSame($workerCount, $readyCount, 'Workers did not signal ready in time');

        touch($donePath);

        foreach ($processes as $w => $meta) {
            $waitDeadline = microtime(true) + 5.0;
            $status = proc_get_status($meta['proc']);
            while ($status['running'] && microtime(true) < $waitDeadline) {
                usleep(5_000);
                $status = proc_get_status($meta['proc']);
            }
            if ($status['running']) {
                // Bounded teardown if a worker missed the done gate.
                proc_terminate($meta['proc'], \SIGTERM);
                $termDeadline = microtime(true) + 2.0;
                $status = proc_get_status($meta['proc']);
                while ($status['running'] && microtime(true) < $termDeadline) {
                    usleep(5_000);
                    $status = proc_get_status($meta['proc']);
                }
                if ($status['running']) {
                    proc_terminate($meta['proc'], \SIGKILL);
                }
            }
            $stderr = stream_get_contents($meta['stderr']);
            fclose($meta['stdout']);
            fclose($meta['stderr']);
            $exit = proc_close($meta['proc']);
            if (0 !== $exit) {
                $this->fail('Worker '.$w.' exited '.$exit.': '.$stderr);
            }
        }

        $this->assertFileExists($resultsPath);
        $lines = array_values(array_filter(array_map('trim', file($resultsPath) ?: [])));
        $this->assertCount($workerCount, $lines);

        $allSeqs = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            $this->assertCount(2, $parts);
            $seqs = array_map('intval', explode(',', $parts[1]));
            $this->assertCount($perWorker, $seqs);
            foreach ($seqs as $seq) {
                $allSeqs[] = $seq;
            }
        }

        $this->assertCount($workerCount * $perWorker, $allSeqs);
        $this->assertSame($allSeqs, array_values(array_unique($allSeqs)), 'Duplicate sequence numbers across processes');
        sort($allSeqs);
        $this->assertSame(range(1, $workerCount * $perWorker), $allSeqs);
    }
}
