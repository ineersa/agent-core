<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueEnv;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use Ineersa\CodingAgent\Tests\Support\OwnedProcessTree;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\SqliteQueue\Client;
use Ineersa\SqliteQueue\Messenger\BrokerConnection;
use Ineersa\SqliteQueue\Messenger\Transport;
use Ineersa\SqliteQueue\ValueObject\QueueName;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Process proof: hatfield:messenger:consume-llm renews sqlite-queue leases with
 * a Revolt EventLoop timer while Amp HTTP handlers are suspended in Fibers.
 *
 * Unique external contract: production LlmFiberConsumeCommand keepalive path +
 * real broker KeepaliveReceiver under Amp/UvDriver yield. Transport unit tests
 * and Fiber strategy probes alone do not cover that command path. Timeout is a
 * safety cap around owned fixture processes.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeCommand
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeWaitSubscriber
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy
 */
#[Group('process')]
final class LlmFiberConsumeKeepaliveProcessTest extends TestCase
{
    private const string FIXTURE_SERVER = '/home/ineersa/projects/agent-core-tasks/reports/memory-07-feasibility/same-process/completions-delay-server.php';
    private const int DELAY_US = 3_500_000;
    private const int REDELIVER_SECONDS = 2;

    private string $tmpDir = '';

    /** @var list<Process> */
    private array $owned = [];

