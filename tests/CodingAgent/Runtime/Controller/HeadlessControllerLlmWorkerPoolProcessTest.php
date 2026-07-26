<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Thesis B: a real HeadlessController process launches the configured fixed
 * llm messenger consumer pool (llm#0..N-1) without reimplementing launch math.
 *
 * Heavyweight process topology proof — excluded from normal `castor test`
 * ParaTest via controller-replay group so unit workers do not spawn controllers.
 *
 * Teardown owns the full controller process tree (root + messenger children)
 * with SIGTERM → 3s grace → SIGKILL, and asserts no owned descendants remain.
 * Only PIDs discovered under this test's controller root (same uid) are signalled.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class HeadlessControllerLlmWorkerPoolProcessTest extends TestCase
{
    private string $sessionId = '';

    /** @var list<int> */
    private array $trackedPids = [];

    private int $controllerRootPid = 0;

    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $tempDir = '';

    protected function tearDown(): void
    {
        $this->stopOwnedProcessTree();
        if ('' !== $this->tempDir && is_dir($this->tempDir)) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function testControllerProcessStartsConfiguredLlmConsumerPool(): void
    {
        $this->sessionId = 'llm-pool-'.substr(bin2hex(random_bytes(8)), 0, 8);
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('controller-llm-pool');

        TestDirectoryIsolation::createHatfieldTree($this->tempDir, withSessions: true);
        file_put_contents($this->tempDir.'/.hatfield/settings.yaml', <<<'YAML'
runtime:
    llm_worker_count: 3
tools:
    execution:
        max_parallelism: 1
tui:
    theme: cyberpunk
logging:
    level: info
    max_files: 1
YAML);

        [$php, $script] = AgentTestExecutable::sourceConsoleCommand();
        $this->assertFileExists($script);

        $env = [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'HATFIELD_SESSION_ID' => $this->sessionId,
            'HATFIELD_TEST_DATABASE_PATH' => 'app_test-llm-pool-'.$this->sessionId.'.sqlite',
            'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => 'messenger_transport_test-llm-pool-'.$this->sessionId.'.sqlite',
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=run_control_{$this->sessionId}",
            'HATFIELD_LLM_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=llm_{$this->sessionId}",
            'HATFIELD_TOOL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=tool_{$this->sessionId}",
            'HATFIELD_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=agent_{$this->sessionId}",
            'HATFIELD_MCP_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=mcp_{$this->sessionId}",
            'HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=extension_agent_{$this->sessionId}",
            'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/tmp'),
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(
            [$php, $script, 'agent', '--controller', '--cwd='.$this->tempDir],
            $descriptors,
            $this->pipes,
            $this->tempDir,
            $env,
        );
        $this->assertIsResource($process, 'Failed to spawn controller process');
        $this->process = $process;
        $this->trackOwnedProcessTree($process);

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $stdout = '';
        $deadline = microtime(true) + 15.0;
        $ready = false;
        while (microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($this->pipes[1]);
            if (str_contains($stdout, 'runtime.ready') || str_contains($stdout, '"type":"runtime.ready"')) {
                $ready = true;
                break;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stderr = (string) stream_get_contents($this->pipes[2]);
                $this->fail("Controller exited before runtime.ready. stdout={$stdout} stderr={$stderr}");
            }
            usleep(20_000);
        }
        $this->assertTrue($ready, 'Controller must emit runtime.ready. stdout='.$stdout);

        $status = proc_get_status($process);
        $controllerPid = (int) ($status['pid'] ?? 0);
        $this->assertGreaterThan(0, $controllerPid);
        $this->controllerRootPid = $controllerPid;
        $this->refreshOwnedProcessTree($process);

        $llmConsumers = $this->waitForControllerLlmConsumers($controllerPid, expected: 3, timeoutSeconds: 10.0);
        $this->assertCount(
            3,
            $llmConsumers,
            'Expected three messenger:consume llm children under controller pid '.$controllerPid.' got: '.json_encode($llmConsumers),
        );
        $this->refreshOwnedProcessTree($process);

        $logPath = $this->tempDir.'/.hatfield/logs/agent-'.date('Y-m-d').'.log';
        if (is_file($logPath)) {
            $log = (string) file_get_contents($logPath);
            $this->assertStringContainsString('"key":"llm#0"', $log);
            $this->assertStringContainsString('"key":"llm#1"', $log);
            $this->assertStringContainsString('"key":"llm#2"', $log);
        }
    }

    /**
     * @return list<array{pid:int, cmd:string}>
     */
    private function waitForControllerLlmConsumers(int $controllerPid, int $expected, float $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $last = [];
        while (microtime(true) < $deadline) {
            $last = $this->listControllerLlmConsumers($controllerPid);
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
    private function listControllerLlmConsumers(int $controllerPid): array
    {
        $matched = [];
        foreach ($this->discoverOwnedDescendants($controllerPid) as $pid) {
            $cmdline = (string) @file_get_contents("/proc/{$pid}/cmdline");
            $cmd = str_replace("\0", ' ', $cmdline);
            if (!preg_match('/messenger:consume\s+llm(\s|$)/', $cmd)) {
                continue;
            }
            $matched[] = ['pid' => $pid, 'cmd' => trim($cmd)];
        }

        return $matched;
    }

    /**
     * @param resource $process
     */
    private function trackOwnedProcessTree($process): void
    {
        $status = @proc_get_status($process);
        if (!\is_array($status) || !isset($status['pid'])) {
            return;
        }
        $root = (int) $status['pid'];
        $this->controllerRootPid = $root;
        $this->trackedPids = array_values(array_unique(array_merge(
            [$root],
            $this->discoverOwnedDescendants($root),
        )));
    }

    /**
     * @param resource $process
     */
    private function refreshOwnedProcessTree($process): void
    {
        $status = @proc_get_status($process);
        if (!\is_array($status) || !isset($status['pid'])) {
            return;
        }
        $root = (int) $status['pid'];
        $this->controllerRootPid = $root;
        $this->trackedPids = array_values(array_unique(array_merge(
            $this->trackedPids,
            [$root],
            $this->discoverOwnedDescendants($root),
        )));
    }

    /**
     * Discover descendants of the controller root by /proc ppid walk.
     * Ownership is process-tree based (not HATFIELD_SESSION_ID): messenger
     * consumers inherit $_ENV from ConsumerSupervisor and may omit the session tag.
     *
     * @return list<int>
     */
    private function discoverOwnedDescendants(int $parentPid): array
    {
        $pids = [];
        $childrenPath = "/proc/{$parentPid}/task/{$parentPid}/children";
        if (is_readable($childrenPath)) {
            $content = (string) @file_get_contents($childrenPath);
            foreach (explode(' ', trim($content)) as $token) {
                $childPid = (int) $token;
                if ($childPid <= 1 || !$this->isSameUid($childPid)) {
                    continue;
                }
                $pids[] = $childPid;
                $pids = array_merge($pids, $this->discoverOwnedDescendants($childPid));
            }

            return $pids;
        }

        foreach (scandir('/proc') ?: [] as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $candidate = (int) $entry;
            if ($candidate <= 1 || !$this->isSameUid($candidate)) {
                continue;
            }
            $stat = @file_get_contents("/proc/{$candidate}/stat");
            if (false === $stat || !preg_match('/^\d+ \(.*\) . (\d+)/', $stat, $m)) {
                continue;
            }
            if ((int) $m[1] !== $parentPid) {
                continue;
            }
            $pids[] = $candidate;
            $pids = array_merge($pids, $this->discoverOwnedDescendants($candidate));
        }

        return $pids;
    }

    private function isSameUid(int $pid): bool
    {
        // /proc/<pid>/stat does not carry real UID (fields after comm are state/ppid/pgrp...).
        // Read real UID from /proc/<pid>/status so we never signal root-owned processes.
        $status = @file_get_contents("/proc/{$pid}/status");
        if (false === $status) {
            return false;
        }
        if (!preg_match('/^Uid:\s+(\d+)/m', $status, $m)) {
            return false;
        }

        return (int) $m[1] === posix_getuid();
    }

    private function isAlive(int $pid): bool
    {
        if (!@posix_kill($pid, 0)) {
            return false;
        }
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (false === $stat) {
            return true;
        }
        $closeParen = strrpos($stat, ')');
        if (false === $closeParen) {
            return true;
        }
        $rest = trim(substr($stat, $closeParen + 1));
        $fields = preg_split('/\s+/', $rest) ?: [];

        return 'Z' !== ($fields[0] ?? '');
    }

    private function isStillOwnedTracked(int $pid): bool
    {
        if ($pid <= 1 || !\in_array($pid, $this->trackedPids, true)) {
            return false;
        }
        if (!$this->isSameUid($pid) || !$this->isAlive($pid)) {
            return false;
        }
        // Root controller is always owned when tracked.
        if ($pid === $this->controllerRootPid) {
            return true;
        }
        // Descendant still under our root tree, or reparented but previously tracked
        // and still matching our messenger/controller command line (orphan after root exit).
        $ppid = $this->readPpid($pid);
        if ($ppid === $this->controllerRootPid || \in_array($ppid, $this->trackedPids, true)) {
            return true;
        }
        $cmdline = str_replace("\0", ' ', (string) @file_get_contents("/proc/{$pid}/cmdline"));

        return str_contains($cmdline, 'messenger:consume')
            || str_contains($cmdline, 'agent --controller')
            || str_contains($cmdline, 'agent --controller');
    }

    private function readPpid(int $pid): int
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (false === $stat || !preg_match('/^\d+ \(.*\) . (\d+)/', $stat, $m)) {
            return -1;
        }

        return (int) $m[1];
    }

    private function stopOwnedProcessTree(): void
    {
        if (\is_resource($this->process)) {
            $this->refreshOwnedProcessTree($this->process);
        }

        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];

        foreach ($this->trackedPids as $pid) {
            if ($this->isStillOwnedTracked($pid)) {
                @posix_kill($pid, \SIGTERM);
            }
        }

        $deadline = microtime(true) + 3.0;
        $stillAlive = true;
        while ($stillAlive && microtime(true) < $deadline) {
            $stillAlive = false;
            foreach ($this->trackedPids as $pid) {
                if ($this->isStillOwnedTracked($pid)) {
                    $stillAlive = true;
                    break;
                }
            }
            if ($stillAlive) {
                usleep(50_000);
            }
        }

        foreach ($this->trackedPids as $pid) {
            if ($this->isStillOwnedTracked($pid)) {
                @posix_kill($pid, \SIGKILL);
            }
        }

        if (\is_resource($this->process)) {
            @proc_close($this->process);
        }
        $this->process = null;

        $survivors = [];
        foreach ($this->trackedPids as $pid) {
            if ($this->isStillOwnedTracked($pid)) {
                $survivors[] = $pid;
            }
        }
        $this->trackedPids = [];
        $this->controllerRootPid = 0;
        $this->assertSame(
            [],
            $survivors,
            'No controller-owned descendants may remain after SIGTERM→3s→SIGKILL teardown',
        );
    }
}
