<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RecordChildLaunchSuccessDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Owner-local child reservation and launch metadata.
 *
 * Reservation stays owner-local under the producer run lock. Workers publish
 * one-way RecordChildLaunchSuccessDTO messages; failed ordinary ToolCallResult
 * retirement stays on the run_control receive path.
 */
final class ChildInvocationLifecycleService
{
    public function __construct(
        private readonly DeferredSubagentBatchRepository $batches,
        private readonly ?MessageBusInterface $commandBus = null,
    ) {
    }

    public function reserveBatch(
        string $lifecycleId,
        string $parentRunId,
        int $parentTurnNo,
        string $parentToolCallId,
        int $parentOrderIndex,
        \DateTimeImmutable $deadlineAt,
        DeferredSubagentBatchChildIntentDTO $childIntent,
        ?string $parentModel = null,
    ): DeferredSubagentBatchProjectionDTO {
        return $this->batches->reserveBatch($lifecycleId, $parentRunId, $parentTurnNo, $parentToolCallId, $parentOrderIndex, $deadlineAt, $childIntent, $parentModel);
    }

    public function applyLaunchFailurePreparation(string $parentRunId, string $parentToolCallId, string $lifecycleId): void
    {
        $this->requireInvocation($parentRunId, $parentToolCallId, $lifecycleId);
        $this->batches->applyLaunchFailurePreparation($parentRunId, $parentToolCallId, $lifecycleId);
    }

    /**
     * Owner-local success persistence. Used by the one-way handler and owner-local callers.
     */
    public function applyLaunchSuccessState(string $parentRunId, string $parentToolCallId, string $lifecycleId, \DateTimeImmutable $startedAt): void
    {
        $this->requireInvocation($parentRunId, $parentToolCallId, $lifecycleId);
        $this->batches->applyLaunchSuccessState($parentRunId, $parentToolCallId, $lifecycleId, $startedAt);
    }

    /**
     * Worker publication path. When a command bus is wired, this is one-way;
     * owner-local processes without a bus apply success immediately.
     */
    public function publishLaunchSuccessState(string $parentRunId, string $parentToolCallId, string $lifecycleId, \DateTimeImmutable $startedAt): void
    {
        if (null !== $this->commandBus) {
            $this->commandBus->dispatch(new RecordChildLaunchSuccessDTO(
                $parentRunId,
                $parentToolCallId,
                $lifecycleId,
                $startedAt,
            ));

            return;
        }

        $this->applyLaunchSuccessState($parentRunId, $parentToolCallId, $lifecycleId, $startedAt);
    }

    private function requireInvocation(string $parentRunId, string $parentToolCallId, string $lifecycleId): void
    {
        $batch = $this->batches->findByLifecycleId($lifecycleId);
        if (null === $batch || $batch->parentRunId !== $parentRunId || $batch->parentToolCallId !== $parentToolCallId) {
            throw new ToolCallException('Child launch metadata does not match its reserved invocation.', retryable: false);
        }
    }
}
