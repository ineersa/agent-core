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
        $metrics->incrementReplayRebuildCount('canonical_events');
        $metrics->incrementReplayRebuildCount('jsonl_fallback');

        $snapshot = $metrics->snapshot();

        self::assertSame(1, $snapshot['active_runs_by_status']['running']);
        self::assertSame(1, $snapshot['turn_duration_ms']['count']);

        self::assertSame(2, $snapshot['llm']['calls']);
        self::assertSame(1, $snapshot['llm']['errors']);
        self::assertSame(0.5, $snapshot['llm']['error_rate']);

        self::assertSame(2, $snapshot['tools']['calls']);
        self::assertSame(1, $snapshot['tools']['errors']);
        self::assertSame(1, $snapshot['tools']['timeouts']);
        self::assertSame(0.5, $snapshot['tools']['timeout_rate']);

        self::assertSame(3, $snapshot['command_queue_lag']['max']);
        self::assertSame(3, $snapshot['command_queue_lag']['by_run']['run-metrics-1']);

        self::assertSame(2, $snapshot['stale_result_count']);
        self::assertSame(2, $snapshot['replay_rebuild_count']);
        self::assertSame(1, $snapshot['replay_rebuild_by_source']['canonical_events']);
        self::assertSame(1, $snapshot['replay_rebuild_by_source']['jsonl_fallback']);
    }
}
