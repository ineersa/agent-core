<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;

final readonly class BashProcessSnapshotDTO
{
    public function __construct(
        public int $id,
        public string $runId,
        public int $pid,
        public ?int $pgid,
        public string $statusPath,
        public bool $finished,
        public BackgroundProcessStatusEnum $status,
        public bool $signalRequired = false,
    ) {
    }
}
