<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects system.notice runtime events into System transcript blocks.
 *
 * Handles events created by the translator for ext_* RunEvents and any
 * other source that emits system.notice (e.g. internal notices, extension
 * messages, output-cap notices from the tool projection path).
 *
 * System blocks are non-streaming, non-collapsed, and carry the notice
 * source type in metadata for downstream UI rendering.
 */
final readonly class SystemNoticeProjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::SystemNotice->value => 'onSystemNotice',
        ];
    }

    public function onSystemNotice(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $blockId = $this->buildBlockId($p);

        // Deduplicate: if a block with this ID already exists (e.g. from
        // live path projection), skip to avoid duplicate System blocks
        // on replay.
        if (null !== $state->getBlock($blockId)) {
            return;
        }

        $text = (string) ($p['text'] ?? '');
        $source = (string) ($p['source'] ?? 'system');
        $severity = (string) ($p['severity'] ?? 'info');

        $meta = [
            'source' => $source,
            'severity' => $severity,
            'notice_type' => 'system',
        ];

        // Include safe details if present.
        if (isset($p['details']) && \is_array($p['details'])) {
            foreach ($p['details'] as $key => $value) {
                $meta[$key] = $value;
            }
        }

        // For output cap notices, include structured cap metadata.
        if (isset($p['output_cap_limit'])) {
            $meta['output_cap_limit'] = $p['output_cap_limit'];
        }
        if (isset($p['output_cap_char_count'])) {
            $meta['output_cap_char_count'] = $p['output_cap_char_count'];
        }
        if (isset($p['output_cap_saved_path'])) {
            $meta['output_cap_saved_path'] = $p['output_cap_saved_path'];
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

    /**
     * Build a stable block ID from the event payload.
     *
     * When a tool_call_id is available, prefix with output_cap_ for
     * deterministic deduplication.  Otherwise derive from source type
     * and message id or text hash.
     *
     * @param array<string, mixed> $p
     */
    private function buildBlockId(array $p): string
    {
        $toolCallId = (string) ($p['tool_call_id'] ?? '');
        if ('' !== $toolCallId) {
            return 'output_cap_'.$toolCallId;
        }

        $source = (string) ($p['source'] ?? 'system');
        $messageId = (string) ($p['message_id'] ?? '');
        if ('' !== $messageId) {
            return 'notice_'.$source.'_'.$messageId;
        }

        // Fallback: use source + a truncated hash of the text.
        $text = (string) ($p['text'] ?? '');
        $hash = '' !== $text ? substr(md5($text), 0, 12) : bin2hex(random_bytes(4));

        return 'notice_'.$source.'_'.$hash;
    }
}
