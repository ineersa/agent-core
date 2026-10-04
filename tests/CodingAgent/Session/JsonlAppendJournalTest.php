<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\JsonlAppendJournal;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class JsonlAppendJournalTest extends TestCase
{
    #[DataProvider('matchingPrefixes')]
    public function testSeparateProcessCompletesExactPreparedBytesWithoutFinalizingCoordination(int $prefix): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('append-journal');
        try {
            $path = $dir.'/events.jsonl';
            $predecessor = "{\"seq\":1}\n";
            $suffix = '{"seq":4,"payload":"'.str_repeat('x', 150000)."\"}\n{\"seq\":8}\n";
            file_put_contents($path, $predecessor);
            $journal = new JsonlAppendJournal();
            $journal->append($path, [$suffix], ['effects' => [], 'actions' => []]);
            $verified = $journal->verifiedPending($path);
            $this->assertNotNull($verified);
            $this->assertSame(\strlen($predecessor), $verified->startOffset);
            $this->assertSame(\strlen($predecessor.$suffix), $verified->endOffset);
            $manifestBytes = file_get_contents($path.'.append.pending.json');
            $handle = fopen($path, 'r+b');
            $this->assertIsResource($handle);
            try {
                $this->assertTrue(ftruncate($handle, \strlen($predecessor) + min($prefix, \strlen($suffix))));
            } finally {
                fclose($handle);
            }
            $this->assertSame(\strlen($predecessor), $journal->readableOffset($path, filesize($path)));
            $process = new Process([\PHP_BINARY, __DIR__.'/Support/ReconcileJsonlAppend.php', $path], env: ['HATFIELD_SESSION_ID' => false]);
            $process->setTimeout(5);
            $process->mustRun();
            $this->assertSame("reconciled\n", $process->getOutput());
            $this->assertSame($predecessor.$suffix, file_get_contents($path));
            $this->assertSame($manifestBytes, file_get_contents($path.'.append.pending.json'));
            $this->assertSame($verified->identity, (new JsonlAppendJournal())->verifiedPending($path)->identity);
            $this->assertSame(['effects' => [], 'actions' => []], (new JsonlAppendJournal())->verifiedPending($path)->work);
            $this->assertSame(\strlen($predecessor), $journal->readableOffset($path, filesize($path)), 'Physical completion does not publish an unfinished owner transition.');
            $journal->finalize($path);
            $this->assertFileDoesNotExist($path.'.append.pending.json');
            $this->assertSame(\strlen($predecessor.$suffix), $journal->readableOffset($path, filesize($path)));
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public static function matchingPrefixes(): iterable
    {
        yield 'absent' => [0];
        yield 'partial JSON line' => [19];
        yield 'multiple bounded chunks' => [70001];
        yield 'complete' => [\PHP_INT_MAX];
    }

    #[DataProvider('inconsistentTails')]
    public function testInconsistentTailFailsClosedAndRetainsEvidence(string $change): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('append-conflict');
        try {
            $path = $dir.'/events.jsonl';
            file_put_contents($path, "prefix\n");
            $journal = new JsonlAppendJournal();
            $journal->append($path, ["planned\n"], ['actions' => []]);
            match ($change) {
                'different suffix' => file_put_contents($path, "prefix\nWRONG!!\n"),
                'extra tail' => file_put_contents($path, "extra\n", \FILE_APPEND),
                'changed predecessor' => file_put_contents($path, "CHANGEDplanned\n"),
                'truncated predecessor' => file_put_contents($path, 'x'),
                'corrupt stage' => file_put_contents($path.'.append.staged', "tampered\n"),
            };
            $physical = file_get_contents($path);
            try {
                $journal->reconcile($path);
                $this->fail('Inconsistent append must not be reconciled.');
            } catch (\RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
            $this->assertSame($physical, file_get_contents($path));
            $this->assertFileExists($path.'.append.pending.json');
            $this->assertFileExists($path.'.append.staged');
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public static function inconsistentTails(): iterable
    {
        foreach (['different suffix', 'extra tail', 'changed predecessor', 'truncated predecessor', 'corrupt stage'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testPendingIntentCannotBeOverwrittenByAnotherAppend(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('append-pending');
        try {
            $path = $dir.'/events.jsonl';
            $journal = new JsonlAppendJournal();
            $journal->append($path, ["prepared\n"], ['actions' => []]);
            $intent = file_get_contents($path.'.append.pending.json');
            try {
                $journal->append($path, ["different\n"]);
                $this->fail('Pending append must block another append.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('existing pending append', $exception->getMessage());
            }
            $this->assertSame($intent, file_get_contents($path.'.append.pending.json'));
            $this->assertSame("prepared\n", file_get_contents($path.'.append.staged'));
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testReaderRejectsUnexpectedPhysicalTailWithoutPublishingIt(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('append-reader-conflict');
        try {
            $path = $dir.'/events.jsonl';
            $journal = new JsonlAppendJournal();
            $journal->append($path, ["prepared\n"], ['actions' => []]);
            file_put_contents($path, "unexpected\n", \FILE_APPEND);
            clearstatcache(true, $path);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('inconsistent with its pending append');
            $journal->readableOffset($path, filesize($path));
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
