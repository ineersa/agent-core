<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\BackgroundProcess;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

#[Group('process')]
final class BashWorkerLifecycleSubscriberTest extends TestCase
{
    #[TestWith(['accepted'])]
    #[TestWith(['transport'])]
    #[TestWith(['lost'])]
    #[TestWith(['foreground'])]
    #[TestWith(['session-stop'])]
    #[TestWith(['registration-failure'], 'registration-failure')]
    #[TestWith(['maintenance'], 'maintenance')]
    #[TestWith(['maintenance-race'], 'maintenance-race')]
    public function testProductionShutdownOwnershipAndCompletion(string $scenario): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('bash-ownership-worker');
        TestDirectoryIsolation::createHatfieldTree($directory, withSessions: true);
        $database = Path::makeRelative($directory, ProjectDir::get().'/var/test').'/state.sqlite';
        $marker = $directory.'/result.json';
        // Separate real kernels and a natural worker process exit are the
        // contract here. The cap bounds failure; sockets provide synchronization.
        $process = new Process([\PHP_BINARY, __DIR__.'/Support/BashOwnershipWorker.php', 'coordinator', $directory, $database, $marker, $scenario], ProjectDir::get(), ['HATFIELD_SESSION_ID' => false], timeout: 10);
        try {
            $process->mustRun();
            $result = json_decode((string) file_get_contents($marker), true, flags: \JSON_THROW_ON_ERROR);
            if (\in_array($scenario, ['maintenance', 'maintenance-race'], true)) {
                $this->assertTrue($result['survived']);

                return;
            }
            if ('session-stop' === $scenario) {
                $this->assertTrue($result['removed']);

                return;
            }
            if ('foreground' === $scenario) {
                $this->assertSame(0, $result['notifications']);
                $this->assertFalse($result['user_stop']);
                $this->assertFalse($result['notified']);

                return;
            }
            $this->assertSame(1, $result['notifications']);
            $this->assertSame(0, $result['exit']);
            $this->assertFalse($result['user_stop']);
            $this->assertTrue($result['notified']);
            $this->assertStringContainsString('BACKGROUND_SURVIVED', $result['text']);
        } finally {
            if ($process->isRunning()) {
                $process->wait();
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
