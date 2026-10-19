<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Session\JsonlAppendJournal;
use Ineersa\CodingAgent\Session\JsonlPhysicalReadObservation;
use Ineersa\CodingAgent\Session\JsonlRunEventLog;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Proves reverseLines distinguishes handle-stat / seek / read failures from a valid empty file.
 *
 * Stream-wrapper isolation keeps pathname filesize() succeeding while handle fstat() fails,
 * so a path-based size probe cannot pass these cases by accident. Short fread results
 * (including empty) are also treated as incomplete scans rather than successful EOF.
 */
#[CoversClass(JsonlRunEventLog::class)]
#[CoversClass(JsonlPhysicalReadObservation::class)]
final class JsonlRunEventLogReverseLinesFailureTest extends TestCase
{
    private const WRAPPER = 'hatfield-jsonl-reverse-fail';

    protected function setUp(): void
    {
        if (\in_array(self::WRAPPER, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::WRAPPER);
        }

        $this->assertTrue(stream_wrapper_register(self::WRAPPER, JsonlReverseLinesFailureStreamWrapper::class));
        JsonlReverseLinesFailureStreamWrapper::reset();
    }

    protected function tearDown(): void
    {
        if (\in_array(self::WRAPPER, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::WRAPPER);
        }
        JsonlReverseLinesFailureStreamWrapper::reset();
    }

    public function testValidEmptyHandleIsSuccessfulEofWithNoLines(): void
    {
        $observation = new JsonlPhysicalReadObservation();
        $lines = iterator_to_array($this->log()->reverseLines($this->path('empty'), $observation), false);

        $this->assertSame([], $lines);
        $this->assertTrue($observation->reachedEof());
        $this->assertFalse($observation->earlyExit());
        $this->assertTrue($observation->fullScan());
        $this->assertSame(0, $observation->archiveBytesRead());
        $this->assertSame(0, $observation->linesYielded());
        $this->assertGreaterThan(0, JsonlReverseLinesFailureStreamWrapper::$streamStatCalls);
    }

    public function testFailedHandleStatIsNotSuccessfulEofEvenWhenPathnameFilesizeSucceeds(): void
    {
        $path = $this->path('failstat');
        $this->assertNotFalse(filesize($path));
        $this->assertGreaterThan(0, filesize($path));

        $observation = new JsonlPhysicalReadObservation();
        $lines = iterator_to_array($this->log()->reverseLines($path, $observation), false);

        $this->assertSame([], $lines);
        $this->assertFalse($observation->reachedEof());
        $this->assertFalse($observation->earlyExit());
        $this->assertFalse($observation->fullScan());
        $this->assertSame(0, $observation->archiveBytesRead());
        $this->assertSame(0, $observation->linesYielded());
        $this->assertGreaterThan(0, JsonlReverseLinesFailureStreamWrapper::$streamStatCalls);
        $this->assertSame(0, JsonlReverseLinesFailureStreamWrapper::$streamSeekCalls);
        $this->assertSame(0, JsonlReverseLinesFailureStreamWrapper::$streamReadCalls);
    }

    public function testFailedSeekIsNotSuccessfulEofOrPartialYield(): void
    {
        $observation = new JsonlPhysicalReadObservation();
        $lines = iterator_to_array($this->log()->reverseLines($this->path('failseek'), $observation), false);

        $this->assertSame([], $lines);
        $this->assertFalse($observation->reachedEof());
        $this->assertFalse($observation->earlyExit());
        $this->assertFalse($observation->fullScan());
        $this->assertSame(0, $observation->archiveBytesRead());
        $this->assertSame(0, $observation->linesYielded());
        $this->assertGreaterThan(0, JsonlReverseLinesFailureStreamWrapper::$streamSeekCalls);
        $this->assertSame(0, JsonlReverseLinesFailureStreamWrapper::$streamReadCalls);
    }

