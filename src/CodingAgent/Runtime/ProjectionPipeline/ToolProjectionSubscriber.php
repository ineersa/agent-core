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
            // Model-facing input projection: update ToolResult blocks and
            // create System blocks with the exact text the model saw after
            // transform hooks — for both tool-role and generated user-role
            // messages. Handled on all LLM outcomes (completed, failed,
            // aborted) so the user sees exact model-facing content even
            // when the LLM request fails or is interrupted.
            RuntimeEventTypeEnum::AssistantMessageCompleted->value => 'onModelInputMessages',
            RuntimeEventTypeEnum::AssistantMessageFailed->value => 'onModelInputMessages',
            RuntimeEventTypeEnum::TurnCancelled->value => 'onTurnCancelledWithModelInputs',
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

    // ── Model-facing text projection ──────────────────────────────────────────

    /**
     * Handle model_input_messages from any LLM outcome event (completed,
     * failed, aborted).
     *
     * For tool-role messages: update existing ToolResult blocks with the
     * exact text the model saw after transform hooks (capped, denied, or
     * otherwise transformed).
     *
     * For generated user-role messages: create a System transcript block
     * with the exact generated text (e.g. image placeholders from
     * AgentMessageConverter).
     *
     * Duplicate updates (same tool_call_id + text hash, or same source +
     * text hash for user role) are skipped to avoid accumulating duplicate
     * blocks on replay.
     */
    public function onModelInputMessages(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;

        $modelInputMessages = $p['model_input_messages'] ?? [];
        if (!\is_array($modelInputMessages) || [] === $modelInputMessages) {
            return;
        }

        foreach ($modelInputMessages as $input) {
            if (!\is_array($input)) {
                continue;
            }

            $role = (string) ($input['role'] ?? 'tool');
            $text = (string) ($input['text'] ?? '');

            if ('' === $text) {
                continue;
            }

            if ('tool' === $role) {
                $this->projectToolModelInput($event, $input);
            } elseif ('user' === $role) {
                $this->projectUserGeneratedInput($event, $input);
            }
        }
    }

    /**
     * Handle turn.cancelled events with model_input_messages.
     *
     * When an LLM step is aborted (e.g. user cancellation during streaming)
     * and the abort path carried model_input_messages, project them here.
     */
    public function onTurnCancelledWithModelInputs(TranscriptProjectionEvent $event): void
    {
        $this->onModelInputMessages($event);
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

    /**
     * Project a tool-role model input by updating the matching ToolResult block.
     *
     * @param array<string, mixed> $input
     */
    private function projectToolModelInput(TranscriptProjectionEvent $event, array $input): void
    {
        $state = $event->state;

        $toolCallId = (string) ($input['tool_call_id'] ?? '');
        $toolName = (string) ($input['tool_name'] ?? '');
        $text = (string) ($input['text'] ?? '');
        $metadata = (array) ($input['metadata'] ?? []);

        if ('' === $toolCallId || '' === $text) {
            return;
        }

        $blockId = 'tool_result_'.$toolCallId;
        $existing = $state->getBlock($blockId);

        if (null === $existing) {
            // No matching ToolResult block yet (unusual — likely a
            // stale/future reference).  Skip to avoid orphan blocks.
            return;
        }

        // Content-hash deduplication: if the tool result already
        // carries the same model-facing text, skip to avoid
        // redundant updates on replay.
        $textHash = hash('sha256', $text);
        if (($existing->meta['model_input_sha256'] ?? '') === $textHash) {
            return;
        }

        // Build metadata for the updated block.
        $meta = $existing->meta;
        $meta['model_input_exact'] = true;
        $meta['model_input_sha256'] = $textHash;
        $meta['tool_call_id'] = $toolCallId;
        $meta['tool_name'] = $toolName;

        // Detect output cap notices for warning styling using strict
        // starts-with check on the canonical notice format.
        $isCapNotice = str_starts_with(ltrim($text), '[Output capped to');
        if ($isCapNotice) {
            $meta['notice_type'] = 'output_cap';
            if (isset($metadata['output_cap_limit'])) {
                $meta['output_cap_limit'] = $metadata['output_cap_limit'];
            }
        }

        // Preserve existing result for potential TUI detail views.
        if ('' !== ($existing->meta['result'] ?? '')) {
            $meta['raw_result'] = $existing->meta['result'];
        }

        // ── Visible text policy ──
        //
        // For cap-notice model inputs: update the visible ToolResult text
        // to the exact model-facing cap notice so the user sees what the
        // model saw.
        //
        // For ALL other model inputs (normal uncapped tool results where
        // the model-facing text is raw provider JSON, SafeGuard denial
        // JSON, etc.): keep the existing human-readable tool output and
        // store the exact model-facing text only in metadata.  Raw JSON
        // must never be the visible ToolResult text.
        //
        // The exact model-facing text is always available in metadata
        // for future TUI detail views or SafeGuard-specific blocks.
        $updateText = $isCapNotice ? $text : $existing->text;

        $state->updateBlock($blockId, $existing->with(
            text: $updateText,
            meta: $meta,
        ));
    }

    /**
     * Project a generated user-role model input as a System transcript block.
     *
     * These are synthetic messages produced by AgentMessageConverter for
     * multimodal tool results (e.g. image placeholders) that the model
     * receives alongside tool results.  They are shown exactly as the
     * model sees them — no paraphrase, no summary.
     *
     * Deduplication: by a stable block ID based on the tool_call_id
     * (if available) or source + text hash, so replay does not multiply
     * blocks.
     *
     * @param array<string, mixed> $input
     */
    private function projectUserGeneratedInput(TranscriptProjectionEvent $event, array $input): void
    {
        $state = $event->state;

        $text = (string) ($input['text'] ?? '');
        $toolCallId = (string) ($input['tool_call_id'] ?? '');
        $toolName = (string) ($input['tool_name'] ?? '');
        $source = (string) ($input['source'] ?? 'tool_result_image');
        $inputMetadata = (array) ($input['metadata'] ?? []);

        if ('' === $text) {
            return;
        }

        // Build a stable block ID for deduplication.
        $textHash = hash('sha256', $text);
        if ('' !== $toolCallId) {
            $blockId = 'generated_input_'.$toolCallId;
        } elseif ('' !== $source) {
            $blockId = 'generated_input_'.$source.'_'.substr($textHash, 0, 12);
        } else {
            $blockId = 'generated_input_'.substr($textHash, 0, 12);
        }

        // Deduplicate: skip if the exact same generated block already exists.
        $existing = $state->getBlock($blockId);
        if (null !== $existing) {
            if (($existing->meta['text_hash'] ?? '') === $textHash) {
                return;
            }

            // Text changed — update the existing block.
            $meta = $existing->meta;
            $meta['text_hash'] = $textHash;
            $state->updateBlock($blockId, $existing->with(text: $text, meta: $meta));

            return;
        }

        $meta = [
            'model_input_exact' => true,
            'model_input_role' => 'user',
            'text_hash' => $textHash,
            'source' => $source,
        ];

        if ('' !== $toolCallId) {
            $meta['tool_call_id'] = $toolCallId;
        }
        if ('' !== $toolName) {
            $meta['tool_name'] = $toolName;
        }

        // Forward any additional metadata (e.g. has_non_text_content).
        foreach ($inputMetadata as $key => $value) {
            if (!\array_key_exists($key, $meta)) {
                $meta[$key] = $value;
            }
        }

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

    // ── Output cap notice projection ─────────────────────────────────────────

    /**
     * When a tool execution result was capped (output_capped=true), mark the
     * existing ToolResult block with output-cap metadata so the renderer can
     * style it with a warning icon/colour.
     *
     * The ToolResult block already contains the exact model-facing cap notice
     * text — no paraphrase, summary, or extra System block is created.
     * The transcript shows precisely what the model saw, no more, no less.
     */
    private function maybeProjectOutputCapNotice(TranscriptProjectionEvent $event, string $toolCallId): void
    {
        $p = $event->payload();
        $state = $event->state;

        $capped = (bool) ($p['output_capped'] ?? false);
        if (!$capped) {
            return;
        }

        $toolResultBlockId = 'tool_result_'.$toolCallId;
        $existing = $state->getBlock($toolResultBlockId);
        if (null === $existing) {
            return;
        }

        $meta = $existing->meta;
        $meta['notice_type'] = 'output_cap';
        $meta['output_cap_limit'] = $p['output_cap_limit'] ?? null;
        $meta['output_cap_char_count'] = $p['output_cap_char_count'] ?? null;
        $meta['output_cap_saved_path'] = $p['output_cap_saved_path'] ?? null;

        $state->updateBlock($toolResultBlockId, $existing->with(meta: $meta));
    }
}
