<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

/**
 * Canonical transcript delta for TUI/session application.
 *
 * Ordinary streaming/tool updates emit {@see self::MODE_INCREMENTAL} with only
 * dirty upserts and explicit removals. Bootstrap, resume, history-position replace,
 * and projector reset use {@see self::MODE_FULL}.
 *
 * When rolling compaction retention advances, incremental deltas may also carry
 * {@see $retentionFloorBlockId}: the previous compaction.completed marker that is
 * now the retained floor. Session application must drop local UI blocks strictly
 * before that floor while keeping newer locals.
 */
final readonly class TranscriptChangeSet
{
    public const string MODE_FULL = 'full';
    public const string MODE_INCREMENTAL = 'incremental';

    /**
     * @param list<TranscriptBlock> $upserts               Ordered dirty/appended/updated blocks (incremental)
     * @param list<string>          $removals              Removed block IDs (incremental)
     * @param list<TranscriptBlock> $blocks                Complete ordered snapshot (full only)
     * @param string|null           $retentionFloorBlockId Previous compaction.completed id when retention advanced
     */
    private function __construct(
        public string $mode,
        public array $upserts = [],
        public array $removals = [],
        public array $blocks = [],
        public ?string $retentionFloorBlockId = null,
    ) {
    }

    /**
     * @param list<TranscriptBlock> $blocks
     */
    public static function full(array $blocks): self
    {
        return new self(mode: self::MODE_FULL, blocks: $blocks);
    }

    /**
     * @param list<TranscriptBlock> $upserts
     * @param list<string>          $removals
     */
    public static function incremental(array $upserts, array $removals = [], ?string $retentionFloorBlockId = null): self
    {
        return new self(
            mode: self::MODE_INCREMENTAL,
            upserts: $upserts,
            removals: $removals,
            retentionFloorBlockId: $retentionFloorBlockId,
        );
    }

    public function isFull(): bool
    {
        return self::MODE_FULL === $this->mode;
    }

    public function isEmpty(): bool
    {
        if ($this->isFull()) {
            return false;
        }

        return [] === $this->upserts && [] === $this->removals && null === $this->retentionFloorBlockId;
    }

    /**
     * Ordered blocks for full replacement; empty list for pure incremental deltas.
     *
     * @return list<TranscriptBlock>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }
}
