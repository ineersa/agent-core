<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption;

use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;

/**
 * After a parent run commit enters Cancelling/Cancelled, enqueue durable parent-cancel interruptions
 * for active deferred batch lifecycles. No RunStore reads.
 */
final readonly class DeferredSubagentBatchParentCancelHookSubscriber implements EssentialAfterTurnHookInterface
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
    ) {
    }

    public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
    {
        if (!\in_array($context->status, [RunStatus::Cancelling->value, RunStatus::Cancelled->value], true)) {
            return [];
        }

        $actions = [];
        foreach ($this->batchRepository->findUnfinishedByParentRunId($context->runId) as $batch) {
            $actions[] = new \Ineersa\CodingAgent\Application\Message\DeferredAfterTurnCoordinationDTO(
                $context->runId, $predecessorSequence,
                new InterruptDeferredSubagentBatchMessage($batch->lifecycleId, DeferredSubagentInterruptionKindEnum::ParentCancelled),
            );
        }

        return $actions;
    }
}
