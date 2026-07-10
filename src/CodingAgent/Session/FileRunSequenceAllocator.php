<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\RunSequenceAllocatorInterface;

/**
 * Per-run monotonic sequence allocator backed by a single integer file.
 *
 * The counter file (typically sequence.cursor next to events.jsonl) is
 * updated before callers append JSONL. A crash after the counter write but
 * before append leaves a gap in seq — replay allows gaps.
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

        $currentHigh = $this->readHighWater($counterPath, $bootstrapMaxSeq);
        $start = $currentHigh + 1;
        $end = $currentHigh + $count;
        $this->writeHighWater($counterPath, $end);

        return range($start, $end);
    }

    public static function counterPathForEventsLog(string $eventsJsonlPath): string
    {
        return \dirname($eventsJsonlPath).'/'.self::COUNTER_BASENAME;
    }

    private function readHighWater(string $counterPath, ?callable $bootstrapMaxSeq): int
    {
        if (is_file($counterPath) && is_readable($counterPath)) {
            $raw = file_get_contents($counterPath);
            if (false === $raw) {
                throw new \RuntimeException(\sprintf('Cannot read sequence counter at "%s".', $counterPath));
            }

            $trimmed = trim($raw);
            if ('' === $trimmed) {
                throw new \RuntimeException(\sprintf('Sequence counter at "%s" is empty.', $counterPath));
            }

            if (!preg_match('/^-?\d+$/', $trimmed)) {
                throw new \RuntimeException(\sprintf('Sequence counter at "%s" is corrupt (expected integer, got %s).', $counterPath, mb_substr($trimmed, 0, 32)));
            }

            $value = (int) $trimmed;
            if ($value < 0) {
                throw new \RuntimeException(\sprintf('Sequence counter at "%s" is negative (%d).', $counterPath, $value));
            }

            return $value;
        }

        $bootstrapMax = 0;
        if (null !== $bootstrapMaxSeq) {
            $bootstrapMax = max(0, (int) $bootstrapMaxSeq());
        }

        return $bootstrapMax;
    }

    private function writeHighWater(string $counterPath, int $highWater): void
    {
        $dir = \dirname($counterPath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory for sequence counter "%s".', $counterPath));
        }

        $payload = (string) $highWater."\n";
        $written = file_put_contents($counterPath, $payload, \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Cannot write sequence counter at "%s".', $counterPath));
        }
    }
}
