<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/**
 * Cold-controller stop protocol only.
 *
 * Same-process detach/completion/grace journeys were deleted: native ready/PID
 * markers precede async owner registration, so cold foregroundBash queries race
 * the durable row; parent O_RDWR on the release FIFO also competed with the child
 * reader. Retained lower-layer proofs are listed in
 * var/reports/test-cleanup-20261029/background-lifecycle-inventory.md.
 */
#[Group('controller-replay')]
final class ControllerReplayBackgroundBashTest extends ControllerReplayE2eTestCase
{
    private string $databasePath;

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            $path = $this->projectDir.'/var/test/'.$this->databasePath.$suffix;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testStopCommandReturnsCorrelatedUnavailableResultWithoutLaunchingWork(): void
    {
        $this->sessionId = trim($this->queryRuntime()->getOutput());
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->writeCommand(['v' => 1, 'id' => 'stop-missing', 'type' => 'stop_bash', 'runId' => $this->sessionId,
            'payload' => ['record_id' => 2147483647]]);
        $events = $this->collectEventsUntil('bg_process.stop.completed', 5);
        $result = $this->indexByType($events)['bg_process.stop.completed'][0] ?? null;
        $this->assertNotNull($result, $this->collectDiagnostics($events));
        $this->assertSame($this->sessionId, $result['runId']);
        $this->assertSame(['commandId' => 'stop-missing', 'available' => false, 'stopped' => false], $result['payload']);
        $this->assertFileDoesNotExist($this->tempDir.'/requests.jsonl');
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-background-bash';
    }

    protected function replayFixtures(): array
    {
        $this->databasePath = 'app_test-replay-'.$this->sessionId.'.sqlite';

        return [];
    }

    protected function replayExtraEnv(): array
    {
        return ['HATFIELD_TEST_DATABASE_PATH' => $this->databasePath];
    }

    private function queryRuntime(): Process
    {
        $query = new Process([\PHP_BINARY, __DIR__.'/Support/ForegroundBashCandidates.php', $this->projectDir], $this->tempDir, array_merge($this->controllerSubprocessEnv(), [
            'APP_ENV' => 'test', 'APP_DEBUG' => '1', 'HATFIELD_CWD' => $this->tempDir, 'HOME' => $this->tempDir,
            'HATFIELD_SESSION_ID' => false, 'HATFIELD_TEST_DATABASE_PATH' => $this->databasePath,
        ]));
        $query->setTimeout(3);
        $query->mustRun();

        return $query;
    }
}
