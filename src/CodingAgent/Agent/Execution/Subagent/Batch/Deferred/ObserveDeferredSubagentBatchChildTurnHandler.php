<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class ObserveDeferredSubagentBatchChildTurnHandler
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredSubagentChildRepository $childRepository,
        private DeferredChildRunEventProjector $projector,
        private LoggerInterface $logger,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(ObserveDeferredSubagentBatchChildTurnMessage $message): void
    {
        $batch = $this->batchRepository->findEntityByLifecycleId($message->batchLifecycleId);
        if (null === $batch) {
            return;
        }

        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return;
        }

        $child = $this->childRepository->findEntityByBatchLifecycleAndIndex($message->batchLifecycleId, $message->batchIndex);
        if (null === $child || $child->childRunId !== $message->childRunId) {
            $this->logger->warning('deferred_subagent_batch.child_turn_child_mismatch', [
                'batch_lifecycle_id' => $message->batchLifecycleId,
                'child_run_id' => $message->childRunId,
                'batch_index' => $message->batchIndex,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.child_turn_child_mismatch',
            ]);

            return;
        }

        $cursor = $child->childEventCursor;
        $newEvents = $this->filterNewEvents($message->committedEvents, $cursor);
        if ([] === $newEvents) {
            $this->enqueueDeliveryIfNeeded($batch->lifecycleId, $batch->aggregateProgressRevision, $batch->deliveredProgressRevision, $batch->terminalCompletionEnqueuedAt, $child->childLifecycleProjection);

            return;
        }

        if (!$this->isContiguousFromCursor($newEvents, $cursor)) {
            $firstSeq = $newEvents[0]->seq;
            $this->logger->warning('deferred_subagent_batch.child_event_gap', [
                'batch_lifecycle_id' => $message->batchLifecycleId,
                'child_run_id' => $message->childRunId,
                'batch_index' => $message->batchIndex,
                'cursor' => $cursor,
                'received_first_seq' => $firstSeq,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.child_event_gap',
            ]);
            $this->enqueueRecovery($message->batchLifecycleId);

            return;
        }

        $rawProjection = $child->childLifecycleProjection;
        $current = \is_array($rawProjection) && [] !== $rawProjection
            ? DeferredChildRunLifecycleProjectionDTO::fromArray($rawProjection)
            : new DeferredChildRunLifecycleProjectionDTO(
                childStatus: RunStatus::Running,
                childTurnNo: $message->turnNo,
                lastCommittedSeq: $cursor,
            );

        $updated = $this->projector->apply(
            current: $current,
            summaries: $newEvents,
            definitionModel: $child->definitionModel,
            committedStatus: $message->committedStatus,
            committedTurnNo: $message->turnNo,
        );

        $bumpRevision = $updated->lastCommittedSeq > $cursor;

        try {
            $this->batchRepository->applyBatchChildLifecycleProjection(
                batchLifecycleId: $message->batchLifecycleId,
                batchIndex: $message->batchIndex,
                projection: $updated,
                childEventCursor: $updated->lastCommittedSeq,
                expectedChildProjectionVersion: $child->projectionVersion,
                expectedBatchProjectionVersion: $batch->projectionVersion,
                bumpAggregateRevision: $bumpRevision,
            );
        } catch (OptimisticLockException $exception) {
            $this->logger->warning('deferred_subagent_batch.child_projection_version_conflict', [
                'batch_lifecycle_id' => $message->batchLifecycleId,
                'child_run_id' => $message->childRunId,
                'batch_index' => $message->batchIndex,
                'cursor' => $cursor,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.child_projection_version_conflict',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        } catch (\RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'projection version conflict')) {
                throw $exception;
            }

            $this->logger->warning('deferred_subagent_batch.child_projection_persist_failed', [
                'batch_lifecycle_id' => $message->batchLifecycleId,
                'child_run_id' => $message->childRunId,
                'batch_index' => $message->batchIndex,
                'cursor' => $cursor,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.child_projection_persist_failed',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        $this->commandBus->dispatch(new DeliverDeferredSubagentBatchLifecycleMessage($message->batchLifecycleId));
    }

    private function enqueueRecovery(string $batchLifecycleId): void
    {
        try {
            $this->commandBus->dispatch(new RecoverDeferredSubagentBatchLifecycleMessage($batchLifecycleId));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to enqueue deferred subagent batch lifecycle recovery after child event gap.', previous: $exception);
        }
    }

    /**
     * @param array<string, mixed>|null $rawChildProjection
     */
    private function enqueueDeliveryIfNeeded(
        string $batchLifecycleId,
        int $aggregateRevision,
        int $deliveredRevision,
        ?\DateTimeImmutable $terminalMarker,
        ?array $rawChildProjection,
    ): void {
        $needsProgress = $aggregateRevision > $deliveredRevision;
        $needsTerminal = null === $terminalMarker
            && \is_array($rawChildProjection)
            && [] !== $rawChildProjection
            && (RunStatus::tryFrom((string) ($rawChildProjection['child_status'] ?? 'running')) ?? RunStatus::Running)->isTerminal();

        if (!$needsProgress && !$needsTerminal) {
            return;
        }

        $this->commandBus->dispatch(new DeliverDeferredSubagentBatchLifecycleMessage($batchLifecycleId));
    }

    /**
     * @param list<AfterTurnCommitEventSummary> $events
     *
     * @return list<AfterTurnCommitEventSummary>
     */
    private function filterNewEvents(array $events, int $cursor): array
    {
        $out = [];
        foreach ($events as $event) {
            if ($event->seq <= $cursor) {
                continue;
            }
            $out[] = $event;
        }

        usort($out, static fn (AfterTurnCommitEventSummary $a, AfterTurnCommitEventSummary $b): int => $a->seq <=> $b->seq);

        return $out;
    }

    /**
     * @param list<AfterTurnCommitEventSummary> $events
     */
    private function isContiguousFromCursor(array $events, int $cursor): bool
    {
        $expected = $cursor + 1;
        foreach ($events as $event) {
            if ($event->seq !== $expected) {
                return false;
            }
            ++$expected;
        }

        return true;
    }
}
