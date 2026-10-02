<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * Accumulates honest physical JSONL read scalars for one storage-boundary scan.
 *
 * Counts bytes actually returned by underlying fread/fgets calls and whether the
 * consumer stopped before the logical end of the scan. This is not device I/O
 * throughput and does not re-encode events to estimate size.
 *
 * Forward scans: {@see fullScan()} requires feof() after the read loop, not merely
 * fgets() returning false (read errors must not be labelled as EOF).
 *
 * Reverse scans: {@see fullScan()} means the consumer did not stop early and the
 * scanner reached the start of the file. {@see archiveBytesRead()} may still equal
 * the file size on an early exit when the final fread chunk already contained the
 * unread prefix bytes; full_scan remains false in that case. A failed handle fstat,
 * seek, or fread is not reached_eof and not a full scan.
 *
 * @internal
 */
final class JsonlPhysicalReadObservation
{
    private int $archiveBytesRead = 0;

    private int $linesYielded = 0;

    private bool $reachedEof = false;

    private bool $earlyExit = false;

    private bool $finished = false;

    public function addBytes(int $bytes): void
    {
        if ($bytes > 0) {
            $this->archiveBytesRead += $bytes;
        }
    }

    public function addLineYielded(): void
    {
        ++$this->linesYielded;
    }

    public function finish(bool $reachedEof, bool $earlyExit): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $this->reachedEof = $reachedEof;
        $this->earlyExit = $earlyExit;
    }

    public function archiveBytesRead(): int
    {
        return $this->archiveBytesRead;
    }

    public function linesYielded(): int
    {
        return $this->linesYielded;
    }

    public function reachedEof(): bool
    {
        return $this->reachedEof;
    }

    public function earlyExit(): bool
    {
        return $this->earlyExit;
    }

    /**
     * True when the scan reached the logical end of its direction without the consumer stopping early.
     */
    public function fullScan(): bool
    {
        return $this->reachedEof && !$this->earlyExit;
    }
}
