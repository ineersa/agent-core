<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;

/**
 * Builds bounded child-run progress summaries by scanning parent-scoped artifact events.
 *
 * Caches per (parentRunId, artifactId) keyed by child lastSeq so repeated poll ticks
 * avoid re-reading JSONL when the child state has not advanced.
 */
final class SubagentChildProgressSummaryBuilder
{
    private const int MAX_RECENT_TOOLS = 4;
    private const int MAX_ARG_VALUE_LEN = 72;
    private const int MAX_ASSISTANT_EXCERPT = 220;

    /** @var array<string, array{lastSeq: int, summary: SubagentChildProgressSummary}> */
    private array $cache = [];

    public function __construct(
        private readonly AgentChildRunEventStoreFactory $childEventStoreFactory,
    ) {
    }

    public function summarize(
        string $parentRunId,
        string $agentRunId,
        string $artifactId,
        RunState $childState,
        ?string $definitionModel = null,
    ): SubagentChildProgressSummary {
        $cacheKey = $parentRunId.'|'.$artifactId;
        $lastSeq = $childState->lastSeq;
        if (isset($this->cache[$cacheKey]) && $this->cache[$cacheKey]['lastSeq'] === $lastSeq) {
            return $this->cache[$cacheKey]['summary'];
        }

        $store = $this->childEventStoreFactory->create($parentRunId, $agentRunId, $artifactId);
        $events = $store->allFor($agentRunId);

        $summary = $this->scanEvents($events, $childState, $artifactId, $definitionModel);
        $this->cache[$cacheKey] = ['lastSeq' => $lastSeq, 'summary' => $summary];

        return $summary;
    }

    /**
     * @param list<RunEvent> $events
     */
    private function scanEvents(
        array $events,
        RunState $childState,
        string $artifactId,
        ?string $definitionModel,
    ): SubagentChildProgressSummary {
        $toolEnds = 0;
        $inputTokens = 0;
        $latestInputTokens = 0;
        $contextWindow = 0;
        $outputTokens = 0;
        $reasoningTokens = 0;
        $totalTokens = 0;
        $cost = 0.0;
        $hasCost = false;
        $model = $definitionModel;
        $provider = null;

        /** @var array<string, array{name: string, args: array<string, mixed>}> $pendingById */
        $pendingById = [];
        /** @var list<array{name: string, args: array<string, mixed>}> $completedTools */
        $completedTools = [];

        $assistantExcerpt = $this->lastAssistantExcerptFromState($childState);

        foreach ($events as $event) {
            $payload = $event->payload;
            if (RunEventTypeEnum::RunStarted->value === $event->type) {
                $inner = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
                $metadata = \is_array($inner['metadata'] ?? null) ? $inner['metadata'] : [];
                if (null === $model && \is_string($metadata['model'] ?? null) && '' !== $metadata['model']) {
                    $model = $metadata['model'];
                }
                if (\is_string($metadata['provider'] ?? null) && '' !== $metadata['provider']) {
                    $provider = $metadata['provider'];
                }
                if (isset($metadata['context_window']) && is_numeric($metadata['context_window'])) {
                    $resolved = (int) $metadata['context_window'];
                    if ($resolved > 0) {
                        $contextWindow = $resolved;
                    }
                }
                continue;
            }

            if (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
                $usage = \is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
                $turnInput = $this->intVal($usage['input_tokens'] ?? 0);
                $inputTokens += $turnInput;
                $latestInputTokens = $turnInput;
                $outputTokens += $this->intVal($usage['output_tokens'] ?? 0);
                $reasoningTokens += $this->intVal($usage['thinking_tokens'] ?? $usage['reasoning_tokens'] ?? 0);
                $totalTokens += $this->intVal($usage['total_tokens'] ?? 0);
                if (isset($usage['cost']) && is_numeric($usage['cost'])) {
                    $cost += (float) $usage['cost'];
                    $hasCost = true;
                }

                $assistantPayload = \is_array($payload['assistant_message'] ?? null) ? $payload['assistant_message'] : null;
                if (null !== $assistantPayload) {
                    $excerpt = $this->assistantExcerptFromPayload($assistantPayload);
                    if ('' !== $excerpt) {
                        $assistantExcerpt = $excerpt;
                    }
                    $toolCalls = \is_array($assistantPayload['tool_calls'] ?? null) ? $assistantPayload['tool_calls'] : [];
                    foreach ($toolCalls as $toolCall) {
                        if (!\is_array($toolCall)) {
                            continue;
                        }
                        $id = \is_string($toolCall['id'] ?? null) ? $toolCall['id'] : null;
                        $name = \is_string($toolCall['name'] ?? null) ? $toolCall['name'] : 'tool';
                        $args = $this->normalizeArgs($toolCall['arguments'] ?? $toolCall['args'] ?? []);
                        if (null !== $id) {
                            $pendingById[$id] = ['name' => $name, 'args' => $args];
                        }
                    }
                }
                continue;
            }

            if (RunEventTypeEnum::ToolExecutionEnd->value === $event->type) {
                ++$toolEnds;
                $toolCallId = \is_string($payload['tool_call_id'] ?? null) ? $payload['tool_call_id'] : null;
                $name = \is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : null;
                $args = [];
                if (null !== $toolCallId && isset($pendingById[$toolCallId])) {
                    $name = $pendingById[$toolCallId]['name'];
                    $args = $pendingById[$toolCallId]['args'];
                    unset($pendingById[$toolCallId]);
                }
                if (null === $name) {
                    $name = 'tool';
                }
                $completedTools[] = ['name' => $name, 'args' => $args];
                continue;
            }
        }

        if (0 === $totalTokens && ($inputTokens > 0 || $outputTokens > 0)) {
            $totalTokens = $inputTokens + $outputTokens + $reasoningTokens;
        }

        $recentLines = [];
        $slice = \array_slice($completedTools, -self::MAX_RECENT_TOOLS);
        foreach ($slice as $tool) {
            $recentLines[] = $this->formatToolLine($tool['name'], $tool['args']);
        }

        $activeLine = null;
        if ([] !== $pendingById) {
            $lastPending = array_values($pendingById);
            $last = $lastPending[\count($lastPending) - 1];
            $activeLine = $this->formatToolLine($last['name'], $last['args']);
        }

        $artifactPath = AgentArtifactPathsDTO::forArtifactId($artifactId)->artifactDir;

        return new SubagentChildProgressSummary(
            toolCount: $toolEnds,
            inputTokens: $inputTokens,
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
            totalTokens: $totalTokens,
            cost: $hasCost ? $cost : null,
            model: $model,
            provider: $provider,
            artifactPath: $artifactPath,
            assistantExcerpt: $assistantExcerpt,
            recentTools: $recentLines,
            activeToolLine: $activeLine,
        );
    }

