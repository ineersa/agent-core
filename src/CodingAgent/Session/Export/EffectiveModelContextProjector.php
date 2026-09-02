<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Export;

use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;

/**
 * Projects canonical events.jsonl into the effective model message context.
 *
 * Reuses HistoryReplayFilter + RunStateReducer so HTML export matches resume
 * semantics (latest compaction checkpoint + later retained messages).
 */
final readonly class EffectiveModelContextProjector
{
    public function __construct(
        private EventPayloadNormalizer $eventPayloadNormalizer,
        private HistoryReplayFilter $historyReplayFilter,
        private RunStateReducer $runStateReducer,
    ) {
    }

    public function project(string $eventsContent, string $runId): EffectiveModelContextSnapshot
    {
        $events = $this->parseEvents($eventsContent, $runId);
        if ([] === $events) {
            throw new \RuntimeException(\sprintf('Session %s has no events to export.', $runId));
        }

        $filtered = $this->historyReplayFilter->filter($events);
        $replayed = $this->runStateReducer->replay(RunState::queued($runId), $filtered);

        return new EffectiveModelContextSnapshot(
            messages: $replayed->messages,
            availableTools: $this->latestAvailableTools($filtered),
            availableToolsSchemaTokensEstimate: $this->latestAvailableToolsEstimate($filtered),
            compaction: $this->latestCompactionMetadata($filtered),
        );
    }

    /**
     * @return list<RunEvent>
     */
    private function parseEvents(string $content, string $runId): array
    {
        $events = [];
        $unsupported = [];
        $skippedIncompatible = 0;

        foreach (explode("\n", $content) as $lineIndex => $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            try {
                $payload = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException(\sprintf('Cannot project model context for session %s: unparseable JSONL at line %d (%s).', $runId, $lineIndex + 1, $exception->getMessage()), previous: $exception);
            }

            if (!\is_array($payload)) {
                throw new \RuntimeException(\sprintf('Cannot project model context for session %s: non-object JSONL at line %d.', $runId, $lineIndex + 1));
            }

            try {
                $event = $this->eventPayloadNormalizer->denormalizeRunEvent($payload);
            } catch (\UnexpectedValueException $exception) {
                $type = \is_string($payload['type'] ?? null) ? $payload['type'] : '(missing)';
                $unsupported[$type] = true;

                continue;
            } catch (\InvalidArgumentException $exception) {
                throw new \RuntimeException(\sprintf('Cannot project model context for session %s: invalid event timestamp at line %d (%s).', $runId, $lineIndex + 1, $exception->getMessage()), previous: $exception);
            }

            if (null === $event) {
                ++$skippedIncompatible;
                continue;
            }

            if ($event->runId !== $runId) {
                throw new \RuntimeException(\sprintf('Cannot project model context for session %s: event seq %d embeds run_id "%s".', $runId, $event->seq, $event->runId));
            }

            $events[] = $this->normalizeLegacyStringContent($event);
        }

        if ([] !== $unsupported) {
            $types = implode(', ', array_keys($unsupported));
            throw new \RuntimeException(\sprintf('Cannot project model context for session %s: unsupported event type(s): %s.', $runId, $types));
        }

        if ([] === $events && $skippedIncompatible > 0) {
            throw new \RuntimeException(\sprintf('Cannot project model context for session %s: all events were skipped as incompatible schema.', $runId));
        }

        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return list<string>|null
     */
    private function latestAvailableTools(array $events): ?array
    {
        $tools = null;
        foreach ($events as $event) {
            if (RunEventTypeEnum::LlmStepCompleted->value !== $event->type
                && RunEventTypeEnum::LlmStepFailed->value !== $event->type
                && RunEventTypeEnum::LlmStepAborted->value !== $event->type) {
                continue;
            }

            $raw = $event->payload['available_tools'] ?? null;
            if (!\is_array($raw) || [] === $raw) {
                continue;
            }

            $names = [];
            foreach ($raw as $entry) {
                if (\is_string($entry) && '' !== $entry) {
                    $names[] = $entry;
                }
            }

            if ([] !== $names) {
                $tools = $names;
            }
        }

        return $tools;
    }

    /**
     * @param list<RunEvent> $events
     */
    private function latestAvailableToolsEstimate(array $events): ?int
    {
        $estimate = null;
        foreach ($events as $event) {
            if (RunEventTypeEnum::LlmStepCompleted->value !== $event->type
                && RunEventTypeEnum::LlmStepFailed->value !== $event->type
                && RunEventTypeEnum::LlmStepAborted->value !== $event->type) {
                continue;
            }

            $raw = $event->payload['available_tools_schema_tokens_estimate'] ?? null;
            if (\is_int($raw)) {
                $estimate = $raw;
            }
        }

        return $estimate;
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return array<string, mixed>|null
     */
    private function latestCompactionMetadata(array $events): ?array
    {
        $latest = null;
        foreach ($events as $event) {
            if (RunEventTypeEnum::ContextCompacted->value !== $event->type) {
                continue;
            }

            $payload = $event->payload;
            $latest = [
                'seq' => $event->seq,
                'trigger' => \is_string($payload['trigger'] ?? null) ? $payload['trigger'] : null,
                'summary_text' => \is_string($payload['summary_text'] ?? null) ? $payload['summary_text'] : null,
                'messages_compacted' => \is_int($payload['messages_compacted'] ?? null) ? $payload['messages_compacted'] : null,
                'messages_retained' => \is_int($payload['messages_retained'] ?? null) ? $payload['messages_retained'] : null,
                'estimated_tokens_before' => \is_int($payload['estimated_tokens_before'] ?? null) ? $payload['estimated_tokens_before'] : null,
                'estimated_tokens_after' => \is_int($payload['estimated_tokens_after'] ?? null) ? $payload['estimated_tokens_after'] : null,
                'hook_metadata' => \is_array($payload['hook_metadata'] ?? null) ? $payload['hook_metadata'] : null,
                'replacement_summary' => \array_key_exists('replacement_summary', $payload)
                    ? $payload['replacement_summary']
                    : null,
            ];
        }

        return $latest;
    }

    /**
     * Older fixtures/exports sometimes store message content as a bare string.
     * Canonical AgentMessage::fromPayload requires typed content blocks; coerce
     * for projection only so HTML export can reuse RunStateReducer.
     */
    private function normalizeLegacyStringContent(RunEvent $event): RunEvent
    {
        $payload = $event->payload;
        $changed = false;

        if (isset($payload['payload']) && \is_array($payload['payload'])) {
            $inner = $payload['payload'];
            if (isset($inner['messages']) && \is_array($inner['messages'])) {
                $normalized = $this->normalizeMessageList($inner['messages']);
                if ($normalized !== $inner['messages']) {
                    $inner['messages'] = $normalized;
                    $payload['payload'] = $inner;
                    $changed = true;
                }
            }
        }

        if (isset($payload['messages']) && \is_array($payload['messages'])) {
            $normalized = $this->normalizeMessageList($payload['messages']);
            if ($normalized !== $payload['messages']) {
                $payload['messages'] = $normalized;
                $changed = true;
            }
        }

        if (isset($payload['assistant_message']) && \is_array($payload['assistant_message'])) {
            $assistant = $payload['assistant_message'];
            $content = $assistant['content'] ?? null;
            if (\is_string($content)) {
                $assistant['content'] = [['type' => 'text', 'text' => $content]];
                $payload['assistant_message'] = $assistant;
                $changed = true;
            }
        }

        if (isset($payload['message']) && \is_array($payload['message'])) {
            $message = $payload['message'];
            $content = $message['content'] ?? null;
            if (\is_string($content)) {
                $message['content'] = [['type' => 'text', 'text' => $content]];
                $payload['message'] = $message;
                $changed = true;
            }
        }

        if (!$changed) {
            return $event;
        }

        return new RunEvent(
            runId: $event->runId,
            seq: $event->seq,
            turnNo: $event->turnNo,
            type: $event->type,
            payload: $payload,
            createdAt: $event->createdAt,
        );
    }

    /**
     * @param list<mixed> $messages
     *
     * @return list<mixed>
     */
    private function normalizeMessageList(array $messages): array
    {
        $normalized = [];
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                $normalized[] = $message;
                continue;
            }

            $content = $message['content'] ?? null;
            if (\is_string($content)) {
                $message['content'] = [['type' => 'text', 'text' => $content]];
            }
            $normalized[] = $message;
        }

        return $normalized;
    }
}
