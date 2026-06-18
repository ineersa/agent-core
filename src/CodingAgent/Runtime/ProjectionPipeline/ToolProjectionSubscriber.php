<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects tool-call and tool-execution events into ToolCall and
 * ToolResult transcript blocks.
 */
final readonly class ToolProjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Tool call streaming
            RuntimeEventTypeEnum::ToolCallStarted->value => 'onToolCallStarted',
            RuntimeEventTypeEnum::ToolCallArgumentsDelta->value => 'onToolCallArgumentsDelta',
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted->value => 'onToolCallArgumentsCompleted',
            // Tool execution
            RuntimeEventTypeEnum::ToolExecutionStarted->value => 'onToolExecutionStarted',
            RuntimeEventTypeEnum::ToolExecutionOutputDelta->value => 'onToolExecutionOutputDelta',
            RuntimeEventTypeEnum::ToolExecutionCompleted->value => 'onToolExecutionCompleted',
            RuntimeEventTypeEnum::ToolExecutionFailed->value => 'onToolExecutionFailed',
            RuntimeEventTypeEnum::ToolExecutionCancelled->value => 'onToolExecutionCancelled',
            // Orphan cleanup: remove ToolCall blocks whose tool_call_id
            // was never executed (common in parallel LLM responses where
            // the LLM emits multiple non-empty tool calls but the runtime
            // only accepts one).
            RuntimeEventTypeEnum::TurnStarted->value => 'onTurnStarted',
            RuntimeEventTypeEnum::RunCompleted->value => 'onRunCompleted',
            RuntimeEventTypeEnum::RunFailed->value => 'onRunFailed',
            RuntimeEventTypeEnum::RunCancelled->value => 'onRunCancelled',
        ];
    }

    // ── Tool call ────────────────────────────────────────────────────────────

    public function onToolCallStarted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $toolName = (string) ($p['tool_name'] ?? '');
        $blockId = 'tool_call_'.$toolCallId;

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: $toolName,
            meta: [
                'tool_call_id' => $toolCallId,
                'tool_name' => $toolName,
            ],
            streaming: true,
        ));
    }

    public function onToolCallArgumentsDelta(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        // Stream subscriber emits 'partial_json', not 'delta'.
        $delta = (string) ($p['partial_json'] ?? $p['delta'] ?? '');
        $blockId = 'tool_call_'.$toolCallId;
        $block = $state->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $state->updateBlock($blockId, $block->appendText($delta));
    }

    public function onToolCallArgumentsCompleted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $arguments = $p['arguments'] ?? [];
        $blockId = 'tool_call_'.$toolCallId;
        $block = $state->getBlock($blockId);

        // Suppress tool-call blocks with empty arguments (common in parallel
        // tool-call responses where the LLM emits placeholder calls alongside
        // the single valid one).  If a real tool_execution.started for this
        // id arrives later, it creates its own ToolResult block independently.
        if ([] === $arguments) {
            $state->removeBlock($blockId);

            return;
        }

        $argumentsText = $state->argumentsToText($arguments);

        if (null === $block) {
            // Tool call block was never started — create a completed snapshot.
            $toolName = (string) ($p['tool_name'] ?? '');
            $text = '' !== $toolName ? $toolName.$argumentsText : $argumentsText;

            $state->addBlock(new TranscriptBlock(
                id: $blockId,
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: $event->runId(),
                seq: $state->nextSeq(),
                text: $text,
                meta: [
                    'tool_call_id' => $toolCallId,
                    'tool_name' => $toolName,
                    'arguments' => $arguments,
                ],
                streaming: false,
            ));

            return;
        }

        // Replace the accumulated streaming text (raw JSON deltas) with
        // the canonical formatted arguments.  This avoids displaying
        // duplicated/raw JSON like bash({"cmd":"ls"})(command: "ls").
        $toolName = (string) ($block->meta['tool_name'] ?? $p['tool_name'] ?? '');
        $text = '' !== $toolName ? $toolName.$argumentsText : $argumentsText;
        $meta = $block->meta;
        $meta['arguments'] = $arguments;

        $state->updateBlock($blockId, $block->with(
            text: $text,
            streaming: false,
            meta: $meta,
        ));
    }

    // ── Tool execution ───────────────────────────────────────────────────────

    public function onToolExecutionStarted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $toolName = (string) ($p['tool_name'] ?? '');
        $blockId = 'tool_result_'.$toolCallId;

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: 'Running…',
            meta: [
                'tool_call_id' => $toolCallId,
                'tool_name' => $toolName,
            ],
            streaming: true,
        ));

        // Remove still-streaming phantom ToolCall blocks now that a
        // concrete tool is known to be executing.  This handles the case
        // where the LLM emitted ToolCallStart for a tool that never
        // appeared in ToolCallComplete (e.g. «read...» visible alongside
        // the actual completed «bash(command: ...)»).
        //
        // Only streaming blocks are removed — finalized (non-streaming)
        // blocks that await execution are safe to keep; the full orphan
        // sweep runs at TurnStarted after all tools in the batch have
        // executed or been dropped.
        $state->removePhantomStreamingToolCallBlocks();
    }

    public function onToolExecutionOutputDelta(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $delta = (string) ($p['delta'] ?? '');
        $blockId = 'tool_result_'.$toolCallId;
        $block = $state->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $state->updateBlock($blockId, $block->appendText($delta));
    }

    public function onToolExecutionCompleted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $result = (string) ($p['result'] ?? '');
        $durationMs = isset($p['duration_ms']) ? (int) $p['duration_ms'] : null;
        $blockId = 'tool_result_'.$toolCallId;

        // On replay the canonical tool_execution_end often has no result
        // text, so the ToolResult block created by tool_execution_start
        // would remain stuck at "Running…".  Resolve the tool name from
        // the existing block's meta and use it as a fallback label so
        // the user sees e.g. "read completed" instead of "Running…".
        if ('' === $result) {
            $existing = $state->getBlock($blockId);
            if (null !== $existing && 'Running…' === $existing->text) {
                $toolName = (string) ($existing->meta['tool_name'] ?? '');
                $result = '' !== $toolName ? $toolName.' completed' : 'Completed';
            }
        }

        $meta = [
            'tool_call_id' => $toolCallId,
            'is_error' => false,
        ];
        if (null !== $durationMs) {
            $meta['duration_ms'] = $durationMs;
        }
        if ('' !== $result) {
            $meta['result'] = $result;
        }

        $state->upsertToolResultBlock($blockId, $event->runId(), $result, $meta, false);

        $this->maybeProjectOutputCapNotice($event, $toolCallId);
    }

    public function onToolExecutionFailed(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $result = (string) ($p['result'] ?? '');
        $blockId = 'tool_result_'.$toolCallId;

        $meta = [
            'tool_call_id' => $toolCallId,
            'is_error' => true,
        ];
        if ('' !== $result) {
            $meta['result'] = $result;
        }

        $state->upsertToolResultBlock($blockId, $event->runId(), $result, $meta, false);

        $this->maybeProjectOutputCapNotice($event, $toolCallId);
    }

    public function onToolExecutionCancelled(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $blockId = 'tool_result_'.$toolCallId;
        $timedOut = (bool) ($p['timed_out'] ?? false);

        $meta = [
            'tool_call_id' => $toolCallId,
            'cancelled' => true,
            'timed_out' => $timedOut,
            'is_error' => true,
        ];

        $text = $timedOut ? 'Timed out' : 'Cancelled';

        $state->upsertToolResultBlock($blockId, $event->runId(), $text, $meta, false);
    }

    // ── Orphan cleanup ───────────────────────────────────────────────────────

    /**
     * Remove orphaned ToolCall blocks at the start of a new turn.
     *
     * After tool execution completes for a turn, any remaining ToolCall
     * blocks without a matching ToolResult represent calls that the LLM
     * emitted but the runtime never executed (e.g. parallel placeholder
     * calls).  Cleaning them at TurnStarted ensures they are gone before
     * the next turn's content appears.
     */
    public function onTurnStarted(TranscriptProjectionEvent $event): void
    {
        $event->state->removeOrphanedToolCallBlocks();
    }

    public function onRunCompleted(TranscriptProjectionEvent $event): void
    {
        $event->state->removeOrphanedToolCallBlocks();
    }

    public function onRunFailed(TranscriptProjectionEvent $event): void
    {
        $event->state->removeOrphanedToolCallBlocks();
    }

    public function onRunCancelled(TranscriptProjectionEvent $event): void
    {
        $event->state->removeOrphanedToolCallBlocks();
    }

    // ── Output cap notice projection ─────────────────────────────────────────

    /**
     * When a tool execution result was capped (output_capped=true), project a
     * System notice block alongside the ToolResult block so the transcript
     * shows both the tool outcome and a visible cap notice.
     *
     * The block ID is stable (output_cap_<tool_call_id>) so replay from
     * events.jsonl produces exactly one notice block per capped tool call.
     */
    private function maybeProjectOutputCapNotice(TranscriptProjectionEvent $event, string $toolCallId): void
    {
        $p = $event->payload();
        $state = $event->state;

        $capped = (bool) ($p['output_capped'] ?? $p['output_cap_notice'] ?? false);
        if (!$capped) {
            return;
        }

        $blockId = 'output_cap_'.$toolCallId;

        // Deduplicate: skip if already projected (e.g. on replay when
        // the live path already added this block).
        if (null !== $state->getBlock($blockId)) {
            return;
        }

        // Build the user-visible notice text.
        $cap = $p['output_cap_limit'] ?? null;
        $charCount = $p['output_cap_char_count'] ?? null;
        $savedPath = $p['output_cap_saved_path'] ?? null;

        // Use formatted numbers with thousands separators.
        $capFmt = null !== $cap ? number_format($cap) : null;
        $charCountFmt = null !== $charCount ? number_format($charCount) : null;

        $parts = ['Output was capped'];
        if (null !== $capFmt && null !== $charCountFmt) {
            $parts[] = \sprintf('%s visible chars of %s', $capFmt, $charCountFmt);
        } elseif (null !== $capFmt) {
            $parts[] = \sprintf('%s character limit', $capFmt);
        } elseif (null !== $charCountFmt) {
            $parts[] = \sprintf('%s total characters', $charCountFmt);
        }
        if (null !== $savedPath) {
            $parts[] = 'full output saved for audit';
        }

        // Tell the user that the model received follow-up guidance.
        $text = implode(' — ', $parts).".\nModel was instructed to continue with targeted reads/search, not to rerun the tool or read the saved file wholesale.";

        $meta = [
            'tool_call_id' => $toolCallId,
            'notice_type' => 'output_cap',
            'output_cap_limit' => $cap,
            'output_cap_char_count' => $charCount,
            'output_cap_saved_path' => $savedPath,
        ];

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::System,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: $text,
            meta: $meta,
            streaming: false,
        ));
    }
}
