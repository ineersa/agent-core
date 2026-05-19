<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects cancellation events into Cancelled transcript blocks.
 * Finalizes streaming blocks before appending cancellation blocks.
 */
final readonly class CancellationProjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::CancellationRequested->value => 'onCancellationRequested',
            RuntimeEventTypeEnum::OperationCancelled->value => 'onOperationCancelled',
            RuntimeEventTypeEnum::TurnCancelled->value => 'onTurnCancelled',
            RuntimeEventTypeEnum::RunCancelled->value => 'onRunCancelled',
        ];
    }

    /**
     * Marker event — no block created. Follow-up operation/turn/run
     * cancellation events create the actual blocks.
     */
    public function onCancellationRequested(TranscriptProjectionEvent $event): void
    {
        // Intentionally blank: marker event only
    }

    public function onOperationCancelled(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $operationId = (string) ($p['operation_id'] ?? '');
        $operationType = (string) ($p['operation_type'] ?? '');
        $reason = (string) ($p['reason'] ?? 'user_cancelled');

        $desc = '' !== $operationType ? "{$operationType} " : '';
        $text = "Cancelled: {$desc}operation".('' !== $operationId ? " {$operationId}" : '');

        $blockId = 'cancel_op_'.('' !== $operationId ? $operationId : $state->nextSeq());

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::Cancelled,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: $text,
            meta: [
                'reason' => $reason,
                'operation_id' => $operationId,
                'operation_type' => $operationType,
            ],
            streaming: false,
        ));
    }

    public function onTurnCancelled(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $reason = (string) ($p['reason'] ?? 'user_cancelled');

        $state->cancelActiveStreamingBlocks($event->runId());
        $state->addCancelledBlock($event->runId(), $reason, 'turn');
    }

    public function onRunCancelled(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $reason = (string) ($p['reason'] ?? 'user_cancelled');

        $state->cancelActiveStreamingBlocks($event->runId());
        $state->addCancelledBlock($event->runId(), $reason, 'run');
    }
}
