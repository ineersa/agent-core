<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Support\ControllerReplayBackgroundProcessSeeder;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;

/**
 * Thesis: ordinary controller startup and shutdown retain completed native
 * execution evidence needed for same-intent replay.
 *
 * This is intentionally a controller-process proof rather than listener-only
 * dispatch: each seed uses the same isolated app SQLite database and
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
        if (isset($this->tempDir) && '' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
        $this->tempDir = '';
        \PHPUnit\Framework\TestCase::tearDown();
    }

    public function testControllerRetainsAcceptedEvidenceAtStartupAndShutdown(): void
    {
        $startup = $this->seedAcceptedFinished('startup');

        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->assertSeedRetained($startup);

        $shutdown = $this->seedAcceptedFinished('shutdown');
        $this->signalOwnedControllerAndWaitForExit();
        $this->assertSeedRetained($shutdown);
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

    /**
     * @return array{id: int, log: string, status: string, pid: string}
     */
    private function seedAcceptedFinished(string $prefix): array
    {
        return ControllerReplayBackgroundProcessSeeder::seedAcceptedFinished(
            $this->tempDir,
            $this->appDatabaseFilename(),
            $this->sessionId,
            $prefix,
        );
    }

    /**
     * @param array{id: int, log: string, status: string, pid: string} $seed
     */
    private function assertSeedRetained(array $seed): void
    {
        $this->assertTrue(
            ControllerReplayBackgroundProcessSeeder::recordExists(
                $this->tempDir,
                $this->appDatabaseFilename(),
                $seed['id'],
            ),
        );
        $this->assertFileExists($seed['log']);
        $this->assertFileExists($seed['status']);
        $this->assertFileExists($seed['pid']);
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
}
