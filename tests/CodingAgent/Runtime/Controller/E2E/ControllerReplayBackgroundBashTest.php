<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

#[Group('controller-replay')]
final class ControllerReplayBackgroundBashTest extends ControllerReplayE2eTestCase
{
    private const string CALL_ID = 'call_detach_bash';
    private string $databasePath;

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            $path = $this->projectDir.'/var/test/'.$this->databasePath.$suffix;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testSameProcessSurvivesAcknowledgementAndExistingPollerDeliversOneCompletion(): void
    {
        $this->assertTrue(posix_mkfifo($this->tempDir.'/release', 0600));
        $release = fopen($this->tempDir.'/release', 'r+b');
        $this->assertIsResource($release);
        try {
            // Match the real client: allocate the database session before booting
            // its controller so poller ownership and run identity are identical.
            $this->sessionId = trim($this->queryRuntime()->getOutput());
            $this->spawnController();
            $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
            $this->writeCommand(['v' => 1, 'id' => 'start', 'type' => 'start_run', 'payload' => ['prompt' => 'Run the Bash FIFO command once.']]);
            $started = $this->collectEventsUntil(null, 5, static fn (array $event): bool => 'tool_execution.started' === ($event['type'] ?? '')
                && self::CALL_ID === ($event['payload']['tool_call_id'] ?? null));
            $this->assertStartRunAcked($started, 'start');
            $byType = $this->indexByType($started);
            $this->assertArrayHasKey('tool_execution.started', $byType, $this->collectDiagnostics($started));
            $this->runId = $byType['run.started'][0]['runId'];

            // The tool-start event precedes native launch. The PID marker is
            // written by the Bash process before it blocks on the owned FIFO.
            $deadline = microtime(true) + 3;
            while (!is_file($this->tempDir.'/ready') && microtime(true) < $deadline) {
                $this->assertTrue($this->isRunning(), $this->collectDiagnostics($started));
                usleep(10000);
            }
            $this->assertFileExists($this->tempDir.'/ready');
            $this->assertFileExists($this->tempDir.'/started');
            $nativePid = (int) trim((string) file_get_contents($this->tempDir.'/started'));
            $this->assertGreaterThan(0, $nativePid);
            $this->assertTrue(posix_kill($nativePid, 0));

            // Query through the configured runtime client in a cold test kernel,
            // against the controller's own database, without starting a controller.
            $query = $this->queryRuntime($this->runId);
            $candidates = json_decode(trim($query->getOutput()), true, 32, \JSON_THROW_ON_ERROR);
            $this->assertCount(1, $candidates);
            $candidate = $candidates[0];
            $this->assertSame(self::CALL_ID, $candidate['toolCallId']);
            $this->writeCommand(['v' => 1, 'id' => 'detach', 'type' => 'background_bash', 'runId' => $this->runId,
                'payload' => ['record_id' => $candidate['id'], 'tool_call_id' => self::CALL_ID]]);
            $detached = $this->collectEventsUntil('run.completed', 5);
            $this->assertArrayHasKey('run.completed', $this->indexByType($detached), $this->collectDiagnostics($detached));
            $this->assertTrue(posix_kill($nativePid, 0), 'The same native Bash must still be blocked after the next model request.');
            $this->assertTrue(posix_kill($candidate['pid'], 0), 'Native supervision retains the original wrapper.');
            $requests = $this->requests();
            $this->assertCount(2, $requests);
            $this->assertCount(1, array_filter($requests[1], static fn (array $message): bool => 'tool' === ($message['role'] ?? '')
                && self::CALL_ID === ($message['tool_call_id'] ?? null)
                && str_contains(json_encode($message, \JSON_THROW_ON_ERROR), 'Command moved to background.')));
            $this->assertStringNotContainsString('[BG_PROCESS_DONE]', json_encode($requests[1], \JSON_THROW_ON_ERROR));

            fwrite($release, "finish\n");
            fflush($release);
            $completed = $this->collectEventsUntil('run.completed', 5);
            $completedByType = $this->indexByType($completed);
            $this->assertArrayHasKey('run.completed', $completedByType, $this->collectDiagnostics($completed));
            $this->assertCount(1, $completedByType[RuntimeEventTypeEnum::BackgroundProcessCompleted->value] ?? []);
            $this->assertSame($candidate['pid'], $completedByType[RuntimeEventTypeEnum::BackgroundProcessCompleted->value][0]['payload']['pid']);
            $this->assertSame(0, $completedByType[RuntimeEventTypeEnum::BackgroundProcessCompleted->value][0]['payload']['exit_code']);
            $this->assertSame('fifo-command-complete', $completedByType[RuntimeEventTypeEnum::BackgroundProcessCompleted->value][0]['payload']['output_tail']);
            $requests = $this->requests();
            $this->assertCount(3, $requests, 'One initial request, one acknowledgement turn, one completion turn.');
            $notifications = array_values(array_filter($requests[2], static fn (array $message): bool => 'user' === ($message['role'] ?? '')
                && str_contains(json_encode($message, \JSON_THROW_ON_ERROR), '[BG_PROCESS_DONE]')));
            $this->assertCount(1, $notifications);
            $this->assertStringContainsString('fifo-command-complete', json_encode($notifications[0], \JSON_THROW_ON_ERROR));
            $this->assertCount(1, array_filter($requests[2], static fn (array $message): bool => 'tool' === ($message['role'] ?? '')
                && self::CALL_ID === ($message['tool_call_id'] ?? null)), 'Completion must not become a second original-call result.');
            $this->assertCount(1, file($this->tempDir.'/started', \FILE_IGNORE_NEW_LINES));
            $canonical = array_map(
                static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
                file($this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES),
            );
            $this->assertCount(1, array_filter($canonical, static fn (array $event): bool => 'agent_command_applied' === ($event['type'] ?? '')
                && 'append_message' === ($event['payload']['kind'] ?? null)), 'Existing poller must append the completion exactly once.');
        } finally {
            fclose($release);
        }
    }

