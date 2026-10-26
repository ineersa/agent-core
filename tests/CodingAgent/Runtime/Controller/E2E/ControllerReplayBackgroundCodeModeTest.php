<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;

#[Group('controller-replay')]
final class ControllerReplayBackgroundCodeModeTest extends ControllerReplayE2eTestCase
{
    private string $databasePath;

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->projectDir.'/var/test/'.$this->databasePath.$suffix);
            $this->assertFileDoesNotExist($this->projectDir.'/var/test/'.$this->databasePath.$suffix);
        }
    }

    // Only real broker consumers can prove occupancy and queued dispatch. FIFO
    // release is the rendezvous; bounded waits are failure caps, not scheduling.
    #[TestWith([false], 'natural return')]
    #[TestWith([true], 'targeted stop')]
    public function testTwoRetainedHostsSaturateTheExistingPoolUntilOneReturns(bool $stop): void
    {
        $releases = [];
        try {
            foreach (['one', 'two'] as $id) {
                $this->assertTrue(posix_mkfifo($this->tempDir.'/release-'.$id, 0600));
                $releases[$id] = fopen($this->tempDir.'/release-'.$id, 'r+b');
                $this->assertIsResource($releases[$id]);
            }
            $this->sessionId = trim($this->query()->getOutput());
            $this->assertStringContainsString('replay-e2e-', $this->databasePath, 'Database identity stays per-case after session identity becomes numeric.');
            file_put_contents($this->tempDir.'/free.txt', 'CAPACITY_RETURNED');
            $this->spawnController();
            $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
            $this->writeCommand(['v' => 1, 'id' => 'start', 'type' => 'start_run', 'payload' => ['prompt' => 'Run two retained hosts, then read free.txt.']]);
            $identities = [];
            $invocations = [];
            foreach (['one', 'two'] as $id) {
                $started = $this->collectEventsUntil(null, 5, static fn (array $e): bool => 'tool_execution.started' === ($e['type'] ?? '') && $id === ($e['payload']['tool_call_id'] ?? ''));
                if ('one' === $id) {
                    $this->runId = $this->indexByType($started)['run.started'][0]['runId'];
                }
                $this->waitForFile($id.'.ready');
                $identities[$id] = json_decode(file_get_contents($this->tempDir.'/'.$id.'.before'), true, 32, \JSON_THROW_ON_ERROR);
                $this->assertFileExists($identities[$id]['socket']);
                $snapshot = json_decode(trim($this->query($this->runId)->getOutput()), true, 32, \JSON_THROW_ON_ERROR);
                $this->assertSame('llama_cpp_test/test', $snapshot['sessionModel'], 'The controller and query retain metadata seeded before numeric session assignment.');
                $candidate = array_values(array_filter($snapshot['codeMode'], static fn (array $c): bool => $id === $c['toolCallId']))[0];
                $invocations[$id] = $candidate['lifecycleId'];
                $this->writeCommand(['v' => 1, 'id' => 'detach-'.$id, 'type' => 'background_code_mode', 'runId' => $this->runId,
                    'payload' => ['deferred_id' => $candidate['lifecycleId'], 'tool_call_id' => $id]]);
                $ack = $this->collectEventsUntil('tool_call.acknowledged', 5);
                $this->assertSame($id, $this->indexByType($ack)['tool_call.acknowledged'][0]['payload']['tool_call_id']);
            }
            foreach (['pid', 'socket', 'workspace'] as $field) {
                $this->assertNotSame($identities['one'][$field], $identities['two'][$field]);
            }
            $this->collectEventsUntil(null, 5, static fn (array $e): bool => 'tool_execution.started' === ($e['type'] ?? '') && 'free' === ($e['payload']['tool_call_id'] ?? ''));
            $snapshot = json_decode(trim($this->query($this->runId)->getOutput()), true, 32, \JSON_THROW_ON_ERROR);
            $this->assertCount(2, $snapshot['work']);
            $this->assertContains('["free"]', $snapshot['inFlight'], 'Ordinary work has been dispatched while both real consumers are occupied.');
            $this->assertCount(3, file($this->tempDir.'/requests.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES), 'The dispatched read has not returned to the model.');
            if ($stop) {
                $this->writeCommand(['v' => 1, 'id' => 'stop', 'type' => 'stop_code_mode', 'runId' => $this->runId,
                    'payload' => ['deferred_id' => $invocations['one'], 'tool_call_id' => 'one']]);
            } else {
                fwrite($releases['one'], "finish\n");
                fflush($releases['one']);
            }
            $progress = $this->collectEventsUntil(null, 5, static fn (array $e): bool => 'tool_execution.completed' === ($e['type'] ?? '') && 'free' === ($e['payload']['tool_call_id'] ?? ''));
            $this->assertCount(1, $this->indexByType($progress)['tool_execution.completed']);
            $this->assertFileExists($identities['two']['socket'], 'The other host is still retained when ordinary work progresses.');
            $this->assertFileDoesNotExist($this->tempDir.'/two.after');
            if (!$stop) {
                $first = $this->indexByType($progress)['model.notification'][0] ?? $this->waitForEvent('model.notification', 5);
                $this->assertSame($invocations['one'], $first['payload']['metadata']['background_completion']['invocation_id']);
            } else {
                $this->collectEventsUntil('run.completed', 5);
                $this->assertCount(4, file($this->tempDir.'/requests.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES), 'Only the completed read, not the stopped host, advances the model.');
                $this->assertFileDoesNotExist($this->tempDir.'/one.after');
            }
            fwrite($releases['two'], "finish\n");
            fflush($releases['two']);
            $second = $this->waitForEvent('model.notification', 5);
            $this->assertSame('two', $second['payload']['metadata']['background_completion']['tool_call_id']);
            foreach ($stop ? ['two'] : ['one', 'two'] as $id) {
                $after = json_decode(file_get_contents($this->tempDir.'/'.$id.'.after'), true, 32, \JSON_THROW_ON_ERROR);
                $this->assertSame($identities[$id], $after['identity'], 'PID, socket, workspace and script-local state survive detachment.');
                $this->assertStringContainsString('NESTED_'.$id, $after['nested']);
            }
            $this->assertSame($invocations['two'], $second['payload']['metadata']['background_completion']['invocation_id']);
            $canonical = array_map(static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR), file($this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
            foreach (['one', 'two'] as $id) {
                $this->assertCount($stop && 'one' === $id ? 0 : 1, array_filter($canonical, static fn (array $e): bool => 'agent_command_applied' === $e['type'] && $invocations[$id] === ($e['payload']['message']['metadata']['background_completion']['invocation_id'] ?? '')));
                $this->assertCount(1, array_filter($canonical, static fn (array $e): bool => 'tool_call_acknowledged' === $e['type'] && $id === ($e['payload']['tool_result']['tool_call_id'] ?? '')));
                $this->assertCount(0, array_filter($canonical, static fn (array $e): bool => 'tool_execution_end' === $e['type'] && $id === ($e['payload']['tool_result']['tool_call_id'] ?? '')));
            }
        } finally {
            foreach ($releases as $release) {
                fclose($release);
            }
        }
    }

    protected function tempDirPrefix(): string
    {
        return 'controller-code-mode-';
    }

    protected function replayFixtures(): array
    {
        $this->databasePath = 'app_test-replay-'.$this->sessionId.'.sqlite';
        $calls = [];
        foreach (['one', 'two'] as $id) {
            $prefix = var_export($this->tempDir.'/'.$id, true);
            $command = ': > '.escapeshellarg($this->tempDir.'/'.$id.'.ready').'; IFS= read -r release < '.escapeshellarg($this->tempDir.'/release-'.$id).'; printf '.escapeshellarg('NESTED_'.$id);
            $script = '$identity = ["pid" => getmypid(), "workspace" => __DIR__, "socket" => __DIR__."/s.sock", "local" => '.var_export($id, true).']; file_put_contents('.$prefix.'.".before", json_encode($identity)); $nested = tool("bash", '.var_export(['command' => $command], true).'); file_put_contents('.$prefix.'.".after", json_encode(["identity" => ["pid" => getmypid(), "workspace" => __DIR__, "socket" => __DIR__."/s.sock", "local" => $identity["local"]], "nested" => $nested])); return $identity["local"];';
            $calls[] = [$id, 'code_mode', ['script' => $script]];
        }
        array_push($calls, ['free', 'read', ['path' => 'free.txt']], null, null, null);
        $fixtures = [];
        foreach ($calls as $call) {
            $fixture = ['model' => 'llama_cpp_test/test', 'provider_id' => 'llama_cpp_test', 'reasoning' => 'off', 'stop_reason' => null === $call ? 'stop' : 'tool_call', 'replay_request_capture' => $this->tempDir.'/requests.jsonl'];
            $fixture['deltas'] = null === $call ? [['type' => 'text', 'content' => 'Done.']] : [
                ['type' => 'tool_call_start', 'id' => $call[0], 'name' => $call[1]],
                ['type' => 'tool_input_delta', 'id' => $call[0], 'name' => $call[1], 'partial_json' => json_encode($call[2], \JSON_THROW_ON_ERROR)],
                ['type' => 'tool_call_complete', 'tool_calls' => [['id' => $call[0], 'name' => $call[1], 'arguments' => $call[2]]]],
            ];
            $fixtures[] = $fixture;
        }

        return $fixtures;
    }

    protected function extraSettingsYaml(): string
    {
        return "tools:\n  execution:\n    max_parallelism: 2\n  code_mode:\n    enabled: true\n";
    }

    protected function replayExtraEnv(): array
    {
        return ['HATFIELD_TEST_DATABASE_PATH' => $this->databasePath];
    }

    private function waitForFile(string $name): void
    {
        $deadline = microtime(true) + 3;
        while (!is_file($this->tempDir.'/'.$name) && microtime(true) < $deadline) {
            $this->assertTrue($this->isRunning());
            usleep(10000);
        }
        $this->assertFileExists($this->tempDir.'/'.$name, $this->collectDiagnostics([]));
    }

    private function query(?string $runId = null): Process
    {
        $args = [\PHP_BINARY, __DIR__.'/Support/ForegroundBashCandidates.php', $this->projectDir];
        if (null !== $runId) {
            array_push($args, $runId, 'code_mode');
        }
        $process = new Process($args, $this->tempDir, array_merge($this->controllerSubprocessEnv(), $this->replayExtraEnv(), ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'HATFIELD_CWD' => $this->tempDir, 'HOME' => $this->tempDir, 'HATFIELD_SESSION_ID' => false]));
        $process->setTimeout(3);
        $process->mustRun();

        return $process;
    }
}
