<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Session\JsonlPhysicalReadObservation;
use Ineersa\CodingAgent\Session\JsonlRunEventLog;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
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

    private function log(): JsonlRunEventLog
    {
        return new JsonlRunEventLog(
            new EventPayloadNormalizer(),
            new LockFactory(new InMemoryStore()),
            $this->createStub(RunSequenceAllocatorInterface::class),
            new EventLogMaxSeqBootstrapReader(),
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
    public function url_stat(string $path, int $flags): array
    {
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

        if ('short4of8' === $this->mode) {
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
