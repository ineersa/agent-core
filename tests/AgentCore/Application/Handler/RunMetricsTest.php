<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use PHPUnit\Framework\TestCase;

final class RunMetricsTest extends TestCase
{
    public function testSnapshotExposesConfiguredMetricFamilies(): void
    {
        $metrics = new RunMetrics();

        $metrics->recordRunStatusTransition(RunStatus::Queued, RunStatus::Running);
        $metrics->setCommandQueueLag('run-metrics-1', 3);

        $metrics->recordTurnStarted('run-metrics-1', 1);
        $metrics->recordTurnCompleted('run-metrics-1', 1);

        $metrics->recordLlmLatency(125.0, false);
        $metrics->recordLlmLatency(90.0, true);

        $metrics->recordToolLatency(40.0, false, false);
        $metrics->recordToolLatency(275.0, true, true);

        $metrics->incrementStaleResultCount(2);
        $metrics->recordOperationalStatusRead(false, false, 1.0);
        $metrics->recordOperationalStatusRead(true, true, 2.0);
        $metrics->recordProjectionReplacement(true, 'parent', 2, 1, 42, 3.0);
        $metrics->recordProjectionReplacement(false, 'child', 0, 0, 0, 4.0);
        $metrics->recordActiveContextCacheHit();
        $metrics->recordCanonicalReplay(4, true, 5.0);
        $metrics->recordCanonicalReplay(0, false, 6.0);
        $metrics->recordStartupCleanup(true, 3, 7.0);
        $metrics->recordStartupCleanup(false, 0, 8.0);
        $metrics->incrementOwnerFenceConflicts();

        $snapshot = $metrics->snapshot();

        $this->assertSame(1, $snapshot['active_runs_by_status']['running']);
        $this->assertSame(1, $snapshot['turn_duration_ms']['count']);

        $this->assertSame(2, $snapshot['llm']['calls']);
        $this->assertSame(1, $snapshot['llm']['errors']);
        $this->assertSame(0.5, $snapshot['llm']['error_rate']);

        $this->assertSame(2, $snapshot['tools']['calls']);
        $this->assertSame(1, $snapshot['tools']['errors']);
        $this->assertSame(1, $snapshot['tools']['timeouts']);
        $this->assertSame(0.5, $snapshot['tools']['timeout_rate']);

        $this->assertSame(3, $snapshot['command_queue_lag']['max']);
        $this->assertArrayNotHasKey('by_run', $snapshot['command_queue_lag']);
        $this->assertStringNotContainsString('run-metrics-1', json_encode($snapshot, \JSON_THROW_ON_ERROR));

        $this->assertSame(2, $snapshot['stale_result_count']);
        $this->assertSame(['attempts' => 2, 'misses' => 1, 'errors' => 1], array_intersect_key($snapshot['operational_status_reads'], array_flip(['attempts', 'misses', 'errors'])));
        $this->assertSame(1, $snapshot['projection_replacements']['successes']);
        $this->assertSame(1, $snapshot['projection_replacements']['errors']);
        $this->assertSame(['state' => 1, 'tool' => 2, 'human' => 1], $snapshot['projection_replacements']['rows_written']);
        $this->assertSame(['parent' => 1, 'child' => 0], $snapshot['projection_replacements']['owner_kind']);
        $this->assertSame(42, $snapshot['projection_replacements']['logical_scalar_bytes']);
        $this->assertSame(1, $snapshot['active_context']['cache_hits']);
        $this->assertSame(2, $snapshot['active_context']['cache_misses']);
        $this->assertSame(4, $snapshot['active_context']['canonical_replay']['events']);
        $this->assertSame(1, $snapshot['run_control_owner']['startup_cleanup_errors']);
        $this->assertSame(3, $snapshot['run_control_owner']['startup_cleanup_state_rows_deleted']);
        $this->assertSame(1, $snapshot['run_control_owner']['fence_conflicts']);
    }
}
