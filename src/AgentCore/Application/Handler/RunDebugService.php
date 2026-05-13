<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Application\Dto\HotPromptStateSnapshot;
use Ineersa\AgentCore\Application\Dto\PendingCommandSnapshot;
use Ineersa\AgentCore\Application\Dto\ResolvedReplayEvents;
use Ineersa\AgentCore\Application\Dto\RunDebugSnapshot;
use Ineersa\AgentCore\Application\Dto\RunStateSnapshot;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\PromptState;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;

final readonly class RunDebugService
{
    public function __construct(
        private RunStoreInterface $runStore,
        private CommandStoreInterface $commandStore,
        private PromptStateStoreInterface $promptStateStore,
        private EventStoreInterface $eventStore,
        private RunLogReader $runLogReader,
        private ReplayService $replayService,
        private ?RunMetrics $metrics = null,
    ) {
    }

    /**
     * Builds a consolidated debug snapshot for a run.
     */
    public function inspect(string $runId): RunDebugSnapshot
    {
        $state = $this->runStore->get($runId);
        $integrity = $this->replayService->verifyIntegrity($runId);
        $hotPromptState = HotPromptStateSnapshot::fromPromptState($this->promptStateStore->get($runId));

        $pendingCommands = array_map(
            static fn (PendingCommand $command): PendingCommandSnapshot => new PendingCommandSnapshot(
                kind: $command->kind,
                idempotencyKey: $command->idempotencyKey,
                payloadKeys: array_values(array_map('strval', array_keys($command->payload))),
                cancelSafe: $command->options->safe,
            ),
            $this->commandStore->pending($runId),
        );

        return new RunDebugSnapshot(
            runId: $runId,
            exists: null !== $state || null !== $hotPromptState || [] !== $pendingCommands || $integrity->eventCount > 0,
            state: null === $state
                ? null
                : new RunStateSnapshot(
                    status: $state->status->value,
                    version: $state->version,
                    turnNo: $state->turnNo,
                    lastSeq: $state->lastSeq,
                    activeStepId: $state->activeStepId,
                    retryableFailure: $state->retryableFailure,
                    messagesCount: \count($state->messages),
                    pendingToolCalls: \count($state->pendingToolCalls),
                ),
            integrity: $integrity,
            hotPromptState: $hotPromptState,
            pendingCommands: $pendingCommands,
            metrics: $this->metrics?->snapshot(),
        );
    }

    /**
     * Returns replayable events after a sequence boundary.
     *
     * @return array{
     * run_id: string,
     * source: 'canonical_events'|'jsonl_fallback',
     * after_seq: int,
     * total_events: int,
     * resync_required: bool,
     * missing_sequences: list<int>,
     * events: list<RunEvent>
     * }
     */
    public function replayAfter(string $runId, int $afterSeq = 0, int $limit = 200): array
    {
        $resolvedReplayEvents = $this->eventsForRun($runId);

        $afterSeq = max(0, $afterSeq);
        $limitedTo = $this->clampLimit($limit, 1000);

        $filtered = [];

        foreach ($resolvedReplayEvents->events as $event) {
            if ($event->seq <= $afterSeq) {
                continue;
            }

            $filtered[] = $event;
        }

        $missingSequences = $this->missingSequences($filtered, $afterSeq + 1);

        return [
            'run_id' => $runId,
            'source' => $resolvedReplayEvents->source,
            'after_seq' => $afterSeq,
            'total_events' => \count($filtered),
            'resync_required' => [] !== $missingSequences,
            'missing_sequences' => $missingSequences,
            'events' => \array_slice($filtered, 0, $limitedTo),
        ];
    }

    /**
     * Returns the latest events for a run in deterministic sequence order.
     *
     * @return array{
     * run_id: string,
     * source: 'canonical_events'|'jsonl_fallback',
     * total_events: int,
     * limit: int,
     * events: list<RunEvent>
     * }
     */
    public function tail(string $runId, int $limit = 25): array
    {
        $resolvedReplayEvents = $this->eventsForRun($runId);

        $limitedTo = $this->clampLimit($limit, 500);

        return [
            'run_id' => $runId,
            'source' => $resolvedReplayEvents->source,
            'total_events' => \count($resolvedReplayEvents->events),
            'limit' => $limitedTo,
            'events' => \array_slice($resolvedReplayEvents->events, -$limitedTo),
        ];
    }

    /**
     * Rebuilds and stores hot prompt state for a run by replaying persisted events.
     */
    public function rebuildHotPromptState(string $runId): PromptState
    {
        return $this->replayService->rebuildHotPromptState($runId);
    }

    /**
     * Resolves canonical events or JSONL fallback events for a run.
     */
    private function eventsForRun(string $runId): ResolvedReplayEvents
    {
        $events = $this->eventStore->allFor($runId);
        if ([] !== $events) {
            return new ResolvedReplayEvents(
                events: $this->sortBySequence($events),
                source: 'canonical_events',
            );
        }

        return new ResolvedReplayEvents(
            events: $this->sortBySequence($this->runLogReader->allFor($runId)),
            source: 'jsonl_fallback',
        );
    }

    /**
     * Orders events by sequence number in ascending order.
     *
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    private function sortBySequence(array $events): array
    {
        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }

    /**
     * Detects sequence gaps in an event list after a starting sequence number.
     *
     * @param list<RunEvent> $events
     *
     * @return list<int>
     */
    private function missingSequences(array $events, int $expectedStart): array
    {
        $missing = [];
        $expected = max(1, $expectedStart);

        foreach ($events as $event) {
            if ($event->seq < $expected) {
                continue;
            }

            while ($expected < $event->seq) {
                $missing[] = $expected;
                ++$expected;
            }

            $expected = $event->seq + 1;
        }

        return $missing;
    }

    private function clampLimit(int $limit, int $max): int
    {
        return min(max(1, $limit), $max);
    }
}
