<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ToolQuestion;

use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;

/**
 * Production implementation using BackgroundProcessManager to check
 * process liveness via /proc and the DB-backed entity status.
 *
 * A process is considered finished if:
 * - The entity record has a terminal status (not Running).
 * - The entity record vanished from DB (process cleaned up).
 */
final readonly class BackgroundProcessStatusChecker implements BackgroundProcessStatusCheckerInterface
{
    public function __construct(
        private readonly BackgroundProcessManager $manager,
    ) {
    }

    public function isFinished(int $pid, string $sessionId): bool
    {
        $process = $this->manager->find($pid, $sessionId);
        if (null === $process) {
            return true; // vanished — no longer running
        }

        return BackgroundProcessStatusEnum::Running !== $process->status;
    }
}
