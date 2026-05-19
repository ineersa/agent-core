<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\AgentCore\Contract\Hook\LlmStreamObserverInterface;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;

/**
 * Maps Symfony AI streaming deltas into transient RuntimeEvent values.
 *
 * Implements the AgentCore-side LlmStreamObserverInterface so that
 * LlmPlatformAdapter can call it during consumeStream() without
 * knowing about CodingAgent types. All mapping logic lives here,
 * inside the CodingAgent runtime.
 *
 * Deltas are mapped to RuntimeEventTypeEnum constant values:
 *
 *   ThinkingStart          → assistant.thinking_started
 *   ThinkingDelta          → assistant.thinking_delta
 *   ThinkingComplete       → assistant.thinking_completed
 *   ThinkingSignature      → (silently skipped — embedded in final block)
 *
 *   TextDelta (first)      → assistant.text_started
 *   TextDelta (subsequent) → assistant.text_delta
 *
 *   ToolCallStart          → tool_call.started
 *   ToolInputDelta         → tool_call.arguments_delta
 *   ToolCallComplete       → tool_call.arguments_completed
 *
 *   onStreamStart          → assistant.message_started
 *
 * Deliberate omissions:
 *   - assistant.message_completed is NOT emitted (durable
 *     RuntimeEventMapper handles that from llm_step_completed).
 *   - Unknown delta types (BinaryDelta, ChoiceDelta, MetadataDelta)
 *     are silently ignored.
 *
 * Block IDs are derived from available delta metadata where possible
 * (e.g., tool call ID), or from stable composite keys (runId_step)
 * for text/thinking blocks.
 *
 * Seq is forced to 0 for all transient events. The consumer
 * (RuntimeEventPoller) treats seq=0 as a transient marker and does
 * not use it for deduplication.
 */
final class RuntimeEventStreamObserver implements LlmStreamObserverInterface
{
    private bool $textStarted = false;
    private bool $thinkingStarted = false;

    public function __construct(
        private readonly RuntimeEventSinkInterface $sink,
    ) {
    }

    public function onStreamStart(string $runId, ?string $stepId): void
    {
        $this->textStarted = false;
        $this->thinkingStarted = false;

        $this->emit($runId, $stepId, RuntimeEventTypeEnum::AssistantMessageStarted, []);
    }

    public function onDelta(string $runId, ?string $stepId, DeltaInterface $delta): void
    {
        match (true) {
            $delta instanceof TextDelta => $this->handleTextDelta($runId, $stepId, $delta),
            $delta instanceof ThinkingStart => $this->handleThinkingStart($runId, $stepId),
            $delta instanceof ThinkingDelta => $this->handleThinkingDelta($runId, $stepId, $delta),
            $delta instanceof ThinkingSignature => null, // Signature is embedded in final thinking block — skip transient event
            $delta instanceof ThinkingComplete => $this->handleThinkingComplete($runId, $stepId, $delta),
            $delta instanceof ToolCallStart => $this->handleToolCallStart($runId, $stepId, $delta),
            $delta instanceof ToolInputDelta => $this->handleToolInputDelta($runId, $stepId, $delta),
            $delta instanceof ToolCallComplete => $this->handleToolCallComplete($runId, $stepId, $delta),
            default => null, // BinaryDelta, ChoiceDelta, MetadataDelta — silently ignore
        };
    }

    public function onStreamEnd(string $runId, ?string $stepId): void
    {
        // No explicit event: the durable path (llm_step_completed →
        // RuntimeEventMapper → assistant.message_completed) handles
        // finalization.  Transient consumers treat stream-end as an
        // implicit finalization of any in-progress text/thinking blocks.
    }

    public function onStreamError(string $runId, ?string $stepId, \Throwable $error): void
    {
        // Error events flow through the durable path (llm_step_failed).
        // No transient event needed here.
    }

    private function handleTextDelta(string $runId, ?string $stepId, TextDelta $delta): void
    {
        if (!$this->textStarted) {
            $this->textStarted = true;
            $this->emit(
                $runId, $stepId,
                RuntimeEventTypeEnum::AssistantTextStarted,
                ['text' => $delta->getText()],
                $this->blockId($runId, $stepId, 'text'),
            );
        } else {
            $this->emit(
                $runId, $stepId,
                RuntimeEventTypeEnum::AssistantTextDelta,
                ['text' => $delta->getText()],
                $this->blockId($runId, $stepId, 'text'),
            );
        }
    }

