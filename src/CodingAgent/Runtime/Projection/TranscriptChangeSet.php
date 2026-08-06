<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

/**
 * Canonical transcript delta for TUI/session application.
 *
 * Ordinary streaming/tool updates emit {@see self::MODE_INCREMENTAL} with only
 * dirty upserts and explicit removals. Bootstrap, resume, history-position replace,
 * and projector reset use {@see self::MODE_FULL}.
 */
final readonly class TranscriptChangeSet
{
    public const string MODE_FULL = 'full';
    public const string MODE_INCREMENTAL = 'incremental';

    /**
     * @param list<TranscriptBlock> $upserts  Ordered dirty/appended/updated blocks (incremental)
     * @param list<string>          $removals Removed block IDs (incremental)
     * @param list<TranscriptBlock> $blocks   Complete ordered snapshot (full only)
     */
    private function __construct(
        public string $mode,
        public array $upserts = [],
        public array $removals = [],
        public array $blocks = [],
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
    public static function incremental(array $upserts, array $removals = []): self
    {
        return new self(mode: self::MODE_INCREMENTAL, upserts: $upserts, removals: $removals);
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

        return [] === $this->upserts && [] === $this->removals;
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
