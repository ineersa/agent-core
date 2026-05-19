<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Consumes ordered RuntimeEvents and maintains a stable, ordered list of
 * TranscriptBlocks for the TUI rendering layer.
 *
 * The projector is event-sourced: replaying the same event stream in the same
 * order produces the same block list. Blocks are accumulated in memory and
 * returned as an ordered list.
 *
 * Merge-compatible with RTVS-03 (assistant/user stream): each event family
 * is dispatched to a dedicated private method. RTVS-03 adds
 * applyAssistantEvents(); RTVS-04 adds applyToolEvents(), applyHitlEvents(),
 * and applyCancellationEvents(). The shared apply() body only calls these
 * family methods — the only merge conflict is the method-call list.
 */
final class TranscriptProjector
{
    /** @var array<string, TranscriptBlock> indexed by block ID */
    private array $blocks = [];

    /** @var list<string> ordered block IDs */
    private array $order = [];

    /** @var int Monotonic sequence counter for new blocks */
    private int $nextSeq = 0;

    public function apply(RuntimeEvent $event): void
    {
        // Advance the sequence counter past the event's seq so new blocks
        // always follow the triggering event.
        $this->nextSeq = max($this->nextSeq, $event->seq + 1);

        $type = RuntimeEventTypeEnum::tryFrom($event->type);
        if ($type === null) {
            return;
        }

        // ── Tool call / execution lifecycle (RTVS-04) ──
        $this->applyToolEvents($type, $event);

        // ── HITL & approval (RTVS-04) ──
        $this->applyHitlEvents($type, $event);

        // ── Cancellation / interruption (RTVS-04) ──
        $this->applyCancellationEvents($type, $event);

        // ── User / assistant stream (RTVS-03) ──
        // RTVS-03 will handle user.message_submitted and assistant.* events here.
    }

