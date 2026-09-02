<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Export;

use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
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
        $toolsSnapshot = $this->latestAvailableToolsSnapshot($filtered);

        return new EffectiveModelContextSnapshot(
            messages: array_values(array_map(
                static fn (AgentMessage $message): array => $message->toArray(),
                $replayed->messages,
            )),
            availableTools: $toolsSnapshot['tools'],
            availableToolsSchemaTokensEstimate: $toolsSnapshot['estimate'],
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

            $events[] = $event;
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
     * @return array{tools: list<string>|null, estimate: int|null}
     */
    private function latestAvailableToolsSnapshot(array $events): array
    {
        $tools = null;
        $estimate = null;

        foreach ($events as $event) {
            if (RunEventTypeEnum::LlmStepCompleted->value !== $event->type
                && RunEventTypeEnum::LlmStepFailed->value !== $event->type
                && RunEventTypeEnum::LlmStepAborted->value !== $event->type) {
                continue;
            }

            if (!\array_key_exists('available_tools', $event->payload)) {
                continue;
            }

            $raw = $event->payload['available_tools'];
            if (!\is_array($raw)) {
                continue;
            }

            $names = [];
            foreach ($raw as $entry) {
                if (\is_string($entry) && '' !== $entry) {
                    $names[] = $entry;
                }
            }

            // Empty arrays are an authoritative zero-tools snapshot.
            $tools = $names;
            $rawEstimate = $event->payload['available_tools_schema_tokens_estimate'] ?? null;
            $estimate = \is_int($rawEstimate) ? $rawEstimate : null;
        }

        return [
            'tools' => $tools,
            'estimate' => $estimate,
        ];
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
}
