<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * Reads the highest committed seq from a JSONL event log under an existing lock.
 *
 * Scans every line and takes the maximum seq value. This is correct even when
 * append order on disk does not match seq order (manual append writers).
 */
final class EventLogMaxSeqReader
{
    public function readMaxSeqLocked(string $path): int
    {
        if (!is_readable($path)) {
            return 0;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return 0;
        }

        try {
            $maxSeq = 0;

            while (false !== ($line = fgets($handle))) {
                $trimmed = trim($line);
                if ('' === $trimmed) {
                    continue;
                }

                try {
                    /** @var array<string, mixed> $payload */
                    $payload = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new EventLogLastSequenceException(\sprintf('Event log "%s" contains a non-JSON line while allocating sequence numbers.', $path), previous: $e);
                }

                $seq = $payload['seq'] ?? null;
                if (!\is_int($seq)) {
                    throw new EventLogLastSequenceException(\sprintf('Event log "%s" contains a line without an integer seq field while allocating sequence numbers.', $path));
                }

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
