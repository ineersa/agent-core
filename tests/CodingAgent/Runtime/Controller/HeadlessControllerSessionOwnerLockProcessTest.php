<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;

/**
 * Thesis: only one live controller may own a given project CWD + session ID.
 * A second controller for the same pair must fail without emitting runtime.ready,
 * preventing two consumer pools from sharing session Doctrine queues.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class HeadlessControllerSessionOwnerLockProcessTest extends ControllerReplayE2eTestCase
{
    /** @var resource|null */
    private mixed $secondProcess = null;

    /** @var array<int, resource> */
    private array $secondPipes = [];

    /** @var list<int> Independent ownership list for the second controller tree. */
    private array $secondTrackedPids = [];

    private string $secondStdoutBuf = '';
    private string $secondStderrBuf = '';

    protected function tearDown(): void
    {
        $this->stopSecondProcess();
        $this->stopProcess();
        $this->assertNoTrackedControllerProcessSurvivors();
        if (isset($this->tempDir) && '' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
        $this->tempDir = '';
        \PHPUnit\Framework\TestCase::tearDown();
    }

    public function testSecondControllerForSameSessionIsRejectedWithoutRuntimeReady(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $this->spawnSecondControllerSameSession();
        $this->assertSecondControllerRejectedWithoutRuntimeReady();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        return [];
    }

    protected function tempDirPrefix(): string
    {
        return 'controller-session-owner-lock';
    }

    protected function isolatedLlmWorkerCount(): int
    {
        return 1;
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'
tools:
    execution:
        max_parallelism: 1
YAML;
    }

    private function spawnSecondControllerSameSession(): void
    {
        [$php, $script] = AgentTestExecutable::sourceConsoleCommand();
        $this->assertFileExists($script, 'Agent executable not found at '.$script);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            // Distinct DB files so lock conflict is not confounded with SQLite file locks.
            'HATFIELD_TEST_DATABASE_PATH' => 'app_test-replay-'.$this->sessionId.'-b.sqlite',
            'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => 'messenger_transport_test-replay-'.$this->sessionId.'-b.sqlite',
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=run_control_{$this->sessionId}",
            'HATFIELD_LLM_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=llm_{$this->sessionId}",
            'HATFIELD_TOOL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=tool_{$this->sessionId}",
            'HATFIELD_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=agent_{$this->sessionId}",
            'HATFIELD_MCP_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=mcp_{$this->sessionId}",
            'HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=extension_agent_{$this->sessionId}",
            'HATFIELD_SESSION_ID' => $this->sessionId,
        ];

        $pipes = [];
        $process = @proc_open(
            array_merge(
                ['setsid', '-w', $php, $script, 'agent', '--controller', '--cwd='.$this->tempDir],
                $this->controllerExtraArgs(),
            ),
            $descriptors,
            $pipes,
            $this->tempDir,
            $env,
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Failed to spawn second controller process.');
        }

        $this->secondProcess = $process;
        $this->secondPipes = $pipes;
        stream_set_blocking($pipes[0], true);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        // Do NOT call trackControllerProcessTree() — that overwrites the first
        // controller's ownership list. Keep a separate PID list for teardown.
        $status = @proc_get_status($process);
        $rootPid = \is_array($status) && isset($status['pid']) ? (int) $status['pid'] : 0;
        $this->secondTrackedPids = $rootPid > 0
            ? array_values(array_unique(array_merge(
                [$rootPid],
                $this->discoverControllerProcessTreePids($rootPid),
            )))
            : [];
    }

    private function assertSecondControllerRejectedWithoutRuntimeReady(): void
    {
        $deadline = microtime(true) + $this->liveControllerReadyTimeout();
        $sawReady = false;

        while (microtime(true) < $deadline) {
            $this->drainSecondStdout();
            if (str_contains($this->secondStdoutBuf, '"type":"runtime.ready"')
                || str_contains($this->secondStdoutBuf, '"type": "runtime.ready"')) {
                $sawReady = true;
                break;
            }

            if (null !== $this->secondProcess) {
                $status = proc_get_status($this->secondProcess);
                if (!$status['running']) {
                    break;
                }
            }

            usleep(50_000);
        }

        $this->drainSecondStdout();
        $this->drainSecondStderr();

        $this->assertFalse(
            $sawReady,
            "Second controller must not emit runtime.ready while first owner is healthy.\nstdout="
            .$this->secondStdoutBuf."\nstderr=".$this->secondStderrBuf,
        );

        if (null !== $this->secondProcess) {
            $status = proc_get_status($this->secondProcess);
            if ($status['running']) {
                // Owner lock rejects before event loop; process should exit quickly.
                $this->stopSecondProcess();
                $this->fail(
                    "Second controller still running without runtime.ready.\nstdout="
                    .$this->secondStdoutBuf."\nstderr=".$this->secondStderrBuf,
                );
            }

            $this->assertNotSame(
                0,
                (int) ($status['exitcode'] ?? -1),
                "Second controller must exit non-zero when session owner lock is held.\nstdout="
                .$this->secondStdoutBuf."\nstderr=".$this->secondStderrBuf,
            );
        }
    }

    private function drainSecondStdout(): void
    {
        if (!isset($this->secondPipes[1]) || !\is_resource($this->secondPipes[1])) {
            return;
        }

        $chunk = stream_get_contents($this->secondPipes[1]);
        if (\is_string($chunk) && '' !== $chunk) {
            $this->secondStdoutBuf .= $chunk;
        }
    }

    private function drainSecondStderr(): void
    {
        if (!isset($this->secondPipes[2]) || !\is_resource($this->secondPipes[2])) {
            return;
        }

        $chunk = stream_get_contents($this->secondPipes[2]);
        if (\is_string($chunk) && '' !== $chunk) {
            $this->secondStderrBuf .= $chunk;
        }
    }

    private function stopSecondProcess(): void
    {
        foreach ($this->secondPipes as $pipe) {
            if (\is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->secondPipes = [];

        if (null === $this->secondProcess) {
            return;
        }

        if (\is_resource($this->secondProcess)) {
            $status = @proc_get_status($this->secondProcess);
            $rootPid = \is_array($status) && isset($status['pid']) ? (int) $status['pid'] : 0;
            if ($rootPid > 0) {
                $this->secondTrackedPids = array_values(array_unique(array_merge(
                    $this->secondTrackedPids,
                    [$rootPid],
                    $this->discoverControllerProcessTreePids($rootPid),
                )));
            }

            $this->terminateTrackedControllerPids($this->secondTrackedPids);
            @proc_close($this->secondProcess);
        }

        $this->secondProcess = null;
        $this->secondTrackedPids = [];
    }
}
