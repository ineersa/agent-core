<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation;

use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;

/**
 * After child RunCommit: enqueue durable observation for tracked deferred batch children.
 */
final readonly class DeferredSubagentBatchChildTurnHookSubscriber implements EssentialAfterTurnHookInterface
{
    public function __construct(
        private DeferredSubagentChildRepository $childRepository,
    ) {
    }

    public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
    {
        if ([] === $context->events) {
            return [];
        }

        $child = $this->childRepository->findByChildRunId($context->runId);
        if (null === $child) {
            return [];
        }

        if (DeferredSubagentChildLaunchStatusEnum::Failed === $child->launchStatus) {
            return [];
        }

        $committedStatus = RunStatus::tryFrom($context->status) ?? RunStatus::Running;

        return [new \Ineersa\CodingAgent\Application\Message\DeferredAfterTurnCoordinationDTO(
            $context->runId, $predecessorSequence,
            new ObserveDeferredSubagentBatchChildTurnMessage(
                batchLifecycleId: $child->batchLifecycleId, batchIndex: $child->batchIndex,
                childRunId: $context->runId, committedStatus: $committedStatus,
                turnNo: $context->turnNo, committedEvents: $context->events,
            ),
        )];
    }
}
