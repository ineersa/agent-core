<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

#[Group('controller-replay')]
final class ControllerReplayBackgroundCodeModeTest extends ControllerReplayE2eTestCase
{
    private string $databasePath;
    private bool $capacity = false;

    protected function setUp(): void
    {
        $this->capacity = 'testTwoRetainedHostsSaturateTheExistingPoolUntilOneReturns' === $this->name();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->projectDir.'/var/test/'.$this->databasePath.$suffix);
        }
    }

    public static function settlements(): iterable
    {
        yield 'natural return' => [false];
        yield 'targeted stop' => [true];
    }

    #[DataProvider('settlements')]
    public function testBlockedNestedCallAcknowledgesAndFreeWorkerProgressesBeforeOneLateNotification(bool $stop): void
    {
        $this->assertTrue(posix_mkfifo($this->tempDir.'/release', 0600));
        $release = fopen($this->tempDir.'/release', 'r+b');
        $this->assertIsResource($release);
        try {
            $this->sessionId = trim($this->query()->getOutput());
            file_put_contents($this->tempDir.'/free.txt', 'FREE_WORKER');
            $this->spawnController();
            $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
            $this->writeCommand(['v' => 1, 'id' => 'start', 'type' => 'start_run', 'payload' => ['prompt' => 'Run code mode, then read the file after backgrounding.']]);
            $started = $this->collectEventsUntil(null, 5, static fn (array $e): bool => 'tool_execution.started' === ($e['type'] ?? '') && 'host' === ($e['payload']['tool_call_id'] ?? ''));
            $this->assertStartRunAcked($started, 'start');
            $this->runId = $this->indexByType($started)['run.started'][0]['runId'];
            $deadline = microtime(true) + 3;
            while (!is_file($this->tempDir.'/ready') && microtime(true) < $deadline) {
                $this->assertTrue($this->isRunning(), $this->collectDiagnostics($started));
                usleep(10000);
            }
            $this->assertFileExists($this->tempDir.'/ready', 'Nested Bash reached the FIFO while the PHP host is retained.');
            $snapshot = json_decode(trim($this->query($this->runId)->getOutput()), true, 32, \JSON_THROW_ON_ERROR);
            $this->assertCount(1, $snapshot['codeMode']);
            $candidate = $snapshot['codeMode'][0];
            $this->writeCommand(['v' => 1, 'id' => 'detach', 'type' => 'background_code_mode', 'runId' => $this->runId,
                'payload' => ['deferred_id' => $candidate['lifecycleId'], 'tool_call_id' => $candidate['toolCallId']]]);
            $continued = $this->collectEventsUntil('run.completed', 5);
            $types = $this->indexByType($continued);
            $this->assertCount(1, $types['tool_call.acknowledged'] ?? [], json_encode($continued, \JSON_THROW_ON_ERROR));
            $this->assertTrue($types['tool_call.acknowledged'][0]['payload']['backgrounded']);
            $this->assertCount(1, array_filter($types['tool_execution.completed'] ?? [], static fn (array $e): bool => 'free' === ($e['payload']['tool_call_id'] ?? '')), 'A second real consumer executes ordinary work while the nested call is blocked.');
            $this->assertCount(3, $this->requests());
            $this->assertStringContainsString('FREE_WORKER', json_encode($this->requests()[2], \JSON_THROW_ON_ERROR));
            $this->assertFileExists($this->tempDir.'/ready');
            $work = json_decode(trim($this->query($this->runId)->getOutput()), true, 32, \JSON_THROW_ON_ERROR)['work'];
            $this->assertCount(1, $work);
            $this->assertSame('code_mode', $work[0]['kind']);
            if ($stop) {
                $this->writeCommand(['v' => 1, 'id' => 'stop', 'type' => 'stop_code_mode', 'runId' => $this->runId,
                    'payload' => ['deferred_id' => $candidate['lifecycleId'], 'tool_call_id' => $candidate['toolCallId']]]);
                $deadline = microtime(true) + 3;
                do {
                    $this->assertTrue($this->isRunning());
                    $remaining = json_decode(trim($this->query($this->runId)->getOutput()), true, 32, \JSON_THROW_ON_ERROR)['work'];
                    if ([] !== $remaining) {
                        usleep(10000);
                    }
                } while ([] !== $remaining && microtime(true) < $deadline);
                $this->assertSame([], $remaining, 'Nested Bash observes the same invocation cancellation token and releases its host.');
                $this->assertCount(3, $this->requests(), 'Stopping the invocation never resumes the parent.');

                return;
            }
            fwrite($release, "finish\n");
            fflush($release);
            $late = $this->collectEventsUntil('run.completed', 5);
            $lateTypes = $this->indexByType($late);
            $notices = array_values(array_filter($lateTypes['model.notification'] ?? [], static fn (array $e): bool => 'code_mode' === ($e['payload']['source'] ?? '')));
            $this->assertCount(1, $notices, $this->collectDiagnostics($late));
            $this->assertSame('host', $notices[0]['payload']['metadata']['background_completion']['tool_call_id']);
            $this->assertSame($candidate['lifecycleId'], $notices[0]['payload']['metadata']['background_completion']['invocation_id']);
            $this->assertStringContainsString('BG_CODE_DONE', $notices[0]['payload']['text']);
            $this->assertArrayNotHasKey('user.message_submitted', $lateTypes);
            $this->assertCount(4, $this->requests());
            $this->assertCount(1, array_filter($this->requests()[3], static fn (array $m): bool => 'tool' === ($m['role'] ?? '') && 'host' === ($m['tool_call_id'] ?? '')));
            $canonical = array_map(static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR), file($this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
            $this->assertCount(1, array_filter($canonical, static fn (array $e): bool => 'tool_call_acknowledged' === $e['type']));
            $this->assertCount(0, array_filter($canonical, static fn (array $e): bool => 'tool_execution_end' === $e['type'] && 'host' === ($e['payload']['tool_result']['tool_call_id'] ?? '')));
        } finally {
            fclose($release);
        }
    }

    // Only real broker consumers can prove occupancy and queued dispatch. FIFO
    // release is the rendezvous; bounded waits are failure caps, not scheduling.
    public function testTwoRetainedHostsSaturateTheExistingPoolUntilOneReturns(): void
    {
        $releases = [];
        try {
            foreach (['one', 'two'] as $id) {
                $this->assertTrue(posix_mkfifo($this->tempDir.'/release-'.$id, 0600));
                $releases[$id] = fopen($this->tempDir.'/release-'.$id, 'r+b');
                $this->assertIsResource($releases[$id]);
            }
            $this->sessionId = trim($this->query()->getOutput());
            file_put_contents($this->tempDir.'/free.txt', 'CAPACITY_RETURNED');
            $this->spawnController();
            $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
            $this->writeCommand(['v' => 1, 'id' => 'start', 'type' => 'start_run', 'payload' => ['prompt' => 'Run two retained hosts, then read free.txt.']]);
            $identities = [];
            foreach (['one', 'two'] as $id) {
                $started = $this->collectEventsUntil(null, 5, static fn (array $e): bool => 'tool_execution.started' === ($e['type'] ?? '') && $id === ($e['payload']['tool_call_id'] ?? ''));
                if ('one' === $id) {
                    $this->runId = $this->indexByType($started)['run.started'][0]['runId'];
                }
                $this->waitForFile($id.'.ready');
                $identities[$id] = json_decode(file_get_contents($this->tempDir.'/'.$id.'.before'), true, 32, \JSON_THROW_ON_ERROR);
                $this->assertFileExists($identities[$id]['socket']);
                $snapshot = json_decode(trim($this->query($this->runId)->getOutput()), true, 32, \JSON_THROW_ON_ERROR);
                $candidate = array_values(array_filter($snapshot['codeMode'], static fn (array $c): bool => $id === $c['toolCallId']))[0];
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
            $this->assertCount(3, $this->requests(), 'The dispatched read has not returned to the model.');
            fwrite($releases['one'], "finish\n");
            fflush($releases['one']);
            $progress = $this->collectEventsUntil(null, 5, static fn (array $e): bool => 'tool_execution.completed' === ($e['type'] ?? '') && 'free' === ($e['payload']['tool_call_id'] ?? ''));
            $this->assertCount(1, $this->indexByType($progress)['tool_execution.completed']);
            $this->assertFileExists($identities['two']['socket'], 'The other host is still retained when ordinary work progresses.');
            $this->assertFileDoesNotExist($this->tempDir.'/two.after');
            $first = isset($this->indexByType($progress)['model.notification']) ? $progress : $this->waitForNotice();
            $this->assertCount(1, $this->indexByType($first)['model.notification']);
            $this->assertSame('one', $this->indexByType($first)['model.notification'][0]['payload']['metadata']['background_completion']['tool_call_id']);
            fwrite($releases['two'], "finish\n");
            fflush($releases['two']);
            $second = $this->waitForNotice();
            $this->assertCount(1, $this->indexByType($second)['model.notification']);
            $this->assertSame('two', $this->indexByType($second)['model.notification'][0]['payload']['metadata']['background_completion']['tool_call_id']);
            foreach (['one', 'two'] as $id) {
                $after = json_decode(file_get_contents($this->tempDir.'/'.$id.'.after'), true, 32, \JSON_THROW_ON_ERROR);
                $this->assertSame($identities[$id], $after['identity'], 'PID, socket, workspace and script-local state survive detachment.');
                $this->assertStringContainsString('NESTED_'.$id, $after['nested']);
            }
            $canonical = array_map(static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR), file($this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
            foreach (['one', 'two'] as $id) {
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
        $this->databasePath = 'app_test-code-mode-'.$this->sessionId.'.sqlite';
        $command = ': > '.escapeshellarg($this->tempDir.'/ready').'; IFS= read -r release < '.escapeshellarg($this->tempDir.'/release');
        $script = 'tool("bash", '.var_export(['command' => $command], true).'); return "BG_CODE_DONE";';
        $calls = [['host', 'code_mode', ['script' => $script]], ['free', 'read', ['path' => 'free.txt']], null, null];
        if ($this->capacity) {
            $calls = [];
            foreach (['one', 'two'] as $id) {
                $prefix = var_export($this->tempDir.'/'.$id, true);
                $command = ': > '.escapeshellarg($this->tempDir.'/'.$id.'.ready').'; IFS= read -r release < '.escapeshellarg($this->tempDir.'/release-'.$id).'; printf '.escapeshellarg('NESTED_'.$id);
                $script = '$identity = ["pid" => getmypid(), "workspace" => __DIR__, "socket" => __DIR__."/s.sock", "local" => '.var_export($id, true).']; file_put_contents('.$prefix.'.".before", json_encode($identity)); $nested = tool("bash", '.var_export(['command' => $command], true).'); file_put_contents('.$prefix.'.".after", json_encode(["identity" => ["pid" => getmypid(), "workspace" => __DIR__, "socket" => __DIR__."/s.sock", "local" => $identity["local"]], "nested" => $nested])); return $identity["local"];';
                $calls[] = [$id, 'code_mode', ['script' => $script]];
            }
            array_push($calls, ['free', 'read', ['path' => 'free.txt']], null, null, null);
        }
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

    protected function replayExtraEnv(): array
    {
        return ['HATFIELD_TEST_DATABASE_PATH' => $this->databasePath];
    }

    protected function extraSettingsYaml(): string
    {
        return "tools:\n  execution:\n    max_parallelism: 2\n  code_mode:\n    enabled: true\n";
    }

    private function waitForNotice(): array
    {
        $deadline = microtime(true) + 5;
        do {
            $events = $this->collectEventsUntil('model.notification', max(0.01, $deadline - microtime(true)));
            if (isset($this->indexByType($events)['model.notification'])) {
                return $events;
            }
        } while ($this->isRunning() && microtime(true) < $deadline);
        $this->fail($this->collectDiagnostics($events));
    }

    private function waitForFile(string $name): void
    {
        $deadline = microtime(true) + 3;
        while (!is_file($this->tempDir.'/'.$name) && microtime(true) < $deadline) {
            $this->assertTrue($this->isRunning());
            usleep(10000);
        }
        if (!is_file($this->tempDir.'/'.$name)) {
            $this->fail($this->collectDiagnostics($this->readEvents()));
        }
    }

    private function query(?string $runId = null): Process
    {
        $args = [\PHP_BINARY, __DIR__.'/Support/ForegroundBashCandidates.php', $this->projectDir];
        if (null !== $runId) {
            array_push($args, $runId, 'code_mode');
        }
        $process = new Process($args, $this->tempDir, array_merge($this->controllerSubprocessEnv(), ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'HATFIELD_CWD' => $this->tempDir, 'HOME' => $this->tempDir, 'HATFIELD_SESSION_ID' => false, 'HATFIELD_TEST_DATABASE_PATH' => $this->databasePath]));
        $process->setTimeout(3);
        $process->mustRun();

        return $process;
    }

    /** @return list<list<array<string, mixed>>> */
    private function requests(): array
    {
        return array_map(static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR), file($this->tempDir.'/requests.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
    }
}