    /**
     * Return blocks in insertion order.
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

    // ── Tool events (RTVS-04) ────────────────────────────────────────────

    private function applyToolEvents(RuntimeEventTypeEnum $type, RuntimeEvent $event): void
    {
        match ($type) {
            RuntimeEventTypeEnum::ToolCallStarted => $this->handleToolCallStarted($event),
            RuntimeEventTypeEnum::ToolCallArgumentsDelta => $this->handleToolCallArgumentsDelta($event),
            RuntimeEventTypeEnum::ToolCallArgumentsCompleted => $this->handleToolCallArgumentsCompleted($event),
            RuntimeEventTypeEnum::ToolExecutionStarted => $this->handleToolExecutionStarted($event),
            RuntimeEventTypeEnum::ToolExecutionOutputDelta => $this->handleToolExecutionOutputDelta($event),
            RuntimeEventTypeEnum::ToolExecutionCompleted => $this->handleToolExecutionCompleted($event),
            RuntimeEventTypeEnum::ToolExecutionFailed => $this->handleToolExecutionFailed($event),
            RuntimeEventTypeEnum::ToolExecutionCancelled => $this->handleToolExecutionCancelled($event),
            default => null,
        };
    }

    private function handleToolCallStarted(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $toolName = (string) ($p['tool_name'] ?? '');
        $blockId = 'tool_call_'.$toolCallId;

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: $event->runId,
            seq: $this->nextSeq(),
            text: $toolName,
            meta: [
                'tool_call_id' => $toolCallId,
                'tool_name' => $toolName,
            ],
            streaming: true,
        ));
    }

    private function handleToolCallArgumentsDelta(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $delta = (string) ($p['delta'] ?? '');
        $blockId = 'tool_call_'.$toolCallId;
        $block = $this->getBlock($blockId);

        if ($block === null) {
            return;
        }

        $this->updateBlock($blockId, $block->appendText($delta));
    }

    private function handleToolCallArgumentsCompleted(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $arguments = $p['arguments'] ?? [];
        $blockId = 'tool_call_'.$toolCallId;
        $block = $this->getBlock($blockId);

        if ($block === null) {
            // Tool call block was never started — create a completed snapshot.
            $toolName = (string) ($p['tool_name'] ?? '');
            $argumentsText = $this->argumentsToText($arguments);
            $this->addBlock(new TranscriptBlock(
                id: $blockId,
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: $event->runId,
                seq: $this->nextSeq(),
                text: $toolName.$argumentsText,
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

        $this->updateBlock($blockId, $block
            ->with(
                text: $meta['tool_name'].$argumentsText,
                streaming: false,
                meta: $meta,
            ));
    }

    private function handleToolExecutionStarted(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $toolName = (string) ($p['tool_name'] ?? '');
        $blockId = 'tool_result_'.$toolCallId;

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: $event->runId,
            seq: $this->nextSeq(),
            text: 'Running…',
            meta: [
                'tool_call_id' => $toolCallId,
                'tool_name' => $toolName,
            ],
            streaming: true,
        ));
    }

    private function handleToolExecutionOutputDelta(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $delta = (string) ($p['delta'] ?? '');
        $blockId = 'tool_result_'.$toolCallId;
        $block = $this->getBlock($blockId);

        if ($block === null) {
            return;
        }

        $this->updateBlock($blockId, $block->appendText($delta));
    }

    private function handleToolExecutionCompleted(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $result = (string) ($p['result'] ?? '');
        $durationMs = isset($p['duration_ms']) ? (int) $p['duration_ms'] : null;
        $blockId = 'tool_result_'.$toolCallId;

        $meta = [
            'tool_call_id' => $toolCallId,
            'is_error' => false,
        ];
        if ($durationMs !== null) {
            $meta['duration_ms'] = $durationMs;
        }

        if ($result !== '') {
            $meta['result'] = $result;
        }

        $this->addOrUpdateToolResult($blockId, $event->runId, $result, $meta, false);
    }

    private function handleToolExecutionFailed(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $result = (string) ($p['result'] ?? '');
        $blockId = 'tool_result_'.$toolCallId;

        $meta = [
            'tool_call_id' => $toolCallId,
            'is_error' => true,
        ];

        if ($result !== '') {
            $meta['result'] = $result;
        }

        $this->addOrUpdateToolResult($blockId, $event->runId, $result, $meta, false);
    }

    private function handleToolExecutionCancelled(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        $blockId = 'tool_result_'.$toolCallId;
        $timedOut = !empty($p['timed_out']);

        $meta = [
            'tool_call_id' => $toolCallId,
            'cancelled' => true,
            'timed_out' => $timedOut,
            'is_error' => true,
        ];

        $text = $timedOut ? 'Timed out' : 'Cancelled';

        $this->addOrUpdateToolResult($blockId, $event->runId, $text, $meta, false);
    }

    /**
     * Add or update a tool-result block (completed, failed, or cancelled).
     */
    private function addOrUpdateToolResult(
        string $blockId,
        string $runId,
        string $text,
        array $meta,
        bool $streaming,
    ): void {
        $existing = $this->getBlock($blockId);
        if ($existing !== null) {
            $this->updateBlock($blockId, $existing->with(
                text: $text !== '' ? $text : $existing->text,
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

    // ── HITL & approval events (RTVS-04) ──────────────────────────────────

    private function applyHitlEvents(RuntimeEventTypeEnum $type, RuntimeEvent $event): void
    {
        match ($type) {
            RuntimeEventTypeEnum::HumanInputRequested => $this->handleHumanInputRequested($event),
            RuntimeEventTypeEnum::HumanInputAnswered => $this->handleHumanInputAnswered($event),
            RuntimeEventTypeEnum::HumanInputRejected => $this->handleHumanInputRejected($event),
            RuntimeEventTypeEnum::ApprovalRequested => $this->handleApprovalRequested($event),
            RuntimeEventTypeEnum::ApprovalApproved => $this->handleApprovalApproved($event),
            RuntimeEventTypeEnum::ApprovalRejected => $this->handleApprovalRejected($event),
            default => null,
        };
    }

    private function handleHumanInputRequested(RuntimeEvent $event): void
    {
        $p = $event->payload;
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
            runId: $event->runId,
            seq: $this->nextSeq(),
            text: $prompt,
            meta: $meta,
            streaming: false,
        ));
    }

    private function handleHumanInputAnswered(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $questionId = (string) ($p['question_id'] ?? '');
        $answer = (string) ($p['answer'] ?? '');
        $blockId = 'hitl_'.$questionId;
        $block = $this->getBlock($blockId);

        if ($block === null) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'answered';
        $meta['answer'] = $answer;

        $this->updateBlock($blockId, $block->with(
            text: $block->text.($answer !== '' ? " → {$answer}" : ' → (answered)'),
            meta: $meta,
        ));
    }

    private function handleHumanInputRejected(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $questionId = (string) ($p['question_id'] ?? '');
        $blockId = 'hitl_'.$questionId;
        $block = $this->getBlock($blockId);

        if ($block === null) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'rejected';

        $this->updateBlock($blockId, $block->with(
            text: $block->text.' (rejected)',
            meta: $meta,
        ));
    }

    private function handleApprovalRequested(RuntimeEvent $event): void
    {
        $p = $event->payload;
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
            runId: $event->runId,
            seq: $this->nextSeq(),
            text: "Approve: {$prompt}",
            meta: $meta,
            streaming: false,
        ));
    }

