<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

/**
 * Resolved checkpoint metadata from canonical events (resume-safe).
 */
final readonly class FileRewindLedgerEntry
{
    public function __construct(
        public int $turnNo,
        public int $eventSeq,
        public FileRewindCheckpointKindEnum $kind,
        public string $snapshotCommitSha,
        public string $projectHash,
        public bool $pruned,
        public ?string $unavailableReason,
    ) {
    }
}
