<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Topology-only proof: HeadlessController starts one llm Fiber consumer
 * (hatfield:messenger:consume-llm, key llm#0).
 *
 * No LLM fixture / start_run / message claiming. Concurrent admission is proven
 * by FiberMessageExecutionStrategy / real-handler probes; this case only asserts
 * controller launch topology.
 *
 * Reuses ControllerE2eTestCase process spawn + process-tree teardown
 * (SIGTERM → short grace → SIGKILL). Never signals root-owned processes.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class HeadlessControllerLlmWorkerPoolProcessTest extends ControllerReplayE2eTestCase
{
    protected function tearDown(): void
    {
        $this->stopProcess();
        $this->assertNoTrackedControllerProcessSurvivors();
        if (isset($this->tempDir) && '' !== $this->tempDir) {
            \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
        $this->tempDir = '';
        // Skip parent tearDown (would double-stop and re-delete).
        \PHPUnit\Framework\TestCase::tearDown();
    }

    public function testControllerProcessStartsSingleLlmFiberConsumer(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $rootPid = $this->controllerRootPid();
        $this->assertGreaterThan(0, $rootPid, 'Controller root PID must be known after runtime.ready');
        $this->refreshTrackedControllerPids();

        $llmConsumers = $this->waitForControllerLlmConsumers(1, 10.0);
        $this->assertCount(
            1,
            $llmConsumers,
            'Expected one hatfield:messenger:consume-llm descendant under controller pid '
            .$rootPid.' got: '.json_encode($llmConsumers),
        );

        $logPath = $this->tempDir.'/.hatfield/logs/agent-'.date('Y-m-d').'.log';
        $this->assertFileExists($logPath, 'Controller agent log must exist for launch-key proof');
        $log = (string) file_get_contents($logPath);
        $this->assertStringContainsString('"key":"llm#0"', $log);
        $this->assertStringNotContainsString('"key":"llm#1"', $log);
    }

    protected function tempDirPrefix(): string
    {
        return 'controller-llm-pool';
    }

    /**
     * Topology test does not use replay fixtures (no LLM I/O).
     *
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        return [];
    }

    protected function extraSettingsYaml(): string
    {
        // Keep tool pool tiny so this topology case only asserts the llm consumer.
        return <<<'YAML'
logging:
    # This test reads launch records. Do not inherit the product log threshold.
    level: info
tools:
    execution:
        max_parallelism: 1
YAML;
    }

    protected function controllerExtraArgs(): array
    {
        return [];
    }

    /**
     * @return list<array{pid:int, cmd:string}>
     */
    private function waitForControllerLlmConsumers(int $expected, float $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $last = [];
        while (microtime(true) < $deadline) {
            $this->refreshTrackedControllerPids();
            $last = $this->listControllerLlmConsumers();
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
    private function listControllerLlmConsumers(): array
    {
        $rootPid = $this->controllerRootPid();
        if ($rootPid <= 0) {
            return [];
        }

        $matched = [];
        foreach ($this->discoverControllerProcessTreePids($rootPid) as $pid) {
            $cmdline = (string) @file_get_contents("/proc/{$pid}/cmdline");
            $cmd = str_replace("\0", ' ', $cmdline);
            if (!str_contains($cmd, 'hatfield:messenger:consume-llm')) {
                continue;
            }
            $matched[] = ['pid' => $pid, 'cmd' => trim($cmd)];
        }

        return $matched;
    }
}
