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

#[CoversClass(SessionResourceSamplerTask::class)]
#[CoversClass(SessionResourceSampleMessage::class)]
final class SessionResourceSamplerTaskTest extends TestCase
{
    private string $directory;

    /** @var array{process: string|false, env: mixed, server: mixed} */
    private array $path;

    protected function setUp(): void
    {
        $this->directory = TestDirectoryIsolation::createOsTempDir('session-resource-sampler');
        $this->path = ['process' => getenv('PATH'), 'env' => $_ENV['PATH'] ?? null, 'server' => $_SERVER['PATH'] ?? null];
        $path = $this->directory.\PATH_SEPARATOR.(false === $this->path['process'] ? '' : $this->path['process']);
        putenv('PATH='.$path);
        $_ENV['PATH'] = $_SERVER['PATH'] = $path;
    }

    protected function tearDown(): void
    {
        putenv(false === $this->path['process'] ? 'PATH' : 'PATH='.$this->path['process']);
        foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
            if (null === $this->path[$key]) {
                unset($GLOBALS[$global]['PATH']);
            } else {
                $GLOBALS[$global]['PATH'] = $this->path[$key];
            }
        }
        TestDirectoryIsolation::removeDirectory($this->directory);
    }

    public function testNormalLaunchDoesNotScheduleOrCollect(): void
    {
        $logger = new TestLogger();
        $task = $this->task($logger, enabled: false);
        $this->assertSame([], $task->getSchedule()->getRecurringMessages());
        $task(new SessionResourceSampleMessage());
        $this->assertSame([], $logger->records);
    }

    public function testOptedInSessionRegistersOneMinuteJob(): void
    {
        $messages = $this->task(new TestLogger())->getSchedule()->getRecurringMessages();
        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame('every 60 seconds', (string) $message->getTrigger());
        $payload = iterator_to_array($message->getMessages(new MessageContext(
            'default', $message->getId(), $message->getTrigger(), new \DateTimeImmutable(), null,
        )));
        $this->assertInstanceOf(SessionResourceSampleMessage::class, $payload[0]);
        $this->assertSame([], $this->task(new TestLogger(), sessionId: '')->getSchedule()->getRecurringMessages());
    }

    public function testSecondTopRefreshSumsOwnedTreeAndSendsRealUdp(): void
    {
        // Two sessions share a Castor ancestor (2). Include our TUI and descendants,
        // including tools without a session environment marker, but not the ancestor/sibling tree.
        $self = (int) getmypid();
        $this->command('ps', 'echo '.var_export("2 1\n20 2\n21 20\n$self 21\n22 21\n23 22\n30 2\n31 30\n", true).';');
        $header = "top - 00:00:00 up 1 day\n\nPID USER PR NI VIRT RES SHR S %CPU %MEM TIME+ COMMAND\n";
        $first = $second = $header;
        foreach ([20, 21, $self, 22, 23, 2, 30, 31] as $index => $pid) {
            $first .= "$pid user 20 0 0 900000 0 S 99 0 0:00 fixture\n";
            $second .= "$pid user 20 0 0 ".(($index + 1) * 100).' 0 S '.($index + 1)." 0 0:00 fixture\n";
        }
        $this->command('top', 'echo '.var_export($first."\n".$second, true).';');
        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, \STREAM_SERVER_BIND);
        $this->assertIsResource($socket);
        try {
            $address = stream_socket_get_name($socket, false);
            $this->assertIsString($address);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            $logger = new TestLogger();
            $this->task($logger, port: $port)(new SessionResourceSampleMessage());
            $read = [$socket];
            $write = $except = [];
            $this->assertSame(1, stream_select($read, $write, $except, 2));
            $this->assertSame(
                "hatfield.session.cpu_percent:15|g|#service:hatfield,env:dev,session_id:session-owned\n"
                ."hatfield.session.rss_bytes:1536000|g|#service:hatfield,env:dev,session_id:session-owned\n"
                ."hatfield.session.process_count:5|g|#service:hatfield,env:dev,session_id:session-owned\n",
                stream_socket_recvfrom($socket, 4096),
            );
            $this->assertSame('session.resource_sample.sent', $logger->records[0]['message']);
        } finally {
            fclose($socket);
        }
    }

    public function testMalformedTopIsLoggedWithoutRawOutput(): void
    {
        $self = (int) getmypid();
        $this->command('ps', 'echo '.var_export("20 1\n21 20\n$self 21\n", true).';');
        $this->command('top', 'echo "private-terminal-content";');
        $logger = new TestLogger();
        $this->task($logger)(new SessionResourceSampleMessage());
        $this->assertSame('session.resource_sample.failed', $logger->records[0]['message']);
        $this->assertSame('top', $logger->records[0]['context']['phase']);
        $this->assertStringNotContainsString('private-terminal-content', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    public function testInstalledCommandsSampleAnOwnedProcessTree(): void
    {
        // A controlled TUI -> controller -> consumer hierarchy exercises real ps/top
        // and UDP without a real session, provider, or application controller.
        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, \STREAM_SERVER_BIND);
        $this->assertIsResource($socket);
        try {
            $address = stream_socket_get_name($socket, false);
            $this->assertIsString($address);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            $autoload = var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true);
            $worker = 'require '.$autoload.'; (new \\Ineersa\\CodingAgent\\Logging\\SessionResourceSamplerTask('
                .'true, "native-tree", "dev", "127.0.0.1", '.$port.', new \\Psr\\Log\\NullLogger()))'
                .'(new \\Ineersa\\CodingAgent\\Logging\\SessionResourceSampleMessage());';
            $controller = 'require '.$autoload.'; (new \\Symfony\\Component\\Process\\Process('
                .'[PHP_BINARY, "-r", '.var_export($worker, true).'], '
                .'env: ["HATFIELD_SESSION_ID" => false], timeout: 5))->mustRun();';
            (new \Symfony\Component\Process\Process(
                [\PHP_BINARY, '-r', $controller], env: ['HATFIELD_SESSION_ID' => false], timeout: 7,
            ))->mustRun();
            $read = [$socket];
            $write = $except = [];
            $this->assertSame(1, stream_select($read, $write, $except, 2));
            $payload = stream_socket_recvfrom($socket, 4096);
            $this->assertIsString($payload);
            $this->assertMatchesRegularExpression('/hatfield.session.cpu_percent:[0-9.]+\\|g/', $payload);
            $this->assertMatchesRegularExpression('/hatfield.session.rss_bytes:[1-9][0-9]*\\|g/', $payload);
            $this->assertMatchesRegularExpression('/hatfield.session.process_count:(?:[3-9]|[1-9][0-9]+)\\|g/', $payload);
            $this->assertStringContainsString('session_id:native-tree', $payload);
        } finally {
            fclose($socket);
        }
    }

    private function task(TestLogger $logger, bool $enabled = true, string $sessionId = 'session-owned', int $port = 8125): SessionResourceSamplerTask
    {
        return new SessionResourceSamplerTask($enabled, $sessionId, 'dev', '127.0.0.1', $port, $logger);
    }

    private function command(string $name, string $body): void
    {
        $path = $this->directory.'/'.$name;
        $this->assertNotFalse(file_put_contents($path, '#!'.\PHP_BINARY."\n<?php\n".$body."\n"));
        $this->assertTrue(chmod($path, 0755));
    }
}
