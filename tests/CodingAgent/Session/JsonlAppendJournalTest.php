<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Session\JsonlAppendJournal;
use Ineersa\CodingAgent\Tests\Session\Support\JournalWorkDecodeCounter;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class JsonlAppendJournalTest extends TestCase
{
    public function testAppendRejectsEmptyWorkDescriptor(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('append-empty-work');
        try {
            $path = $dir.'/events.jsonl';
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Canonical append requires captured owner coordination work.');
            (new JsonlAppendJournal())->append($path, ["x\n"], []);
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    #[DataProvider('matchingPrefixes')]
    public function testSeparateProcessCompletesExactPreparedBytesWithoutFinalizingCoordination(int $prefix): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('append-journal');
        try {
            $path = $dir.'/events.jsonl';
            $predecessor = "{\"seq\":1}\n";
            $records = [
                '{"seq":4,"payload":"'.str_repeat('x', 150000)."\"}\n",
                "{\"seq\":8}\n",
            ];
            $suffix = implode('', $records);
            file_put_contents($path, $predecessor);
            $journal = new JsonlAppendJournal();
            $journal->append($path, $records, ['effects' => [], 'actions' => []]);
            $verified = $journal->verifiedPending($path);
            $this->assertNotNull($verified);
            $this->assertSame([4, 8], $verified->eventSequences);
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
            $this->assertSame(['effects' => [], 'actions' => [], 'event_sequences' => [4, 8]], (new JsonlAppendJournal())->verifiedPending($path)->work);
            $this->assertSame(\strlen($predecessor), $journal->readableOffset($path, filesize($path)), 'Physical completion does not publish an unfinished owner transition.');
            $journal->finalizeVerified($path, $verified->identity);
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

    public function testVerifiedByteConsumersDoNotDeserializeCapturedWorkAgain(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('append-work-decodes');
        JournalWorkDecodeCounter::$decodes = 0;
        try {
            $path = $dir.'/events.jsonl';
            $records = ["{\"seq\":1}\n"];
            $journal = new JsonlAppendJournal();
            $journal->append($path, $records, ['actions' => [], 'probe' => new JournalWorkDecodeCounter()]);
            $verified = $journal->verifiedPending($path);
            $this->assertNotNull($verified);
            $this->assertSame(1, JournalWorkDecodeCounter::$decodes);
            $this->assertSame($records, $journal->verifiedStagedRecords($path, $verified->identity));
            $journal->finalizeVerified($path, $verified->identity);
            $this->assertSame(1, JournalWorkDecodeCounter::$decodes);
            $this->assertFileDoesNotExist($path.'.append.pending.json');
            $this->assertFileDoesNotExist($path.'.append.work');
            $this->assertFileDoesNotExist($path.'.append.staged');
        } finally {
            JournalWorkDecodeCounter::$decodes = 0;
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    #[DataProvider('changedVerifiedEvidence')]
    public function testVerifiedConsumersRecheckChangedEvidenceAndRetainPendingCut(string $operation, string $change): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('append-verified-conflict');
        try {
            $path = $dir.'/events.jsonl';
            file_put_contents($path, "prefix\n");
            $journal = new JsonlAppendJournal();
            $journal->append($path, ["{\"seq\":2}\n"], ['actions' => []]);
            $verified = $journal->verifiedPending($path);
            $this->assertNotNull($verified);
            $identity = $verified->identity;
            switch ($change) {
                case 'wrong identity':
                    $identity = str_repeat('0', 64);
                    break;
                case 'work':
                    file_put_contents($path.'.append.work', 'corrupt work');
                    break;
                case 'missing work':
                    unlink($path.'.append.work');
                    break;
                case 'stage':
                    file_put_contents($path.'.append.staged', "{\"seq\":3}\n");
                    break;
                case 'suffix':
                    file_put_contents($path, "prefix\n{\"seq\":3}\n");
                    break;
                case 'extra tail':
                    file_put_contents($path, 'extra', \FILE_APPEND);
                    break;
                case 'predecessor':
                    file_put_contents($path, "PREFIX\n{\"seq\":2}\n");
                    break;
                case 'archive identity':
                    rename($path, $path.'.old');
                    file_put_contents($path, "prefix\n{\"seq\":2}\n");
                    break;
                case 'manifest identity':
                    $manifest = json_decode(file_get_contents($path.'.append.pending.json'), true, flags: \JSON_THROW_ON_ERROR);
                    $manifest['work_hash'] = hash('sha256', 'changed work');
                    file_put_contents($path.'.append.pending.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
                    break;
            }
            clearstatcache();
            $physical = file_get_contents($path);
            try {
                if ('batch' === $operation) {
                    $journal->verifiedStagedRecords($path, $identity);
                } else {
                    $journal->finalizeVerified($path, $identity);
                }
                $this->fail('Changed evidence must not be consumed or finalized.');
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

    public static function changedVerifiedEvidence(): iterable
    {
        foreach (['batch', 'finalize'] as $operation) {
            foreach (['wrong identity', 'work', 'missing work', 'stage', 'suffix', 'extra tail', 'predecessor', 'archive identity', 'manifest identity'] as $change) {
                yield $operation.' '.$change => [$operation, $change];
            }
        }
    }

    #[DataProvider('inconsistentTails')]
    public function testInconsistentTailFailsClosedAndRetainsEvidence(string $change): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('append-conflict');
        try {
            $path = $dir.'/events.jsonl';
            file_put_contents($path, "prefix\n");
            $journal = new JsonlAppendJournal();
            $journal->append($path, ["{\"seq\":2}\n"], ['actions' => [], 'predecessor_seq' => 0]);
            match ($change) {
                'different suffix' => file_put_contents($path, "prefix\n{\"seq\":9}\n"),
                'extra tail' => file_put_contents($path, "extra\n", \FILE_APPEND),
                'changed predecessor' => file_put_contents($path, "CHANGED{\"seq\":2}\n"),
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
            $journal->append($path, ["{\"seq\":1}\n"], ['actions' => [], 'predecessor_seq' => 0]);
            $intent = file_get_contents($path.'.append.pending.json');
            try {
                $journal->append($path, ["{\"seq\":2}\n"], ['actions' => [], 'predecessor_seq' => 0]);
                $this->fail('Pending append must block another append.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('existing pending append', $exception->getMessage());
            }
            $this->assertSame($intent, file_get_contents($path.'.append.pending.json'));
            $this->assertSame("{\"seq\":1}\n", file_get_contents($path.'.append.staged'));
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
            $journal->append($path, ["{\"seq\":1}\n"], ['actions' => [], 'predecessor_seq' => 0]);
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
