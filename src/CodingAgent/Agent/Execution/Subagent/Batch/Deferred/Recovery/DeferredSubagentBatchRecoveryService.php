<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Recovery;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\InterruptDeferredSubagentBatchMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Gap/restart recovery for deferred normalized batches: tails durable child events.jsonl once per wakeup per child.
 */
final readonly class DeferredSubagentBatchRecoveryService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredSubagentChildRepository $childRepository,
        private AgentChildRunEventStoreFactory $childEventStoreFactory,
        private DeferredChildRunEventProjector $projector,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public function recover(string $batchLifecycleId): void
    {
        $batch = $this->batchRepository->findByLifecycleId($batchLifecycleId);
        if (null === $batch) {
            return;
        }

        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return;
        }

        if (null !== $batch->interruptionKind) {
            $this->dispatchInterrupt($batchLifecycleId, $batch->interruptionKind);

            return;
        }

        $children = $this->childRepository->findOrderedByBatchLifecycleId($batchLifecycleId);
        foreach ($children as $child) {
            $batch = $this->batchRepository->findByLifecycleId($batchLifecycleId);
            if (null === $batch || null !== $batch->terminalCompletionEnqueuedAt) {
                return;
            }

            if (null !== $batch->interruptionKind) {
                $this->dispatchInterrupt($batchLifecycleId, $batch->interruptionKind);

                return;
            }

            $childEntity = $this->childRepository->findEntityByBatchLifecycleAndIndex($batchLifecycleId, $child->batchIndex);
            if (null === $childEntity) {
                continue;
            }

            $cursor = $childEntity->childEventCursor;
            $childStore = $this->childEventStoreFactory->create($batch->parentRunId, $child->childRunId, $child->artifactId);
            $tailEvents = $childStore->readAfterSeq($cursor);

            if ([] === $tailEvents) {
                continue;
            }

            $summaries = array_map(
                static fn (RunEvent $event): AfterTurnCommitEventSummary => new AfterTurnCommitEventSummary(
                    seq: $event->seq,
                    type: $event->type,
                    payload: $event->payload,
                ),
                $tailEvents,
            );

            $rawProjection = $childEntity->childLifecycleProjection;
            $current = \is_array($rawProjection) && [] !== $rawProjection
                ? DeferredChildRunLifecycleProjectionDTO::fromArray($rawProjection)
                : new DeferredChildRunLifecycleProjectionDTO(
                    childStatus: RunStatus::Running,
                    childTurnNo: 0,
                    lastCommittedSeq: $cursor,
                );

            $maxTurnNo = $current->childTurnNo;
            foreach ($tailEvents as $event) {
                $maxTurnNo = max($maxTurnNo, $event->turnNo);
            }

            $updated = $this->projector->apply(
                current: $current,
                summaries: $summaries,
                definitionModel: $child->definitionModel,
                committedStatus: null,
                committedTurnNo: $maxTurnNo,
            );

            $bumpRevision = $updated->lastCommittedSeq > $cursor;

            try {
                $this->batchRepository->applyBatchChildLifecycleProjection(
                    batchLifecycleId: $batchLifecycleId,
                    batchIndex: $child->batchIndex,
                    projection: $updated,
                    childEventCursor: $updated->lastCommittedSeq,
                    expectedChildProjectionVersion: $childEntity->projectionVersion,
                    expectedBatchProjectionVersion: $batch->projectionVersion,
                    bumpAggregateRevision: $bumpRevision,
                );
            } catch (OptimisticLockException $exception) {
                $this->logger->warning('deferred_subagent_batch.recovery_projection_version_conflict', [
                    'batch_lifecycle_id' => $batchLifecycleId,
                    'child_run_id' => $child->childRunId,
                    'batch_index' => $child->batchIndex,
                    'cursor' => $cursor,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_subagent_batch.recovery_projection_version_conflict',
                    'exception_class' => $exception::class,
                ]);

                throw $exception;
            }
        }

        $this->dispatchDeliver($batchLifecycleId);
    }

    private function dispatchDeliver(string $batchLifecycleId): void
    {
        try {
            $this->commandBus->dispatch(new DeliverDeferredSubagentBatchLifecycleMessage($batchLifecycleId));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to enqueue deferred subagent batch lifecycle delivery after recovery.', previous: $exception);
        }
    }

    private function dispatchInterrupt(string $batchLifecycleId, DeferredSubagentInterruptionKindEnum $kind): void
    {
        try {
            $this->commandBus->dispatch(new InterruptDeferredSubagentBatchMessage($batchLifecycleId, $kind));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to enqueue deferred subagent batch interruption after recovery.', previous: $exception);
        }
    }
}
