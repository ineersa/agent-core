<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Export;

use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
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
        private ReplayEventPreparer $replayEventPreparer,
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

        $this->assertMessagePayloadsReplayWithoutLoss($events, $runId);

        try {
            $filtered = $this->historyReplayFilter->filter($events);
            $replayed = $this->runStateReducer->replay(RunState::queued($runId), $filtered);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(\sprintf('Cannot project model context for session %s: retained event replay failed (%s).', $runId, $exception->getMessage()), previous: $exception);
        }

        $toolsSnapshot = $this->latestAvailableToolsSnapshot($filtered, $runId);

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
                throw new \RuntimeException(\sprintf('Cannot project model context for session %s: malformed or incompatible canonical event at line %d.', $runId, $lineIndex + 1));
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

        $events = $this->replayEventPreparer->sortBySequence($events);
        $duplicateSequences = $this->replayEventPreparer->duplicateSequences($events);
        if ([] !== $duplicateSequences) {
            throw new \RuntimeException(\sprintf('Cannot project model context for session %s: duplicate event sequence(s): %s.', $runId, implode(', ', $duplicateSequences)));
        }

        return $events;
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return array{tools: list<string>|null, estimate: int|null}
     */
    private function latestAvailableToolsSnapshot(array $events, string $runId): array
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
                if (\array_key_exists('available_tools_schema_tokens_estimate', $event->payload)) {
                    throw new \RuntimeException(\sprintf('Cannot project model context for session %s: event seq %d has an available-tools estimate without available_tools.', $runId, $event->seq));
                }

                continue;
            }

            $raw = $event->payload['available_tools'];
            if (!\is_array($raw)) {
                throw new \RuntimeException(\sprintf('Cannot project model context for session %s: event seq %d has malformed available_tools.', $runId, $event->seq));
            }

            $names = [];
            foreach ($raw as $entry) {
                if (!\is_string($entry) || '' === $entry) {
                    throw new \RuntimeException(\sprintf('Cannot project model context for session %s: event seq %d has a malformed available_tools entry.', $runId, $event->seq));
                }
                $names[] = $entry;
            }

            $rawEstimate = $event->payload['available_tools_schema_tokens_estimate'] ?? null;
            if (null !== $rawEstimate && !\is_int($rawEstimate)) {
                throw new \RuntimeException(\sprintf('Cannot project model context for session %s: event seq %d has a malformed available_tools_schema_tokens_estimate.', $runId, $event->seq));
            }

            // Empty arrays are an authoritative zero-tools snapshot.
            $tools = $names;
            $estimate = $rawEstimate;
        }

        return [
            'tools' => $tools,
            'estimate' => $estimate,
        ];
    }

    /**
     * Rejects message payloads that RunStateReducer would otherwise skip or partially filter.
     *
     * @param list<RunEvent> $events
     */
    private function assertMessagePayloadsReplayWithoutLoss(array $events, string $runId): void
    {
        foreach ($events as $event) {
            $messageLists = [];

            if (RunEventTypeEnum::RunStarted->value === $event->type) {
                $innerPayload = $event->payload['payload'] ?? null;
                if (!\is_array($innerPayload) || !\array_key_exists('messages', $innerPayload)) {
                    $this->throwMalformedMessage($runId, $event->seq);
                }
                $messageLists[] = $innerPayload['messages'];
            } elseif (RunEventTypeEnum::ContextCompacted->value === $event->type) {
                if (!\array_key_exists('messages', $event->payload)) {
                    $this->throwMalformedMessage($runId, $event->seq);
                }
                $messageLists[] = $event->payload['messages'];
            } elseif (RunEventTypeEnum::AgentCommandApplied->value === $event->type
                && \in_array($event->payload['kind'] ?? null, ['steer', 'follow_up', 'append_message'], true)) {
                if (!\array_key_exists('message', $event->payload)) {
                    $this->throwMalformedMessage($runId, $event->seq);
                }
                $messageLists[] = [$event->payload['message']];
            } elseif (RunEventTypeEnum::AgentCommandApplied->value === $event->type
                && 'human_response' === ($event->payload['kind'] ?? null)
                && \array_key_exists('message', $event->payload)) {
                $messageLists[] = [$event->payload['message']];
            } elseif (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
                if (!\array_key_exists('assistant_message', $event->payload)) {
                    $this->throwMalformedMessage($runId, $event->seq);
                }
                $assistant = $event->payload['assistant_message'];
                if (!\is_array($assistant)) {
                    $this->throwMalformedMessage($runId, $event->seq);
                }
                if (\is_array($assistant['content'] ?? null)) {
                    $messageLists[] = [$assistant];
                } elseif ('assistant' !== ($assistant['role'] ?? null)
                    || (\array_key_exists('content', $assistant) && null !== $assistant['content'])) {
                    $this->throwMalformedMessage($runId, $event->seq);
                }
            }

            foreach ($messageLists as $rawMessages) {
                if (!\is_array($rawMessages)) {
                    $this->throwMalformedMessage($runId, $event->seq);
                }

                foreach ($rawMessages as $rawMessage) {
                    if (!\is_array($rawMessage)) {
                        $this->throwMalformedMessage($runId, $event->seq);
                    }

                    try {
                        $message = AgentMessage::fromPayload($rawMessage);
                    } catch (\InvalidArgumentException $exception) {
                        throw new \RuntimeException(\sprintf('Cannot project model context for session %s: event seq %d has a malformed message (%s).', $runId, $event->seq, $exception->getMessage()), previous: $exception);
                    }

                    $rawContent = $rawMessage['content'] ?? null;
                    if (null === $message
                        || !\is_array($rawContent)
                        || \count($message->content) !== \count($rawContent)) {
                        $this->throwMalformedMessage($runId, $event->seq);
                    }
                }
            }
        }
    }

    private function throwMalformedMessage(string $runId, int $seq): never
    {
        throw new \RuntimeException(\sprintf('Cannot project model context for session %s: event seq %d has a malformed message.', $runId, $seq));
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
            ];
        }

        return $latest;
    }
}