    public function testControllerAcknowledgesCommandWhileTimedOutProcessIsInTermGrace(): void
    {
        $this->sessionId = trim($this->queryRuntime()->getOutput());
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->writeCommand(['v' => 1, 'id' => 'start', 'type' => 'start_run', 'payload' => ['prompt' => 'Start the resistant Bash command.']]);
        $started = $this->collectEventsUntil(null, 5, static fn (array $event): bool => 'tool_execution.started' === ($event['type'] ?? ''));
        $this->runId = $this->indexByType($started)['run.started'][0]['runId'];
        $deadline = microtime(true) + 3;
        while (!is_file($this->tempDir.'/ready') && microtime(true) < $deadline) {
            $this->assertTrue($this->isRunning(), $this->collectDiagnostics($started));
            usleep(10_000);
        }
        $this->assertFileExists($this->tempDir.'/ready');
        $candidates = json_decode(trim($this->queryRuntime($this->runId)->getOutput()), true, 32, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $candidates);
        $candidate = $candidates[0];
        try {
            $this->writeCommand(['v' => 1, 'id' => 'detach', 'type' => 'background_bash', 'runId' => $this->runId,
                'payload' => ['record_id' => $candidate['id'], 'tool_call_id' => self::CALL_ID]]);
            $detached = $this->collectEventsUntil('run.completed', 3);
            $this->assertArrayHasKey('run.completed', $this->indexByType($detached), $this->collectDiagnostics($detached));
            // The command writes this marker only after TERM is actually handled.
            // Its original two-second timeout and the controller polling interval
            // are the product delay under test, not readiness sleeps.
            $deadline = microtime(true) + 5;
            while (!is_file($this->tempDir.'/term') && microtime(true) < $deadline) {
                $this->assertTrue($this->isRunning());
                usleep(10_000);
            }
            $this->assertFileExists($this->tempDir.'/term');
            $this->writeCommand(['v' => 1, 'id' => 'during-grace', 'type' => 'cancel', 'runId' => $this->runId, 'payload' => []]);
            $events = $this->collectEventsUntil(null, 2, static fn (array $event): bool => 'command.ack' === ($event['type'] ?? '') && 'during-grace' === ($event['payload']['commandId'] ?? null));
            $this->assertTrue($this->foundAck($events, 'during-grace'), $this->collectDiagnostics($events));
            $lifecycle = new ProcessLifecycle(new BackgroundProcessConfig(), new NullLogger());
            $this->assertTrue($lifecycle->isAlive($candidate['pid']), 'Acknowledgment arrives while native termination is still in grace, not after KILL or zombie exit.');
        } finally {
            // Owned process group only. Avoid spending another full explicit-stop
            // grace during controller teardown after the responsiveness proof.
            $pgid = posix_getpgid($candidate['pid']);
            if (false !== $pgid && $pgid === $candidate['pid']) {
                posix_kill(-$pgid, \SIGKILL);
            }
        }
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-background-bash';
    }