    public function testFailedReadAfterSuccessfulSeekIsNotSuccessfulEofOrPartialYield(): void
    {
        $observation = new JsonlPhysicalReadObservation();
        $lines = iterator_to_array($this->log()->reverseLines($this->path('failread'), $observation), false);

        $this->assertSame([], $lines);
        $this->assertFalse($observation->reachedEof());
        $this->assertFalse($observation->earlyExit());
        $this->assertFalse($observation->fullScan());
        $this->assertSame(0, $observation->archiveBytesRead());
        $this->assertSame(0, $observation->linesYielded());
        $this->assertGreaterThan(0, JsonlReverseLinesFailureStreamWrapper::$streamSeekCalls);
        $this->assertGreaterThan(0, JsonlReverseLinesFailureStreamWrapper::$streamReadCalls);
    }

    public function testShortReadFourOfEightIsNotSuccessfulEofOrPartialYield(): void
    {
        $observation = new JsonlPhysicalReadObservation();
        $lines = iterator_to_array($this->log()->reverseLines($this->path('short4of8'), $observation), false);

        $this->assertSame([], $lines);
        $this->assertFalse($observation->reachedEof());
        $this->assertFalse($observation->earlyExit());
        $this->assertFalse($observation->fullScan());
        $this->assertSame(4, $observation->archiveBytesRead());
        $this->assertSame(0, $observation->linesYielded());
        $this->assertGreaterThan(0, JsonlReverseLinesFailureStreamWrapper::$streamReadCalls);
    }

    public function testZeroLengthReadIsNotSuccessfulEofOrPartialYield(): void
    {
        $observation = new JsonlPhysicalReadObservation();
        $lines = iterator_to_array($this->log()->reverseLines($this->path('zeroread'), $observation), false);

        $this->assertSame([], $lines);
        $this->assertFalse($observation->reachedEof());
        $this->assertFalse($observation->earlyExit());
        $this->assertFalse($observation->fullScan());
        $this->assertSame(0, $observation->archiveBytesRead());
        $this->assertSame(0, $observation->linesYielded());
        $this->assertGreaterThan(0, JsonlReverseLinesFailureStreamWrapper::$streamReadCalls);
    }

