<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;

/**
 * Local native supervision facts for one launch identified by statusPath.
 *
 * Distinct from the durable background_process row. Never carries a fabricated
 * database id; callers that need persistence resolve statusPath through ProcessStore.
 */
final readonly class NativeBashObservationDTO
{
    public function __construct(
        public int $pid,
        public ?int $pgid,
        public string $sessionId,
        public string $command,
        public string $logPath,
        public string $statusPath,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?int $exitCode,
        public bool $stoppedByUser,
        public bool $accepted,
        public ?string $toolCallId,
        public bool $backgroundRequested,
        public BackgroundProcessStatusEnum $status,
        public ?int $deadlineNanoseconds,
    ) {
    }
}
