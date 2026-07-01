<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CompactionProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjectionEvent;
use PHPUnit\Framework\TestCase;

final class CompactionProjectionSubscriberTest extends TestCase
{
    private CompactionProjectionSubscriber $subscriber;
    private TranscriptProjectionState $state;

    protected function setUp(): void
    {
        $this->subscriber = new CompactionProjectionSubscriber();
        $this->state = new TranscriptProjectionState();
    }

    /**
     * Subject: compaction.completed block text never includes token estimates.
     *
     * The internal CompactionTokenEstimator uses a text-only heuristic that
     * excludes tool definitions / JSON envelope, producing token counts
     * that differ from the provider input_tokens used for auto-trigger
     * thresholds.  Displaying both estimates side-by-side is confusing.
     *
     * Projection text is glyph-free ('Conversation compacted.'); the TUI renderer
     * owns the ⧉ lifecycle prefix via meta.lifecycle — no Token estimate: banner.
     */

    public function testCompactionStartedTextIsGlyphFree(): void
    {
        $event = new TranscriptProjectionEvent(
            rawEvent: [
                'type' => 'compaction.started',
                'runId' => 'run-1',
                'seq' => $this->state->nextSeq(),
                'payload' => [],
            ],
            state: $this->state,
        );

        $this->subscriber->onCompactionStarted($event);

        $blocks = $this->state->blocks();
        self::assertCount(1, $blocks);
        self::assertSame('Compacting conversation', $blocks[0]->text);
        self::assertStringNotContainsString('◐', $blocks[0]->text);
        self::assertSame('compaction_started', $blocks[0]->meta['lifecycle'] ?? null);
    }

    public function testCompactionCompletedTextIsGlyphFree(): void
    {
        $event = $this->makeCompactionCompletedEvent(100, 50);

        $this->subscriber->onCompactionCompleted($event);

        $block = $this->state->blocks()[0];
        self::assertSame('Conversation compacted.', $block->text);
        self::assertStringNotContainsString('⧉', $block->text);
    }

    public function testCompactedTextNeverShowsTokenEstimate(): void
    {
        $event = $this->makeCompactionCompletedEvent(
            estimatedTokensBefore: 12708,
            estimatedTokensAfter: 7255,
        );

        $this->subscriber->onCompactionCompleted($event);

        $blocks = $this->state->blocks();
        self::assertCount(1, $blocks, 'Expected one transcript block.');

        $block = $blocks[0];
        self::assertSame(
            TranscriptBlockKindEnum::System,
            $block->kind,
            'Compaction completed block should be System kind.',
        );
        self::assertStringNotContainsString(
            'Token estimate',
            $block->text,
            'User-visible text must not include token estimates.',
        );
        self::assertStringContainsString(
            'Conversation compacted',
            $block->text,
            'Text should contain the compressed-intro message.',
        );
    }

    /**
     * Subject: structured metadata survives the UX change.
     *
     * The estimated_tokens_before / estimated_tokens_after fields remain
     * in the block meta for diagnostics, tests, and downstream consumers.
     */
    public function testCompactedMetaCarriesTokenEstimates(): void
    {
        $event = $this->makeCompactionCompletedEvent(
            estimatedTokensBefore: 12708,
            estimatedTokensAfter: 7255,
        );

        $this->subscriber->onCompactionCompleted($event);

        $blocks = $this->state->blocks();
        self::assertCount(1, $blocks);

        $block = $blocks[0];
        $meta = $block->meta;

        self::assertArrayHasKey('estimated_tokens_before', $meta);
        self::assertSame(12708, $meta['estimated_tokens_before']);
        self::assertArrayHasKey('estimated_tokens_after', $meta);
        self::assertSame(7255, $meta['estimated_tokens_after']);
    }

    /**
     * Subject: compaction completed block is produced even when
     * estimates are absent (graceful degradation).
     */
    public function testCompactedWorksWithoutEstimates(): void
    {
        $event = $this->makeCompactionCompletedEvent(
            estimatedTokensBefore: null,
            estimatedTokensAfter: null,
        );

        $this->subscriber->onCompactionCompleted($event);

        $blocks = $this->state->blocks();
        self::assertCount(1, $blocks);
        self::assertStringContainsString(
            'Conversation compacted',
            $blocks[0]->text,
        );
        self::assertNull($blocks[0]->meta['estimated_tokens_before']);
        self::assertNull($blocks[0]->meta['estimated_tokens_after']);
    }

    // ── private helpers ────────────────────────────────────────────────

    private function makeCompactionCompletedEvent(
        ?int $estimatedTokensBefore,
        ?int $estimatedTokensAfter,
    ): TranscriptProjectionEvent {
        return new TranscriptProjectionEvent(
            rawEvent: [
                'type' => 'compaction.completed',
                'runId' => 'run-1',
                'seq' => $this->state->nextSeq(),
                'payload' => [
                    'estimated_tokens_before' => $estimatedTokensBefore,
                    'estimated_tokens_after' => $estimatedTokensAfter,
                    'messages_before' => 10,
                    'messages_after' => 5,
                ],
            ],
            state: $this->state,
        );
    }
}