    public function testRealFileTruncationAfterFirstYieldKeepsCompleteLinesAndRejectsIncompletePrefix(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('jsonl-reverse-short-read');
        $path = $dir.'/events.jsonl';

        try {
            $tailLine = '{"seq":2,"marker":"tail-complete"}';
            $prefix = str_repeat('x', 21000 - \strlen($tailLine) - 1);
            $this->assertSame(21000, \strlen($prefix."\n".$tailLine));
            file_put_contents($path, $prefix."\n".$tailLine);
            $this->assertSame(21000, filesize($path));

            $observation = new JsonlPhysicalReadObservation();
            $generator = $this->log()->reverseLines($path, $observation);

            $this->assertTrue($generator->valid());
            $this->assertSame($tailLine, $generator->current());

            $truncated = fopen($path, 'r+b');
            $this->assertNotFalse($truncated);
            try {
                // Truncate before the next reverse seek so the following fread returns ''.
                ftruncate($truncated, 0);
                fflush($truncated);
            } finally {
                fclose($truncated);
            }
            $this->assertSame(0, filesize($path));

            $generator->next();
            $this->assertFalse($generator->valid());

            $this->assertFalse($observation->reachedEof());
            $this->assertFalse($observation->earlyExit());
            $this->assertFalse($observation->fullScan());
            $this->assertSame(8192, $observation->archiveBytesRead());
            $this->assertSame(1, $observation->linesYielded());
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    #[DataProvider('largeRecordBoundaries')]
    public function testLargeRecordsPreserveByteOrderAndPhysicalReadCounts(int $payloadBytes, string $ending): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('jsonl-reverse-fragments');
        try {
            $path = $dir.'/events.jsonl';
            $first = '{"seq":1}';
            $payload = str_repeat('漢🙂', intdiv($payloadBytes, 7)).str_repeat('x', $payloadBytes % 7);
            $large = '{"seq":2,"payload":"'.$payload.'"}';
            $last = 'partial' === $ending ? '{"seq":3,"text":"unfinished🙂' : '{"seq":3}';
            $contents = $first."\r\n".$large."\r\r\n\n \t\r\n".$last.('newline' === $ending ? "\n" : '');
            file_put_contents($path, $contents);

            $observation = new JsonlPhysicalReadObservation();
            $lines = iterator_to_array($this->log()->reverseLines($path, $observation), false);

            // The first physical line historically keeps its CR; later records
            // trim CR terminators, including a partial final record.
            $this->assertSame([$last, $large, $first."\r"], $lines);
            $this->assertSame(\strlen($contents), $observation->archiveBytesRead());
            $this->assertSame(3, $observation->linesYielded());
            $this->assertTrue($observation->fullScan());
            $this->assertFalse($observation->earlyExit());
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public static function largeRecordBoundaries(): iterable
    {
        foreach ([8191, 8192, 8193, 2097152] as $bytes) {
            foreach (['newline', 'no newline', 'partial'] as $ending) {
                yield $bytes.' '.$ending => [$bytes, $ending];
            }
        }
    }

    public function testLargeFirstRecordWithoutAPrecedingDelimiterKeepsItsOriginalBytes(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('jsonl-reverse-first');
        try {
            $path = $dir.'/events.jsonl';
            $record = '{"seq":1,"payload":"'.str_repeat('漢🙂', 300000).'"}'."\r";
            foreach (['', "\n"] as $ending) {
                file_put_contents($path, $record.$ending);
                $observation = new JsonlPhysicalReadObservation();
                $this->assertSame([$record], iterator_to_array($this->log()->reverseLines($path, $observation), false));
                $this->assertSame(\strlen($record.$ending), $observation->archiveBytesRead());
                $this->assertSame(1, $observation->linesYielded());
                $this->assertTrue($observation->fullScan());
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testEarlyExitReadsOnlyOneTailChunkWithoutMaterializingOlderLargeRecord(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('jsonl-reverse-early');
        try {
            $path = $dir.'/events.jsonl';
            $last = '{"seq":2}';
            file_put_contents($path, str_repeat('x', 2097152)."\n".$last."\n");
            $observation = new JsonlPhysicalReadObservation();
            $reader = $this->log()->reverseLines($path, $observation);
            $this->assertSame($last, $reader->current());
            unset($reader);
            $this->assertSame(8192, $observation->archiveBytesRead());
            $this->assertSame(1, $observation->linesYielded());
            $this->assertTrue($observation->earlyExit());
            $this->assertFalse($observation->fullScan());
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testLargePendingRecordStaysBehindCommittedCutUntilVerifiedFinalization(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('jsonl-reverse-pending');
        try {
            $path = $dir.'/events.jsonl';
            $first = '{"seq":1}';
            file_put_contents($path, $first."\n");
            $pending = '{"seq":2,"payload":"'.str_repeat('x', 2097152).'"}';
            $journal = new JsonlAppendJournal();
            $journal->append($path, [$pending."\n"], ['actions' => [], 'predecessor_seq' => 1]);
            $observation = new JsonlPhysicalReadObservation();
            $this->assertSame([$first], iterator_to_array($this->log()->reverseLines($path, $observation), false));
            $this->assertSame(\strlen($first) + 1, $observation->archiveBytesRead());
            $this->assertTrue($observation->fullScan());
            $verified = $journal->verifiedPending($path);
            $this->assertNotNull($verified);
            $journal->finalizeVerified($path, $verified->identity);
            $this->assertSame([$pending, $first], iterator_to_array($this->log()->reverseLines($path), false));
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testLateShortReadDiscardsAccumulatedFragmentsButRetainsCompleteTail(): void
    {
        $observation = new JsonlPhysicalReadObservation();
        $lines = iterator_to_array($this->log()->reverseLines($this->path('late-short'), $observation), false);
        $this->assertSame(['{"seq":2}'], $lines);
        $this->assertSame(2 * 8192 + 4, $observation->archiveBytesRead());
        $this->assertSame(1, $observation->linesYielded());
        $this->assertFalse($observation->fullScan());
        $this->assertFalse($observation->earlyExit());
    }

    private function log(): JsonlRunEventLog
    {
        return new JsonlRunEventLog(
            new EventPayloadNormalizer(),
            new LockFactory(new InMemoryStore()),
            $this->createStub(RunSequenceAllocatorInterface::class),
            new EventLogMaxSeqBootstrapReader(),
            new \Psr\Log\NullLogger(),
        );
    }

    private function path(string $mode): string
    {
        return self::WRAPPER.'://'.$mode;
    }
}

/**
 * Pathname filesize()/url_stat can succeed while handle fstat/seek/read fail.
 *
 * @internal
 */
final class JsonlReverseLinesFailureStreamWrapper
{
    public mixed $context;

    public static int $streamStatCalls = 0;

    public static int $streamSeekCalls = 0;

    public static int $streamReadCalls = 0;

    private string $mode = 'empty';

    private string $data = '';

    private int $position = 0;

    public static function reset(): void
    {
        self::$streamStatCalls = 0;
        self::$streamSeekCalls = 0;
        self::$streamReadCalls = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $host = parse_url($path, \PHP_URL_HOST);
        $this->mode = \is_string($host) && '' !== $host ? $host : 'empty';
        $this->data = $this->payloadFor($this->mode);
        $this->position = 0;

        return true;
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (str_contains($path, '.append.')) {
            return false;
        }
        $host = parse_url($path, \PHP_URL_HOST);
        $mode = \is_string($host) && '' !== $host ? $host : 'empty';

        // Pathname filesize() succeeds even for failstat so the test proves handle fstat is required.
        return $this->statArray(\strlen($this->payloadFor($mode)));
    }

    /**
     * @return array<string, int>|false
     */
    public function stream_stat(): array|false
    {
        ++self::$streamStatCalls;
        if ('failstat' === $this->mode) {
            return false;
        }

        return $this->statArray(\strlen($this->data));
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        ++self::$streamSeekCalls;
        if ('failseek' === $this->mode) {
            return false;
        }

        $length = \strlen($this->data);
        $this->position = match ($whence) {
            \SEEK_SET => $offset,
            \SEEK_CUR => $this->position + $offset,
            \SEEK_END => $length + $offset,
            default => $this->position,
        };

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_read(int $count): string|false
    {
        ++self::$streamReadCalls;
        if ('failread' === $this->mode) {
            return false;
        }

        if ('zeroread' === $this->mode) {
            return '';
        }

        if ('short4of8' === $this->mode || ('late-short' === $this->mode && self::$streamReadCalls >= 3)) {
            $chunk = substr($this->data, $this->position, min(4, $count));
            $this->position += \strlen($chunk);

            return $chunk;
        }

        $chunk = substr($this->data, $this->position, $count);
        $this->position += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= \strlen($this->data);
    }

    public function stream_close(): void
    {
    }

    private function payloadFor(string $mode): string
    {
        return match ($mode) {
            'empty' => '',
            'failstat', 'failseek', 'failread' => "{\"seq\":1}\n{\"seq\":2}\n",
            'short4of8', 'zeroread' => str_repeat('a', 8),
            'late-short' => str_repeat('x', 65536)."\n{\"seq\":2}\n",
            default => '',
        };
    }

    /**
     * @return array<string, int>
     */
    private function statArray(int $size): array
    {
        return [
            'dev' => 1,
            'ino' => 1,
            'mode' => 0100644,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => $size,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => -1,
            'blocks' => -1,
        ];
    }
}
