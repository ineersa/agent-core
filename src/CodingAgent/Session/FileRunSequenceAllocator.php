<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;

/**
 * Per-run monotonic sequence allocator backed by a single integer file.
 *
 * The counter file (typically sequence.cursor next to events.jsonl) is
 * updated before callers append JSONL. A crash after the counter write but
 * before append leaves a gap in seq — replay allows gaps.
 *
 * Read/bootstrap/advance/write run under one exclusive flock on the cursor handle.
 * Invariant (one critical section per allocation):
 * open sequence.cursor (c+b) → flock LOCK_EX → read high-water (bootstrap from events.jsonl only when file empty)
 * → reserve seq block → durable fwrite high-water → flock LOCK_UN → fclose.
 * JSONL append is a separate step under the run lock; cursor may advance before append completes, so seq gaps are allowed.
 */
final class FileRunSequenceAllocator implements RunSequenceAllocatorInterface
{
    public const COUNTER_BASENAME = 'sequence.cursor';

    public function allocateNext(string $counterPath, ?callable $bootstrapMaxSeq = null): int
    {
        $block = $this->allocateBlock($counterPath, 1, $bootstrapMaxSeq);

        return $block[0];
    }

    public function allocateBlock(string $counterPath, int $count, ?callable $bootstrapMaxSeq = null): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('allocateBlock count must be >= 1.');
        }

        $handle = $this->openCounterHandle($counterPath);
        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException(\sprintf('Cannot acquire exclusive lock on sequence counter "%s".', $counterPath));
            }

            try {
                $currentHigh = $this->readHighWaterLocked($handle, $bootstrapMaxSeq);
                $start = $currentHigh + 1;
                $end = $currentHigh + $count;
                $this->writeHighWaterLocked($handle, $end);

                return range($start, $end);
            } finally {
                flock($handle, \LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    public static function counterPathForEventsLog(string $eventsJsonlPath): string
    {
        return \dirname($eventsJsonlPath).'/'.self::COUNTER_BASENAME;
    }

    /**
     * @param resource $handle
     */
    private function readHighWaterLocked($handle, ?callable $bootstrapMaxSeq): int
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if (false === $raw) {
            throw new \RuntimeException('Cannot read sequence counter from locked handle.');
        }

        $trimmed = trim($raw);
        if ('' !== $trimmed) {
            if (!preg_match('/^-?\d+$/', $trimmed)) {
                throw new \RuntimeException(\sprintf('Sequence counter is corrupt (expected integer, got %s).', mb_substr($trimmed, 0, 32)));
            }

            $value = (int) $trimmed;
            if ($value < 0) {
                throw new \RuntimeException(\sprintf('Sequence counter is negative (%d).', $value));
            }

            return $value;
        }

        $bootstrapMax = 0;
        if (null !== $bootstrapMaxSeq) {
            $bootstrapMax = max(0, (int) $bootstrapMaxSeq());
        }

        return $bootstrapMax;
    }

    /**
     * @param resource $handle
     */
    private function writeHighWaterLocked($handle, int $highWater): void
    {
        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new \RuntimeException('Cannot truncate sequence counter for write.');
        }

        $payload = (string) $highWater."\n";
        $written = fwrite($handle, $payload);
        if (false === $written || $written !== \strlen($payload)) {
            throw new \RuntimeException('Cannot write sequence counter high-water mark.');
        }

        fflush($handle);
    }

    /** @return resource */
    private function openCounterHandle(string $counterPath): mixed
    {
        $dir = \dirname($counterPath);
        if (!is_dir($dir) && !mkdir(directory: $dir, recursive: true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory for sequence counter "%s".', $counterPath));
        }

        $handle = fopen($counterPath, 'c+b');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot open sequence counter at "%s".', $counterPath));
        }

        return $handle;
    }
}
