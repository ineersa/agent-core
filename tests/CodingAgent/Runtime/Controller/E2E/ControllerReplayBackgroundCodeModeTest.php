<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;
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
        }
    }

    public function testBlockedNestedCallAcknowledgesAndFreeWorkerProgressesBeforeOneLateNotification(): void
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
            $this->assertCount(0, array_filter($canonical, static fn (array $e): bool => 'tool_execution_end' === $e['type'] && 'host' === ($e['payload']['tool_call_id'] ?? '')));
        } finally {
            fclose($release);
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
        $fixtures = [];
        foreach ([['host', 'code_mode', ['script' => $script]], ['free', 'read', ['path' => 'free.txt']], null, null] as $call) {
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
