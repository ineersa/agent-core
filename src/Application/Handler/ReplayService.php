<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;

final readonly class ReplayService
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RunLogReader $runLogReader,
        private PromptStateStoreInterface $promptStateStore,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
    ) {
    }

    /**
     * reconstructs current prompt state for a given run by replaying events.
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
        $rebuild = function () use ($runId): array {
            [$events, $source] = $this->eventsForReplay($runId);

            $messages = $this->replayMessages($events);
            $integrity = $this->verifyIntegrity($runId);

            $state = [
                'run_id' => $runId,
                'source' => $source,
                'event_count' => $integrity['event_count'],
                'last_seq' => $integrity['last_seq'],
                'missing_sequences' => $integrity['missing_sequences'],
                'is_contiguous' => $integrity['is_contiguous'],
                'token_estimate' => $this->estimateTokens($messages),
                'messages' => $messages,
            ];

            $this->promptStateStore->save($runId, $state);
            $this->metrics?->incrementReplayRebuildCount($source);

            return $state;
        };

        if (null === $this->tracer) {
            return $rebuild();
        }

        return $this->tracer->inSpan('replay.rebuild_hot_prompt_state', [
            'run_id' => $runId,
        ], $rebuild);
    }

    /**
     * validates event sequence integrity and identifies missing sequences for a run.
     *
     * @return array{
     * run_id: string,
     * source: 'canonical_events'|'jsonl_fallback',
     * event_count: int,
     * last_seq: int,
     * missing_sequences: list<int>,
     * is_contiguous: bool
     * }
     */
    public function verifyIntegrity(string $runId): array
    {
        [$events, $source] = $this->eventsForReplay($runId);
        $missingSequences = $this->missingSequences($events);

        return [
            'run_id' => $runId,
            'source' => $source,
            'event_count' => \count($events),
            'last_seq' => [] === $events ? 0 : max(array_map(static fn (RunEvent $event): int => $event->seq, $events)),
            'missing_sequences' => $missingSequences,
            'is_contiguous' => [] === $missingSequences,
        ];
    }

    /**
     * fetches and sorts events required for replaying a specific run.
     *
     * @return array{0: list<RunEvent>, 1: 'canonical_events'|'jsonl_fallback'}
     */
    private function eventsForReplay(string $runId): array
    {
        $events = $this->eventStore->allFor($runId);
        if ([] !== $events) {
            return [$this->sortBySequence($events), 'canonical_events'];
        }

        return [$this->sortBySequence($this->runLogReader->allFor($runId)), 'jsonl_fallback'];
    }

    /**
     * orders events by their sequence number to ensure correct replay order.
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
     * processes events to generate replayed message history.
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

            if (isset($payload['assistant']) && \is_string($payload['assistant'])) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => $payload['assistant'],
                    ]],
                ];
            }
        }

        return $messages;
    }

    /**
     * identifies gaps in event sequences for a given set of events.
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
     * calculates approximate token count for a list of messages.
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
