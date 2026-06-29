<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects human-input and approval events into Question and Approval
 * transcript blocks, including status transitions (answered/rejected,
 * approved/rejected).
 */
final readonly class HitlProjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::HumanInputRequested->value => 'onHumanInputRequested',
            RuntimeEventTypeEnum::HumanInputAnswered->value => 'onHumanInputAnswered',
            RuntimeEventTypeEnum::HumanInputRejected->value => 'onHumanInputRejected',
            RuntimeEventTypeEnum::ApprovalRequested->value => 'onApprovalRequested',
            RuntimeEventTypeEnum::ApprovalApproved->value => 'onApprovalApproved',
            RuntimeEventTypeEnum::ApprovalRejected->value => 'onApprovalRejected',
        ];
    }

    // ── Human input (questions) ──────────────────────────────────────────────

    public function onHumanInputRequested(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $questionId = (string) ($p['question_id'] ?? '');
        $prompt = (string) ($p['prompt'] ?? '');
        // ui_kind carries the UI semantics (text/confirm/choice/approval);
        // kind is a transport marker (interrupt) from the tool layer.
        // Prefer ui_kind when available.
        $kind = (string) ($p['ui_kind'] ?? $p['kind'] ?? 'question');
        $blockId = 'hitl_'.$questionId;

        $meta = [
            'question_id' => $questionId,
            'kind' => $kind,
            'prompt' => $prompt,
            'status' => 'pending',
        ];
        if (isset($p['request_id'])) {
            $meta['request_id'] = $p['request_id'];
        }
        if (isset($p['schema'])) {
            $meta['schema'] = $p['schema'];
        }
        if (isset($p['tool_call_id'])) {
            $meta['tool_call_id'] = $p['tool_call_id'];
        }
        if (isset($p['tool_name'])) {
            $meta['tool_name'] = $p['tool_name'];
        }

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::Question,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: $prompt,
            meta: $meta,
            streaming: false,
        ));
    }

    public function onHumanInputAnswered(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $questionId = (string) ($p['question_id'] ?? '');
        $answer = (string) ($p['answer'] ?? '');
        $blockId = 'hitl_'.$questionId;
        $block = $state->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'answered';
        $meta['answer'] = $answer;

        $state->updateBlock($blockId, $block->with(
            text: $block->text.('' !== $answer ? " → {$answer}" : ' → (answered)'),
            meta: $meta,
        ));
    }

    public function onHumanInputRejected(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $questionId = (string) ($p['question_id'] ?? '');
        $blockId = 'hitl_'.$questionId;
        $block = $state->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'rejected';

        $state->updateBlock($blockId, $block->with(
            text: $block->text.' (rejected)',
            meta: $meta,
        ));
    }

    // ── Approval ─────────────────────────────────────────────────────────────

    public function onApprovalRequested(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
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

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::Approval,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: "Approve: {$prompt}",
            meta: $meta,
            streaming: false,
        ));
    }

    public function onApprovalApproved(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $requestId = (string) ($p['request_id'] ?? '');
        $blockId = 'approval_'.$requestId;
        $block = $state->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'approved';

        $state->updateBlock($blockId, $block->with(
            text: $block->text.' ✓',
            meta: $meta,
        ));
    }

    public function onApprovalRejected(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $requestId = (string) ($p['request_id'] ?? '');
        $blockId = 'approval_'.$requestId;
        $block = $state->getBlock($blockId);

        if (null === $block) {
            return;
        }

        $meta = $block->meta;
        $meta['status'] = 'rejected';

        $state->updateBlock($blockId, $block->with(
            text: $block->text.' ✗',
            meta: $meta,
        ));
    }
}
