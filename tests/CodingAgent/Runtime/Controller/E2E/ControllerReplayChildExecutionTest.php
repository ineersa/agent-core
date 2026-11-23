<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

#[Group('controller-replay')]
final class ControllerReplayChildExecutionTest extends ControllerReplayE2eTestCase
{
    public function testNativeChildLifecycleReachesRootControllerStream(): void
    {
        $this->assertSame($this->tempDir, realpath($this->tempDir));
        (new Filesystem())->dumpFile($this->tempDir.'/.agents/scout.md', "---\nname: scout\ndescription: Replay scout\n---\nProvide a short handoff.\n");
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->writeCommand(['v' => 1, 'id' => 'start-child-replay', 'type' => 'start_run', 'payload' => ['prompt' => 'Launch the scout.']]);

        $events = $this->collectEventsUntil('child_execution.ended', 8.0);
        $byType = $this->indexByType($events);
        $this->assertStartRunAcked($events, 'start-child-replay');
        $root = $byType['run.started'][0]['runId'] ?? null;
        $start = $byType['child_execution.started'][0] ?? null;
        $end = $byType['child_execution.ended'][0] ?? null;
        $this->assertNotNull($root, $this->collectDiagnostics($events));
        $this->assertNotNull($start, $this->collectDiagnostics($events));
        $this->assertNotNull($end, $this->collectDiagnostics($events));
        $this->runId = $root;
        $child = $start['payload']['child_run_id'];
        $this->assertNotSame($root, $child);
        foreach ([$start, $end] as $event) {
            $this->assertSame($root, $event['runId']);
            $this->assertSame(0, $event['seq']);
            $this->assertSame($root, $event['payload']['parent_run_id']);
            $this->assertSame($child, $event['payload']['child_run_id']);
        }
        $this->assertGreaterThan($start['payload']['child_sequence'], $end['payload']['child_sequence']);
        $this->assertSame('unknown', $end['payload']['outcome']);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-child-execution';
    }

    protected function replayFixtures(): array
    {
        $base = ['model' => 'llama_cpp_test/test', 'provider_id' => 'llama_cpp_test', 'reasoning' => 'off'];
        $arguments = ['agent' => 'scout', 'task' => 'Provide a short handoff.'];
        $call = ['id' => 'call_scout', 'name' => 'subagent'];

        return [
            $base + ['stop_reason' => 'tool_call', 'deltas' => [
                ['type' => 'tool_call_start'] + $call,
                ['type' => 'tool_input_delta', 'id' => 'call_scout', 'partial_json' => json_encode($arguments, \JSON_THROW_ON_ERROR)],
                ['type' => 'tool_call_complete', 'tool_calls' => [$call + ['arguments' => $arguments]]],
            ]],
            $base + ['stop_reason' => 'stop', 'deltas' => [['type' => 'text', 'content' => 'Scout handoff.']]],
            $base + ['stop_reason' => 'stop', 'deltas' => [['type' => 'text', 'content' => 'Parent received the handoff.']]],
        ];
    }
}
