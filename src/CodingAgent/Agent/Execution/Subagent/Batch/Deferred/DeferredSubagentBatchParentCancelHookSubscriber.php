<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * After a parent run commit enters Cancelling/Cancelled, enqueue durable parent-cancel interruptions
 * for active deferred batch lifecycles. No RunStore reads.
 */
final readonly class DeferredSubagentBatchParentCancelHookSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        if (!\in_array($context->status, [RunStatus::Cancelling->value, RunStatus::Cancelled->value], true)) {
            return $context;
        }

        $active = $this->batchRepository->findActiveByParentRunId($context->runId);
        foreach ($active as $batch) {
            try {
                $this->commandBus->dispatch(new InterruptDeferredSubagentBatchMessage(
                    $batch->lifecycleId,
                    DeferredSubagentInterruptionKindEnum::ParentCancelled,
                ));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to enqueue deferred subagent batch parent cancellation.', previous: $exception);
            }
        }

        return $context;
    }
}
