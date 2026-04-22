<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

final class LatencyHistogram
{
    private int $count = 0;

    private float $sumMs = 0.0;

    private ?float $minMs = null;

    private ?float $maxMs = null;

    /** @var array<int, int> */
    private array $bucketCounts = [];

    /**
     * Initializes histogram bucket boundaries in ascending milliseconds order.
     *
     * @param list<int> $bucketBoundsMs
     */
    public function __construct(private readonly array $bucketBoundsMs)
    {
        foreach ($bucketBoundsMs as $index => $boundMs) {
            if ($boundMs < 1) {
                throw new \InvalidArgumentException('Histogram bucket bounds must be positive integers.');
            }

            if ($index > 0 && $boundMs <= $bucketBoundsMs[$index - 1]) {
                throw new \InvalidArgumentException('Histogram bucket bounds must be strictly ascending.');
            }

            $this->bucketCounts[$index] = 0;
        }

        $this->bucketCounts[\count($bucketBoundsMs)] = 0;
    }

    public function observe(float $durationMs): void
    {
        $valueMs = max(0.0, $durationMs);

        ++$this->count;
        $this->sumMs += $valueMs;
        $this->minMs = null === $this->minMs ? $valueMs : min($this->minMs, $valueMs);
        $this->maxMs = null === $this->maxMs ? $valueMs : max($this->maxMs, $valueMs);

        $bucketIndex = \count($this->bucketBoundsMs);

        foreach ($this->bucketBoundsMs as $index => $boundMs) {
            if ($valueMs <= $boundMs) {
                $bucketIndex = $index;

                break;
            }
        }

        ++$this->bucketCounts[$bucketIndex];
    }

    /**
     * Returns a stable array representation of current histogram statistics.
     *
     * @return array{count: int, min_ms: ?float, max_ms: ?float, avg_ms: float, buckets: array<string, int>}
     */
    public function snapshot(): array
    {
        $buckets = [];

        foreach ($this->bucketBoundsMs as $index => $boundMs) {
            $buckets[\sprintf('<=%dms', $boundMs)] = $this->bucketCounts[$index] ?? 0;
        }

        $overflowIndex = \count($this->bucketBoundsMs);
        $overflowLabel = [] === $this->bucketBoundsMs
            ? '>inf'
            : \sprintf('>%dms', $this->bucketBoundsMs[array_key_last($this->bucketBoundsMs)]);

        $buckets[$overflowLabel] = $this->bucketCounts[$overflowIndex] ?? 0;

        return [
            'count' => $this->count,
            'min_ms' => $this->minMs,
            'max_ms' => $this->maxMs,
            'avg_ms' => 0 === $this->count ? 0.0 : round($this->sumMs / $this->count, 3),
            'buckets' => $buckets,
        ];
    }
}
