<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

/**
 * Consumes ordered runtime events and maintains a stable, ordered list of
 * transcript blocks for the TUI rendering layer.
 *
 * The projector is event-sourced: replaying the same event stream in the same
 * order produces the same block list. Blocks are accumulated in memory and
 * returned in insertion order.
 *
 * Public API accepts array-shaped events ({@see RuntimeEvent} shape) so the
 * projector stays within the AppRuntimeProjection deptrac boundary (zero
 * production dependencies outside its own namespace).
 */
final class TranscriptProjector
{
    /** @var array<string, TranscriptBlock> indexed by block ID */
    private array $blocks = [];

    /** @var list<string> ordered block IDs */
    private array $order = [];

    /** Monotonic sequence counter for new blocks. Reset on replay. */
    private int $nextSeq = 0;

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Accept a single runtime event and update the projection.
     *
     * Unknown event types are silently ignored. The event array must match
     * the shape of {@see \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent}.
     *
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    public function accept(array $event): void
    {
        $type = $event['type'];

        match ($type) {
            // ── User ────────────────────────────────────────────────────
            'user.message_submitted' => $this->handleUserMessageSubmitted($event),

            // ── Assistant stream ────────────────────────────────────────
            'assistant.message_started' => null, // Marker only
            'assistant.text_started' => $this->handleTextStarted($event),
            'assistant.text_delta' => $this->handleTextDelta($event),
            'assistant.text_completed' => $this->handleTextCompleted($event),
            'assistant.thinking_started' => $this->handleThinkingStarted($event),
            'assistant.thinking_delta' => $this->handleThinkingDelta($event),
            'assistant.thinking_completed' => $this->handleThinkingCompleted($event),
            'assistant.message_completed' => $this->handleMessageCompleted($event),
            'assistant.message_failed' => $this->handleMessageFailed($event),

            // ── Tool call / execution lifecycle ─────────────────────────
            'tool_call.started' => $this->handleToolCallStarted($event),
            'tool_call.arguments_delta' => $this->handleToolCallArgumentsDelta($event),
            'tool_call.arguments_completed' => $this->handleToolCallArgumentsCompleted($event),
            'tool_execution.started' => $this->handleToolExecutionStarted($event),
            'tool_execution.output_delta' => $this->handleToolExecutionOutputDelta($event),
            'tool_execution.completed' => $this->handleToolExecutionCompleted($event),
            'tool_execution.failed' => $this->handleToolExecutionFailed($event),
            'tool_execution.cancelled' => $this->handleToolExecutionCancelled($event),

            // ── HITL & approval ─────────────────────────────────────────
            'human_input.requested' => $this->handleHumanInputRequested($event),
            'human_input.answered' => $this->handleHumanInputAnswered($event),
            'human_input.rejected' => $this->handleHumanInputRejected($event),
            'approval.requested' => $this->handleApprovalRequested($event),
            'approval.approved' => $this->handleApprovalApproved($event),
            'approval.rejected' => $this->handleApprovalRejected($event),

            // ── Cancellation ────────────────────────────────────────────
            'cancellation.requested' => null, // No block; follow-up events create them
            'operation.cancelled' => $this->handleOperationCancelled($event),
            'turn.cancelled' => $this->handleTurnCancelled($event),
            'run.cancelled' => $this->handleRunCancelled($event),

            default => null,
        };
    }

    /**
     * Return the current ordered list of transcript blocks.
     *
     * @return list<TranscriptBlock>
     */
    public function blocks(): array
    {
        $result = [];
        foreach ($this->order as $id) {
            $result[] = $this->blocks[$id];
        }

        return $result;
    }

    /**
     * Reset all internal state so a fresh replay produces the same output.
     */
    public function reset(): void
    {
        $this->blocks = [];
        $this->order = [];
        $this->nextSeq = 0;
    }

