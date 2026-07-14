<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * After child RunCommit: enqueue durable observation for tracked deferred batch children.
 */
final readonly class DeferredSubagentBatchChildTurnHookSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private DeferredSubagentChildRepository $childRepository,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        if ([] === $context->events) {
            return $context;
        }

        $child = $this->childRepository->findByChildRunId($context->runId);
        if (null === $child) {
            return $context;
        }

        if (DeferredSubagentChildLaunchStatusEnum::Failed === $child->launchStatus) {
            return $context;
        }

        $committedStatus = RunStatus::tryFrom($context->status) ?? RunStatus::Running;

        try {
            $this->commandBus->dispatch(new ObserveDeferredSubagentBatchChildTurnMessage(
                batchLifecycleId: $child->batchLifecycleId,
                batchIndex: $child->batchIndex,
                childRunId: $context->runId,
                committedStatus: $committedStatus,
                turnNo: $context->turnNo,
                committedEvents: $context->events,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('deferred_subagent_batch.child_turn_dispatch_failed', [
                'batch_lifecycle_id' => $child->batchLifecycleId,
                'child_run_id' => $context->runId,
                'batch_index' => $child->batchIndex,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.child_turn_dispatch_failed',
                'exception_class' => $e::class,
            ]);
        }

        return $context;
    }
}
