<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Logging\SessionResourceSampleMessage;
use Ineersa\CodingAgent\Logging\SessionResourceSamplerTask;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

#[CoversClass(SessionResourceSamplerTask::class)]
#[CoversClass(SessionResourceSampleMessage::class)]
final class SessionResourceSamplerTaskTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('session-resource-sampler');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testDisabledScheduleContainsNoSamplerJob(): void
    {
        $task = $this->task(enabled: false, sessionId: '11111111-1111-4111-8111-111111111111');

        $this->assertSame([], $task->getSchedule()->getRecurringMessages());
    }

    public function testEnabledScheduleRegistersOncePerMinuteSample(): void
    {
        $task = $this->task(enabled: true, sessionId: '22222222-2222-4222-8222-222222222222');
        $messages = $task->getSchedule()->getRecurringMessages();

        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertInstanceOf(RecurringMessage::class, $message);
        $this->assertSame('every 60 seconds', (string) $message->getTrigger());

        $payloads = iterator_to_array($message->getMessages(
            new MessageContext(
                'default',
                $message->getId(),
                $message->getTrigger(),
                new \DateTimeImmutable(),
                null,
            ),
        ));
        $this->assertCount(1, $payloads);
        $this->assertInstanceOf(SessionResourceSampleMessage::class, $payloads[0]);
    }

    public function testSamplePublishesOwnedSessionMetricsAndIgnoresForeignSession(): void
    {
        $sessionId = '33333333-3333-4333-8333-333333333333';
        $foreignId = '44444444-4444-4444-8444-444444444444';
        $procRoot = $this->tmpDir.'/proc';
        self::makeProc($procRoot, 10, 1, 'HATFIELD_SESSION_ID='.$sessionId);
        self::makeProc($procRoot, 11, 10, 'HATFIELD_SESSION_ID='.$sessionId);
        self::makeProc($procRoot, 20, 1, 'HATFIELD_SESSION_ID='.$foreignId);

        $topBinary = $this->writeTopStub([
            10 => ['cpu' => '1.5', 'res' => '2048'],
            11 => ['cpu' => '2.5', 'res' => '4096'],
            20 => ['cpu' => '9.0', 'res' => '8192'],
        ]);

        $listener = self::startUdpListener();
        try {
            $logger = new TestLogger();
            $task = new SessionResourceSamplerTask(
                enabled: true,
                sessionId: $sessionId,
                telemetryEnv: 'dev',
                dogstatsdHost: '127.0.0.1',
                dogstatsdPort: $listener['port'],
                logger: $logger,
                procRoot: $procRoot,
                topBinary: $topBinary,
            );

            ($task)(new SessionResourceSampleMessage());

            $payload = self::readUdpPayload($listener, timeoutSeconds: 2.0);
            $this->assertNotNull($payload);
            $lines = [];
            foreach (explode("\n", trim($payload)) as $line) {
                if ('' !== $line) {
                    $lines[] = $line;
                }
            }
            $this->assertCount(3, $lines);
            $this->assertSame(
                'hatfield.session.cpu_percent:4|g|#service:hatfield,env:dev,session_id:'.$sessionId,
                $lines[0],
            );
            $this->assertSame(
                'hatfield.session.rss_bytes:'.((2048 + 4096) * 1024).'|g|#service:hatfield,env:dev,session_id:'.$sessionId,
                $lines[1],
            );
            $this->assertSame(
                'hatfield.session.process_count:2|g|#service:hatfield,env:dev,session_id:'.$sessionId,
                $lines[2],
            );
            $this->assertStringNotContainsString($foreignId, $payload);
            $this->assertTrue(self::loggerHasMessage($logger, 'info', 'session.resource_sample.published'));
        } finally {
            self::stopUdpListener($listener);
        }
    }

    public function testMissingSessionIdDoesNotScheduleOrPublish(): void
    {
        $logger = new TestLogger();
        $task = new SessionResourceSamplerTask(
            enabled: true,
            sessionId: '',
            telemetryEnv: 'dev',
            dogstatsdHost: '127.0.0.1',
            dogstatsdPort: 8125,
            logger: $logger,
            procRoot: $this->tmpDir.'/empty-proc',
            topBinary: $this->writeTopStub([]),
        );

        $this->assertSame([], $task->getSchedule()->getRecurringMessages());
        ($task)(new SessionResourceSampleMessage());
        $this->assertSame([], $logger->records);
    }

    /**
     * @param array<int, array{cpu: string, res: string}> $rows
     */
    private function writeTopStub(array $rows): string
    {
        $path = $this->tmpDir.'/top-stub';
        $encoded = var_export($rows, true);
        $script = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
$rows = ROWS;
$requested = [];
foreach ($argv as $i => $arg) {
    if ('-p' === $arg && isset($argv[$i + 1])) {
        foreach (explode(',', (string) $argv[$i + 1]) as $pid) {
            if (is_numeric($pid)) {
                $requested[] = (int) $pid;
            }
        }
    }
}
$frameLines = [
    'top - 00:00:00 up 0 days',
    'Tasks: 1 total',
    '%Cpu(s): 0.0 us',
    'MiB Mem : 0.0 total',
    'MiB Swap: 0.0 total',
    '',
    '    PID USER      PR  NI    VIRT    RES    SHR S  %CPU  %MEM     TIME+ COMMAND',
];
$body = $frameLines;
foreach ($requested as $pid) {
    if (!isset($rows[$pid])) {
        continue;
    }
    $body[] = sprintf(
        '%7d alice     20   0       0 %6s      0 S %5s   0.0   0:00.00 fixture',
        $pid,
        $rows[$pid]['res'],
        $rows[$pid]['cpu'],
    );
}
$frame = implode("\n", $body)."\n";
echo $frame."\n".$frame;
PHP;
        $script = str_replace('ROWS', $encoded, $script);
        if (false === file_put_contents($path, $script)) {
            $this->fail('Unable to write top stub.');
        }
        chmod($path, 0755);

        return $path;
    }

    private function task(bool $enabled, string $sessionId): SessionResourceSamplerTask
    {
        return new SessionResourceSamplerTask(
            enabled: $enabled,
            sessionId: $sessionId,
            telemetryEnv: 'dev',
            dogstatsdHost: '127.0.0.1',
            dogstatsdPort: 8125,
            logger: new TestLogger(),
            procRoot: $this->tmpDir.'/empty-proc',
            topBinary: $this->writeTopStub([]),
        );
    }

    private static function makeProc(string $procRoot, int $pid, int $ppid, string $environEntry): void
    {
        $dir = $procRoot.'/'.$pid;
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            self::fail('Unable to create fixture proc dir.');
        }
        if (false === file_put_contents($dir.'/environ', $environEntry."\0")) {
            self::fail('Unable to write environ fixture.');
        }
        if (false === file_put_contents($dir.'/stat', $pid.' (php) S '.$ppid." 0\n")) {
            self::fail('Unable to write stat fixture.');
        }
    }

    /**
     * @return array{port: int, socket: resource}
     */
    private static function startUdpListener(): array
    {
        $socket = @stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, \STREAM_SERVER_BIND);
        if (false === $socket) {
            self::fail('Unable to bind UDP listener: '.$errstr);
        }
        stream_set_blocking($socket, false);
        $name = stream_socket_get_name($socket, false);
        if (false === $name || !str_contains($name, ':')) {
            fclose($socket);
            self::fail('Unable to resolve UDP listener port.');
        }
        $port = (int) substr($name, strrpos($name, ':') + 1);

        return [
            'port' => $port,
            'socket' => $socket,
        ];
    }

    /**
     * @param array{port: int, socket: resource} $listener
     */
    private static function readUdpPayload(array $listener, float $timeoutSeconds): ?string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $peer = '';
            $payload = @stream_socket_recvfrom($listener['socket'], 65535, 0, $peer);
            if (\is_string($payload) && '' !== $payload) {
                return $payload;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * @param array{port: int, socket: resource} $listener
     */
    private static function stopUdpListener(array $listener): void
    {
        if (\is_resource($listener['socket'])) {
            fclose($listener['socket']);
        }
    }

    private static function loggerHasMessage(TestLogger $logger, string $level, string $message): bool
    {
        foreach ($logger->records as $record) {
            if ($level === $record['level'] && $message === $record['message']) {
                return true;
            }
        }

        return false;
    }
}