    protected function replayFixtures(): array
    {
        $this->databasePath = 'app_test-replay-'.$this->sessionId.'.sqlite';
        $command = 'printf "%s\\n" "$$" >> '.escapeshellarg($this->tempDir.'/started')
            .'; : > '.escapeshellarg($this->tempDir.'/ready')
            .'; IFS= read -r release < '.escapeshellarg($this->tempDir.'/release').'; printf fifo-command-complete';
        $arguments = ['command' => $command];
        if ('testControllerAcknowledgesCommandWhileTimedOutProcessIsInTermGrace' === $this->name()) {
            $command = 'trap '.escapeshellarg(': > '.escapeshellarg($this->tempDir.'/term')).' TERM; : > '.escapeshellarg($this->tempDir.'/ready').'; while :; do sleep 0.05; done';
            $arguments = ['command' => $command, 'timeout' => 2];
        }
        $start = json_decode((string) file_get_contents(__DIR__.'/fixtures/controller-bash-blocker.json'), true, 512, \JSON_THROW_ON_ERROR);
        $start['deltas'] = [
            ['type' => 'tool_call_start', 'id' => self::CALL_ID, 'name' => 'bash'],
            ['type' => 'tool_input_delta', 'id' => self::CALL_ID, 'name' => 'bash', 'partial_json' => json_encode($arguments, \JSON_THROW_ON_ERROR)],
            ['type' => 'tool_call_complete', 'tool_calls' => [['id' => self::CALL_ID, 'name' => 'bash', 'arguments' => $arguments]]],
        ];
        $stop = ['model' => 'llama_cpp_test/test', 'provider_id' => 'llama_cpp_test', 'reasoning' => 'off', 'stop_reason' => 'stop',
            'deltas' => [['type' => 'text', 'content' => 'Acknowledged.']]];
        foreach ([$start, $stop, $stop] as $fixture) {
            $fixture['replay_request_capture'] = $this->tempDir.'/requests.jsonl';
            $fixtures[] = $fixture;
        }

        return $fixtures;
    }

    protected function replayExtraEnv(): array
    {
        return ['HATFIELD_TEST_DATABASE_PATH' => $this->databasePath];
    }

    private function queryRuntime(?string $runId = null): Process
    {
        $command = [\PHP_BINARY, __DIR__.'/Support/ForegroundBashCandidates.php', $this->projectDir];
        if (null !== $runId) {
            $command[] = $runId;
        }
        $query = new Process($command, $this->tempDir, array_merge($this->controllerSubprocessEnv(), [
            'APP_ENV' => 'test', 'APP_DEBUG' => '1', 'HATFIELD_CWD' => $this->tempDir, 'HOME' => $this->tempDir,
            'HATFIELD_SESSION_ID' => false, 'HATFIELD_TEST_DATABASE_PATH' => $this->databasePath,
        ]));
        $query->setTimeout(3);
        $query->mustRun();

        return $query;
    }

    /** @return list<list<array<string, mixed>>> */
    private function requests(): array
    {
        return array_map(static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
            file($this->tempDir.'/requests.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
    }
}
