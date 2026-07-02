<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

final readonly class FileRewindLedgerEntry
{
    public function __construct(
        public string $runId,
        public int $turnNo,
        public int $anchorSeq,
        public FileRewindCheckpointKindEnum $kind,
        public string $snapshotCommitSha,
        public string $projectHash,
        public bool $pruned,
        public ?string $unavailableReason,
    ) {
    }
}
