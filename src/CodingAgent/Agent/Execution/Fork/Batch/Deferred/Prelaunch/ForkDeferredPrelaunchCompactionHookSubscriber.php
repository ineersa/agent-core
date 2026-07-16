<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;

/**
 * Observes canonical compaction terminal events on fork-local staging runs only.
 */
final readonly class ForkDeferredPrelaunchCompactionHookSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private ForkDeferredPrelaunchStagingService $stagingService,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        if ([] === $context->events) {
            return $context;
        }

        if (null === $this->batchRepository->findByForkLocalRunId($context->runId)) {
            return $context;
        }

        foreach ($context->events as $summary) {
            if (RunEventTypeEnum::ContextCompacted->value === $summary->type) {
                $this->stagingService->handleForkLocalCompactionTerminal($context->runId, $summary->type, $summary->payload);

                return $context;
            }

            if (RunEventTypeEnum::ContextCompactionFailed->value === $summary->type) {
                $this->stagingService->handleForkLocalCompactionTerminal($context->runId, $summary->type, $summary->payload);

                return $context;
            }
        }

        return $context;
    }
}
