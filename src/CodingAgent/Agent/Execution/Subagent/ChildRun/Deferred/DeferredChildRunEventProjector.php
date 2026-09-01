<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildToolProgressPresentationFormatter;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Incrementally reduces committed child event summaries into a compact lifecycle projection.
 */
final class DeferredChildRunEventProjector
{
    private const int MAX_RECENT_TOOLS = 4;

    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly ToolExecutionEndPayloadCodec $toolExecutionEndPayloadCodec,
        private readonly SubagentChildToolProgressPresentationFormatter $presentationFormatter = new SubagentChildToolProgressPresentationFormatter(),
    ) {
    }

    /**
     * @param list<AfterTurnCommitEventSummary> $summaries Ordered, contiguous, seq > cursor
     */
    public function apply(
        DeferredChildRunLifecycleProjectionDTO $current,
        array $summaries,
        ?RunStatus $committedStatus,
        int $committedTurnNo,
    ): DeferredChildRunLifecycleProjectionDTO {
        $status = $current->childStatus;
        $turnNo = $current->childTurnNo;
        $lastSeq = $current->lastCommittedSeq;
        $errorMessage = $current->errorMessage;
        $assistantResultText = $current->assistantResultText;
        $assistantExcerpt = $current->assistantExcerpt;
        $toolCount = $current->toolCount;
        $llmStepCount = $current->llmStepCount;
        $inputTokens = $current->inputTokens;
        $latestInputTokens = $current->latestInputTokens;
        $contextWindow = $current->contextWindow;
        $outputTokens = $current->outputTokens;
        $reasoningTokens = $current->reasoningTokens;
        $totalTokens = $current->totalTokens;
        $cost = $current->cost;
        // Identity is always seeded from durable launch model/reasoning before RunStarted.
        $model = $current->model;
        $reasoning = $current->reasoning;
        $recentTools = $current->recentTools;
        $activeToolLine = $current->activeToolLine;

        /** @var array<string, DeferredPendingToolCallRowDTO> $pendingById */
        $pendingById = $current->pendingToolCalls;

        foreach ($summaries as $summary) {
            $lastSeq = $summary->seq;
            $payload = $summary->payload;
            $type = $summary->type;

            if (RunEventTypeEnum::TurnAdvanced->value === $type) {
                if (isset($payload['turn_no']) && is_numeric($payload['turn_no'])) {
                    $turnNo = (int) $payload['turn_no'];
                }
                $status = RunStatus::Running;
                continue;
            }

            if (RunEventTypeEnum::RunStarted->value === $type) {
                // DTO constructor already trims/requires nonblank model (and child reasoning).
                $metadata = $this->denormalizer->denormalize($payload, RunStartedMetadataDTO::class);
                $model = $metadata->model;
                $reasoning = $metadata->reasoning ?? $reasoning;
                $contextWindow = $metadata->contextWindow ?? $contextWindow;
                $status = RunStatus::Running;
                continue;
            }

            if (RunEventTypeEnum::LlmStepCompleted->value === $type) {
                ++$llmStepCount;
                $errorMessage = null;
                $usage = \is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
                $turnInput = $this->intVal($usage['input_tokens'] ?? 0);
                $inputTokens += $turnInput;
                $latestInputTokens = $turnInput;
                $outputTokens += $this->intVal($usage['output_tokens'] ?? 0);
                $reasoningTokens += $this->intVal($usage['thinking_tokens'] ?? $usage['reasoning_tokens'] ?? 0);
                $totalTokens += $this->intVal($usage['total_tokens'] ?? 0);
                if (isset($usage['cost']) && is_numeric($usage['cost'])) {
                    $nextCost = ($cost ?? 0.0) + (float) $usage['cost'];
                    $cost = $nextCost > 0.0 ? $nextCost : null;
                }

                $assistantPayload = \is_array($payload['assistant_message'] ?? null) ? $payload['assistant_message'] : null;
                if (null !== $assistantPayload) {
                    $resultText = $this->presentationFormatter->assistantResultTextFromPayload($assistantPayload);
                    if ('' !== $resultText) {
                        $assistantResultText = $resultText;
                        $assistantExcerpt = $this->presentationFormatter->assistantExcerptFromText($resultText);
                    }
                    $fullText = $this->presentationFormatter->assistantTextFromPayload($assistantPayload);
                    if ('' !== $fullText && '' === $assistantExcerpt) {
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
                        $displayLine = $this->presentationFormatter->formatToolDisplayLine($name, $args);
                        if (null !== $id) {
                            $pendingById[$id] = new DeferredPendingToolCallRowDTO(name: $name, displayLine: $displayLine);
                        }
                    }
                }
                $status = RunStatus::Running;
                continue;
            }

            if (RunEventTypeEnum::ToolExecutionEnd->value === $type) {
                ++$toolCount;
                $typedResult = $this->toolExecutionEndPayloadCodec->fromEventPayload($payload);
                $toolCallId = $typedResult->toolCallId;
                $displayLine = null;
                if (isset($pendingById[$toolCallId])) {
                    $displayLine = $pendingById[$toolCallId]->displayLine;
                    unset($pendingById[$toolCallId]);
                }
                if (null === $displayLine) {
                    $name = \is_string($typedResult->result['tool_name'] ?? null)
                        ? $typedResult->result['tool_name']
                        : 'tool';
                    $displayLine = $this->presentationFormatter->formatToolDisplayLine($name, []);
                }
                $recentTools[] = $displayLine;
                if (\count($recentTools) > self::MAX_RECENT_TOOLS) {
                    $recentTools = \array_slice($recentTools, -self::MAX_RECENT_TOOLS);
                }
                $status = RunStatus::Running;
                continue;
            }

            if (RunEventTypeEnum::LlmStepFailed->value === $type) {
                ++$llmStepCount;
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
                    'failed' => RunStatus::Failed,
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
            $activeToolLine = $last->displayLine;
        }

        if (null !== $committedStatus) {
            $status = $committedStatus;
            $turnNo = $committedTurnNo;
        }

        return new DeferredChildRunLifecycleProjectionDTO(
            childStatus: $status,
            childTurnNo: $turnNo,
            lastCommittedSeq: $lastSeq,
            model: $model,
            reasoning: $reasoning,
            errorMessage: $errorMessage,
            assistantResultText: $assistantResultText,
            assistantExcerpt: $assistantExcerpt,
            toolCount: $toolCount,
            llmStepCount: $llmStepCount,
            inputTokens: $inputTokens,
            latestInputTokens: $latestInputTokens,
            contextWindow: $contextWindow,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
            totalTokens: $totalTokens,
            cost: $cost,
            recentTools: $recentTools,
            activeToolLine: $activeToolLine,
            pendingToolCalls: $pendingById,
        );
    }

    private function intVal(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
