<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Run\RunStatus;

/**
 * This class collects in-process runtime metrics for run orchestration and workers.
 */
final class RunMetrics
{
    /** @var array<string, int> */
    private array $activeRunsByStatus = [];

    /** @var array<string, int> */
    private array $commandQueueLagByRun = [];

    /** @var array<string, float> */
    private array $turnStartedAtMsByRunTurn = [];

    private int $llmCalls = 0;

    private int $llmErrors = 0;

    private int $toolCalls = 0;

    private int $toolErrors = 0;

    private int $toolTimeouts = 0;

    private int $staleResultCount = 0;

    /** @var array<'canonical_events'|'jsonl_fallback', int> */
    private array $replayRebuildBySource = [
        'canonical_events' => 0,
        'jsonl_fallback' => 0,
    ];

    private LatencyHistogram $turnDurationHistogram;

    private LatencyHistogram $llmLatencyHistogram;

    private LatencyHistogram $toolLatencyHistogram;

    /**
     * Initializes metric histograms with stable latency buckets.
     */
    public function __construct()
    {
        $this->turnDurationHistogram = new LatencyHistogram([50, 100, 250, 500, 1_000, 2_500, 5_000]);
        $this->llmLatencyHistogram = new LatencyHistogram([100, 250, 500, 1_000, 2_500, 5_000, 10_000, 20_000]);
        $this->toolLatencyHistogram = new LatencyHistogram([25, 50, 100, 250, 500, 1_000, 2_500, 5_000, 10_000]);

        foreach (RunStatus::cases() as $status) {
            $this->activeRunsByStatus[$status->value] = 0;
        }
    }

    /**
     * Updates active run gauges after a status transition.
     */
    public function recordRunStatusTransition(RunStatus $from, RunStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        if (($this->activeRunsByStatus[$from->value] ?? 0) > 0) {
            --$this->activeRunsByStatus[$from->value];
        }

        $this->activeRunsByStatus[$to->value] = ($this->activeRunsByStatus[$to->value] ?? 0) + 1;
    }

    /**
     * Records the latest command mailbox lag for a run.
     */
    public function setCommandQueueLag(string $runId, int $pendingCount): void
    {
        $this->commandQueueLagByRun[$runId] = max(0, $pendingCount);
    }

    /**
     * Marks the start timestamp for a run turn duration measurement.
     */
    public function recordTurnStarted(string $runId, int $turnNo): void
    {
        if ($turnNo < 1) {
            return;
        }

        $this->turnStartedAtMsByRunTurn[$this->turnKey($runId, $turnNo)] = microtime(true) * 1000;
    }

    /**
     * Records turn duration when a tracked turn reaches a completion boundary.
     */
    public function recordTurnCompleted(string $runId, int $turnNo): void
    {
        if ($turnNo < 1) {
            return;
        }

        $turnKey = $this->turnKey($runId, $turnNo);
        $startedAt = $this->turnStartedAtMsByRunTurn[$turnKey] ?? null;

        if (null === $startedAt) {
            return;
        }

        unset($this->turnStartedAtMsByRunTurn[$turnKey]);

        $durationMs = (microtime(true) * 1000) - $startedAt;
        $this->turnDurationHistogram->observe($durationMs);
    }

    /**
     * Records LLM invocation latency and updates error totals.
     */
    public function recordLlmLatency(float $durationMs, bool $isError): void
    {
        ++$this->llmCalls;
        if ($isError) {
            ++$this->llmErrors;
        }

        $this->llmLatencyHistogram->observe($durationMs);
    }

    /**
     * Records tool execution latency and tracks error/timeout counters.
     */
    public function recordToolLatency(float $durationMs, bool $isError, bool $isTimeout): void
    {
        ++$this->toolCalls;

        if ($isError) {
            ++$this->toolErrors;
        }

        if ($isTimeout) {
            ++$this->toolTimeouts;
        }

        $this->toolLatencyHistogram->observe($durationMs);
    }

    /**
     * Increments stale result counter when stale executor outcomes are ignored.
     */
    public function incrementStaleResultCount(int $by = 1): void
    {
        if ($by < 1) {
            return;
        }

        $this->staleResultCount += $by;
    }

    /**
     * Increments rebuild counter grouped by replay source category.
     */
    public function incrementReplayRebuildCount(string $source): void
    {
        $metricSource = 'jsonl_fallback' === $source ? 'jsonl_fallback' : 'canonical_events';
        ++$this->replayRebuildBySource[$metricSource];
    }

    /**
     * Returns a snapshot consumable by debug tooling and dashboards.
     *
     * @return array{
     * active_runs_by_status: array<string, int>,
     * turn_duration_ms: array{count: int, min_ms: ?float, max_ms: ?float, avg_ms: float, buckets: array<string, int>},
     * llm: array{calls: int, errors: int, error_rate: float, latency_ms: array{count: int, min_ms: ?float, max_ms: ?float, avg_ms: float, buckets: array<string, int>}},
     * tools: array{calls: int, errors: int, error_rate: float, timeouts: int, timeout_rate: float, latency_ms: array{count: int, min_ms: ?float, max_ms: ?float, avg_ms: float, buckets: array<string, int>}},
     * command_queue_lag: array{runs_tracked: int, sum: int, max: int, by_run: array<string, int>},
     * stale_result_count: int,
     * replay_rebuild_count: int,
     * replay_rebuild_by_source: array<'canonical_events'|'jsonl_fallback', int>
     * }
     */
    public function snapshot(): array
    {
        $queueLagValues = array_values($this->commandQueueLagByRun);

        return [
            'active_runs_by_status' => $this->activeRunsByStatus,
            'turn_duration_ms' => $this->turnDurationHistogram->snapshot(),
            'llm' => [
                'calls' => $this->llmCalls,
                'errors' => $this->llmErrors,
                'error_rate' => $this->rate($this->llmErrors, $this->llmCalls),
                'latency_ms' => $this->llmLatencyHistogram->snapshot(),
            ],
            'tools' => [
                'calls' => $this->toolCalls,
                'errors' => $this->toolErrors,
                'error_rate' => $this->rate($this->toolErrors, $this->toolCalls),
                'timeouts' => $this->toolTimeouts,
                'timeout_rate' => $this->rate($this->toolTimeouts, $this->toolCalls),
                'latency_ms' => $this->toolLatencyHistogram->snapshot(),
            ],
            'command_queue_lag' => [
                'runs_tracked' => \count($this->commandQueueLagByRun),
                'sum' => (int) array_sum($queueLagValues),
                'max' => [] === $queueLagValues ? 0 : max($queueLagValues),
                'by_run' => $this->commandQueueLagByRun,
            ],
            'stale_result_count' => $this->staleResultCount,
            'replay_rebuild_count' => (int) array_sum($this->replayRebuildBySource),
            'replay_rebuild_by_source' => $this->replayRebuildBySource,
        ];
    }

    /**
     * Builds a deterministic key for a run/turn pair.
     */
    private function turnKey(string $runId, int $turnNo): string
    {
        return \sprintf('%s|%d', $runId, $turnNo);
    }

    /**
     * Computes a rounded ratio while guarding against division by zero.
     */
    private function rate(int $numerator, int $denominator): float
    {
        if (0 === $denominator) {
            return 0.0;
        }

        return round($numerator / $denominator, 6);
    }
}
