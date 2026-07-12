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
    /**
     * Canonical envelope order is schema_version, run_id, seq (see {@see EventPayloadNormalizer::normalize()}).
     * Require run_id immediately before seq so nested payload "seq" keys cannot win.
     */
    private const TOP_LEVEL_SEQ_PATTERN = '/"run_id"\s*:\s*"[^"]*"\s*,\s*"seq"\s*:\s*(\d+)/';

    public function readMaxSeq(string $path): int
    {
        if (!is_readable($path) || !is_file($path)) {
            return 0;
        }

        $contents = file_get_contents($path);
        if (false === $contents || '' === $contents) {
            return 0;
        }

        if (!preg_match_all(self::TOP_LEVEL_SEQ_PATTERN, $contents, $matches)) {
            return 0;
        }

        $maxSeq = 0;
        foreach ($matches[1] as $rawSeq) {
            $seq = (int) $rawSeq;
            if ($seq < 1) {
                continue;
            }

            if ($seq > $maxSeq) {
                $maxSeq = $seq;
            }
        }

        return $maxSeq;
    }
}
