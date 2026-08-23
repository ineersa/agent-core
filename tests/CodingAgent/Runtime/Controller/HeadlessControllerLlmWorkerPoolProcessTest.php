<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Topology-only proof: HeadlessController starts the configured fixed llm
 * messenger consumer pool (messenger:consume llm × N, keys llm#0..N-1).
 *
 * No LLM fixture / start_run / message claiming. Distinct-message concurrent
 * claiming is trusted Symfony Messenger/Doctrine transport behavior.
 *
 * Reuses ControllerE2eTestCase process spawn + process-tree teardown
 * (SIGTERM → short grace → SIGKILL). Never signals root-owned processes.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class HeadlessControllerLlmWorkerPoolProcessTest extends ControllerReplayE2eTestCase
{
    private const int EXPECTED_LLM_WORKERS = 3;

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

    public function testControllerProcessStartsConfiguredLlmConsumerPool(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $rootPid = $this->controllerRootPid();
        $this->assertGreaterThan(0, $rootPid, 'Controller root PID must be known after runtime.ready');
        $this->refreshTrackedControllerPids();

        $llmConsumers = $this->waitForControllerLlmConsumers(self::EXPECTED_LLM_WORKERS, 10.0);
        $this->assertCount(
            self::EXPECTED_LLM_WORKERS,
            $llmConsumers,
            'Expected '.self::EXPECTED_LLM_WORKERS.' messenger:consume llm descendants under controller pid '
            .$rootPid.' got: '.json_encode($llmConsumers),
        );

        $logPath = $this->tempDir.'/.hatfield/logs/agent-'.date('Y-m-d').'.log';
        $this->assertFileExists($logPath, 'Controller agent log must exist for launch-key proof');
        $log = (string) file_get_contents($logPath);
        $this->assertStringContainsString('"key":"llm#0"', $log);
        $this->assertStringContainsString('"key":"llm#1"', $log);
        $this->assertStringContainsString('"key":"llm#2"', $log);
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

    protected function isolatedLlmWorkerCount(): int
    {
        return self::EXPECTED_LLM_WORKERS;
    }

    protected function extraSettingsYaml(): string
    {
        // Keep tool pool tiny so this topology case only asserts llm workers.
        return <<<'YAML'
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
            if (!preg_match('/messenger:consume\s+llm(\s|$)/', $cmd)) {
                continue;
            }
            $matched[] = ['pid' => $pid, 'cmd' => trim($cmd)];
        }

        return $matched;
    }
}
