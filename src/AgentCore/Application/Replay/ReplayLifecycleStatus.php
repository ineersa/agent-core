<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Replay;

use Ineersa\AgentCore\Domain\Run\RunStatus;

/** Scalar lifecycle decisions shared by execution replay and historical presentation. */
final class ReplayLifecycleStatus
{
    public static function terminal(mixed $reason): RunStatus
    {
        return match ($reason) {
            'cancelled' => RunStatus::Cancelled,
            'failed' => RunStatus::Failed,
            default => RunStatus::Completed,
        };
    }

    public static function afterCompaction(bool $continue): RunStatus
    {
        return $continue ? RunStatus::Running : RunStatus::Completed;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: RunStatus, activeStep: ?string, terminal: bool}
     */
    public static function compactionFailure(RunStatus $status, ?string $activeStep, array $payload): array
    {
        $step = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : null;
        $terminal = null !== $step && $step === $activeStep && 'stale_result' !== ($payload['reason'] ?? null);
        if (null !== $step && RunStatus::Compacting === $status) {
            $status = $terminal ? self::afterCompaction((bool) ($payload['continue_after_compaction'] ?? false)) : RunStatus::Running;
        }

        return ['status' => $status, 'activeStep' => $terminal ? null : $activeStep, 'terminal' => $terminal];
    }
}
