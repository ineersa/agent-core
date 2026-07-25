<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis B: a real HeadlessController process launches the configured fixed
 * llm messenger consumer pool (llm#0..N-1) without reimplementing launch math.
 */
final class HeadlessControllerLlmWorkerPoolProcessTest extends TestCase
{
    public function testControllerProcessStartsConfiguredLlmConsumerPool(): void
    {
        $sessionId = 'llm-pool-'.substr(bin2hex(random_bytes(8)), 0, 8);
        $tempDir = TestDirectoryIsolation::createProjectTempDir('controller-llm-pool');
        $process = null;
        $pipes = [];

        try {
            TestDirectoryIsolation::createHatfieldTree($tempDir, withSessions: true);
            file_put_contents($tempDir.'/.hatfield/settings.yaml', <<<'YAML'
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
                'HATFIELD_SESSION_ID' => $sessionId,
                'HATFIELD_TEST_DATABASE_PATH' => 'app_test-llm-pool-'.$sessionId.'.sqlite',
                'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => 'messenger_transport_test-llm-pool-'.$sessionId.'.sqlite',
                'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=run_control_{$sessionId}",
                'HATFIELD_LLM_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=llm_{$sessionId}",
                'HATFIELD_TOOL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=tool_{$sessionId}",
                'HATFIELD_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=agent_{$sessionId}",
                'HATFIELD_MCP_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=mcp_{$sessionId}",
                'HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=extension_agent_{$sessionId}",
            ];
            // Preserve PATH so php/bin lookups still work when replacing the process env.
            $env['PATH'] = (string) (getenv('PATH') ?: '/usr/bin:/bin');
            $env['HOME'] = (string) (getenv('HOME') ?: '/tmp');

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = @proc_open(
                [$php, $script, 'agent', '--controller', '--cwd='.$tempDir],
                $descriptors,
                $pipes,
                $tempDir,
                $env,
            );
            $this->assertIsResource($process, 'Failed to spawn controller process');

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $deadline = microtime(true) + 15.0;
            $ready = false;
            while (microtime(true) < $deadline) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                if (str_contains($stdout, 'runtime.ready') || str_contains($stdout, '"type":"runtime.ready"')) {
                    $ready = true;
                    break;
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $stderr = (string) stream_get_contents($pipes[2]);
                    $this->fail("Controller exited before runtime.ready. stdout={$stdout} stderr={$stderr}");
                }
                usleep(20_000);
            }
            $this->assertTrue($ready, 'Controller must emit runtime.ready. stdout='.$stdout);

            $status = proc_get_status($process);
            $controllerPid = (int) ($status['pid'] ?? 0);
            $this->assertGreaterThan(0, $controllerPid);

            $llmConsumers = $this->waitForControllerLlmConsumers($controllerPid, expected: 3, timeoutSeconds: 10.0);
            $this->assertCount(
                3,
                $llmConsumers,
                'Expected three messenger:consume llm children under controller pid '.$controllerPid.' got: '.json_encode($llmConsumers),
            );

            // Structured log must record llm#0..2 for the configured pool.
            $logPath = $tempDir.'/.hatfield/logs/agent-'.date('Y-m-d').'.log';
            if (is_file($logPath)) {
                $log = (string) file_get_contents($logPath);
                $this->assertStringContainsString('"key":"llm#0"', $log);
                $this->assertStringContainsString('"key":"llm#1"', $log);
                $this->assertStringContainsString('"key":"llm#2"', $log);
            }
        } finally {
            if (\is_resource($process ?? null)) {
                if (isset($pipes[0]) && \is_resource($pipes[0])) {
                    fclose($pipes[0]);
                }
                $deadline = microtime(true) + 5.0;
                while (microtime(true) < $deadline) {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(50_000);
                }
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, \SIGTERM);
                    usleep(200_000);
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        proc_terminate($process, \SIGKILL);
                    }
                }
                foreach ($pipes as $pipe) {
                    if (\is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            TestDirectoryIsolation::removeDirectory($tempDir);
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
        foreach (scandir('/proc') ?: [] as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $pid = (int) $entry;
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if (false === $stat) {
                continue;
            }
            // /proc/pid/stat: pid (comm) state ppid ...
            if (!preg_match('/^\d+ \(.*\) . (\d+)/', $stat, $m)) {
                continue;
            }
            $ppid = (int) $m[1];
            if ($ppid !== $controllerPid) {
                continue;
            }
            $cmdline = (string) @file_get_contents("/proc/{$pid}/cmdline");
            $cmd = str_replace("\0", ' ', $cmdline);
            if (!preg_match('/messenger:consume\s+llm(\s|$)/', $cmd)) {
                continue;
            }
            $matched[] = ['pid' => $pid, 'cmd' => trim($cmd)];
        }

        return $matched;
    }
}
