<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;

/**
 * This service provides read-only debugging views for run state, replay streams, and hot prompt snapshots.
 */
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
     *
     * @return array{
     * run_id: string,
     * exists: bool,
     * state: array{
     * status: string,
     * version: int,
     * turn_no: int,
     * last_seq: int,
     * active_step_id: ?string,
     * retryable_failure: bool,
     * messages_count: int,
     * pending_tool_calls: int
     * }|null,
     * integrity: array{
     * run_id: string,
     * source: 'canonical_events'|'jsonl_fallback',
     * event_count: int,
     * last_seq: int,
     * missing_sequences: list<int>,
     * is_contiguous: bool
     * },
     * hot_prompt_state: array{
     * source: ?string,
     * event_count: int,
     * last_seq: int,
     * token_estimate: int,
     * is_contiguous: bool,
     * missing_sequences: list<int>,
     * messages_count: int
     * }|null,
     * pending_commands: list<array{
     * kind: string,
     * idempotency_key: string,
     * payload_keys: list<string>,
     * options: array<string, mixed>
     * }>,
     * metrics: array<string, mixed>|null
     * }
     */
    public function inspect(string $runId): array
    {
        $state = $this->runStore->get($runId);
        $integrity = $this->replayService->verifyIntegrity($runId);
        $hotPromptState = $this->normalizeHotPromptState($this->promptStateStore->get($runId));

        $pendingCommands = array_map(
            static fn (PendingCommand $command): array => [
                'kind' => $command->kind,
                'idempotency_key' => $command->idempotencyKey,
                'payload_keys' => array_values(array_map('strval', array_keys($command->payload))),
                'options' => $command->options,
            ],
            $this->commandStore->pending($runId),
        );

        return [
            'run_id' => $runId,
            'exists' => null !== $state || null !== $hotPromptState || [] !== $pendingCommands || $integrity['event_count'] > 0,
            'state' => null === $state
                ? null
                : [
                    'status' => $state->status->value,
                    'version' => $state->version,
                    'turn_no' => $state->turnNo,
                    'last_seq' => $state->lastSeq,
                    'active_step_id' => $state->activeStepId,
                    'retryable_failure' => $state->retryableFailure,
                    'messages_count' => \count($state->messages),
                    'pending_tool_calls' => \count($state->pendingToolCalls),
                ],
            'integrity' => $integrity,
            'hot_prompt_state' => $hotPromptState,
            'pending_commands' => $pendingCommands,
            'metrics' => $this->metrics?->snapshot(),
        ];
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
        [$events, $source] = $this->eventsForRun($runId);

        $afterSeq = max(0, $afterSeq);
        $limitedTo = $this->clampLimit($limit, 1000);

        $filtered = [];

        foreach ($events as $event) {
            if ($event->seq <= $afterSeq) {
                continue;
            }

            $filtered[] = $event;
        }

        $missingSequences = $this->missingSequences($filtered, $afterSeq + 1);

        return [
            'run_id' => $runId,
            'source' => $source,
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
        [$events, $source] = $this->eventsForRun($runId);

        $limitedTo = $this->clampLimit($limit, 500);

        return [
            'run_id' => $runId,
            'source' => $source,
            'total_events' => \count($events),
            'limit' => $limitedTo,
            'events' => \array_slice($events, -$limitedTo),
        ];
    }

    /**
     * Rebuilds and stores hot prompt state for a run by replaying persisted events.
     *
     * @return array{
     * run_id: string,
     * source: 'canonical_events'|'jsonl_fallback',
     * event_count: int,
     * last_seq: int,
     * missing_sequences: list<int>,
     * is_contiguous: bool,
     * token_estimate: int,
     * messages: list<array<string, mixed>>
     * }
     */
    public function rebuildHotPromptState(string $runId): array
    {
        return $this->replayService->rebuildHotPromptState($runId);
    }

    /**
     * Normalizes stored hot prompt state into a stable debug shape.
     *
     * @param array<string, mixed>|null $state
     *
     * @return array{
     * source: ?string,
     * event_count: int,
     * last_seq: int,
     * token_estimate: int,
     * is_contiguous: bool,
     * missing_sequences: list<int>,
     * messages_count: int
     * }|null
     */
    private function normalizeHotPromptState(?array $state): ?array
    {
        if (null === $state) {
            return null;
        }

        $messages = \is_array($state['messages'] ?? null) ? $state['messages'] : [];
        $missingSequences = [];

        foreach ($state['missing_sequences'] ?? [] as $sequence) {
            if (!\is_int($sequence)) {
                continue;
            }

            $missingSequences[] = $sequence;
        }

        return [
            'source' => \is_string($state['source'] ?? null) ? $state['source'] : null,
            'event_count' => \is_int($state['event_count'] ?? null) ? $state['event_count'] : 0,
            'last_seq' => \is_int($state['last_seq'] ?? null) ? $state['last_seq'] : 0,
            'token_estimate' => \is_int($state['token_estimate'] ?? null) ? $state['token_estimate'] : 0,
            'is_contiguous' => true === ($state['is_contiguous'] ?? false),
            'missing_sequences' => $missingSequences,
            'messages_count' => \count($messages),
        ];
    }

    /**
     * Resolves canonical events or JSONL fallback events for a run.
     *
     * @return array{0: list<RunEvent>, 1: 'canonical_events'|'jsonl_fallback'}
     */
    private function eventsForRun(string $runId): array
    {
        $events = $this->eventStore->allFor($runId);
        if ([] !== $events) {
            return [$this->sortBySequence($events), 'canonical_events'];
        }

        return [$this->sortBySequence($this->runLogReader->allFor($runId)), 'jsonl_fallback'];
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