    /** @var list<InputStream> */
    private array $brokerInputs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->owned) as $process) {
            $this->stopOwned($process);
        }
        $this->owned = [];
        foreach ($this->brokerInputs as $input) {
            $input->close();
        }
        $this->brokerInputs = [];
        if ('' !== $this->tmpDir) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
            $this->tmpDir = '';
        }
    }

    public function testConsumeLlmKeepaliveRenewsLeaseDuringAmpHttpOverlap(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('llm-fiber-keepalive');
        TestDirectoryIsolation::createHatfieldTree($this->tmpDir, withSessions: true);

        $hitFile = $this->tmpDir.'/hits.txt';
        $claimFile = $this->tmpDir.'/claims.txt';
        $resultFile = $this->tmpDir.'/result.json';
        file_put_contents($hitFile, '0');
        file_put_contents($claimFile, '0');

        $fixture = $this->startFixture($hitFile.'.fixture');
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'keepalive-llm');

        $publisher = null;
        $watcher = null;
        $worker = null;
        try {
            $this->startShortLeaseBroker($paths);

            $connect = static fn (): Client => Client::connect($paths->endpointPath);
            $serializer = new PhpSerializer();
            $publisher = new Transport(
                new BrokerConnection($connect),
                new QueueName('llm'),
                $serializer,
                new BrokerConnection($connect),
                $paths->endpointPath,
            );
            $watcher = Client::connect($paths->endpointPath);

            $publisher->send(new Envelope((object) ['id' => 'A']));
            $publisher->send(new Envelope((object) ['id' => 'B']));

            $worker = new Process([
                \PHP_BINARY,
                __DIR__.'/Support/LlmFiberKeepaliveAmpWorker.php',
                $paths->endpointPath,
                $fixture['base'],
                $hitFile,
                $resultFile,
                $claimFile,
            ], dirname(__DIR__, 4), array_merge(
                array_filter(getenv() ?: [], static fn (mixed $v): bool => false !== $v),
                [
                    'APP_ENV' => 'test',
                    'APP_DEBUG' => '0',
                    'HATFIELD_SESSION_ID' => '',
                ],
            ), timeout: null);
            $worker->start();
            $this->owned[] = $worker;

            // Wait until both Fibers have claimed deliveries and started Amp HTTP.
            $claimDeadline = microtime(true) + 5.0;
            while (microtime(true) < $claimDeadline) {
                if (!$worker->isRunning()) {
                    break;
                }
                if (2 <= (int) @file_get_contents($claimFile)) {
                    break;
                }
                usleep(20_000);
            }
            $this->assertSame(
                2,
                (int) @file_get_contents($claimFile),
                'Both overlapping handlers must claim before lease-expiry observation. worker_out='
                .$worker->getOutput().' worker_err='.$worker->getErrorOutput()
                .' boot='.(string) @file_get_contents($resultFile.'.boot')
                .' log='.(string) @file_get_contents($resultFile.'.log'),
            );

            // Observe across more than one redeliver interval while Amp HTTP is in flight.
            $observeDeadline = microtime(true) + 2.5;
            while (microtime(true) < $observeDeadline) {
                $stolen = $watcher->receive('llm');
                if (null !== $stolen) {
                    $this->fail(
                        'Active Fiber claims must stay reserved past the 2s redeliver timeout while Amp HTTP is in flight.'
                        .' claims='.(string) @file_get_contents($claimFile)
                        .' hits='.(string) @file_get_contents($hitFile)
                        .' worker_running='.($worker->isRunning() ? 'yes' : 'no')
                        .' worker_out='.$worker->getOutput()
                        .' worker_err='.$worker->getErrorOutput()
                        .' result='.(string) @file_get_contents($resultFile)
                        .' log='.(string) @file_get_contents($resultFile.'.log'),
                    );
                }
                usleep(50_000);
            }

            $finishDeadline = microtime(true) + 5.0;
            while (microtime(true) < $finishDeadline) {
                if (!$worker->isRunning() && 2 === (int) @file_get_contents($hitFile)) {
                    break;
                }
                if (2 === (int) @file_get_contents($hitFile) && !$worker->isRunning()) {
                    break;
                }
                if (2 === (int) @file_get_contents($hitFile) && $worker->isRunning()) {
                    // Message-limit stop may still be settling.
                    usleep(20_000);
                    continue;
                }
                usleep(50_000);
            }

            if (2 !== (int) @file_get_contents($hitFile)) {
                $this->fail(
                    'Both overlapping Amp handlers must complete. hits='.(string) @file_get_contents($hitFile)
                    .' claims='.(string) @file_get_contents($claimFile)
                    .' worker_out='.$worker->getOutput()
                    .' worker_err='.$worker->getErrorOutput()
                    .' result='.(string) @file_get_contents($resultFile)
                    .' log='.(string) @file_get_contents($resultFile.'.log'),
                );
            }

            if ($worker->isRunning()) {
                $pid = $worker->getPid();
                $this->assertNotNull($pid);
                $this->assertTrue(0 !== posix_geteuid());
                posix_kill($pid, \SIGTERM);
                $worker->setTimeout(5.0);
                $worker->wait();
            }

            $this->assertNull($watcher->receive('llm'), 'Completed claims must leave the queue empty.');
            $this->assertFileExists($resultFile);
            $payload = json_decode((string) file_get_contents($resultFile), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertIsArray($payload);
            $this->assertSame(0, $payload['exit']);
            $this->assertTrue($payload['uv']);
            $this->assertSame(\Revolt\EventLoop\Driver\UvDriver::class, $payload['driver']);
            $this->assertNotEmpty($payload['keepalive_armed'], 'Revolt keepalive timer must be armed.');
            $this->assertGreaterThanOrEqual(
                1,
                \count($payload['keepalive_signals']),
                'Revolt EventLoop::repeat must renew Worker leases during Amp yield.',
            );
            $ids = array_column($payload['handled'], 'id');
            sort($ids);
            $this->assertSame(['A', 'B'], $ids);
            $starts = array_column($payload['handled'], 'started_at');
            $finishes = array_column($payload['handled'], 'finished_at');
            $this->assertLessThan(1.0, abs(min($starts) - max($starts)), 'Handlers must start together under Fiber admission.');
            $this->assertLessThan(5.5, max($finishes) - min($starts), 'Two 3.5s Amp requests must overlap instead of serializing.');
        } finally {
            $publisher?->close();
            $watcher?->close();
            if (null !== $worker) {
                $this->stopOwned($worker);
            }
            $this->stopOwned($fixture['process']);
        }
    }

    private function startShortLeaseBroker(SessionSqliteQueuePaths $paths): void
    {
        $databaseDir = dirname($paths->databasePath);
        if (!is_dir($databaseDir)) {
            mkdir($databaseDir, 0o700, true);
        }
        if (!is_dir($paths->endpointDirectory())) {
            mkdir($paths->endpointDirectory(), 0o700, true);
        }
        $this->assertFileDoesNotExist($paths->endpointPath);

        $process = new Process([
            \PHP_BINARY,
            dirname(__DIR__, 4).'/bin/console',
            'sqlite-queue:broker',
            '--database='.$paths->databasePath,
            '--endpoint='.$paths->endpointPath,
            '--redeliver-timeout='.self::REDELIVER_SECONDS,
            '--max-connections=16',
            '--no-ansi',
            '--no-interaction',
        ], $this->tmpDir, array_merge(
            array_filter(getenv() ?: [], static fn (mixed $v): bool => false !== $v),
            [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '0',
                ...SessionSqliteQueueEnv::processEnv($paths),
            ],
            // Explicitly clear session tagging so OwnedProcessTree can signal this broker.
            ['HATFIELD_SESSION_ID' => ''],
        ), timeout: null);
        $input = new InputStream();
        $process->setInput($input);
        $process->start();
        $this->owned[] = $process;
        $this->brokerInputs[] = $input;

        $deadline = microtime(true) + 10.0;
        $ready = false;
        $carry = '';
        while (microtime(true) < $deadline) {
            if (!$process->isRunning()) {
                break;
            }
            $carry .= $process->getIncrementalOutput();
            foreach (preg_split("/\n/", $carry) ?: [] as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                try {
                    $event = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (\is_array($event) && ($event['event'] ?? null) === 'ready') {
                    $ready = true;
                    break 2;
                }
            }
            usleep(20_000);
        }

        $this->assertTrue(
            $process->isRunning(),
            'Short-lease broker must stay alive. stdout='.$process->getOutput().' stderr='.$process->getErrorOutput(),
        );
        $this->assertTrue($ready, 'Short-lease broker must emit ready JSON. stdout='.$process->getOutput().' stderr='.$process->getErrorOutput());
        $this->assertFileExists($paths->endpointPath);
    }

    /**
     * @return array{process: Process, base: string}
     */
    private function startFixture(string $hitFile): array
    {
        $process = new Process(
            [\PHP_BINARY, self::FIXTURE_SERVER],
            null,
            [
                'M07_HITFILE' => $hitFile,
                'M07_DELAY_US' => (string) self::DELAY_US,
            ],
            timeout: null,
        );
        $process->start();
        $this->owned[] = $process;

        $deadline = microtime(true) + 5.0;
        $port = '';
        while (microtime(true) < $deadline) {
            $port = trim($process->getOutput());
            if ('' !== $port && ctype_digit($port)) {
                break;
            }
            usleep(20_000);
        }
        $this->assertTrue($process->isRunning(), 'Fixture server must stay alive: '.$process->getErrorOutput());
        $this->assertNotSame('', $port, 'Fixture server must publish its port.');

        return [
            'process' => $process,
            'base' => 'http://127.0.0.1:'.$port,
        ];
    }

    private function stopOwned(Process $process): void
    {
        if (!$process->isRunning()) {
            return;
        }
        $pid = $process->getPid();
        if (null !== $pid && $pid > 1) {
            foreach (OwnedProcessTree::descendants($pid) as $row) {
                if (OwnedProcessTree::canSignal($row)) {
                    posix_kill($row['pid'], \SIGTERM);
                }
            }
            $identity = [
                'pid' => $pid,
                'start' => '',
                'cmdline' => str_replace("\0", ' ', (string) @file_get_contents('/proc/'.$pid.'/cmdline')),
                'exe' => (string) @readlink('/proc/'.$pid.'/exe'),
            ];
            $stat = @file_get_contents('/proc/'.$pid.'/stat');
            if (false !== $stat && false !== ($end = strrpos($stat, ')'))) {
                $fields = explode(' ', trim(substr($stat, $end + 1)));
                $identity['start'] = $fields[19] ?? '';
            }
            if (OwnedProcessTree::canSignal($identity)) {
                posix_kill($pid, \SIGTERM);
            }
        }
        $deadline = microtime(true) + 3.0;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if ($process->isRunning()) {
            $process->stop(0.2, \SIGKILL);
        }
    }
}
