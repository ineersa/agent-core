<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerOwnerSubscriber;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueEnv;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerOwnerSubscriber
 */
final class SessionSqliteQueueBrokerOwnerSubscriberTest extends TestCase
{
    /** @var array<int, string> */
    private static array $probeDirs = [];

    protected function tearDown(): void
    {
        foreach (self::$probeDirs as $dir) {
            TestDirectoryIsolation::removeDirectory($dir);
        }
        self::$probeDirs = [];
        parent::tearDown();
    }

    public function testSupervisedBrokerIsolatesAndExitsOnOwnerEof(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR || !\function_exists('posix_setsid') || !\function_exists('posix_getpgid')) {
            $this->markTestSkipped('posix process-group isolation is unavailable on this host');
        }

        $process = $this->startOwnerProbe(supervised: true, owner: $owner = new InputStream());
        try {
            $payload = $this->awaitIsolationPayload($process);
            $parentPgid = posix_getpgid(getmypid());
            $this->assertNotFalse($parentPgid);
            $this->assertTrue($payload['isolated']);
            $this->assertSame($payload['pid'], $payload['pgid']);
            $this->assertNotSame($parentPgid, $payload['pgid']);
            $this->assertFalse($this->processCommandLineContains($payload['pid'], 'setsid'));

            $owner->close();
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline && $process->isRunning()) {
                usleep(20_000);
            }
            $this->assertFalse($process->isRunning(), 'supervised owner stdin EOF must terminate the broker');
            $this->assertSame(0, $process->getExitCode());
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public function testStandaloneBrokerSurvivesStdinEof(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR || !\function_exists('posix_setsid') || !\function_exists('posix_getpgid')) {
            $this->markTestSkipped('posix process-group isolation is unavailable on this host');
        }

        $process = $this->startOwnerProbe(supervised: false, owner: $owner = new InputStream());
        try {
            $payload = $this->awaitIsolationPayload($process);
            $this->assertTrue($payload['isolated']);

            $owner->close();
            $carry = '';
            $heartbeat = $process->waitUntil(static function (string $type, string $data) use (&$carry): bool {
                if (Process::OUT !== $type) {
                    return false;
                }
                $carry .= $data;

                return str_contains($carry, '"heartbeat":true');
            });
            $this->assertTrue($heartbeat, 'standalone broker must keep running and emit a post-EOF heartbeat');
            $this->assertTrue($process->isRunning(), 'standalone sqlite-queue:broker must keep vendor stdin behavior');
        } finally {
            if ($process->isRunning()) {
                $process->signal(\SIGTERM);
                $deadline = microtime(true) + 2.0;
                while (microtime(true) < $deadline && $process->isRunning()) {
                    usleep(20_000);
                }
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }

    public function testNonBrokerCommandsAreIgnored(): void
    {
        $subscriber = new SessionSqliteQueueBrokerOwnerSubscriber();
        $command = new class extends Command {
            public function __construct()
            {
                parent::__construct('about');
            }
        };
        $before = \function_exists('posix_getpgid') ? posix_getpgid(getmypid()) : null;
        $subscriber->onConsoleCommand(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()));
        if (null !== $before) {
            $this->assertSame($before, posix_getpgid(getmypid()));
        }
        $this->addToAssertionCount(1);
    }

    private function startOwnerProbe(bool $supervised, InputStream $owner): Process
    {
        $projectRoot = \dirname(__DIR__, 4);
        $tmp = TestDirectoryIsolation::createProjectTempDir($supervised ? 'broker-owner-supervised' : 'broker-owner-standalone');
        $script = $tmp.'/owner-subscriber-probe.php';
        $autoload = var_export($projectRoot.'/vendor/autoload.php', true);
        file_put_contents($script, <<<PHP
<?php

require {$autoload};

use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerOwnerSubscriber;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

\$parentPgid = (int) getenv('HATFIELD_PROBE_PARENT_PGID');
\$subscriber = new SessionSqliteQueueBrokerOwnerSubscriber();
\$command = new class extends Command {
    public function __construct()
    {
        parent::__construct('sqlite-queue:broker');
    }
};
\$subscriber->onConsoleCommand(new ConsoleCommandEvent(\$command, new ArrayInput([]), new NullOutput()));
\$pid = getmypid();
\$pgid = posix_getpgid(\$pid);
fwrite(STDOUT, json_encode([
    'pid' => \$pid,
    'pgid' => \$pgid,
    'parent_pgid' => \$parentPgid,
    'isolated' => \$pgid === \$pid && \$pgid !== \$parentPgid,
], JSON_THROW_ON_ERROR)."\\n");
fflush(STDOUT);
EventLoop::delay(5.0, static function (): void {
    fwrite(STDERR, "timeout\\n");
    exit(3);
});
EventLoop::repeat(0.05, static function (): void {
    fwrite(STDOUT, json_encode(['heartbeat' => true], JSON_THROW_ON_ERROR)."\\n");
    fflush(STDOUT);
});
EventLoop::onSignal(SIGTERM, static function (): void {
    exit(0);
});
EventLoop::run();
PHP);

        $parentPgid = posix_getpgid(getmypid());
        $this->assertNotFalse($parentPgid);
        $currentEnv = getenv();
        $env = array_merge(\is_array($currentEnv) ? $currentEnv : [], [
            'HATFIELD_PROBE_PARENT_PGID' => (string) $parentPgid,
            'HATFIELD_PROBE_TMP' => $tmp,
        ]);
        unset($env[SessionSqliteQueueEnv::DATABASE_PATH], $env[SessionSqliteQueueEnv::ENDPOINT_PATH]);
        if ($supervised) {
            $env[SessionSqliteQueueEnv::DATABASE_PATH] = $tmp.'/messenger-queue.sqlite';
            $env[SessionSqliteQueueEnv::ENDPOINT_PATH] = $tmp.'/q.sock';
        }

        $process = new Process(
            [\PHP_BINARY, $script],
            cwd: $projectRoot,
            env: $env,
            input: $owner,
            timeout: null,
        );
        $process->start();

        // Bind cleanup of the probe temp dir to process lifetime via a shutdown hook
        // in tearDown-equivalent: register on the Process object through a static map.
        self::$probeDirs[spl_object_id($process)] = $tmp;

        return $process;
    }

    /**
     * @return array{pid:int,pgid:int,parent_pgid:int,isolated:bool}
     */
    private function awaitIsolationPayload(Process $process): array
    {
        $carry = '';
        $ready = $process->waitUntil(static function (string $type, string $data) use (&$carry): bool {
            if (Process::OUT !== $type) {
                return false;
            }
            $carry .= $data;

            return str_contains($carry, "\n");
        });
        $this->assertTrue($ready, 'owner probe must emit isolation payload');
        $line = trim(strstr($carry, "\n", true) ?: $carry);

        /** @var array{pid:int,pgid:int,parent_pgid:int,isolated:bool} $payload */
        $payload = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function processCommandLineContains(int $pid, string $needle): bool
    {
        $cmdline = @file_get_contents('/proc/'.$pid.'/cmdline');
        if (false === $cmdline) {
            return false;
        }

        return str_contains(str_replace("\0", ' ', $cmdline), $needle);
    }
}
