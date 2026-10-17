<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCompletionDeliveryFenceProviderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO;

/** Automatic permission is independent of retained evidence and user continuation. */
final readonly class ToolCompletionDeliveryEligibilityPolicy
{
    public function __construct(private ToolCompletionDeliveryFenceProviderInterface $fences)
    {
    }

    public function ineligibleReason(RunState $state, ?ToolCompletionDeliveryFenceDTO $captured): ?string
    {
        if (null === $captured) {
            return 'missing_delivery_fence';
        }
        if (\in_array($state->status, [RunStatus::Cancelled, RunStatus::Cancelling, RunStatus::Failed], true)) {
            return 'cancelled_or_failed';
        }
        if (null !== $state->parentRunId && RunStatus::Completed === $state->status) {
            return 'terminal_child';
        }

        return $this->fenceIneligibleReason($state->runId, $captured);
    }

    public function capture(string $runId): ToolCompletionDeliveryFenceDTO
    {
        return $this->fences->capture($runId);
    }

    public function fenceIneligibleReason(string $runId, ToolCompletionDeliveryFenceDTO $captured): ?string
    {
        $current = $this->fences->capture($runId);
        if ($current->controllerGeneration !== $captured->controllerGeneration) {
            return 'stale_controller_generation';
        }
        if ($current->wakeGeneration !== $captured->wakeGeneration) {
            return 'stale_cancellation_generation';
        }
        if ($current->historyGeneration !== $captured->historyGeneration) {
            return 'stale_history_generation';
        }

        return null;
    }
}