    private function handleThinkingStart(string $runId, ?string $stepId): void
    {
        $this->thinkingStarted = true;
        $this->emit(
            $runId, $stepId,
            RuntimeEventTypeEnum::AssistantThinkingStarted,
            [],
            $this->blockId($runId, $stepId, 'thinking'),
        );
    }

    private function handleThinkingDelta(string $runId, ?string $stepId, ThinkingDelta $delta): void
    {
        if (!$this->thinkingStarted) {
            // ThinkingDelta without prior ThinkingStart — emit start implicitly
            $this->thinkingStarted = true;
            $this->emit(
                $runId, $stepId,
                RuntimeEventTypeEnum::AssistantThinkingStarted,
                [],
                $this->blockId($runId, $stepId, 'thinking'),
            );
        }

        $this->emit(
            $runId, $stepId,
            RuntimeEventTypeEnum::AssistantThinkingDelta,
            ['thinking' => $delta->getThinking()],
            $this->blockId($runId, $stepId, 'thinking'),
        );
    }

    private function handleThinkingComplete(string $runId, ?string $stepId, ThinkingComplete $delta): void
    {
        $this->emit(
            $runId, $stepId,
            RuntimeEventTypeEnum::AssistantThinkingCompleted,
            [
                'thinking' => $delta->getThinking(),
                'signature' => $delta->getSignature(),
            ],
            $this->blockId($runId, $stepId, 'thinking'),
        );
    }

    private function handleToolCallStart(string $runId, ?string $stepId, ToolCallStart $delta): void
    {
        $toolId = $delta->getId();

        $this->emit(
            $runId, $stepId,
            RuntimeEventTypeEnum::ToolCallStarted,
            [
                'tool_call_id' => $toolId,
                'tool_name' => $delta->getName(),
            ],
            $this->toolBlockId($toolId),
        );
    }

    private function handleToolInputDelta(string $runId, ?string $stepId, ToolInputDelta $delta): void
    {
        $toolId = $delta->getId();

        $this->emit(
            $runId, $stepId,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta,
            [
                'tool_call_id' => $toolId,
                'tool_name' => $delta->getName(),
                'partial_json' => $delta->getPartialJson(),
            ],
            $this->toolBlockId($toolId),
        );
    }

    private function handleToolCallComplete(string $runId, ?string $stepId, ToolCallComplete $delta): void
    {
        $toolCalls = $delta->getToolCalls();

        foreach ($toolCalls as $toolCall) {
            $toolId = $toolCall->getId();

            $this->emit(
                $runId, $stepId,
                RuntimeEventTypeEnum::ToolCallArgumentsCompleted,
                [
                    'tool_call_id' => $toolId,
                    'tool_name' => $toolCall->getName(),
                    'arguments' => $toolCall->getArguments(),
                ],
                $this->toolBlockId($toolId),
            );
        }
    }

    /**
     * Emit a transient runtime event through the sink.
     *
     * @param array<string, mixed> $payload
     */
    private function emit(
        string $runId,
        ?string $stepId,
        RuntimeEventTypeEnum $type,
        array $payload = [],
        ?string $blockId = null,
    ): void {
        $merged = $payload;

        if (null !== $stepId) {
            $merged['step_id'] = $stepId;
        }

        if (null !== $blockId) {
            $merged['block_id'] = $blockId;
        }

        $this->sink->emit(new RuntimeEvent(
            type: $type->value,
            runId: $runId,
            seq: 0, // Transient marker — RuntimeEventPoller treats seq=0 as non-dedup
            payload: $merged,
        ));
    }

    /**
     * Build a stable block ID for text and thinking blocks.
     */
    private function blockId(string $runId, ?string $stepId, string $kind): string
    {
        if (null !== $stepId) {
            return \sprintf('%s_%s_%s', $runId, $stepId, $kind);
        }

        return \sprintf('%s_%s', $runId, $kind);
    }

    /**
     * Build a stable block ID for a tool call.
     */
    private function toolBlockId(string $toolCallId): string
    {
        return \sprintf('tool_call_%s', $toolCallId);
    }
}
