<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Run\RunStatus;

/** Aggregate-only runtime metrics. Snapshot output never contains run or session identities. */
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
    private int $operationalStatusReadAttempts = 0;
    private int $operationalStatusReadMisses = 0;
    private int $operationalStatusReadErrors = 0;
    private int $projectionReplacementSuccesses = 0;
    private int $projectionReplacementErrors = 0;
    /** @var array<'state'|'tool'|'human', int> */
    private array $projectionRowsWritten = ['state' => 0, 'tool' => 0, 'human' => 0];
    /** @var array<'parent'|'child', int> */
    private array $projectionWritesByOwnerKind = ['parent' => 0, 'child' => 0];
    private int $projectionLogicalScalarBytes = 0;
    private int $activeContextCacheHits = 0;
    private int $activeContextCacheMisses = 0;
    private int $canonicalReplayCount = 0;
    private int $canonicalReplayEventCount = 0;
    private int $canonicalReplaySuccesses = 0;
    private int $canonicalReplayErrors = 0;
    private int $startupCleanupAttempts = 0;
    private int $startupCleanupErrors = 0;
    private int $startupCleanupStateRowsDeleted = 0;
    private int $ownerFenceConflicts = 0;
    private LatencyHistogram $turnDurationHistogram;
    private LatencyHistogram $llmLatencyHistogram;
    private LatencyHistogram $toolLatencyHistogram;
    private LatencyHistogram $operationalStatusReadDurationHistogram;
    private LatencyHistogram $projectionWriteDurationHistogram;
    private LatencyHistogram $canonicalReplayDurationHistogram;
    private LatencyHistogram $startupCleanupDurationHistogram;

    public function __construct()
    {
        $this->turnDurationHistogram = new LatencyHistogram([50, 100, 250, 500, 1_000, 2_500, 5_000]);
        $this->llmLatencyHistogram = new LatencyHistogram([100, 250, 500, 1_000, 2_500, 5_000, 10_000, 20_000]);
        $this->toolLatencyHistogram = new LatencyHistogram([25, 50, 100, 250, 500, 1_000, 2_500, 5_000, 10_000]);
        $this->operationalStatusReadDurationHistogram = new LatencyHistogram([1, 5, 10, 25, 50, 100, 250]);
        $this->projectionWriteDurationHistogram = new LatencyHistogram([1, 5, 10, 25, 50, 100, 250]);
        $this->canonicalReplayDurationHistogram = new LatencyHistogram([1, 5, 10, 25, 50, 100, 250, 1_000]);
        $this->startupCleanupDurationHistogram = new LatencyHistogram([1, 5, 10, 25, 50, 100, 250]);
        foreach (RunStatus::cases() as $status) {
            $this->activeRunsByStatus[$status->value] = 0;
        }
    }

    public function recordRunStatusTransition(RunStatus $from, RunStatus $to): void
    {
        if ($from !== $to) {
            if (($this->activeRunsByStatus[$from->value] ?? 0) > 0) {
                --$this->activeRunsByStatus[$from->value];
            } $this->activeRunsByStatus[$to->value] = ($this->activeRunsByStatus[$to->value] ?? 0) + 1;
        }
    }

    public function setCommandQueueLag(string $runId, int $pendingCount): void
    {
        $this->commandQueueLagByRun[$runId] = max(0, $pendingCount);
    }

    public function recordTurnStarted(string $runId, int $turnNo): void
    {
        if ($turnNo >= 1) {
            $this->turnStartedAtMsByRunTurn[$this->turnKey($runId, $turnNo)] = microtime(true) * 1000;
        }
    }

    public function recordTurnCompleted(string $runId, int $turnNo): void
    {
        if ($turnNo < 1) {
            return;
        } $key = $this->turnKey($runId, $turnNo);
        $started = $this->turnStartedAtMsByRunTurn[$key] ?? null;
        if (null !== $started) {
            unset($this->turnStartedAtMsByRunTurn[$key]);
            $this->turnDurationHistogram->observe((microtime(true) * 1000) - $started);
        }
    }

    /** @return list<callable(): void> */
    public function turnCompletedCallback(string $runId, int $turnNo): array
    {
        return [fn () => $this->recordTurnCompleted($runId, $turnNo)];
    }

    public function recordLlmLatency(float $durationMs, bool $isError): void
    {
        ++$this->llmCalls;
        if ($isError) {
            ++$this->llmErrors;
        } $this->llmLatencyHistogram->observe($durationMs);
    }

    public function recordToolLatency(float $durationMs, bool $isError, bool $isTimeout): void
    {
        ++$this->toolCalls;
        if ($isError) {
            ++$this->toolErrors;
        } if ($isTimeout) {
            ++$this->toolTimeouts;
        } $this->toolLatencyHistogram->observe($durationMs);
    }

    public function incrementStaleResultCount(int $by = 1): void
    {
        if ($by >= 1) {
            $this->staleResultCount += $by;
        }
    }

    public function incrementReplayRebuildCount(string $source): void
    { /* retained compatibility aggregate; cache-miss metrics are authoritative */
    }

    public function recordOperationalStatusRead(bool $miss, bool $error, float $durationMs): void
    {
        ++$this->operationalStatusReadAttempts;
        if ($miss) {
            ++$this->operationalStatusReadMisses;
        } if ($error) {
            ++$this->operationalStatusReadErrors;
        } $this->operationalStatusReadDurationHistogram->observe($durationMs);
    }

    public function recordProjectionReplacement(bool $success, string $ownerKind, int $toolRows, int $humanRows, int $logicalScalarBytes, float $durationMs): void
    {
        if ($success) {
            ++$this->projectionReplacementSuccesses;
            ++$this->projectionRowsWritten['state'];
            $this->projectionRowsWritten['tool'] += $toolRows;
            $this->projectionRowsWritten['human'] += $humanRows;
            ++$this->projectionWritesByOwnerKind[$ownerKind];
            $this->projectionLogicalScalarBytes += $logicalScalarBytes;
        } else {
            ++$this->projectionReplacementErrors;
        } $this->projectionWriteDurationHistogram->observe($durationMs);
    }

    public function recordActiveContextCacheHit(): void
    {
        ++$this->activeContextCacheHits;
    }

    public function recordCanonicalReplay(int $eventCount, bool $success, float $durationMs): void
    {
        ++$this->activeContextCacheMisses;
        ++$this->canonicalReplayCount;
        $this->canonicalReplayEventCount += max(0, $eventCount);
        if ($success) {
            ++$this->canonicalReplaySuccesses;
        } else {
            ++$this->canonicalReplayErrors;
        } $this->canonicalReplayDurationHistogram->observe($durationMs);
    }

    public function recordStartupCleanup(bool $success, int $stateRowsDeleted, float $durationMs): void
    {
        ++$this->startupCleanupAttempts;
        if ($success) {
            $this->startupCleanupStateRowsDeleted += max(0, $stateRowsDeleted);
        } else {
            ++$this->startupCleanupErrors;
        } $this->startupCleanupDurationHistogram->observe($durationMs);
    }

    public function incrementOwnerFenceConflicts(): void
    {
        ++$this->ownerFenceConflicts;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $queueLagValues = array_values($this->commandQueueLagByRun);

        return [
            'active_runs_by_status' => $this->activeRunsByStatus,
            'turn_duration_ms' => $this->turnDurationHistogram->snapshot(),
            'llm' => ['calls' => $this->llmCalls, 'errors' => $this->llmErrors, 'error_rate' => $this->rate($this->llmErrors, $this->llmCalls), 'latency_ms' => $this->llmLatencyHistogram->snapshot()],
            'tools' => ['calls' => $this->toolCalls, 'errors' => $this->toolErrors, 'error_rate' => $this->rate($this->toolErrors, $this->toolCalls), 'timeouts' => $this->toolTimeouts, 'timeout_rate' => $this->rate($this->toolTimeouts, $this->toolCalls), 'latency_ms' => $this->toolLatencyHistogram->snapshot()],
            'command_queue_lag' => ['runs_tracked' => \count($this->commandQueueLagByRun), 'sum' => (int) array_sum($queueLagValues), 'max' => [] === $queueLagValues ? 0 : max($queueLagValues)],
            'stale_result_count' => $this->staleResultCount,
            'operational_status_reads' => ['attempts' => $this->operationalStatusReadAttempts, 'misses' => $this->operationalStatusReadMisses, 'errors' => $this->operationalStatusReadErrors, 'duration_ms' => $this->operationalStatusReadDurationHistogram->snapshot()],
            'projection_replacements' => ['successes' => $this->projectionReplacementSuccesses, 'errors' => $this->projectionReplacementErrors, 'rows_written' => $this->projectionRowsWritten, 'owner_kind' => $this->projectionWritesByOwnerKind, 'logical_scalar_bytes' => $this->projectionLogicalScalarBytes, 'duration_ms' => $this->projectionWriteDurationHistogram->snapshot()],
            'active_context' => ['cache_hits' => $this->activeContextCacheHits, 'cache_misses' => $this->activeContextCacheMisses, 'canonical_replay' => ['count' => $this->canonicalReplayCount, 'events' => $this->canonicalReplayEventCount, 'successes' => $this->canonicalReplaySuccesses, 'errors' => $this->canonicalReplayErrors, 'duration_ms' => $this->canonicalReplayDurationHistogram->snapshot()]],
            'run_control_owner' => ['startup_cleanup_attempts' => $this->startupCleanupAttempts, 'startup_cleanup_errors' => $this->startupCleanupErrors, 'startup_cleanup_state_rows_deleted' => $this->startupCleanupStateRowsDeleted, 'startup_cleanup_duration_ms' => $this->startupCleanupDurationHistogram->snapshot(), 'fence_conflicts' => $this->ownerFenceConflicts],
        ];
    }

    private function turnKey(string $runId, int $turnNo): string
    {
        return \sprintf('%s|%d', $runId, $turnNo);
    }

    private function rate(int $numerator, int $denominator): float
    {
        return 0 === $denominator ? 0.0 : round($numerator / $denominator, 6);
    }
}
