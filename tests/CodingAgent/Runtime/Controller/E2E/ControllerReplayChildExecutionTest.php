<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[Group('controller-replay')]
final class ControllerReplayChildExecutionTest extends ControllerReplayE2eTestCase
{
    /** @var resource */
    private $gate;

    protected function tearDown(): void
    {
        if (isset($this->gate)) {
            fclose($this->gate);
        }
        parent::tearDown();
    }

    public function testNativeChildLifecycleReachesRootControllerStream(): void
    {
        $this->assertSame($this->tempDir, realpath($this->tempDir));
        (new Filesystem())->dumpFile($this->tempDir.'/.agents/scout.md', "---\nname: scout\ndescription: Replay scout\ntools: bash\n---\nProvide a short handoff.\n");
        $seed = new Process([\PHP_BINARY, __DIR__.'/Support/ForegroundBashCandidates.php', $this->projectDir], $this->tempDir,
            array_merge($this->controllerSubprocessEnv(), ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'HATFIELD_CWD' => $this->tempDir, 'HOME' => $this->tempDir,
                'HATFIELD_SESSION_ID' => false, 'HATFIELD_TEST_DATABASE_PATH' => 'app_test-replay-'.$this->sessionId.'.sqlite',
            ]));
        $seed->setTimeout(5);
        $seed->mustRun();
        $this->runId = trim($seed->getOutput());
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->writeCommand(['v' => 1, 'id' => 'start-child-replay', 'type' => 'start_run', 'payload' => ['prompt' => 'Launch the scout.']]);

        $events = $this->collectEventsUntil('run.completed', 8.0, fn (array $event): bool => 'run.completed' === $event['type'] && $this->runId === $event['runId']);
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
        $this->assertContains($root, array_column($byType['run.completed'] ?? [], 'runId'));
        $jobStart = $byType['background_job.started'][0] ?? null;
        $this->assertNotNull($jobStart, $this->collectDiagnostics($events));
        // The job cannot exit until both the child and root have settled.
        $peer = stream_socket_accept($this->gate, 2);
        $this->assertIsResource($peer);
        try {
            $this->assertSame(8, fwrite($peer, "release\n"));
        } finally {
            fclose($peer);
        }
        // Unlike the turn collector, this keeps reading beyond root completion.
        $jobEnd = $this->waitForEvent('background_job.ended', 5.0);
        $this->assertMatchesRegularExpression('/^bash:[1-9][0-9]*$/', $jobStart['payload']['job_id']);
        foreach ([$jobStart, $jobEnd] as $job) {
            $this->assertSame($root, $job['runId']);
            $this->assertSame(0, $job['seq']);
            $this->assertEquals(['job_id' => $jobStart['payload']['job_id'], 'owner_run_id' => $child], $job['payload']);
        }
        foreach ([$start, $end] as $event) {
            $this->assertSame($root, $event['runId']);
            $this->assertSame(0, $event['seq']);
            $this->assertSame($child, $event['payload']['child_run_id']);
        }
        $this->assertGreaterThan($start['payload']['child_sequence'], $end['payload']['child_sequence']);
    }

    protected function controllerExtraArgs(): array
    {
        return [];
    }

    protected function replayExtraEnv(): array
    {
        return ['HATFIELD_REPLAY_SESSION_ID' => $this->runId];
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-child-execution';
    }

    protected function replayFixtures(): array
    {
        $gate = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($gate);
        $this->gate = $gate;
        $address = stream_socket_get_name($gate, false);
        $this->assertIsString($address);
        $port = substr($address, strrpos($address, ':') + 1);
        $bash = ['command' => 'exec 3<>/dev/tcp/127.0.0.1/'.$port.'; IFS= read -r release <&3; printf private-job-output', 'backgrounded' => true];
        $base = ['model' => 'llama_cpp_test/test', 'provider_id' => 'llama_cpp_test', 'reasoning' => 'off'];
        $arguments = ['agent' => 'scout', 'task' => 'Provide a short handoff.'];
        $call = ['id' => 'call_scout', 'name' => 'subagent'];

        return [
            $base + ['stop_reason' => 'tool_call', 'deltas' => [
                ['type' => 'tool_call_start'] + $call,
                ['type' => 'tool_input_delta', 'id' => 'call_scout', 'partial_json' => json_encode($arguments, \JSON_THROW_ON_ERROR)],
                ['type' => 'tool_call_complete', 'tool_calls' => [$call + ['arguments' => $arguments]]],
            ]],
            $base + ['stop_reason' => 'tool_call', 'deltas' => [
                ['type' => 'tool_call_start', 'id' => 'call_child_bash', 'name' => 'bash'],
                ['type' => 'tool_input_delta', 'id' => 'call_child_bash', 'partial_json' => json_encode($bash, \JSON_THROW_ON_ERROR)],
                ['type' => 'tool_call_complete', 'tool_calls' => [['id' => 'call_child_bash', 'name' => 'bash', 'arguments' => $bash]]],
            ]],
            $base + ['stop_reason' => 'stop', 'deltas' => [['type' => 'text', 'content' => 'Scout handoff.']]],
            $base + ['stop_reason' => 'stop', 'deltas' => [['type' => 'text', 'content' => 'Parent received the handoff.']]],
            $base + ['stop_reason' => 'stop', 'deltas' => [['type' => 'text', 'content' => 'Parent received the job completion.']]],
        ];
    }
}
