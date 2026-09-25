<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic real-process proof that an independent native read+bash batch
 * overlaps on the existing tool consumer pool.
 *
 * Both tools register ToolExecutionMode::Parallel. The bash child waits on a
 * FIFO until the parent observes the bash process after both
 * tool_execution.started events, then releases it. Overlap is proven by that
 * readiness barrier, not by sleep windows or transcript order.
 *
 * Live tool_execution.started timestamps remain admission-time (before worker
 * entry). Final duration_ms remains executor-side. This case does not invent
 * new timing UI labels.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayParallelToolOverlapTest extends ControllerReplayE2eTestCase
{
    private const string READ_TOOL_CALL_ID = 'call_parallel_read_1';
    private const string BASH_TOOL_CALL_ID = 'call_parallel_bash_1';
    private const string FILE_CONTENT = 'parallel-read-bash-barrier';
    private const string BASH_MARKER = 'parallel-bash-released';

    private string $notesPath = '';
    private string $releaseFifo = '';
    private string $enteredMarker = '';
    /** @var resource|null */
    private mixed $releaseEndpoint = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notesPath = $this->tempDir.'/notes.txt';
        file_put_contents($this->notesPath, self::FILE_CONTENT);

        // Barrier paths are created in replayFixtures() because the parent
        // ControllerReplayE2eTestCase materializes fixtures during parent::setUp().
        $this->assertNotSame('', $this->releaseFifo, 'Barrier FIFO path must be prepared during fixture materialization');
        $this->assertNotSame('', $this->enteredMarker, 'Barrier marker path must be prepared during fixture materialization');
        if (!\is_resource($this->releaseEndpoint)) {
            $endpoint = fopen($this->releaseFifo, 'r+b');
            $this->assertIsResource($endpoint, 'Parent must own the release FIFO endpoint');
            $this->assertTrue(stream_set_blocking($endpoint, false), 'Release FIFO endpoint must be nonblocking');
            $this->releaseEndpoint = $endpoint;
        }
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->releaseEndpoint)) {
            fclose($this->releaseEndpoint);
            $this->releaseEndpoint = null;
        }

        parent::tearDown();
    }

    public function testIndependentNativeReadAndBashOverlapOnToolWorkers(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $toolConsumers = $this->waitForControllerToolConsumers(2, 8.0);
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
                'prompt' => 'Call tools named read and bash together once. Do not call any other tool.',
            ],
        ]);

        $seenStarted = [];
        $events = $this->collectEventsUntil(
            null,
            8.0,
            static function (array $event) use (&$seenStarted): bool {
                if (($event['type'] ?? '') !== 'tool_execution.started') {
                    return false;
                }

                $toolCallId = (string) ($event['payload']['tool_call_id'] ?? '');
                if ('' !== $toolCallId) {
                    $seenStarted[$toolCallId] = true;
                }

                return isset($seenStarted[self::READ_TOOL_CALL_ID], $seenStarted[self::BASH_TOOL_CALL_ID]);
            },
        );

        $byType = $this->indexByType($events);
        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));
        $this->runId = (string) ($byType['run.started'][0]['runId']
            ?? $byType['run.started'][0]['payload']['runId']
            ?? '');
        $this->assertNotEmpty($this->runId);

        $startedById = [];
        foreach ($byType['tool_execution.started'] ?? [] as $startedEvent) {
            $toolCallId = (string) ($startedEvent['payload']['tool_call_id'] ?? '');
            $startedById[$toolCallId] = $startedEvent;
        }
        $this->assertArrayHasKey(self::READ_TOOL_CALL_ID, $startedById, $this->collectDiagnostics($events));
        $this->assertArrayHasKey(self::BASH_TOOL_CALL_ID, $startedById, $this->collectDiagnostics($events));
        $this->assertSame('read', $startedById[self::READ_TOOL_CALL_ID]['payload']['tool_name'] ?? null);
        $this->assertSame('bash', $startedById[self::BASH_TOOL_CALL_ID]['payload']['tool_name'] ?? null);
        // RuntimeEventTranslator intentionally omits mode from tool_execution.started.
        // Canonical session events remain the source for mode.

        $this->waitForBashBarrierEntry(8.0);
        $readCompletedWhileBashBlocked = $this->collectEventsUntil(
            null,
            8.0,
            static fn (array $event): bool => ($event['type'] ?? '') === 'tool_execution.completed'
                && ($event['payload']['tool_call_id'] ?? null) === self::READ_TOOL_CALL_ID,
        );
        $events = array_merge($events, $readCompletedWhileBashBlocked);
        $byType = $this->indexByType($events);
        $this->assertTrue(
            is_file($this->enteredMarker) && 'entered' === trim((string) file_get_contents($this->enteredMarker)),
            'Bash must still be held at the FIFO barrier while the read sibling completes',
        );
        $this->assertArrayHasKey('tool_execution.completed', $byType, $this->collectDiagnostics($events));
        $readCompletedIds = [];
        foreach ($byType['tool_execution.completed'] as $completedEvent) {
            $readCompletedIds[] = (string) ($completedEvent['payload']['tool_call_id'] ?? '');
        }
        $this->assertContains(self::READ_TOOL_CALL_ID, $readCompletedIds, $this->collectDiagnostics($events));
        $this->assertNotContains(
            self::BASH_TOOL_CALL_ID,
            $readCompletedIds,
            'Bash must not complete before the parent releases the FIFO barrier. '.$this->collectDiagnostics($events),
        );

        $this->assertIsResource($this->releaseEndpoint);
        $this->assertSame(
            8,
            fwrite($this->releaseEndpoint, "release\n"),
            'Parent must release the waiting bash child',
        );

        $completionEvents = $this->collectEventsUntil(
            null,
            8.0,
            static function (array $event): bool {
                if (($event['type'] ?? '') !== 'tool_execution.completed') {
                    return false;
                }

                return ($event['payload']['tool_call_id'] ?? null) === self::BASH_TOOL_CALL_ID;
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
        $this->assertArrayHasKey(self::READ_TOOL_CALL_ID, $completedById, $this->collectDiagnostics($allEvents));
        $this->assertArrayHasKey(self::BASH_TOOL_CALL_ID, $completedById, $this->collectDiagnostics($allEvents));
        $this->assertStringContainsString(
            self::BASH_MARKER,
            (string) ($completedById[self::BASH_TOOL_CALL_ID]['payload']['result'] ?? ''),
            $this->collectDiagnostics($allEvents),
        );

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $allEvents);

        $canonicalDurations = $this->canonicalToolDurations($sessionDir.'/events.jsonl');
        $this->assertArrayHasKey(self::READ_TOOL_CALL_ID, $canonicalDurations, $this->collectDiagnostics($allEvents));
        $this->assertArrayHasKey(self::BASH_TOOL_CALL_ID, $canonicalDurations, $this->collectDiagnostics($allEvents));
        $readDuration = $canonicalDurations[self::READ_TOOL_CALL_ID];
        $bashDuration = $canonicalDurations[self::BASH_TOOL_CALL_ID];
        $this->assertIsInt($readDuration);
        $this->assertIsInt($bashDuration);
        $this->assertLessThan(
            $bashDuration,
            $readDuration,
            'Executor-side read duration must stay below the barrier-held bash duration. '
            .$this->collectDiagnostics($allEvents),
        );

        $traceOverlap = $this->assertWorkerTraceOverlap($this->tempDir.'/.hatfield/logs');
        $timelinePath = $this->tempDir.'/tool-overlap-timeline.json';
        file_put_contents($timelinePath, json_encode([
            'tool_consumers' => $toolConsumers,
            'started' => [
                self::READ_TOOL_CALL_ID => $startedById[self::READ_TOOL_CALL_ID]['payload'] ?? [],
                self::BASH_TOOL_CALL_ID => $startedById[self::BASH_TOOL_CALL_ID]['payload'] ?? [],
            ],
            'completed' => [
                self::READ_TOOL_CALL_ID => [
                    'duration_ms' => $readDuration,
                    'ended_at' => $completedById[self::READ_TOOL_CALL_ID]['payload']['ended_at'] ?? null,
                ],
                self::BASH_TOOL_CALL_ID => [
                    'duration_ms' => $bashDuration,
                    'ended_at' => $completedById[self::BASH_TOOL_CALL_ID]['payload']['ended_at'] ?? null,
                ],
            ],
            'trace_overlap' => $traceOverlap,
            'entered_marker' => is_file($this->enteredMarker) ? (string) file_get_contents($this->enteredMarker) : null,
        ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-parallel-tool-overlap';
    }

    protected function controllerExtraArgs(): array
    {
        // Keep bash available; default E2E excludes it.
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
        $this->releaseFifo = $this->tempDir.'/tool-overlap-release.fifo';
        $this->enteredMarker = $this->tempDir.'/tool-overlap-bash-entered.marker';
        if (!is_file($this->releaseFifo) && !file_exists($this->releaseFifo)) {
            $this->assertTrue(posix_mkfifo($this->releaseFifo, 0o600), 'Release FIFO must be created');
        }
        if (!\is_resource($this->releaseEndpoint)) {
            $endpoint = fopen($this->releaseFifo, 'r+b');
            $this->assertIsResource($endpoint, 'Parent must own the release FIFO endpoint');
            $this->assertTrue(stream_set_blocking($endpoint, false), 'Release FIFO endpoint must be nonblocking');
            $this->releaseEndpoint = $endpoint;
        }

        $fixturePath = __DIR__.'/fixtures/controller-parallel-read-bash-barrier.json';
        $fixture = json_decode(
            (string) file_get_contents($fixturePath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \PHPUnit\Framework\Assert::assertIsArray($fixture);

        $releaseArg = escapeshellarg($this->releaseFifo);
        $enteredArg = escapeshellarg($this->enteredMarker);
        $markerArg = escapeshellarg(self::BASH_MARKER);
        $command = 'printf entered > '.$enteredArg
            .' && IFS= read -r release < '.$releaseArg
            .' && printf %s '.$markerArg;

        $fixture['deltas'][3]['partial_json'] = json_encode(
            ['command' => $command],
            \JSON_THROW_ON_ERROR,
        );
        $fixture['deltas'][4]['tool_calls'][1]['arguments']['command'] = $command;

        $postToolFixture = [
            '$schema' => 'Synthetic controller replay — post parallel-tool assistant turn',
            'fixture_source' => 'synthetic',
            'synthetic_reason' => 'Absorb the post-tool LLM turn after parallel read+bash completes.',
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

    private function waitForBashBarrierEntry(float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($this->enteredMarker) && 'entered' === trim((string) @file_get_contents($this->enteredMarker))) {
                // Marker file is written before the blocking FIFO read. Its
                // presence proves the bash worker entered execution while the
                // sibling read path remains free to finish.
                return;
            }

            $this->assertRunning('waiting for bash barrier entry');
            usleep(10_000);
        }

        $this->fail(
            'Bash worker did not enter the FIFO barrier before timeout. '
            .'marker='.(is_file($this->enteredMarker) ? (string) file_get_contents($this->enteredMarker) : 'missing'),
        );
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
            usleep(50_000);
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
     * @return array<string, int>
     */
    private function canonicalToolDurations(string $eventsJsonl): array
    {
        $this->assertFileExists($eventsJsonl);
        $durations = [];
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
            $duration = $toolResult['result']['details']['duration_ms'] ?? null;
            if ('' === $toolCallId || !\is_int($duration)) {
                continue;
            }

            $durations[$toolCallId] = $duration;
        }

        return $durations;
    }

    /**
     * @return array{
     *     read_worker_start:?float,
     *     bash_worker_start:?float,
     *     read_tool_finish:?float,
     *     bash_tool_finish:?float
     * }
     */
    private function assertWorkerTraceOverlap(string $logsDir): array
    {
        $this->assertDirectoryExists($logsDir);
        $logFiles = glob($logsDir.'/agent-*.log') ?: [];
        $this->assertNotSame([], $logFiles, 'Expected agent log for worker-entry overlap proof');

        $marks = [
            'read_worker_start' => null,
            'bash_worker_start' => null,
            'read_tool_finish' => null,
            'bash_tool_finish' => null,
        ];

        foreach ($logFiles as $logFile) {
            foreach (file($logFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $record = json_decode($line, true);
                if (!\is_array($record)) {
                    continue;
                }

                $message = (string) ($record['message'] ?? '');
                $context = $record['context'] ?? [];
                if (!\is_array($context)) {
                    continue;
                }

                $spanName = (string) ($context['span_name'] ?? '');
                $toolCallId = (string) ($context['tool_call_id'] ?? '');
                $timestamp = $this->logTimestampSeconds($record);

                if ('agent_loop.trace.start' === $message && 'turn.execution.tool_worker' === $spanName) {
                    if (self::READ_TOOL_CALL_ID === $toolCallId) {
                        $marks['read_worker_start'] = $timestamp;
                    }
                    if (self::BASH_TOOL_CALL_ID === $toolCallId) {
                        $marks['bash_worker_start'] = $timestamp;
                    }
                }

                if ('agent_loop.trace.finish' === $message && 'tool.call' === $spanName) {
                    if (self::READ_TOOL_CALL_ID === $toolCallId) {
                        $marks['read_tool_finish'] = $timestamp;
                    }
                    if (self::BASH_TOOL_CALL_ID === $toolCallId) {
                        $marks['bash_tool_finish'] = $timestamp;
                    }
                }
            }
        }

        $this->assertNotNull($marks['read_worker_start'], 'Missing read worker-entry trace');
        $this->assertNotNull($marks['bash_worker_start'], 'Missing bash worker-entry trace');
        $this->assertNotNull($marks['read_tool_finish'], 'Missing read tool.call finish trace');
        $this->assertNotNull($marks['bash_tool_finish'], 'Missing bash tool.call finish trace');

        $this->assertLessThan(
            $marks['read_tool_finish'],
            $marks['bash_worker_start'],
            'Bash worker must enter before the sibling read tool.call finishes',
        );

        return $marks;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function logTimestampSeconds(array $record): ?float
    {
        $datetime = $record['datetime'] ?? null;
        if (!\is_string($datetime) || '' === $datetime) {
            return null;
        }

        try {
            return (float) (new \DateTimeImmutable($datetime))->format('U.u');
        } catch (\Exception) {
            return null;
        }
    }
}
