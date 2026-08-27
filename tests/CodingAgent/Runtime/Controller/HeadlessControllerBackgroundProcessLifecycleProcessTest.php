<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Support\ControllerReplayBackgroundProcessSeeder;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;

/**
 * Thesis: the real controller dispatches accepted background-process cleanup
 * before runtime.ready at startup and before its owned process exits on EOF.
 *
 * This is intentionally a controller-process proof rather than listener-only
 * dispatch: each seed uses the same isolated app/transport SQLite pair and
 * .hatfield tree as the source controller subprocess.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class HeadlessControllerBackgroundProcessLifecycleProcessTest extends ControllerReplayE2eTestCase
{
    protected function tearDown(): void
    {
        $this->stopProcess();
        $this->assertNoTrackedControllerProcessSurvivors();
        if (isset($this->tempDir) && '' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
        $this->tempDir = '';
        \PHPUnit\Framework\TestCase::tearDown();
    }

    public function testControllerCleansAcceptedStateAtStartupAndShutdown(): void
    {
        $startup = $this->seedAcceptedFinished('startup');

        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->assertSeedRemoved($startup);

        $shutdown = $this->seedAcceptedFinished('shutdown');
        $this->signalOwnedControllerAndWaitForExit();
        $this->assertSeedRemoved($shutdown);
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
        return 'controller-background-process-lifecycle';
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

    /**
     * @return array{id: int, log: string, status: string, pid: string}
     */
    private function seedAcceptedFinished(string $prefix): array
    {
        return ControllerReplayBackgroundProcessSeeder::seedAcceptedFinished(
            $this->tempDir,
            $this->appDatabaseFilename(),
            $this->messengerTransportDatabaseFilename(),
            $this->sessionId,
            $prefix,
        );
    }

    /**
     * @param array{id: int, log: string, status: string, pid: string} $seed
     */
    private function assertSeedRemoved(array $seed): void
    {
        $this->assertFalse(
            ControllerReplayBackgroundProcessSeeder::recordExists(
                $this->tempDir,
                $this->appDatabaseFilename(),
                $this->messengerTransportDatabaseFilename(),
                $seed['id'],
            ),
        );
        $this->assertFileDoesNotExist($seed['log']);
        $this->assertFileDoesNotExist($seed['status']);
        $this->assertFileDoesNotExist($seed['pid']);
    }

    private function signalOwnedControllerAndWaitForExit(): void
    {
        $rootPid = $this->controllerRootPid();
        $this->assertGreaterThan(1, $rootPid, 'Owned controller PID is unavailable.');
        $this->assertTrue(posix_kill($rootPid, \SIGTERM), 'Unable to signal the owned controller root.');

        $deadline = microtime(true) + 5.0;
        while ($this->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $this->assertFalse(
            $this->isRunning(),
            'Controller did not exit after SIGTERM. '.$this->collectDiagnostics([]),
        );
    }

    private function appDatabaseFilename(): string
    {
        return 'app_test-replay-'.$this->sessionId.'.sqlite';
    }

    private function messengerTransportDatabaseFilename(): string
    {
        return 'messenger_transport_test-replay-'.$this->sessionId.'.sqlite';
    }
}
