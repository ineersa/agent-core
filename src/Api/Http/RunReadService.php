<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Http;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunAccessStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;

final readonly class RunReadService
{
    public function __construct(
        private RunStoreInterface $runStore,
        private RunAccessStoreInterface $runAccessStore,
        private EventStoreInterface $eventStore,
        private RunLogReader $runLogReader,
    ) {
    }

    /**
     * @return array{
     *   run_id: string,
     *   status: string,
     *   turn_count: int,
     *   created_at: string,
     *   updated_at: string,
     *   latest_summary: ?string,
     *   waiting_flags: array{waiting_human: bool, cancelling: bool, retryable_failure: bool}
     * }|null
     */
    public function summary(string $runId): ?array
    {
        $scope = $this->runAccessStore->get($runId);
        if (null === $scope) {
            return null;
        }

        $state = $this->runStore->get($runId);
        [$events] = $this->eventsForRun($runId);

        $createdAt = $scope->createdAt;
        $updatedAt = $scope->updatedAt;

        if ([] !== $events) {
            $createdAt = $events[0]->createdAt;
            $lastEvent = $events[array_key_last($events)];

            if ($lastEvent->createdAt > $updatedAt) {
                $updatedAt = $lastEvent->createdAt;
            }
        }

        return [
            'run_id' => $runId,
            'status' => $state?->status->value ?? RunStatus::Queued->value,
            'turn_count' => $state->turnNo ?? 0,
            'created_at' => $createdAt->format(\DATE_ATOM),
            'updated_at' => $updatedAt->format(\DATE_ATOM),
            'latest_summary' => $this->latestSummary($state->messages ?? []),
            'waiting_flags' => [
                'waiting_human' => RunStatus::WaitingHuman === ($state->status ?? RunStatus::Queued),
                'cancelling' => RunStatus::Cancelling === ($state->status ?? RunStatus::Queued),
                'retryable_failure' => true === ($state->retryableFailure ?? false),
            ],
        ];
    }

    /**
     * @return array{
     *   run_id: string,
     *   cursor: string,
     *   next_cursor: ?string,
     *   has_more: bool,
     *   total: int,
     *   items: list<array{cursor: string, role: string, summary: ?string, message: array<string, mixed>}>
     * }|null
     */
    public function transcriptPage(string $runId, int $cursor, int $limit): ?array
    {
        if (null === $this->runAccessStore->get($runId)) {
            return null;
        }

        $state = $this->runStore->get($runId);
        $messages = $state->messages ?? [];

        $total = \count($messages);
        $offset = min(max(0, $cursor), $total);
        $pageSize = min(max(1, $limit), 200);

        /** @var list<AgentMessage> $slice */
        $slice = \array_slice($messages, $offset, $pageSize);

        $items = [];

        foreach ($slice as $index => $message) {
            $absoluteIndex = $offset + $index;
            $items[] = [
                'cursor' => (string) $absoluteIndex,
                'role' => $message->role,
                'summary' => $this->messageSummary($message),
                'message' => $message->toArray(),
            ];
        }

        $nextOffset = $offset + \count($slice);

        return [
            'run_id' => $runId,
            'cursor' => (string) $offset,
            'next_cursor' => $nextOffset < $total ? (string) $nextOffset : null,
            'has_more' => $nextOffset < $total,
            'total' => $total,
            'items' => $items,
        ];
    }

    /**
     * @return array{
     *   run_id: string,
     *   source: 'canonical_events'|'jsonl_fallback',
     *   resync_required: bool,
     *   missing_sequences: list<int>,
     *   events: list<RunEvent>
     * }|null
     */
    public function replayAfter(string $runId, int $afterSeq): ?array
    {
        if (null === $this->runAccessStore->get($runId)) {
            return null;
        }

        [$events, $source] = $this->eventsForRun($runId);

        $replayEvents = [];
        foreach ($events as $event) {
            if ($event->seq <= $afterSeq) {
                continue;
            }

            $replayEvents[] = $event;
        }

        $missingSequences = $this->missingSequences($replayEvents, $afterSeq + 1);

        return [
            'run_id' => $runId,
            'source' => $source,
            'resync_required' => [] !== $missingSequences,
            'missing_sequences' => $missingSequences,
            'events' => $replayEvents,
        ];
    }

    /**
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
     * @param list<AgentMessage> $messages
     */
    private function latestSummary(array $messages): ?string
    {
        for ($index = \count($messages) - 1; $index >= 0; --$index) {
            $summary = $this->messageSummary($messages[$index]);
            if (null === $summary) {
                continue;
            }

            return $summary;
        }

        return null;
    }

    private function messageSummary(AgentMessage $message): ?string
    {
        $chunks = [];

        foreach ($message->content as $part) {
            if (!\is_array($part) || ('text' !== ($part['type'] ?? null)) || !\is_string($part['text'] ?? null)) {
                continue;
            }

            $chunks[] = trim($part['text']);
        }

        $text = trim(implode("\n", array_filter($chunks, static fn (string $chunk): bool => '' !== $chunk)));
        if ('' === $text) {
            return null;
        }

        return substr($text, 0, 240);
    }

    /**
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
}
