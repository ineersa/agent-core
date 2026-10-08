<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Process regression: a fresh actual run_control Messenger worker recovers an
 * unfinished captured transition when no invocation is armed and no new user
 * command exists. Lower layers call subscriber callbacks directly; this case
 * drives the configured Worker + dispatcher startup path instead.
 *
 * Unique external contract: WorkerStartedEvent on a real receiver/dispatcher
 * process. Timeout is only a safety cap; natural stop owns completion.
 */
#[Group('process')]
final class ExecutionPendingDeliveryIdleOwnerRestartTest extends TestCase
{
    public function testFreshRunControlWorkerRecoversUnarmedPendingIntentWithoutUserCommand(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('idle-owner-pending');
        TestDirectoryIsolation::createHatfieldTree($directory, withSessions: true);
        $dbDirectory = '../tmp/'.basename($directory);
        $marker = $directory.'/recovery.json';
        $process = new Process([
            \PHP_BINARY,
            __DIR__.'/Support/IdleOwnerPendingTransitionWorker.php',
            $directory,
            $dbDirectory.'/state.sqlite',
            $marker,
        ], ProjectDir::get(), [
            'HATFIELD_SESSION_ID' => false,
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
        ], timeout: 8.0);

        try {
            $process->mustRun();
            $this->assertFileExists($marker.'.before');
            $this->assertFileExists($marker.'.started');
            $this->assertFileExists($marker);
            $this->assertSame("worker_started\n", file_get_contents($marker.'.started'));
            $before = json_decode((string) file_get_contents($marker.'.before'), true, flags: \JSON_THROW_ON_ERROR);
            $after = json_decode((string) file_get_contents($marker), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertFalse($before['armed']);
            $this->assertFalse($before['source_accepted']);
            $this->assertSame($before['run_id'], $after['run_id']);
            $this->assertSame($before['pending_identity'], $after['pending_identity']);
            $this->assertTrue($after['ok']);
            $this->assertTrue($after['worker_started']);
            $this->assertNotSame('', $after['effect_id']);
            $this->assertNotSame('', $after['request_hash']);
            $this->assertGreaterThanOrEqual($before['cut'], $after['cut']);
            $this->assertSame(0, $process->getExitCode());
            $this->assertFalse($process->isRunning(), 'Owned worker must exit naturally after the startup stop marker.');
        } finally {
            if ($process->isRunning()) {
                // EOF/natural stop only; never signal tagged or root workers.
                $process->wait();
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
