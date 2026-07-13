<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;

/**
 * Incrementally reduces committed child event summaries into a compact lifecycle projection.
 */
final class DeferredSingleSubagentChildEventProjector
{
    private const int MAX_RECENT_TOOLS = 4;
    private const int MAX_ARG_VALUE_LEN = 72;
    private const int MAX_ASSISTANT_EXCERPT = 220;

    /**
     * @param list<AfterTurnCommitEventSummary> $summaries Ordered, contiguous, seq > cursor
     */
    public function apply(
        DeferredSingleSubagentChildLifecycleProjectionDTO $current,
        array $summaries,
        ?string $definitionModel,
        ?string $artifactPath,
    ): DeferredSingleSubagentChildLifecycleProjectionDTO {
        $status = $current->childStatus;
        $turnNo = $current->childTurnNo;
        $lastSeq = $current->lastCommittedSeq;
        $errorMessage = $current->errorMessage;
        $assistantExcerpt = $current->assistantExcerpt;
        $toolCount = $current->toolCount;
        $inputTokens = $current->inputTokens;
        $latestInputTokens = $current->latestInputTokens;
        $contextWindow = $current->contextWindow;
        $outputTokens = $current->outputTokens;
        $reasoningTokens = $current->reasoningTokens;
        $totalTokens = $current->totalTokens;
        $cost = $current->cost;
        $hasCost = null !== $cost && $cost > 0.0;
        $model = $current->model ?? $definitionModel;
        $provider = $current->provider;
        $recentTools = $current->recentTools;
        $activeToolLine = $current->activeToolLine;

        /** @var array<string, array{name: string, args: array<string, mixed>}> $pendingById */
        $pendingById = $current->pendingToolCalls;

        foreach ($summaries as $summary) {
            $lastSeq = $summary->seq;
            $payload = $summary->payload;
            $type = $summary->type;

            if (RunEventTypeEnum::TurnAdvanced->value === $type) {
                if (isset($payload['turn_no']) && is_numeric($payload['turn_no'])) {
                    $turnNo = (int) $payload['turn_no'];
                }
                continue;
            }

            if (RunEventTypeEnum::RunStarted->value === $type) {
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
                $status = RunStatus::Running;
                continue;
            }

            if (RunEventTypeEnum::LlmStepCompleted->value === $type) {
                $usage = \is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
                $turnInput = $this->intVal($usage['input_tokens'] ?? 0);
                $inputTokens += $turnInput;
                $latestInputTokens = $turnInput;
                $outputTokens += $this->intVal($usage['output_tokens'] ?? 0);
                $reasoningTokens += $this->intVal($usage['thinking_tokens'] ?? $usage['reasoning_tokens'] ?? 0);
                $totalTokens += $this->intVal($usage['total_tokens'] ?? 0);
                if (isset($usage['cost']) && is_numeric($usage['cost'])) {
                    $cost = ($cost ?? 0.0) + (float) $usage['cost'];
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
                $status = RunStatus::Running;
                continue;
            }

            if (RunEventTypeEnum::ToolExecutionEnd->value === $type) {
                ++$toolCount;
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
                $recentTools[] = $this->formatToolLine($name, $args);
                if (\count($recentTools) > self::MAX_RECENT_TOOLS) {
                    $recentTools = \array_slice($recentTools, -self::MAX_RECENT_TOOLS);
                }
                $status = RunStatus::Running;
                continue;
            }

            if (RunEventTypeEnum::LlmStepFailed->value === $type) {
                $error = \is_array($payload['error'] ?? null) ? $payload['error'] : null;
                $errorMessage = \is_string($error['user_message'] ?? null)
                    ? $error['user_message']
                    : (\is_string($error['message'] ?? null) ? $error['message'] : 'LLM worker failed.');
                $status = RunStatus::Failed;
                continue;
            }

            if (RunEventTypeEnum::WaitingHuman->value === $type) {
                $status = RunStatus::WaitingHuman;
                continue;
            }

            if (RunEventTypeEnum::AgentEnd->value === $type) {
                $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : null;
                $status = match ($reason) {
                    'cancelled' => RunStatus::Cancelled,
                    default => RunStatus::Completed,
                };
                continue;
            }
        }

        if (0 === $totalTokens && ($inputTokens > 0 || $outputTokens > 0)) {
            $totalTokens = $inputTokens + $outputTokens + $reasoningTokens;
        }

        $activeToolLine = null;
        if ([] !== $pendingById) {
            $lastPending = array_values($pendingById);
            $last = $lastPending[\count($lastPending) - 1];
            $activeToolLine = $this->formatToolLine($last['name'], $last['args']);
        }

        return new DeferredSingleSubagentChildLifecycleProjectionDTO(
            childStatus: $status,
            childTurnNo: $turnNo,
            lastCommittedSeq: $lastSeq,
            errorMessage: $errorMessage,
            assistantExcerpt: $assistantExcerpt,
            toolCount: $toolCount,
            inputTokens: $inputTokens,
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
            totalTokens: $totalTokens,
            cost: $hasCost ? $cost : null,
            model: $model,
            provider: $provider,
            recentTools: $recentTools,
            activeToolLine: $activeToolLine,
            pendingToolCalls: $pendingById,
        );
    }

    /** @param array<string, mixed> $assistantPayload */
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

    /** @return array<string, mixed> */
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
            }
        }

        return [];
    }

    /** @param array<string, mixed> $args */
    private function formatToolLine(string $name, array $args): string
    {
        if ([] === $args) {
            return $name;
        }
        $pairs = [];
        foreach ($args as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            $pairs[] = $key.'='.$this->truncateArgValue($value);
        }

        return $name.': '.implode(', ', $pairs);
    }

    private function truncateArgValue(mixed $value): string
    {
        $encoded = json_encode($value, \JSON_UNESCAPED_UNICODE);
        $text = \is_scalar($value) ? (string) $value : (false !== $encoded ? $encoded : '');

        return $this->truncateLine($text, self::MAX_ARG_VALUE_LEN);
    }

    private function truncateLine(string $text, int $max): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (\strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 3).'...';
    }

    private function intVal(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
