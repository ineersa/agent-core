<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

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
    public function __construct(
        private DenormalizerInterface $denormalizer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::ModelNotification->value => 'onModelNotification',
        ];
    }

    public function onModelNotification(TranscriptProjectionEvent $event): void
    {
        $notification = $this->denormalizer->denormalize($event->payload(), ModelNotificationDTO::class);
        if (!$notification instanceof ModelNotificationDTO) {
            throw new \InvalidArgumentException('model_notification payload did not denormalize to ModelNotificationDTO.');
        }
        $state = $event->state;

        $blockId = 'model_notification_'.('' !== $notification->id
            ? $notification->id
            : hash('sha256', $notification->text));

        // Build metadata for downstream renderers.
        $meta = [
            'source' => $notification->source,
            'kind' => $notification->kind,
            'severity' => $notification->severity,
            'notification_id' => $notification->id,
        ];

        if (null !== $notification->toolCallId) {
            $meta['tool_call_id'] = $notification->toolCallId;
        }

        // Carry through any extra producer metadata.
        if ([] !== $notification->metadata) {
            $meta['producer_metadata'] = $notification->metadata;
        }

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::System,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: $notification->text,
            meta: $meta,
            streaming: false,
        ));

        // When a notification replaces the tool-result text in the model
        // context (delivery=tool_result_replace), compact the related
        // ToolResult block so the TUI does not show raw/full output that
        // the model never saw.  The exact model-facing notification is
        // already visible in the System block above.
        if ('tool_result_replace' === $notification->delivery
            && null !== $notification->toolCallId
            && '' !== $notification->toolCallId) {
            $this->compactCappedToolResult(
                $state,
                $event->runId(),
                $notification->toolCallId,
                $notification->toolName,
            );
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
