<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;

final readonly class BashProcessRecordDTO
{
    public function __construct(
        public int $id,
        public ?int $pid,
        public ?int $pgid,
        public string $sessionId,
        public string $command,
        public string $logPath,
        public string $statusPath,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?int $exitCode,
        public bool $stoppedByUser,
        public ?\DateTimeImmutable $backgroundedAt,
        public ?string $toolCallId,
        public bool $backgroundRequested,
        public ?\DateTimeImmutable $completionNotifiedAt,
        public BackgroundProcessStatusEnum $status,
        public ?int $deadlineNanoseconds,
    ) {
    }
}
