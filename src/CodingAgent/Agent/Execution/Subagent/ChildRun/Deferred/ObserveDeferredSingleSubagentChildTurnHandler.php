<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Psr\Log\LoggerInterface;

/**
 * Applies one child commit batch to the durable deferred-single lifecycle projection.
 *
 * Assumes run_control consumer ordering per lifecycle; duplicate delivery is safe via cursor.
 */
final readonly class ObserveDeferredSingleSubagentChildTurnHandler
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
        private DeferredSingleSubagentChildEventProjector $projector,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ObserveDeferredSingleSubagentChildTurnMessage $message): void
    {
        $row = $this->launchRepository->findEntityByLifecycleId($message->lifecycleId);
        if (null === $row) {
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
                childStatus: \Ineersa\AgentCore\Domain\Run\RunStatus::Running,
                childTurnNo: $message->turnNo,
                lastCommittedSeq: $cursor,
            );
        $artifactPath = AgentArtifactPathsDTO::forArtifactId($row->artifactId)->artifactDir;

        $updated = $this->projector->apply(
            $current,
            $newEvents,
            $row->definitionModel,
            $artifactPath,
        );

        $this->launchRepository->applyChildLifecycleProjection(
            lifecycleId: $message->lifecycleId,
            projection: $updated,
            childEventCursor: $updated->lastCommittedSeq,
        );
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
