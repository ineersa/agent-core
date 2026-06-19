<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Application\Dto\ReplayIntegrity;
use Ineersa\AgentCore\Application\Dto\ResolvedReplayEvents;
use Ineersa\AgentCore\Application\Replay\TurnTreeReplayFilter;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\PromptState;

final readonly class ReplayService
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private PromptStateStoreInterface $promptStateStore,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
        private ?TurnTreeReplayFilter $turnTreeReplayFilter = null,
    ) {
    }

    /**
     * Reconstructs current prompt state for a given run by replaying events.
     */
    public function rebuildHotPromptState(string $runId): PromptState
    {
        $rebuild = function () use ($runId): PromptState {
            $resolvedReplayEvents = $this->eventsForReplay($runId);

            // Filter to active branch when tree metadata is available.
            // Messages come from filtered branch events; integrity
            // (eventCount, lastSeq, missingSequences, isContiguous)
            // describes the full canonical stream so active-branch gaps
            // are not reported as corruption.
            $canonicalEvents = $resolvedReplayEvents->events;
            $filteredEvents = $canonicalEvents;
            if (null !== $this->turnTreeReplayFilter) {
                $filteredEvents = $this->turnTreeReplayFilter->filter($runId, $canonicalEvents)->events;
            }

            $messages = $this->replayMessages($filteredEvents);
            $integrity = $this->integrityFromResolvedReplayEvents($runId, $resolvedReplayEvents);

            $promptState = new PromptState(
                runId: $runId,
                source: $resolvedReplayEvents->source,
                eventCount: $integrity->eventCount,
                lastSeq: $integrity->lastSeq,
                missingSequences: $integrity->missingSequences,
                isContiguous: $integrity->isContiguous,
                tokenEstimate: $this->estimateTokens($messages),
                messages: $messages,
            );

            $this->promptStateStore->save($runId, $promptState);
            $this->metrics?->incrementReplayRebuildCount($resolvedReplayEvents->source);

            return $promptState;
        };

        if (null === $this->tracer) {
            return $rebuild();
        }

        return $this->tracer->inSpan('replay.rebuild_hot_prompt_state', [
            'run_id' => $runId,
        ], $rebuild);
    }

    /**
     * Validates event sequence integrity and identifies missing sequences for a run.
     */
    public function verifyIntegrity(string $runId): ReplayIntegrity
    {
        return $this->integrityFromResolvedReplayEvents($runId, $this->eventsForReplay($runId));
    }

    /**
     * Fetches and sorts events required for replaying a specific run.
     */
    private function eventsForReplay(string $runId): ResolvedReplayEvents
    {
        $events = $this->eventStore->allFor($runId);

        return new ResolvedReplayEvents(
            events: $this->sortBySequence($events),
            source: 'canonical_events',
        );
    }

    private function integrityFromResolvedReplayEvents(string $runId, ResolvedReplayEvents $resolvedReplayEvents): ReplayIntegrity
    {
        $missingSequences = $this->missingSequences($resolvedReplayEvents->events);

        return new ReplayIntegrity(
            runId: $runId,
            source: $resolvedReplayEvents->source,
            eventCount: \count($resolvedReplayEvents->events),
            lastSeq: [] === $resolvedReplayEvents->events
                ? 0
                : max(array_map(static fn (RunEvent $event): int => $event->seq, $resolvedReplayEvents->events)),
            missingSequences: $missingSequences,
            isContiguous: [] === $missingSequences,
        );
    }

    /**
     * Orders events by their sequence number to ensure correct replay order.
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
     * Processes events to generate replayed message history.
     *
     * @param list<RunEvent> $events
     *
     * @return list<array<string, mixed>>
     */
    private function replayMessages(array $events): array
    {
        $messages = [];

        foreach ($events as $event) {
            $payload = $event->payload;

            if (isset($payload['messages']) && \is_array($payload['messages'])) {
                $messages = [];

                foreach ($payload['messages'] as $message) {
                    if (!\is_array($message)) {
                        continue;
                    }

                    $messages[] = $message;
                }
            }

            if (isset($payload['message']) && \is_array($payload['message'])) {
                $messages[] = $payload['message'];
            }

            // Canonical llm_step_completed events emit the full normalized
            // assistant message structure under payload.assistant_message.
            // Convert the canonical shape into the internal message format:
            //   - Keep role and content as-is
            //   - Move tool_calls from top-level to metadata.tool_calls
            //   - Keep details as-is
            //   - Handle null content (tool-call-only) → empty array
            if (isset($payload['assistant_message']) && \is_array($payload['assistant_message'])) {
                $am = $payload['assistant_message'];

                if (!isset($am['role']) || !\is_string($am['role'])) {
                    continue;
                }

                $content = \is_array($am['content'] ?? null) ? $am['content'] : [];

                $message = [
                    'role' => $am['role'],
                    'content' => $content,
                ];

                if (isset($am['tool_calls']) && \is_array($am['tool_calls']) && [] !== $am['tool_calls']) {
                    $message['metadata']['tool_calls'] = $am['tool_calls'];
                }

                if (isset($am['details']) && \is_array($am['details']) && [] !== $am['details']) {
                    $message['details'] = $am['details'];
                }

                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Identifies gaps in event sequences for a given set of events.
     *
     * @param list<RunEvent> $events
     *
     * @return list<int>
     */
    private function missingSequences(array $events): array
    {
        $missing = [];
        $expected = 1;

        foreach ($events as $event) {
            if ($event->seq < $expected) {
                continue;
            }

            while ($expected < $event->seq) {
                $missing[] = $expected;
                ++$expected;
            }

            ++$expected;
        }

        return $missing;
    }

    /**
     * Calculates approximate token count for a list of messages.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function estimateTokens(array $messages): int
    {
        $encoded = json_encode($messages);

        if (false === $encoded) {
            return 0;
        }

        return (int) ceil(\strlen($encoded) / 4);
    }
}