    private function lastAssistantExcerptFromState(RunState $state): ?string
    {
        $messages = array_reverse($state->messages);
        foreach ($messages as $message) {
            if (!$message instanceof AgentMessage || 'assistant' !== $message->role) {
                continue;
            }
            $text = $this->textFromMessage($message);
            if ('' !== $text) {
                return $this->truncateLine($text, self::MAX_ASSISTANT_EXCERPT);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $assistantPayload
     */
    private function assistantExcerptFromPayload(array $assistantPayload): string
    {
        $msg = AgentMessage::fromPayload($assistantPayload);
        if (null === $msg || 'assistant' !== $msg->role) {
            return '';
        }

        return $this->truncateLine($this->textFromMessage($msg), self::MAX_ASSISTANT_EXCERPT);
    }

    private function textFromMessage(AgentMessage $message): string
    {
        $parts = [];
        foreach ($message->content as $part) {
            if (!\is_array($part)) {
                continue;
            }
            if ('text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArgs(mixed $raw): array
    {
        if (\is_array($raw)) {
            return $raw;
        }
        if (\is_string($raw) && '' !== $raw) {
            try {
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
                return [];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function formatToolLine(string $name, array $args): string
    {
        $pairs = $this->safeArgPairs($name, $args);
        if ([] === $pairs) {
            return $name;
        }

        return $name.': '.implode(', ', $pairs);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return list<string>
     */
    private function safeArgPairs(string $toolName, array $args): array
    {
        $keys = match ($toolName) {
            'read', 'write', 'edit' => ['path'],
            'bash', 'shell' => ['command', 'cmd'],
            'grep' => ['pattern', 'path'],
            'glob', 'find' => ['pattern', 'path'],
            default => ['path', 'command', 'cmd', 'query', 'pattern', 'file'],
        };

        $pairs = [];
        foreach ($keys as $key) {
            if (!isset($args[$key]) || !\is_scalar($args[$key])) {
                continue;
            }
            $value = (string) $args[$key];
            if ('' === $value) {
                continue;
            }
            $pairs[] = $key.'="'.$this->truncateLine($value, self::MAX_ARG_VALUE_LEN).'"';
            if (\count($pairs) >= 2) {
                break;
            }
        }

        return $pairs;
    }

    private function truncateLine(string $text, int $max): string
    {
        $normalized = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (mb_strlen($normalized) <= $max) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $max - 1).'…';
    }

    private function intVal(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
