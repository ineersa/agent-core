<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects generic model_notification events into System transcript blocks.
 *
 * Every notification is rendered as a System block carrying the exact
 * notification text that the model received.  Structured metadata
 * (source, kind, severity, tool_call_id, …) is preserved in the block's
 * meta so downstream renderers can apply severity‑based styling (icon,
 * theme color) without text parsing or output-cap-specific checks.
 */
final readonly class ModelNotificationProjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::ModelNotification->value => 'onModelNotification',
        ];
    }

    public function onModelNotification(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;

        $notificationId = (string) ($p['id'] ?? '');
        $text = (string) ($p['text'] ?? '');
        $source = (string) ($p['source'] ?? '');
        $kind = (string) ($p['kind'] ?? '');
        $severity = (string) ($p['severity'] ?? 'info');
        $toolCallId = isset($p['tool_call_id']) && \is_string($p['tool_call_id'])
            ? $p['tool_call_id']
            : null;

        $blockId = 'model_notification_'.('' !== $notificationId
            ? $notificationId
            : hash('sha256', $text));

        // Build metadata for downstream renderers.
        $meta = [
            'source' => $source,
            'kind' => $kind,
            'severity' => $severity,
            'notification_id' => $notificationId,
        ];

        if (null !== $toolCallId) {
            $meta['tool_call_id'] = $toolCallId;
        }

        // Carry through any extra producer metadata.
        $producerMeta = $p['metadata'] ?? null;
        if (\is_array($producerMeta) && [] !== $producerMeta) {
            $meta['producer_metadata'] = $producerMeta;
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
}
