<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * One-time bootstrap helper: derive max committed seq from an on-disk JSONL log.
 *
 * Used only when {@see FileRunSequenceAllocator} creates the first counter file for a run.
 * Normal allocation never reads events.jsonl. Malformed lines are skipped (bootstrap-only).
 */
final class EventLogMaxSeqBootstrapReader
{
    /** Match top-level JSON "seq": <int> without full json_decode per line. */
    private const SEQ_FIELD_PATTERN = '/"seq"\s*:\s*(-?\d+)/';

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

                if (!preg_match(self::SEQ_FIELD_PATTERN, $trimmed, $matches)) {
                    continue;
                }

                $seq = (int) $matches[1];
                if ($seq > $maxSeq) {
                    $maxSeq = $seq;
                }
            }

            return $maxSeq;
        } finally {
            fclose($handle);
        }
    }
}
