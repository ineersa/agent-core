<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess\DTO;

/**
 * Local native launch facts retained by the supervising worker.
 *
 * Distinct from the durable background_process row. Foreground completion
 * uses these paths and PIDs directly; persistence is one-way to run_control.
 */
final readonly class NativeBashLaunchDTO
{
    public function __construct(
        public int $pid,
        public ?int $pgid,
        public string $sessionId,
        public string $command,
        public ?string $toolCallId,
        public ?int $deadlineNanoseconds,
        public bool $backgroundRequested,
        public string $logPath,
        public string $statusPath,
        public \DateTimeImmutable $startedAt,
    ) {
    }
}
