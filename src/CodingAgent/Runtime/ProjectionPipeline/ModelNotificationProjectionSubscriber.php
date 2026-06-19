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
        $delivery = (string) ($p['delivery'] ?? '');
        $toolCallId = isset($p['tool_call_id']) && \is_string($p['tool_call_id'])
            ? $p['tool_call_id']
            : null;
        $toolName = isset($p['tool_name']) && \is_string($p['tool_name'])
            ? $p['tool_name']
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

        // When a notification replaces the tool-result text in the model
        // context (delivery=tool_result_replace), compact the related
        // ToolResult block so the TUI does not show raw/full output that
        // the model never saw.  The exact model-facing notification is
        // already visible in the System block above.
        if ('tool_result_replace' === $delivery && null !== $toolCallId && '' !== $toolCallId) {
            $this->compactCappedToolResult($state, $event->runId(), $toolCallId, $toolName);
        }
    }

    /**
     * Compact a ToolResult block whose raw output was replaced by a
     * notification.  The visible text becomes a generic status label
     * like 'read completed' — the exact model-facing notification is
     * shown in the System block.
     *
     * Preserves existing ToolResult metadata (tool_name, is_error, etc.)
     * since upsertToolResultBlock replaces metadata entirely rather than
     * merging.
     */
    private function compactCappedToolResult(
        \Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState $state,
        string $runId,
        string $toolCallId,
        ?string $toolName,
    ): void {
        $toolResultBlockId = 'tool_result_'.$toolCallId;
        $existing = $state->getBlock($toolResultBlockId);

        // If no ToolResult block exists yet (e.g. notification arrived
        // before tool_execution.completed via late hook), do nothing —
        // the primary ToolProjectionSubscriber will create it later.
        if (null === $existing) {
            return;
        }

        // Prefer tool_name from the notification payload, then from
        // existing ToolResult block metadata, then nothing.
        $resolvedName = $toolName
            ?? (\is_string($existing->meta['tool_name'] ?? null) && '' !== $existing->meta['tool_name']
                ? $existing->meta['tool_name']
                : null);

        $isError = \is_bool($existing->meta['is_error'] ?? null)
            && $existing->meta['is_error'];

        $label = null !== $resolvedName
            ? $resolvedName.($isError ? ' failed' : ' completed')
            : ($isError ? 'failed' : 'completed');

        // Collect existing metadata, preserving everything except the
        // full visible text (which is now compact).
        $meta = $existing->meta;
        $meta['compact_label'] = true;
        $meta['tool_call_id'] = $toolCallId;

        $state->upsertToolResultBlock(
            blockId: $toolResultBlockId,
            runId: $runId,
            text: $label,
            meta: $meta,
            streaming: false,
        );
    }
}
