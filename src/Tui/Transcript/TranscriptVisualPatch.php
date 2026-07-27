<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

/**
 * Production visual reconciliation contract for {@see TranscriptMountedWidget}.
 *
 * Ordinary tail stream/update emits {@see self::MODE_INCREMENTAL} with only the
 * changed visual keys. Bootstrap/resume/leaf/preview/non-tail/reorder emit
 * {@see self::MODE_FULL} with the complete ordered visual snapshot.
 */
final readonly class TranscriptVisualPatch
{
    public const string MODE_FULL = 'full';
    public const string MODE_INCREMENTAL = 'incremental';

    /**
     * @param list<TranscriptVisualNode> $upserts  Changed or new visual nodes
     * @param list<string>               $removals Removed visual keys
     * @param list<string>               $order    Full key order (always set; structural truth)
     * @param list<TranscriptVisualNode> $nodes    Full node snapshot (full mode only)
     */
    private function __construct(
        public string $mode,
        public array $upserts = [],
        public array $removals = [],
        public array $order = [],
        public array $nodes = [],
    ) {
    }

    /**
     * @param list<TranscriptVisualNode> $nodes
     */
    public static function full(array $nodes): self
    {
        $order = [];
        foreach ($nodes as $node) {
            $order[] = $node->key;
        }

        return new self(
            mode: self::MODE_FULL,
            upserts: $nodes,
            order: $order,
            nodes: $nodes,
        );
    }

    /**
     * @param list<TranscriptVisualNode> $upserts
     * @param list<string>               $removals
     * @param list<string>               $order
     */
    public static function incremental(array $upserts, array $removals, array $order): self
    {
        return new self(
            mode: self::MODE_INCREMENTAL,
            upserts: $upserts,
            removals: $removals,
            order: $order,
        );
    }

    public function isFull(): bool
    {
        return self::MODE_FULL === $this->mode;
    }

    /**
     * Stable keys touched by this patch (upserts + removals). Operation-scope contract.
     *
     * @return list<string>
     */
    public function touchedKeys(): array
    {
        $keys = $this->removals;
        foreach ($this->upserts as $node) {
            $keys[] = $node->key;
        }

        return array_values(array_unique($keys));
    }
}