    // ── User message ────────────────────────────────────────────────────────

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleUserMessageSubmitted(array $event): void
    {
        $p = $event['payload'];

        $this->addBlock(new TranscriptBlock(
            id: (string) ($p['message_id'] ?? ''),
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: $event['runId'],
            seq: $this->nextSeq(),
            text: (string) ($p['text'] ?? ''),
        ));
    }

    // ── Assistant text block ─────────────────────────────────────────────────

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleTextStarted(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: $event['runId'],
            seq: $this->nextSeq(),
            text: (string) ($p['text'] ?? ''),
            meta: $this->buildAssistantMeta($p),
            streaming: true,
        ));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleTextDelta(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');
        $delta = (string) ($p['delta'] ?? '');

        $block = $this->getBlock($blockId);
        if (null === $block) {
            return;
        }
        if (!$block->streaming) {
            return;
        }

        $this->updateBlock($blockId, $block->appendText($delta));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleTextCompleted(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');

        $block = $this->getBlock($blockId);
        if (null === $block) {
            return;
        }

        $this->updateBlock($blockId, $block
            ->with(text: isset($p['text']) ? (string) $p['text'] : $block->text)
            ->finalize(),
        );
    }

    // ── Assistant thinking block ─────────────────────────────────────────────

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleThinkingStarted(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::AssistantThinking,
            runId: $event['runId'],
            seq: $this->nextSeq(),
            text: (string) ($p['text'] ?? ''),
            meta: $this->buildAssistantMeta($p),
            streaming: true,
            collapsed: true,
        ));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleThinkingDelta(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');
        $delta = (string) ($p['delta'] ?? '');

        $block = $this->getBlock($blockId);
        if (null === $block) {
            return;
        }
        if (!$block->streaming) {
            return;
        }

        $this->updateBlock($blockId, $block->appendText($delta));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleThinkingCompleted(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');

        $block = $this->getBlock($blockId);
        if (null === $block) {
            return;
        }

        $this->updateBlock($blockId, $block
            ->with(text: isset($p['text']) ? (string) $p['text'] : $block->text)
            ->finalize(),
        );
    }

    // ── Message lifecycle ────────────────────────────────────────────────────

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleMessageCompleted(array $event): void
    {
        $p = $event['payload'];
        $messageId = (string) ($p['message_id'] ?? '');

        $this->finalizeMessageBlocks($messageId);
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleMessageFailed(array $event): void
    {
        $p = $event['payload'];
        $messageId = (string) ($p['message_id'] ?? '');

        // Finalize any streaming blocks belonging to this message
        $this->finalizeMessageBlocks($messageId);

        // Append an error block
        $this->addBlock(new TranscriptBlock(
            id: $this->pickErrorBlockId($p, $messageId),
            kind: TranscriptBlockKindEnum::Error,
            runId: $event['runId'],
            seq: $this->nextSeq(),
            text: (string) ($p['text'] ?? 'Assistant message failed'),
            meta: [
                'message_id' => $messageId,
                'stop_reason' => (string) ($p['stop_reason'] ?? 'error'),
            ],
        ));
    }

    // ── Tool call lifecycle ──────────────────────────────────────────────────

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleToolCallStarted(array $event): void
    {
        $p = $event['payload'];
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $toolName = (string) ($p['tool_name'] ?? '');
        $blockId = 'tool_call_'.$toolCallId;

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: $event['runId'],
            seq: $this->nextSeq(),
            text: $toolName,
            meta: [
                'tool_call_id' => $toolCallId,
                'tool_name' => $toolName,
            ],
            streaming: true,
        ));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleToolCallArgumentsDelta(array $event): void
    {
        $p = $event['payload'];
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $delta = (string) ($p['delta'] ?? '');
        $blockId = 'tool_call_'.$toolCallId;
        $block = $this->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $this->updateBlock($blockId, $block->appendText($delta));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleToolCallArgumentsCompleted(array $event): void
    {
        $p = $event['payload'];
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $arguments = $p['arguments'] ?? [];
        $blockId = 'tool_call_'.$toolCallId;
        $block = $this->getBlock($blockId);

        if (null === $block) {
            // Tool call block was never started — create a completed snapshot.
            $toolName = (string) ($p['tool_name'] ?? '');
            $argumentsText = $this->argumentsToText($arguments);
            $this->addBlock(new TranscriptBlock(
                id: $blockId,
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: $event['runId'],
                seq: $this->nextSeq(),
                text: '' !== $toolName ? $toolName.$argumentsText : $argumentsText,
                meta: [
                    'tool_call_id' => $toolCallId,
                    'tool_name' => $toolName,
                    'arguments' => $arguments,
                ],
                streaming: false,
            ));

            return;
        }

        $argumentsText = $this->argumentsToText($arguments);
        $meta = $block->meta;
        $meta['arguments'] = $arguments;

        $this->updateBlock($blockId, $block->with(
            text: $block->text.$argumentsText,
            streaming: false,
            meta: $meta,
        ));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleToolExecutionStarted(array $event): void
    {
        $p = $event['payload'];
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $toolName = (string) ($p['tool_name'] ?? '');
        $blockId = 'tool_result_'.$toolCallId;

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: $event['runId'],
            seq: $this->nextSeq(),
            text: 'Running…',
            meta: [
                'tool_call_id' => $toolCallId,
                'tool_name' => $toolName,
            ],
            streaming: true,
        ));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleToolExecutionOutputDelta(array $event): void
    {
        $p = $event['payload'];
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $delta = (string) ($p['delta'] ?? '');
        $blockId = 'tool_result_'.$toolCallId;
        $block = $this->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $this->updateBlock($blockId, $block->appendText($delta));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleToolExecutionCompleted(array $event): void
    {
        $p = $event['payload'];
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $result = (string) ($p['result'] ?? '');
        $durationMs = isset($p['duration_ms']) ? (int) $p['duration_ms'] : null;
        $blockId = 'tool_result_'.$toolCallId;

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

        $this->upsertToolResultBlock($blockId, $event['runId'], $result, $meta, false);
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleToolExecutionFailed(array $event): void
    {
        $p = $event['payload'];
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

        $this->upsertToolResultBlock($blockId, $event['runId'], $result, $meta, false);
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleToolExecutionCancelled(array $event): void
    {
        $p = $event['payload'];
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

        $this->upsertToolResultBlock($blockId, $event['runId'], $text, $meta, false);
    }

    // ── HITL events ──────────────────────────────────────────────────────────

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleHumanInputRequested(array $event): void
    {
        $p = $event['payload'];
        $questionId = (string) ($p['question_id'] ?? '');
        $prompt = (string) ($p['prompt'] ?? '');
        $kind = (string) ($p['kind'] ?? 'question');
        $blockId = 'hitl_'.$questionId;

        $meta = [
            'question_id' => $questionId,
            'request_id' => $p['request_id'] ?? '',
            'kind' => $kind,
            'prompt' => $prompt,
            'status' => 'pending',
        ];
        if (isset($p['schema'])) {
            $meta['schema'] = $p['schema'];
        }
        if (isset($p['tool_call_id'])) {
            $meta['tool_call_id'] = $p['tool_call_id'];
        }
        if (isset($p['tool_name'])) {
            $meta['tool_name'] = $p['tool_name'];
        }

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::Question,
            runId: $event['runId'],
            seq: $this->nextSeq(),
            text: $prompt,
            meta: $meta,
            streaming: false,
        ));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleHumanInputAnswered(array $event): void
    {
        $p = $event['payload'];
        $questionId = (string) ($p['question_id'] ?? '');
        $answer = (string) ($p['answer'] ?? '');
        $blockId = 'hitl_'.$questionId;
        $block = $this->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'answered';
        $meta['answer'] = $answer;

        $this->updateBlock($blockId, $block->with(
            text: $block->text.('' !== $answer ? " → {$answer}" : ' → (answered)'),
            meta: $meta,
        ));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleHumanInputRejected(array $event): void
    {
        $p = $event['payload'];
        $questionId = (string) ($p['question_id'] ?? '');
        $blockId = 'hitl_'.$questionId;
        $block = $this->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'rejected';

        $this->updateBlock($blockId, $block->with(
            text: $block->text.' (rejected)',
            meta: $meta,
        ));
    }

    // ── Approval events ──────────────────────────────────────────────────────

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleApprovalRequested(array $event): void
    {
        $p = $event['payload'];
        $requestId = (string) ($p['request_id'] ?? '');
        $prompt = (string) ($p['prompt'] ?? '');
        $blockId = 'approval_'.$requestId;

        $meta = [
            'request_id' => $requestId,
            'prompt' => $prompt,
            'status' => 'pending',
        ];
        if (isset($p['tool_call_id'])) {
            $meta['tool_call_id'] = $p['tool_call_id'];
        }
        if (isset($p['tool_name'])) {
            $meta['tool_name'] = $p['tool_name'];
        }

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::Approval,
            runId: $event['runId'],
            seq: $this->nextSeq(),
            text: "Approve: {$prompt}",
            meta: $meta,
            streaming: false,
        ));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleApprovalApproved(array $event): void
    {
        $p = $event['payload'];
        $requestId = (string) ($p['request_id'] ?? '');
        $blockId = 'approval_'.$requestId;
        $block = $this->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'approved';

        $this->updateBlock($blockId, $block->with(
            text: $block->text.' ✓',
            meta: $meta,
        ));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleApprovalRejected(array $event): void
    {
        $p = $event['payload'];
        $requestId = (string) ($p['request_id'] ?? '');
        $blockId = 'approval_'.$requestId;
        $block = $this->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'rejected';

        $this->updateBlock($blockId, $block->with(
            text: $block->text.' ✗',
            meta: $meta,
        ));
    }

    // ── Cancellation events ──────────────────────────────────────────────────

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleOperationCancelled(array $event): void
    {
        $p = $event['payload'];
        $operationId = (string) ($p['operation_id'] ?? '');
        $operationType = (string) ($p['operation_type'] ?? '');
        $reason = (string) ($p['reason'] ?? 'user_cancelled');

        $desc = '' !== $operationType ? "{$operationType} " : '';
        $text = "Cancelled: {$desc}operation".('' !== $operationId ? " {$operationId}" : '');

        $blockId = 'cancel_op_'.('' !== $operationId ? $operationId : $this->nextSeq());

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::Cancelled,
            runId: $event['runId'],
            seq: $this->nextSeq(),
            text: $text,
            meta: [
                'reason' => $reason,
                'operation_id' => $operationId,
                'operation_type' => $operationType,
            ],
            streaming: false,
        ));
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleTurnCancelled(array $event): void
    {
        $p = $event['payload'];
        $reason = (string) ($p['reason'] ?? 'user_cancelled');

        $this->cancelActiveStreamingBlocks($event['runId']);

        $this->addCancelledBlock($event['runId'], $reason, 'turn');
    }

    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    private function handleRunCancelled(array $event): void
    {
        $p = $event['payload'];
        $reason = (string) ($p['reason'] ?? 'user_cancelled');

        $this->cancelActiveStreamingBlocks($event['runId']);

        $this->addCancelledBlock($event['runId'], $reason, 'run');
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    private function addBlock(TranscriptBlock $block): void
    {
        // Guard against duplicate IDs from replay
        if (!\array_key_exists($block->id, $this->blocks)) {
            $this->order[] = $block->id;
        }
        $this->blocks[$block->id] = $block;
    }

    private function getBlock(string $id): ?TranscriptBlock
    {
        return $this->blocks[$id] ?? null;
    }

    private function updateBlock(string $id, TranscriptBlock $block): void
    {
        $this->blocks[$id] = $block;
    }

    /**
     * Consume and return the next monotonic sequence number.
     */
    private function nextSeq(): int
    {
        return $this->nextSeq++;
    }

    /**
     * Build the common assistant metadata map from event payload.
     *
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    private function buildAssistantMeta(array $p): array
    {
        $meta = [
            'message_id' => (string) ($p['message_id'] ?? ''),
            'content_index' => (int) ($p['content_index'] ?? 0),
        ];

        if (isset($p['model'])) {
            $meta['model'] = (string) $p['model'];
        }

        if (isset($p['stop_reason'])) {
            $meta['stop_reason'] = (string) $p['stop_reason'];
        }

        return $meta;
    }

    /**
     * Choose an error block id: prefer the payload block_id, then message_id, then a generated fallback.
     *
     * @param array<string, mixed> $p
     */
    private function pickErrorBlockId(array $p, string $messageId): string
    {
        $blockId = (string) ($p['block_id'] ?? '');

        return '' !== $blockId ? $blockId : ('' !== $messageId ? $messageId : 'error_'.$this->nextSeq());
    }

    /**
     * Add or update a tool-result block (completed, failed, or cancelled).
     *
     * @param array<string, mixed> $meta
     */
    private function upsertToolResultBlock(
        string $blockId,
        string $runId,
        string $text,
        array $meta,
        bool $streaming,
    ): void {
        $existing = $this->getBlock($blockId);
        if (null !== $existing) {
            $this->updateBlock($blockId, $existing->with(
                text: '' !== $text ? $text : $existing->text,
                streaming: $streaming,
                meta: $meta,
            ));
        } else {
            $this->addBlock(new TranscriptBlock(
                id: $blockId,
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: $runId,
                seq: $this->nextSeq(),
                text: $text,
                meta: $meta,
                streaming: $streaming,
            ));
        }
    }

    /**
     * Mark all streaming blocks for the given run as finalized (non-streaming).
     */
    private function cancelActiveStreamingBlocks(string $runId): void
    {
        foreach ($this->blocks as $id => $block) {
            if ($block->streaming && $block->runId === $runId) {
                $this->blocks[$id] = $block->with(
                    streaming: false,
                );
            }
        }
    }

    /**
     * Add a cancellation block for turn/run cancelled events.
     */
    private function addCancelledBlock(string $runId, string $reason, string $scope): void
    {
        $seq = $this->nextSeq();

        $this->addBlock(new TranscriptBlock(
            id: "cancel_{$scope}_{$seq}",
            kind: TranscriptBlockKindEnum::Cancelled,
            runId: $runId,
            seq: $seq,
            text: "{$scope} cancelled".('' !== $reason ? " ({$reason})" : ''),
            meta: [
                'reason' => $reason,
                'scope' => $scope,
            ],
            streaming: false,
        ));
    }

    /**
     * Finalize all streaming blocks belonging to a given message.
     */
    private function finalizeMessageBlocks(string $messageId): void
    {
        foreach ($this->blocks as $id => $block) {
            if (($block->meta['message_id'] ?? '') === $messageId && $block->streaming) {
                $this->blocks[$id] = $block->finalize();
            }
        }
    }

    /**
     * Convert tool arguments to a compact text representation.
     *
     * @param array<string, mixed>|list<mixed> $arguments
     */
    private function argumentsToText(array $arguments): string
    {
        if ([] === $arguments) {
            return '()';
        }

        $parts = [];
        foreach ($arguments as $key => $value) {
            if (\is_string($value)) {
                $parts[] = "{$key}: \"{$value}\"";
            } else {
                $parts[] = "{$key}: ".json_encode($value, \JSON_THROW_ON_ERROR);
            }
        }

        return '('.implode(', ', $parts).')';
    }
}
