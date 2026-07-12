<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * One-time bootstrap helper: derive max committed seq from an on-disk JSONL log.
 *
 * Used only when {@see FileRunSequenceAllocator} creates the first counter file for a run.
 * Normal allocation never reads events.jsonl. Scans via external grep so PHP does not load
 * the full log into memory (Hatfield targets Unix-like environments with GNU/BSD grep).
 *
 * Malformed or non-matching lines are ignored (bootstrap-only). A harmless sequence gap is
 * acceptable if hand-edited JSONL diverges from the canonical envelope order.
 */
final class EventLogMaxSeqBootstrapReader
{
    /**
     * Canonical envelope order is schema_version, run_id, seq (see {@see EventPayloadNormalizer::normalize()}).
     * grep -o emits a compact prefix ending at the top-level seq digits so nested payload "seq" cannot match.
     */
    private const GREP_ERE_PREFIX = '^[{]"schema_version":[^,]*,"run_id":[^,]*,"seq":[0-9]+';

    private const SEQ_SUFFIX_PATTERN = '/,"seq"\s*:\s*(\d+)$/';

    public function readMaxSeq(string $path): int
    {
        if (!is_readable($path) || !is_file($path)) {
            return 0;
        }

        $process = new Process([
            'grep',
            '-E',
            '-o',
            self::GREP_ERE_PREFIX,
            '--',
            $path,
        ]);

        try {
            $process->start();
        } catch (ProcessStartFailedException $e) {
            throw new \RuntimeException(\sprintf('Failed to start grep for event log bootstrap scan (path=%s).', $this->safePathForMessage($path)), 0, $e);
        }

        $maxSeq = 0;
        $carry = '';
        $capturedStderr = '';

        foreach ($process->getIterator() as $type => $buffer) {
            if ('' === $buffer) {
                continue;
            }

            if (Process::ERR === $type) {
                $this->appendBoundedDiagnostic($capturedStderr, $buffer);

                continue;
            }

            if (Process::OUT !== $type) {
                continue;
            }

            $carry .= $buffer;
            while (($newlinePos = strpos($carry, "\n")) !== false) {
                $complete = substr($carry, 0, $newlinePos);
                $carry = substr($carry, $newlinePos + 1);
                $this->updateMaxFromGrepLine($complete, $maxSeq);
            }
        }

        if ('' !== $carry) {
            $this->updateMaxFromGrepLine($carry, $maxSeq);
        }

        $exitCode = $process->getExitCode();
        if (1 === $exitCode) {
            return 0;
        }

        if (0 !== $exitCode) {
            throw new \RuntimeException(\sprintf('grep failed while scanning event log for bootstrap seq (path=%s, exit_code=%d, error_output=%s).', $this->safePathForMessage($path), $exitCode, $this->truncateDiagnostic($capturedStderr)));
        }

        return $maxSeq;
    }

    private function updateMaxFromGrepLine(string $line, int &$maxSeq): void
    {
        $trimmed = trim($line);
        if ('' === $trimmed) {
            return;
        }

        if (!preg_match(self::SEQ_SUFFIX_PATTERN, $trimmed, $matches)) {
            return;
        }

        $seq = (int) $matches[1];
        if ($seq < 1) {
            return;
        }

        if ($seq > $maxSeq) {
            $maxSeq = $seq;
        }
    }

    private function safePathForMessage(string $path): string
    {
        if (\strlen($path) <= 256) {
            return $path;
        }

        return substr($path, 0, 128).'…'.substr($path, -64);
    }

    private function appendBoundedDiagnostic(string &$buffer, string $chunk): void
    {
        if ('' === $chunk) {
            return;
        }

        if (str_ends_with($buffer, '…')) {
            return;
        }

        $remaining = 512 - \strlen($buffer);
        if ($remaining <= 0) {
            $buffer .= '…';

            return;
        }

        if (\strlen($chunk) <= $remaining) {
            $buffer .= $chunk;

            return;
        }

        $buffer .= substr($chunk, 0, $remaining).'…';
    }

    private function truncateDiagnostic(string $text): string
    {
        $trimmed = trim($text);
        if (\strlen($trimmed) <= 512) {
            return $trimmed;
        }

        return substr($trimmed, 0, 512).'…';
    }
}
