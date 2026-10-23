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

    public function testTwoRetainedHostsRespectActualWorkerCapacity(): void
    {
        // This six-turn process contract needs the actual broker and two tool
        // consumers; isolated workers cannot prove queue saturation or release.
        // FIFOs and broker reservations synchronize it, not elapsed-time windows.
        $pipes = [];
        foreach (['a', 'b', 'queued'] as $name) {
            $this->assertTrue(posix_mkfifo($this->tempDir.'/release-'.$name, 0600));
            $pipes[$name] = fopen($this->tempDir.'/release-'.$name, 'r+b');
            $this->assertIsResource($pipes[$name]);
        }
        try {
            $this->sessionId = trim($this->queryRuntime()->getOutput());
            $this->spawnController();
            $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
            $this->writeCommand(['v' => 1, 'id' => 'start-pool', 'type' => 'start_run', 'payload' => ['prompt' => 'Exercise the fixed two-worker pool.']]);
            $started = $this->collectEventsUntil('tool_execution.started', 5);
            $this->assertStartRunAcked($started, 'start-pool');
            $this->runId = $this->indexByType($started)['run.started'][0]['runId'];
            $this->awaitPool(static fn (string $dir): bool => is_file($dir.'/ready-a'));
            $first = $this->backgroundPoolHost('pool-a');
            $this->collectEventsUntil(null, 5, static fn (array $e): bool => 'tool_execution.started' === ($e['type'] ?? '') && 'pool-b' === ($e['payload']['tool_call_id'] ?? null));
            $this->awaitPool(static fn (string $dir): bool => is_file($dir.'/ready-b'));
            $this->assertFileExists($this->tempDir.'/free-worker', 'Ordinary work must finish while host A remains blocked.');
            $second = $this->backgroundPoolHost('pool-b');
            $this->assertNotSame($first['identity'], $second['identity']);
            $this->collectEventsUntil(null, 5, static fn (array $e): bool => 'tool_execution.started' === ($e['type'] ?? '') && 'pool-queued' === ($e['payload']['tool_call_id'] ?? null));
            $a = json_decode((string) file_get_contents($this->tempDir.'/host-a'), true, flags: \JSON_THROW_ON_ERROR);
            $b = json_decode((string) file_get_contents($this->tempDir.'/host-b'), true, flags: \JSON_THROW_ON_ERROR);
            foreach (['pid', 'worker', 'socket'] as $key) {
                $this->assertNotSame($a[$key], $b[$key], 'Hosts must retain independent '.$key.' ownership.');
            }
            $this->assertTrue(posix_kill($a['pid'], 0));
            $this->assertTrue(posix_kill($b['pid'], 0));
            $this->assertFileExists($a['socket']);
            $this->assertFileExists($b['socket']);
            $broker = new \PDO('sqlite:'.$this->tempDir.'/.hatfield/sessions/'.$this->sessionId.'/messenger-queue.sqlite');
            $this->awaitPool(static fn (): bool => 2 === (int) $broker->query("SELECT count(*) FROM queue_messages WHERE queue = 'tool' AND reserved_until IS NOT NULL")->fetchColumn()
                && 1 === (int) $broker->query("SELECT count(*) FROM queue_messages WHERE queue = 'tool' AND reserved_until IS NULL")->fetchColumn());
            $this->assertFileDoesNotExist($this->tempDir.'/ready-queued', 'Both retained hosts occupy the configured pool.');
            fwrite($pipes['a'], "finish\n");
            fflush($pipes['a']);
            $this->awaitPool(static fn (string $dir): bool => is_file($dir.'/ready-queued'));
            $this->assertTrue(posix_kill($b['pid'], 0), 'Returning A admits queued work while B remains blocked.');
            $app = new \PDO('sqlite:'.$this->projectDir.'/var/test/'.$this->databasePath);
            $statement = $app->prepare('SELECT status FROM tool_deferred_completion WHERE deferred_id = ?');
            $this->awaitPool(static function () use ($statement, $first): bool {
                $statement->execute([$first['identity']]);

                return 'completed' === $statement->fetchColumn();
            });
            fwrite($pipes['queued'], "finish\n");
            fflush($pipes['queued']);
            $this->collectEventsUntil('run.completed', 5);
            $this->assertCount(5, $this->requests());
            $this->assertTrue(posix_kill($b['pid'], 0));
            fwrite($pipes['b'], "finish\n");
            fflush($pipes['b']);
            $this->collectEventsUntil('run.completed', 5);
            $requests = $this->requests();
            $this->assertCount(6, $requests);
            $history = json_encode($requests[5], \JSON_THROW_ON_ERROR);
            foreach (['pool-a', 'pool-b'] as $id) {
                $this->assertCount(1, array_filter($requests[5], static fn (array $m): bool => 'tool' === ($m['role'] ?? '') && $id === ($m['tool_call_id'] ?? null)));
                $this->assertStringContainsString('done-'.$id, $history);
                $this->assertStringContainsString('nested-'.$id, $history);
                $this->assertStringContainsString('stdout-'.$id, $history);
                $this->assertStringContainsString('stderr-'.$id, $history);
            }
            $canonical = array_map(static fn (string $line): array => json_decode($line, true, flags: \JSON_THROW_ON_ERROR), file($this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
            $this->assertCount(2, array_filter($canonical, static fn (array $e): bool => 'tool_call_acknowledged' === $e['type']));
            $this->assertCount(2, array_filter($canonical, static fn (array $e): bool => 'agent_command_applied' === $e['type']));
            foreach (['a', 'b'] as $name) {
                $this->assertCount(1, file($this->tempDir.'/host-'.$name, \FILE_IGNORE_NEW_LINES));
            }
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
        }
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-background-code-mode';
    }

    protected function extraSettingsYaml(): string
    {
        return "tools:\n  execution:\n    max_parallelism: 2\n  code_mode:\n    enabled: true\n";
    }

    protected function replayFixtures(): array
    {
        $this->databasePath = 'app_test-replay-code-mode-'.$this->sessionId.'.sqlite';
        if ('testTwoRetainedHostsRespectActualWorkerCapacity' === $this->name()) {
            return $this->poolFixtures();
        }
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

    /** @return array<string, mixed> */
    private function backgroundPoolHost(string $call): array
    {
        $candidates = json_decode(trim($this->queryRuntime($this->runId)->getOutput()), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $candidates);
        $candidate = $candidates[0];
        $this->assertSame($call, $candidate['toolCallId']);
        $this->writeCommand(['v' => 1, 'id' => 'background-'.$call, 'type' => 'background_code_mode', 'runId' => $this->runId, 'payload' => ['identity' => $candidate['identity'], 'tool_call_id' => $call]]);

        return $candidate;
    }

    private function awaitPool(\Closure $ready): void
    {
        $deadline = microtime(true) + 4;
        do {
            $this->assertTrue($this->isRunning(), $this->collectDiagnostics([]));
            if ($ready($this->tempDir)) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Pool barrier was not reached: '.$this->collectDiagnostics([]));
    }

    /** @return list<array<string, mixed>> */
    private function poolFixtures(): array
    {
        $tools = [];
        foreach (['a', 'b'] as $name) {
            $id = 'pool-'.$name;
            $command = 'printf ready > '.escapeshellarg($this->tempDir.'/ready-'.$name).'; IFS= read -r release < '.escapeshellarg($this->tempDir.'/release-'.$name).'; printf nested-'.$id;
            $script = 'file_put_contents('.var_export($this->tempDir.'/host-'.$name, true).', json_encode(["pid" => getmypid(), "worker" => posix_getppid(), "socket" => getenv("HATFIELD_CODE_MODE_SOCKET")])."\\n", FILE_APPEND); echo "stdout-'.$id.'\\n"; fwrite(STDERR, "stderr-'.$id.'\\n"); $nested = tool("bash", ["command" => '.var_export($command, true).', "timeout" => 15]); return ["marker" => "done-'.$id.'", "nested" => $nested, "pid" => getmypid()];';
            $tools[$name] = [$id, 'code_mode', ['script' => $script, 'timeout_seconds' => 15]];
        }
        $free = ['pool-free', 'bash', ['command' => 'printf free > '.escapeshellarg($this->tempDir.'/free-worker'), 'timeout' => 5]];
        $queued = ['pool-queued', 'bash', ['command' => 'printf ready > '.escapeshellarg($this->tempDir.'/ready-queued').'; IFS= read -r release < '.escapeshellarg($this->tempDir.'/release-queued').'; printf queue-complete', 'timeout' => 15]];
        $fixtures = [];
        foreach ([$tools['a'], $free, $tools['b'], $queued] as [$id, $name, $arguments]) {
            $fixtures[] = ['model' => 'llama_cpp_test/test', 'provider_id' => 'llama_cpp_test', 'reasoning' => 'off', 'stop_reason' => 'tool_use', 'deltas' => [
                ['type' => 'tool_call_start', 'id' => $id, 'name' => $name],
                ['type' => 'tool_input_delta', 'id' => $id, 'name' => $name, 'partial_json' => json_encode($arguments, \JSON_THROW_ON_ERROR)],
                ['type' => 'tool_call_complete', 'tool_calls' => [['id' => $id, 'name' => $name, 'arguments' => $arguments]]],
            ]];
        }
        $stop = ['model' => 'llama_cpp_test/test', 'provider_id' => 'llama_cpp_test', 'reasoning' => 'off', 'stop_reason' => 'stop', 'deltas' => [['type' => 'text', 'content' => 'Pool completed.']]];
        $fixtures[] = $stop;
        $fixtures[] = $stop;
        foreach ($fixtures as &$fixture) {
            $fixture['replay_request_capture'] = $this->tempDir.'/requests.jsonl';
        }

        return $fixtures;
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
