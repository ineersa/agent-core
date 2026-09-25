<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic real-process proof that two independent native parallel bash
 * tool calls overlap on the existing tool consumer pool.
 *
 * Each bash child writes an entered marker, then blocks on its own FIFO.
 * Readiness requires both entered markers and a live bash child process before
 * either FIFO is released. A serial single-worker scheduler cannot reach that
 * state. Overlap is therefore a positive barrier, not a timing lottery.
 *
 * Earlier read+bash wall-clock comparisons remain diagnostic-only and are not
 * retained as a contract here. Live tool_execution.started timestamps remain
 * admission-time; final duration_ms remains executor-side.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayParallelToolOverlapTest extends ControllerReplayE2eTestCase
{
    private const string BASH_A_TOOL_CALL_ID = 'call_parallel_bash_a';
    private const string BASH_B_TOOL_CALL_ID = 'call_parallel_bash_b';
    private const string MARKER_A = 'parallel-bash-a-released';
    private const string MARKER_B = 'parallel-bash-b-released';

    private string $releaseFifoA = '';
    private string $releaseFifoB = '';
    private string $enteredMarkerA = '';
    private string $enteredMarkerB = '';
    /** @var resource|null */
    private mixed $releaseEndpointA = null;
    /** @var resource|null */
    private mixed $releaseEndpointB = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Barrier paths are created in replayFixtures() because the parent
        // ControllerReplayE2eTestCase materializes fixtures during parent::setUp().
        $this->assertNotSame('', $this->releaseFifoA);
        $this->assertNotSame('', $this->releaseFifoB);
        $this->assertNotSame('', $this->enteredMarkerA);
        $this->assertNotSame('', $this->enteredMarkerB);
        $this->ensureReleaseEndpoints();
    }

    protected function tearDown(): void
    {
        foreach ([$this->releaseEndpointA, $this->releaseEndpointB] as $endpoint) {
            if (\is_resource($endpoint)) {
                fclose($endpoint);
            }
        }
        $this->releaseEndpointA = null;
        $this->releaseEndpointB = null;

        parent::tearDown();
    }

    public function testTwoIndependentNativeBashCallsOverlapOnToolWorkers(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $toolConsumers = $this->waitForControllerToolConsumers(2, 3.0);
        $this->assertCount(
            2,
            $toolConsumers,
            'Expected two messenger:consume tool descendants. got: '.json_encode($toolConsumers),
        );

        $startCmdId = 'cmd_start_'.uniqid('', true);
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Call bash twice together once. Do not call any other tool.',
            ],
        ]);

        $seenStarted = [];
        $events = $this->collectEventsUntil(
            null,
            4.0,
            static function (array $event) use (&$seenStarted): bool {
                if (($event['type'] ?? '') !== 'tool_execution.started') {
                    return false;
                }

                $toolCallId = (string) ($event['payload']['tool_call_id'] ?? '');
                if ('' !== $toolCallId) {
                    $seenStarted[$toolCallId] = true;
                }

                return isset($seenStarted[self::BASH_A_TOOL_CALL_ID], $seenStarted[self::BASH_B_TOOL_CALL_ID]);
            },
        );

        $byType = $this->indexByType($events);
        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));
        $this->runId = (string) ($byType['run.started'][0]['runId']
            ?? $byType['run.started'][0]['payload']['runId']
            ?? '');
        $this->assertNotEmpty($this->runId);

        $startedIds = [];
        foreach ($byType['tool_execution.started'] ?? [] as $startedEvent) {
            $startedIds[] = (string) ($startedEvent['payload']['tool_call_id'] ?? '');
        }
        $this->assertContains(self::BASH_A_TOOL_CALL_ID, $startedIds, $this->collectDiagnostics($events));
        $this->assertContains(self::BASH_B_TOOL_CALL_ID, $startedIds, $this->collectDiagnostics($events));

        $this->waitForBothBashEntriesAndLiveness(4.0);

        $this->assertIsResource($this->releaseEndpointA);
        $this->assertIsResource($this->releaseEndpointB);
        $this->assertSame(8, fwrite($this->releaseEndpointA, "release\n"));
        $this->assertSame(8, fwrite($this->releaseEndpointB, "release\n"));

        $completedIds = [];
        $completionEvents = $this->collectEventsUntil(
            null,
            4.0,
            static function (array $event) use (&$completedIds): bool {
                if (($event['type'] ?? '') !== 'tool_execution.completed') {
                    return false;
                }
                $toolCallId = (string) ($event['payload']['tool_call_id'] ?? '');
                if ('' !== $toolCallId) {
                    $completedIds[$toolCallId] = true;
                }

                return isset($completedIds[self::BASH_A_TOOL_CALL_ID], $completedIds[self::BASH_B_TOOL_CALL_ID]);
            },
        );
        $allEvents = array_merge($events, $completionEvents);
        $allByType = $this->indexByType($allEvents);

        $this->assertArrayHasKey('tool_execution.completed', $allByType, $this->collectDiagnostics($allEvents));
        $this->assertArrayNotHasKey('tool_execution.failed', $allByType, $this->collectDiagnostics($allEvents));
        $this->assertArrayNotHasKey('run.failed', $allByType, $this->collectDiagnostics($allEvents));

        $completedById = [];
        foreach ($allByType['tool_execution.completed'] as $completedEvent) {
            $toolCallId = (string) ($completedEvent['payload']['tool_call_id'] ?? '');
            $completedById[$toolCallId] = $completedEvent;
        }
        $this->assertArrayHasKey(self::BASH_A_TOOL_CALL_ID, $completedById, $this->collectDiagnostics($allEvents));
        $this->assertArrayHasKey(self::BASH_B_TOOL_CALL_ID, $completedById, $this->collectDiagnostics($allEvents));
        $this->assertStringContainsString(self::MARKER_A, (string) ($completedById[self::BASH_A_TOOL_CALL_ID]['payload']['result'] ?? ''));
        $this->assertStringContainsString(self::MARKER_B, (string) ($completedById[self::BASH_B_TOOL_CALL_ID]['payload']['result'] ?? ''));

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $allEvents);

        $canonicalModes = $this->canonicalToolModes($sessionDir.'/events.jsonl');
        $this->assertSame('parallel', $canonicalModes[self::BASH_A_TOOL_CALL_ID] ?? null);
        $this->assertSame('parallel', $canonicalModes[self::BASH_B_TOOL_CALL_ID] ?? null);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-parallel-tool-overlap';
    }

    protected function controllerExtraArgs(): array
    {
        return [];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'
tools:
    execution:
        max_parallelism: 2
logging:
    level: info
YAML;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $this->releaseFifoA = $this->tempDir.'/tool-overlap-release-a.fifo';
        $this->releaseFifoB = $this->tempDir.'/tool-overlap-release-b.fifo';
        $this->enteredMarkerA = $this->tempDir.'/tool-overlap-entered-a.marker';
        $this->enteredMarkerB = $this->tempDir.'/tool-overlap-entered-b.marker';
        $this->createFifoIfMissing($this->releaseFifoA);
        $this->createFifoIfMissing($this->releaseFifoB);
        $this->ensureReleaseEndpoints();

        $fixturePath = __DIR__.'/fixtures/controller-parallel-dual-bash-barrier.json';
        $fixture = json_decode(
            (string) file_get_contents($fixturePath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \PHPUnit\Framework\Assert::assertIsArray($fixture);

        $commandA = $this->barrierCommand($this->enteredMarkerA, $this->releaseFifoA, self::MARKER_A);
        $commandB = $this->barrierCommand($this->enteredMarkerB, $this->releaseFifoB, self::MARKER_B);

        $fixture['deltas'][1]['partial_json'] = json_encode(['command' => $commandA], \JSON_THROW_ON_ERROR);
        $fixture['deltas'][3]['partial_json'] = json_encode(['command' => $commandB], \JSON_THROW_ON_ERROR);
        $fixture['deltas'][4]['tool_calls'][0]['arguments']['command'] = $commandA;
        $fixture['deltas'][4]['tool_calls'][1]['arguments']['command'] = $commandB;

        $postToolFixture = [
            '$schema' => 'Synthetic controller replay — post dual-bash assistant turn',
            'fixture_source' => 'synthetic',
            'synthetic_reason' => 'Absorb the post-tool LLM turn after dual bash completes.',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'stop_reason' => 'stop',
            'deltas' => [
                ['type' => 'text', 'content' => 'done'],
            ],
        ];

        return [$fixture, $postToolFixture];
    }

    private function barrierCommand(string $enteredMarker, string $releaseFifo, string $outputMarker): string
    {
        return 'printf entered > '.escapeshellarg($enteredMarker)
            .' && IFS= read -r release < '.escapeshellarg($releaseFifo)
            .' && printf %s '.escapeshellarg($outputMarker);
    }

    private function createFifoIfMissing(string $path): void
    {
        if (file_exists($path)) {
            return;
        }

        $this->assertTrue(posix_mkfifo($path, 0o600), 'Release FIFO must be created: '.$path);
    }

    private function ensureReleaseEndpoints(): void
    {
        if (!\is_resource($this->releaseEndpointA)) {
            $endpoint = fopen($this->releaseFifoA, 'r+b');
            $this->assertIsResource($endpoint, 'Parent must own release FIFO A');
            $this->assertTrue(stream_set_blocking($endpoint, false));
            $this->releaseEndpointA = $endpoint;
        }

        if (!\is_resource($this->releaseEndpointB)) {
            $endpoint = fopen($this->releaseFifoB, 'r+b');
            $this->assertIsResource($endpoint, 'Parent must own release FIFO B');
            $this->assertTrue(stream_set_blocking($endpoint, false));
            $this->releaseEndpointB = $endpoint;
        }
    }

    private function waitForBothBashEntriesAndLiveness(float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $enteredA = is_file($this->enteredMarkerA) && 'entered' === trim((string) @file_get_contents($this->enteredMarkerA));
            $enteredB = is_file($this->enteredMarkerB) && 'entered' === trim((string) @file_get_contents($this->enteredMarkerB));
            if ($enteredA && $enteredB && $this->countLiveOwnedBarrierBashProcesses() >= 2) {
                return;
            }

            $this->assertRunning('waiting for dual bash barrier entry');
            usleep(10_000);
        }

        $this->fail(sprintf(
            'Both bash workers did not enter barriers with live children before timeout. a=%s b=%s live=%d',
            is_file($this->enteredMarkerA) ? trim((string) file_get_contents($this->enteredMarkerA)) : 'missing',
            is_file($this->enteredMarkerB) ? trim((string) file_get_contents($this->enteredMarkerB)) : 'missing',
            $this->countLiveOwnedBarrierBashProcesses(),
        ));
    }

    private function countLiveOwnedBarrierBashProcesses(): int
    {
        // BackgroundProcessManager launches via setsid, so barrier bash children
        // are not under the controller process tree. Use owned .hatfield/tmp/bg
        // PID files plus /proc cmdline matching for positive liveness.
        $bgDir = $this->tempDir.'/.hatfield/tmp/bg';
        if (!is_dir($bgDir)) {
            return 0;
        }

        $live = 0;
        foreach (glob($bgDir.'/*.pid') ?: [] as $pidFile) {
            $pid = (int) trim((string) @file_get_contents($pidFile));
            if ($pid <= 0 || !is_dir('/proc/'.$pid)) {
                continue;
            }

            $cmdline = (string) @file_get_contents('/proc/'.$pid.'/cmdline');
            $cmd = str_replace("\0", ' ', $cmdline);
            if (!str_contains($cmd, 'tool-overlap-release-')) {
                // Wrapper PID may own a child that holds the real command.
                foreach ($this->discoverControllerProcessTreePids($pid) as $childPid) {
                    $childCmd = str_replace("\0", ' ', (string) @file_get_contents('/proc/'.$childPid.'/cmdline'));
                    if (str_contains($childCmd, 'tool-overlap-release-') && str_contains($childCmd, 'IFS= read -r release <')) {
                        ++$live;
                        break;
                    }
                }

                continue;
            }

            if (str_contains($cmd, 'IFS= read -r release <')) {
                ++$live;
            }
        }

        return $live;
    }

    /**
     * @return list<array{pid:int, cmd:string}>
     */
    private function waitForControllerToolConsumers(int $expected, float $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $last = [];
        while (microtime(true) < $deadline) {
            $this->refreshTrackedControllerPids();
            $last = $this->listControllerToolConsumers();
            if (\count($last) >= $expected) {
                return $last;
            }
            usleep(20_000);
        }

        return $last;
    }

    /**
     * @return list<array{pid:int, cmd:string}>
     */
    private function listControllerToolConsumers(): array
    {
        $rootPid = $this->controllerRootPid();
        if ($rootPid <= 0) {
            return [];
        }

        $matched = [];
        foreach ($this->discoverControllerProcessTreePids($rootPid) as $pid) {
            $cmdline = (string) @file_get_contents("/proc/{$pid}/cmdline");
            $cmd = str_replace("\0", ' ', $cmdline);
            if (!preg_match('/messenger:consume\s+tool(\s|$)/', $cmd)) {
                continue;
            }
            $matched[] = ['pid' => $pid, 'cmd' => trim($cmd)];
        }

        return $matched;
    }

    /**
     * @return array<string, string>
     */
    private function canonicalToolModes(string $eventsJsonl): array
    {
        $this->assertFileExists($eventsJsonl);
        $modes = [];
        foreach (file($eventsJsonl, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if (($event['type'] ?? null) !== 'tool_execution_end') {
                continue;
            }

            $toolResult = $event['payload']['tool_result'] ?? null;
            if (!\is_array($toolResult)) {
                continue;
            }

            $toolCallId = (string) ($toolResult['tool_call_id'] ?? '');
            $mode = $toolResult['result']['details']['mode'] ?? null;
            if ('' === $toolCallId || !\is_string($mode)) {
                continue;
            }

            $modes[$toolCallId] = $mode;
        }

        return $modes;
    }
}
