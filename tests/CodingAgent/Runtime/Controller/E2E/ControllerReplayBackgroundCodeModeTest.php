<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

#[Group('controller-replay')]
final class ControllerReplayBackgroundCodeModeTest extends ControllerReplayE2eTestCase
{
    private const string CALL_ID = 'call_background_code_mode';
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

    public function testControllerAcknowledgesBlockedHostThenDeliversOneAttributedLateResult(): void
    {
        // A real controller/worker/IPC contract: only replay can prove that the
        // independent owner ACK does not unwind the synchronous nested call.
        // Bounded waits are safety caps; the owned FIFO is the release barrier.
        $this->assertTrue(posix_mkfifo($this->tempDir.'/release', 0600));
        $release = fopen($this->tempDir.'/release', 'r+b');
        $this->assertIsResource($release);
        try {
            $this->sessionId = trim($this->queryRuntime()->getOutput());
            $this->spawnController();
            $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
            $this->writeCommand(['v' => 1, 'id' => 'start', 'type' => 'start_run', 'payload' => ['prompt' => 'Run the code-mode script once.']]);
            $started = $this->collectEventsUntil(null, 5, static fn (array $event): bool => 'tool_execution.started' === ($event['type'] ?? '') && self::CALL_ID === ($event['payload']['tool_call_id'] ?? null));
            $this->assertStartRunAcked($started, 'start');
            $this->runId = $this->indexByType($started)['run.started'][0]['runId'];
            $deadline = microtime(true) + 3;
            while (!is_file($this->tempDir.'/nested-ready') && microtime(true) < $deadline) {
                $this->assertTrue($this->isRunning(), $this->collectDiagnostics($started));
                usleep(10000);
            }
            $this->assertFileExists($this->tempDir.'/nested-ready');
            $scriptPid = (int) trim((string) file_get_contents($this->tempDir.'/script-pid'));
            $nestedPid = (int) trim((string) file_get_contents($this->tempDir.'/nested-ready'));
            $this->assertTrue(posix_kill($scriptPid, 0));
            $this->assertTrue(posix_kill($nestedPid, 0));
            $candidates = json_decode(trim($this->queryRuntime($this->runId)->getOutput()), true, 32, \JSON_THROW_ON_ERROR);
            $this->assertCount(1, $candidates);
            $candidate = $candidates[0];
            $this->assertSame(self::CALL_ID, $candidate['toolCallId']);
            $this->writeCommand(['v' => 1, 'id' => 'background', 'type' => 'background_code_mode', 'runId' => $this->runId, 'payload' => ['identity' => $candidate['identity'], 'tool_call_id' => self::CALL_ID]]);
            $acknowledged = $this->collectEventsUntil('run.completed', 5);
            $this->assertTrue($this->foundAck($acknowledged, 'background'), $this->collectDiagnostics($acknowledged));
            $this->assertArrayHasKey('run.completed', $this->indexByType($acknowledged), $this->collectDiagnostics($acknowledged));
            $this->assertTrue(posix_kill($scriptPid, 0), 'ACK retains the same PHP host.');
            $this->assertTrue(posix_kill($nestedPid, 0), 'ACK does not return from the blocked nested Bash call.');
            $requests = $this->requests();
            $this->assertCount(2, $requests);
            $ackResult = array_find($requests[1], static fn (array $message): bool => 'tool' === ($message['role'] ?? '') && self::CALL_ID === ($message['tool_call_id'] ?? null));
            $this->assertNotNull($ackResult);
            $this->assertStringContainsString('original host and worker remain occupied', json_encode($ackResult, \JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('code-mode-complete', json_encode($ackResult, \JSON_THROW_ON_ERROR));
            fwrite($release, "finish\n");
            fflush($release);
            $late = $this->collectEventsUntil('run.completed', 5);
            $this->assertArrayHasKey('run.completed', $this->indexByType($late), $this->collectDiagnostics($late));
            $this->assertNotContains('user.message_submitted', array_column($late, 'type'));
            $this->assertArrayHasKey('model.notification', $this->indexByType($late));
            $notice = json_encode($this->indexByType($late)['model.notification'], \JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('code-mode-complete', $notice);
            $this->assertStringContainsString('nested-complete', $notice);
            $this->assertStringContainsString(self::CALL_ID, $notice);
            $this->assertStringContainsString($candidate['identity'], $notice);
            $requests = $this->requests();
            $this->assertCount(3, $requests);
            $this->assertStringContainsString('code-mode-complete', json_encode($requests[2], \JSON_THROW_ON_ERROR));
            $this->assertStringContainsString('nested-complete', json_encode($requests[2], \JSON_THROW_ON_ERROR));
            $this->assertCount(1, array_filter($requests[2], static fn (array $message): bool => 'tool' === ($message['role'] ?? '') && self::CALL_ID === ($message['tool_call_id'] ?? null)), 'Late delivery is not a second result for the original call.');
            $canonical = array_map(static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR), file($this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
            $this->assertCount(1, array_filter($canonical, static fn (array $event): bool => 'tool_call_acknowledged' === ($event['type'] ?? null)));
            $this->assertCount(0, array_filter($canonical, static fn (array $event): bool => 'tool_execution_end' === ($event['type'] ?? null)), 'Caller acknowledgement never fabricates native execution-end history.');
            $this->assertCount(1, array_filter($canonical, static fn (array $event): bool => 'agent_command_applied' === ($event['type'] ?? null)));
            $this->assertCount(1, file($this->tempDir.'/script-pid', \FILE_IGNORE_NEW_LINES));
        } finally {
            fclose($release);
        }
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-background-code-mode';
    }

    protected function extraSettingsYaml(): string
    {
        return "tools:\n  code_mode:\n    enabled: true\n";
    }

    protected function replayFixtures(): array
    {
        $this->databasePath = 'app_test-replay-code-mode-'.$this->sessionId.'.sqlite';
        $command = 'printf "%s\\n" "$$" > '.escapeshellarg($this->tempDir.'/nested-ready').'; IFS= read -r release < '.escapeshellarg($this->tempDir.'/release').'; printf nested-complete';
        $script = 'file_put_contents('.var_export($this->tempDir.'/script-pid', true).', getmypid()."\\n", FILE_APPEND); echo "script-before\\n"; $nested = tool("bash", ["command" => '.var_export($command, true).', "timeout" => 8]); echo "script-after\\n"; return ["marker" => "code-mode-complete", "nested" => $nested, "script_pid" => getmypid()];';
        $arguments = ['script' => $script, 'timeout_seconds' => 8];
        $start = json_decode((string) file_get_contents(__DIR__.'/fixtures/controller-bash-blocker.json'), true, 512, \JSON_THROW_ON_ERROR);
        $start['deltas'] = [
            ['type' => 'tool_call_start', 'id' => self::CALL_ID, 'name' => 'code_mode'],
            ['type' => 'tool_input_delta', 'id' => self::CALL_ID, 'name' => 'code_mode', 'partial_json' => json_encode($arguments, \JSON_THROW_ON_ERROR)],
            ['type' => 'tool_call_complete', 'tool_calls' => [['id' => self::CALL_ID, 'name' => 'code_mode', 'arguments' => $arguments]]],
        ];
        $stop = ['model' => 'llama_cpp_test/test', 'provider_id' => 'llama_cpp_test', 'reasoning' => 'off', 'stop_reason' => 'stop', 'deltas' => [['type' => 'text', 'content' => 'Acknowledged.']]];
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
        $command = [\PHP_BINARY, __DIR__.'/Support/ForegroundCodeModeCandidates.php', $this->projectDir];
        if (null !== $runId) {
            $command[] = $runId;
        }
        $query = new Process($command, $this->tempDir, array_merge($this->controllerSubprocessEnv(), ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'HATFIELD_CWD' => $this->tempDir, 'HOME' => $this->tempDir, 'HATFIELD_SESSION_ID' => false, 'HATFIELD_TEST_DATABASE_PATH' => $this->databasePath]));
        $query->setTimeout(3);
        $query->mustRun();

        return $query;
    }

    /** @return list<list<array<string, mixed>>> */
    private function requests(): array
    {
        return array_map(static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR), file($this->tempDir.'/requests.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
    }
}
