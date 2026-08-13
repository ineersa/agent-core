<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedEventPayloadDTO;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Builds bounded child-run progress summaries by scanning parent-scoped artifact events.
 *
 * Caches per (parentRunId, artifactId) keyed by child lastSeq so repeated poll ticks
 * avoid re-reading JSONL when the child state has not advanced.
 */
final class SubagentChildProgressSummaryBuilder
{
    private const int MAX_RECENT_TOOLS = 4;

    /** @var array<string, array{lastSeq: int, summary: SubagentChildProgressSummary}> */
    private array $cache = [];

    public function __construct(
        private readonly AgentChildRunEventStoreFactory $childEventStoreFactory,
        private readonly DenormalizerInterface $denormalizer,
        private readonly SubagentChildToolProgressPresentationFormatter $presentationFormatter = new SubagentChildToolProgressPresentationFormatter(),
    ) {
    }

    public function fromDeferredProjection(
        DeferredChildRunLifecycleProjectionDTO $projection,
        string $artifactId,
    ): SubagentChildProgressSummary {
        $recentTools = $projection->recentTools;

        return new SubagentChildProgressSummary(
            model: $projection->model,
            reasoning: $projection->reasoning,
            toolCount: $projection->toolCount,
            llmStepCount: $projection->llmStepCount,
            inputTokens: $projection->inputTokens,
            latestInputTokens: $projection->latestInputTokens,
            contextWindow: $projection->contextWindow ?? 0,
            outputTokens: $projection->outputTokens,
            reasoningTokens: $projection->reasoningTokens,
            totalTokens: $projection->totalTokens,
            cost: $projection->cost,
            provider: $projection->provider,
            artifactPath: AgentArtifactPathsDTO::forArtifactId($artifactId)->artifactDir,
            assistantExcerpt: $projection->assistantExcerpt,
            recentTools: $recentTools,
            activeToolLine: $projection->activeToolLine,
        );
    }

    public function summarize(
        string $parentRunId,
        string $agentRunId,
        string $artifactId,
        RunState $childState,
        string $launchModel,
        string $launchReasoning,
    ): SubagentChildProgressSummary {
        $cacheKey = $parentRunId.'|'.$artifactId;
        $lastSeq = $childState->lastSeq;
        if (isset($this->cache[$cacheKey]) && $this->cache[$cacheKey]['lastSeq'] === $lastSeq) {
            return $this->cache[$cacheKey]['summary'];
        }

        $store = $this->childEventStoreFactory->create($parentRunId, $agentRunId, $artifactId);
        $events = $store->allFor($agentRunId);

        $summary = $this->scanEvents($events, $childState, $artifactId, $launchModel, $launchReasoning);
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
        string $launchModel,
        string $launchReasoning,
    ): SubagentChildProgressSummary {
        $toolEnds = 0;
        $llmStepCount = 0;
        $inputTokens = 0;
        $latestInputTokens = 0;
        $contextWindow = 0;
        $outputTokens = 0;
        $reasoningTokens = 0;
        $totalTokens = 0;
        $cost = 0.0;
        $hasCost = false;
        $model = trim($launchModel);
        $reasoning = trim($launchReasoning);
        if ('' === $model || '' === $reasoning) {
            throw new \InvalidArgumentException('Subagent progress scan requires non-empty launch model and reasoning.');
        }
        $provider = null;

        /** @var array<string, array{name: string, args: array<string, mixed>}> $pendingById */
        $pendingById = [];
        /** @var list<array{name: string, args: array<string, mixed>}> $completedTools */
        $completedTools = [];

        $assistantExcerpt = $this->lastAssistantExcerptFromState($childState);

        foreach ($events as $event) {
            $payload = $event->payload;
            if (RunEventTypeEnum::RunStarted->value === $event->type) {
                try {
                    $envelope = $this->denormalizer->denormalize($payload, RunStartedEventPayloadDTO::class);
                } catch (SerializerExceptionInterface|\TypeError|\ValueError|\InvalidArgumentException) {
                    // Malformed RunStarted: keep launch-seeded identity; continue scan (best-effort).
                    $envelope = null;
                }
                // RunStarted may confirm/update concrete identity; never clear to empty.
                if ($envelope instanceof RunStartedEventPayloadDTO) {
                    $metadata = $envelope->payload->metadata;
                    if (null !== $metadata->model && '' !== trim($metadata->model)) {
                        $model = trim($metadata->model);
                    }
                    if (null !== $metadata->reasoning && '' !== trim($metadata->reasoning)) {
                        $reasoning = trim($metadata->reasoning);
                    }
                    if (null !== $metadata->provider && '' !== $metadata->provider) {
                        $provider = $metadata->provider;
                    }
                    if (null !== $metadata->contextWindow && $metadata->contextWindow > 0) {
                        $contextWindow = $metadata->contextWindow;
                    }
                }
                continue;
            }

            if (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
                ++$llmStepCount;
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
                    $fullText = $this->presentationFormatter->assistantTextFromPayload($assistantPayload);
                    if ('' !== $fullText) {
                        $assistantExcerpt = $this->presentationFormatter->assistantExcerptFromText($fullText);
                    }
                    $toolCalls = \is_array($assistantPayload['tool_calls'] ?? null) ? $assistantPayload['tool_calls'] : [];
                    foreach ($toolCalls as $toolCall) {
                        if (!\is_array($toolCall)) {
                            continue;
                        }
                        $id = \is_string($toolCall['id'] ?? null) ? $toolCall['id'] : null;
                        $name = \is_string($toolCall['name'] ?? null) ? $toolCall['name'] : 'tool';
                        $args = $this->presentationFormatter->normalizeToolArguments($toolCall['arguments'] ?? $toolCall['args'] ?? []);
                        if (null !== $id) {
                            $pendingById[$id] = ['name' => $name, 'args' => $args];
                        }
                    }
                }
                continue;
            }

            if (RunEventTypeEnum::LlmStepFailed->value === $event->type) {
                ++$llmStepCount;
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
            $recentLines[] = $this->presentationFormatter->formatToolDisplayLine($tool['name'], $tool['args']);
        }

        $activeLine = null;
        if ([] !== $pendingById) {
            $lastPending = array_values($pendingById);
            $last = $lastPending[\count($lastPending) - 1];
            $activeLine = $this->presentationFormatter->formatToolDisplayLine($last['name'], $last['args']);
        }

        $artifactPath = AgentArtifactPathsDTO::forArtifactId($artifactId)->artifactDir;

        return new SubagentChildProgressSummary(
            model: $model,
            reasoning: $reasoning,
            toolCount: $toolEnds,
            llmStepCount: $llmStepCount,
            inputTokens: $inputTokens,
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
            totalTokens: $totalTokens,
            cost: $hasCost ? $cost : null,
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
            $text = $this->presentationFormatter->assistantTextFromMessage($message);
            if ('' !== $text) {
                return $this->presentationFormatter->assistantExcerptFromText($text);
            }
        }

        return null;
    }

    private function intVal(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
