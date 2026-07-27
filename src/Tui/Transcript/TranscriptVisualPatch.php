<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

/**
 * Production visual reconciliation contract for {@see TranscriptMountedWidget}.
 *
 * - {@see self::full()} — complete ordered snapshot (bootstrap/resume/leaf/reorder).
 * - {@see self::content()} — non-structural content-only upserts (pure stream/update).
 *   No order payload; consumer applies keyed mutations in O(changes).
 * - {@see self::structural()} — incremental with order (append/remove when survivor
 *   relative order is stable). Consumer may scan order for append/remove placement.
 */
final readonly class TranscriptVisualPatch
{
    public const string MODE_FULL = 'full';
    public const string MODE_INCREMENTAL = 'incremental';

    /**
     * @param list<TranscriptVisualNode> $upserts      Changed or new visual nodes
     * @param list<string>               $removals     Removed visual keys
     * @param list<string>|null          $order        Full key order when structure changed; null for content-only
     * @param list<TranscriptVisualNode> $nodes        Full node snapshot (full mode only)
     * @param bool                       $orderChanged True when order is structural truth for this patch
     */
    private function __construct(
        public string $mode,
        public array $upserts = [],
        public array $removals = [],
        public ?array $order = null,
        public array $nodes = [],
        public bool $orderChanged = false,
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
            orderChanged: true,
        );
    }

    /**
     * Content-only (non-structural) incremental patch: keyed upserts/removals only.
     * No order snapshot — mounted reconciler must not scan history/order.
     *
     * @param list<TranscriptVisualNode> $upserts
     * @param list<string>               $removals
     */
    public static function content(array $upserts, array $removals = []): self
    {
        return new self(
            mode: self::MODE_INCREMENTAL,
            upserts: $upserts,
            removals: $removals,
            order: null,
            orderChanged: false,
        );
    }

    /**
     * Structural incremental patch carrying the full key order after the change.
     *
     * @param list<TranscriptVisualNode> $upserts
     * @param list<string>               $removals
     * @param list<string>               $order
     */
    public static function structural(array $upserts, array $removals, array $order): self
    {
        return new self(
            mode: self::MODE_INCREMENTAL,
            upserts: $upserts,
            removals: $removals,
            order: $order,
            orderChanged: true,
        );
    }

    /**
     * @deprecated prefer content() / structural(); kept for call-site migration clarity
     *
     * @param list<TranscriptVisualNode> $upserts
     * @param list<string>               $removals
     * @param list<string>               $order
     */
    public static function incremental(array $upserts, array $removals, array $order): self
    {
        return self::structural($upserts, $removals, $order);
    }

    public function isFull(): bool
    {
        return self::MODE_FULL === $this->mode;
    }

    /**
     * True when this patch is content-only (no order payload, no structural mutation).
     */
    public function isContentOnly(): bool
    {
        return self::MODE_INCREMENTAL === $this->mode && !$this->orderChanged;
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