    private function handleApprovalApproved(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $requestId = (string) ($p['request_id'] ?? '');
        $blockId = 'approval_'.$requestId;
        $block = $this->getBlock($blockId);

        if ($block === null) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'approved';

        $this->updateBlock($blockId, $block->with(
            text: $block->text.' ✓',
            meta: $meta,
        ));
    }

    private function handleApprovalRejected(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $requestId = (string) ($p['request_id'] ?? '');
        $blockId = 'approval_'.$requestId;
        $block = $this->getBlock($blockId);

        if ($block === null) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'rejected';

        $this->updateBlock($blockId, $block->with(
            text: $block->text.' ✗',
            meta: $meta,
        ));
    }

    // ── Cancellation events (RTVS-04) ────────────────────────────────────

    private function applyCancellationEvents(RuntimeEventTypeEnum $type, RuntimeEvent $event): void
    {
        match ($type) {
            RuntimeEventTypeEnum::CancellationRequested => $this->handleCancellationRequested($event),
            RuntimeEventTypeEnum::OperationCancelled => $this->handleOperationCancelled($event),
            RuntimeEventTypeEnum::TurnCancelled => $this->handleTurnCancelled($event),
            RuntimeEventTypeEnum::RunCancelled => $this->handleRunCancelled($event),
            default => null,
        };
    }

    /**
     * Cancellation was requested but not yet acted upon.
     * We do not create a block yet; the follow-up turn/run/operation
     * cancelled event will produce the visible block.
     */
    private function handleCancellationRequested(RuntimeEvent $event): void
    {
        // No block is created for the request itself.
        // The actual cancellation block appears when turn.cancelled /
        // run.cancelled / operation.cancelled arrives.
    }

    private function handleOperationCancelled(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $operationId = (string) ($p['operation_id'] ?? '');
        $operationType = (string) ($p['operation_type'] ?? '');
        $reason = (string) ($p['reason'] ?? 'user_cancelled');

        $desc = $operationType !== '' ? "{$operationType} " : '';
        $text = "Cancelled: {$desc}operation".($operationId !== '' ? " {$operationId}" : '');

        $blockId = 'cancel_op_'.($operationId !== '' ? $operationId : 'op_'.$this->nextSeq());

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::Cancelled,
            runId: $event->runId,
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

    private function handleTurnCancelled(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $reason = (string) ($p['reason'] ?? 'user_cancelled');

        // Finalize all currently streaming blocks
        $this->cancelActiveStreamingBlocks($event->runId);

        $this->addCancelledBlock($event, $reason, 'turn');
    }

    private function handleRunCancelled(RuntimeEvent $event): void
    {
        $p = $event->payload;
        $reason = (string) ($p['reason'] ?? 'user_cancelled');

        // Finalize all currently streaming blocks
        $this->cancelActiveStreamingBlocks($event->runId);

        $this->addCancelledBlock($event, $reason, 'run');
    }

    /**
     * Mark all currently streaming blocks as finalized (non-streaming).
     * This is called when a turn or run is cancelled so the TUI does not
     * keep showing spinner/pending state on blocks that will never complete.
     */
    private function cancelActiveStreamingBlocks(string $runId): void
    {
        foreach ($this->blocks as $id => $block) {
            if ($block->streaming) {
                $this->blocks[$id] = $block->with(
                    streaming: false,
                );
            }
        }
    }

    private function addCancelledBlock(RuntimeEvent $event, string $reason, string $scope): void
    {
        $blockId = "cancel_{$scope}_".$this->nextSeq();

        $this->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::Cancelled,
            runId: $event->runId,
            seq: $this->nextSeq(),
            text: "{$scope} cancelled".($reason !== '' ? " ({$reason})" : ''),
            meta: [
                'reason' => $reason,
                'scope' => $scope,
            ],
            streaming: false,
        ));
    }

    // ── Internal helpers ─────────────────────────────────────────────────

    private function addBlock(TranscriptBlock $block): void
    {
        $this->blocks[$block->id] = $block;
        $this->order[] = $block->id;
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
     * Convert tool arguments to a compact text representation.
     *
     * @param array<string, mixed>|list<mixed> $arguments
     */
    private function argumentsToText(array $arguments): string
    {
        if ($arguments === []) {
            return '()';
        }

        $parts = [];
        foreach ($arguments as $key => $value) {
            if (is_string($value)) {
                $parts[] = "{$key}: \"{$value}\"";
            } elseif (is_scalar($value) || $value === null) {
                $parts[] = "{$key}: ".json_encode($value, JSON_THROW_ON_ERROR);
            } else {
                $parts[] = "{$key}: ".json_encode($value, JSON_THROW_ON_ERROR);
            }
        }

        return '('.implode(', ', $parts).')';
    }
}
