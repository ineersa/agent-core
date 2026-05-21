<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;

/**
 * Creates transcript blocks for TUI-local messages.
 *
 * Runtime events are projected by the runtime projector; this factory covers
 * local UI messages such as welcome text, slash-command responses, and the
 * transient processing indicator.
 */
final readonly class TranscriptBlockFactory
{
    public function user(string $runId, string $text, int $seq): TranscriptBlock
    {
        return $this->block(
            runId: $runId,
            kind: TranscriptBlockKindEnum::UserMessage,
            idPrefix: 'tui_user',
            text: $text,
            seq: $seq,
        );
    }

    public function system(string $runId, string $text, int $seq, string $style = ''): TranscriptBlock
    {
        $meta = [];
        if ('' !== $style) {
            $meta['style'] = $style;
        }

        return $this->block(
            runId: $runId,
            kind: TranscriptBlockKindEnum::System,
            idPrefix: 'tui_system',
            text: $text,
            seq: $seq,
            meta: $meta,
        );
    }

    public function error(string $runId, string $text, int $seq): TranscriptBlock
    {
        return $this->block(
            runId: $runId,
            kind: TranscriptBlockKindEnum::Error,
            idPrefix: 'tui_error',
            text: $text,
            seq: $seq,
        );
    }

    /** @param array<string, mixed> $meta */
    private function block(
        string $runId,
        TranscriptBlockKindEnum $kind,
        string $idPrefix,
        string $text,
        int $seq,
        array $meta = [],
    ): TranscriptBlock {
        return new TranscriptBlock(
            id: \sprintf('%s_%s_%d', $idPrefix, $runId, $seq),
            kind: $kind,
            runId: $runId,
            seq: $seq,
            text: $text,
            meta: $meta,
        );
    }
}
