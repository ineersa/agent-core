<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * One-time bootstrap helper: derive max committed seq from an on-disk JSONL log.
 *
 * Used only when {@see FileRunSequenceAllocator} creates the first counter file for a
 * run. Normal allocation never reads events.jsonl.
 */
final class EventLogMaxSeqBootstrapReader
{
    public function readMaxSeq(string $path): int
    {
        if (!is_readable($path) || !is_file($path)) {
            return 0;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return 0;
        }

        try {
            $maxSeq = 0;

            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ('' === $trimmed) {
                    continue;
                }

                try {
                    $payload = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }

                if (!\is_array($payload)) {
                    continue;
                }

                $seq = $payload['seq'] ?? null;
                if (\is_int($seq) && $seq > $maxSeq) {
                    $maxSeq = $seq;
                }
            }

            return $maxSeq;
        } finally {
            fclose($handle);
        }
    }
}
