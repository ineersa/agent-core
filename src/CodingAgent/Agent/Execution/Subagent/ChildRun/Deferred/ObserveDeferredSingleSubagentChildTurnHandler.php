<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Applies one child commit batch to the durable deferred-single lifecycle projection.
 */
final readonly class ObserveDeferredSingleSubagentChildTurnHandler
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
        private DeferredSingleSubagentChildEventProjector $projector,
        private LoggerInterface $logger,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(ObserveDeferredSingleSubagentChildTurnMessage $message): void
    {
        $row = $this->launchRepository->findEntityByLifecycleId($message->lifecycleId);
        if (null === $row) {
            return;
        }

        if (null !== $row->terminalCompletionEnqueuedAt) {
            return;
        }

        if ($row->childRunId !== $message->childRunId) {
            $this->logger->warning('deferred_single_subagent.child_turn_child_mismatch', [
                'lifecycle_id' => $message->lifecycleId,
                'child_run_id' => $message->childRunId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.child_turn_child_mismatch',
            ]);

            return;
        }

        $cursor = $row->childEventCursor;
        $newEvents = $this->filterNewEvents($message->committedEvents, $cursor);
        if ([] === $newEvents) {
            $this->enqueueDeliveryIfNeeded($row);

            return;
        }

        if (!$this->isContiguousFromCursor($newEvents, $cursor)) {
            $firstSeq = $newEvents[0]->seq;
            $this->logger->warning('deferred_single_subagent.child_event_gap', [
                'lifecycle_id' => $message->lifecycleId,
                'child_run_id' => $message->childRunId,
                'cursor' => $cursor,
                'received_first_seq' => $firstSeq,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.child_event_gap',
            ]);

            return;
        }

        $rawProjection = $row->childLifecycleProjection;
        $current = \is_array($rawProjection) && [] !== $rawProjection
            ? DeferredSingleSubagentChildLifecycleProjectionDTO::fromArray($rawProjection)
            : new DeferredSingleSubagentChildLifecycleProjectionDTO(
                childStatus: RunStatus::Running,
                childTurnNo: $message->turnNo,
                lastCommittedSeq: $cursor,
            );

        $updated = $this->projector->apply(
            current: $current,
            summaries: $newEvents,
            definitionModel: $row->definitionModel,
            committedStatus: $message->committedStatus,
            committedTurnNo: $message->turnNo,
        );

        try {
            $this->launchRepository->applyChildLifecycleProjection(
                lifecycleId: $message->lifecycleId,
                projection: $updated,
                childEventCursor: $updated->lastCommittedSeq,
                expectedProjectionVersion: $row->projectionVersion,
            );
        } catch (OptimisticLockException $exception) {
            $this->logger->warning('deferred_single_subagent.child_projection_version_conflict', [
                'lifecycle_id' => $message->lifecycleId,
                'child_run_id' => $message->childRunId,
                'cursor' => $cursor,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.child_projection_version_conflict',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        $this->enqueueDelivery($message->lifecycleId);
    }

    private function enqueueDeliveryIfNeeded(\Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunch $row): void
    {
        $needsProgress = $row->childEventCursor > $row->parentProgressCursor;
        $needsTerminal = null === $row->terminalCompletionEnqueuedAt
            && \is_array($row->childLifecycleProjection)
            && [] !== $row->childLifecycleProjection
            && (RunStatus::tryFrom((string) ($row->childLifecycleProjection['child_status'] ?? 'running')) ?? RunStatus::Running)->isTerminal();

        if (!$needsProgress && !$needsTerminal) {
            return;
        }

        $this->enqueueDelivery($row->lifecycleId);
    }

    private function enqueueDelivery(string $lifecycleId): void
    {
        $this->commandBus->dispatch(new DeliverDeferredSingleSubagentLifecycleMessage($lifecycleId));
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
