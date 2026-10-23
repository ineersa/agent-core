<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Logging\LogContextProcessor;
use Ineersa\CodingAgent\Logging\SessionResourceSampleMessage;
use Ineersa\CodingAgent\Logging\SessionResourceSamplerSchedule;
use Ineersa\CodingAgent\Logging\SessionResourceSamplerTask;
use Ineersa\CodingAgent\Logging\TelemetrySessionKeyProvider;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(SessionResourceSamplerTask::class)]
#[CoversClass(SessionResourceSamplerSchedule::class)]
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
        $this->assertSame([], $this->schedule(enabled: false)->getSchedule()->getRecurringMessages());
        $task(new SessionResourceSampleMessage());
        $this->assertSame([], $logger->records);
    }

    public function testOptedInSessionRegistersOneMinuteJob(): void
    {
        $messages = $this->schedule()->getSchedule()->getRecurringMessages();
        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame('every 60 seconds', (string) $message->getTrigger());
        $payload = iterator_to_array($message->getMessages(new MessageContext(
            'default', $message->getId(), $message->getTrigger(), new \DateTimeImmutable(), null,
        )));
        $this->assertInstanceOf(SessionResourceSampleMessage::class, $payload[0]);
        $this->assertSame([], $this->schedule(sessionId: '')->getSchedule()->getRecurringMessages());
    }

    public function testSecondTopRefreshSumsOwnedTreeAndSendsRealUdp(): void
    {
        // Two sessions share a Castor ancestor (2). Include our TUI and descendants,
        // including tools without a session environment marker, but not the ancestor/sibling tree.
        // Synthetic owned PIDs exceed Linux's maximum PID allocation range so
        // partial smaps coverage cannot depend on unrelated live host processes.
        $self = (int) getmypid();
        $this->command('ps', 'echo '.var_export("2 1\n2147483620 2\n2147483621 2147483620\n$self 2147483621\n2147483622 2147483621\n2147483623 2147483622\n30 2\n31 30\n", true).';');
        $header = "top - 00:00:00 up 1 day\n\nPID USER PR NI VIRT RES SHR S %CPU %MEM TIME+ COMMAND\n";
        $first = $second = $header;
        foreach ([2147483620, 2147483621, $self, 2147483622, 2147483623, 2, 30, 31] as $index => $pid) {
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
            $key = hash('sha256', (string) realpath($this->directory)).':session-owned';
            $this->assertSame(
                "hatfield.session.cpu_percent:15|g|#service:hatfield,env:dev,session_id:session-owned,session_key:$key\n"
                ."hatfield.session.rss_bytes:1536000|g|#service:hatfield,env:dev,session_id:session-owned,session_key:$key\n"
                ."hatfield.session.process_count:5|g|#service:hatfield,env:dev,session_id:session-owned,session_key:$key\n",
                stream_socket_recvfrom($socket, 4096),
            );
            $this->assertSame('session.resource_sample.sent', $logger->records[0]['message']);
            // Fake top PIDs are missing from /proc; the real PHPUnit PID remains readable.
            $this->assertSame('partial', $logger->records[0]['context']['memory_counter_status']);
            $this->assertNull($logger->records[0]['context']['pss_bytes']);
            $this->assertNull($logger->records[0]['context']['uss_bytes']);
            $this->assertSame(1, $logger->records[0]['context']['memory_counter_readable']);
            $this->assertSame(4, $logger->records[0]['context']['memory_counter_unreadable']);
            $this->assertArrayNotHasKey('memory_counter_exited', $logger->records[0]['context']);
            $processor = new LogContextProcessor(new TelemetrySessionKeyProvider($this->directory));
            $record = $processor(new LogRecord(new \DateTimeImmutable(), 'test', Level::Info, $logger->records[0]['message'], $logger->records[0]['context']));
            $this->assertSame($key, $record->extra['session_key']);
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

    public function testInstalledCommandsSampleOwnedTreeMemoryCoverageAndUdp(): void
    {
        // One controlled TUI -> controller -> consumer hierarchy proves real ps/top,
        // complete smaps_rollup coverage, one sample log, and UDP gauges together.
        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, \STREAM_SERVER_BIND);
        $this->assertIsResource($socket);
        try {
            $address = stream_socket_get_name($socket, false);
            $this->assertIsString($address);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            $autoload = var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true);
            $directory = var_export($this->directory, true);
            $worker = 'require '.$autoload.'; $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();'
                .' (new \Ineersa\CodingAgent\Logging\SessionResourceSamplerTask('
                .'true, "native-tree", "dev", "127.0.0.1", '.$port.', $logger, '
                .'new \Ineersa\CodingAgent\Logging\TelemetrySessionKeyProvider('.$directory.')))'
                .'(new \Ineersa\CodingAgent\Logging\SessionResourceSampleMessage());'
                .' file_put_contents('.$directory.'.\'/native-tree.json\', json_encode($logger->records, JSON_THROW_ON_ERROR));';
            $controller = 'require '.$autoload.'; (new \Symfony\Component\Process\Process('
                .'[PHP_BINARY, "-r", '.var_export($worker, true).'], '
                .'env: ["HATFIELD_SESSION_ID" => false], timeout: 5))->mustRun();';
            (new \Symfony\Component\Process\Process(
                [\PHP_BINARY, '-r', $controller], env: ['HATFIELD_SESSION_ID' => false], timeout: 7,
            ))->mustRun();
            $read = [$socket];
            $write = $except = [];
            $this->assertSame(1, stream_select($read, $write, $except, 2));
            $payload = stream_socket_recvfrom($socket, 8192);
            $this->assertIsString($payload);
            $records = json_decode((string) file_get_contents($this->directory.'/native-tree.json'), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertCount(1, $records);
            $this->assertSame('session.resource_sample.sent', $records[0]['message']);
            $this->assertSame('ok', $records[0]['context']['memory_counter_status']);
            $this->assertIsInt($records[0]['context']['pss_bytes']);
            $this->assertIsInt($records[0]['context']['uss_bytes']);
            $this->assertGreaterThan(0, $records[0]['context']['pss_bytes']);
            $this->assertGreaterThan(0, $records[0]['context']['uss_bytes']);
            $this->assertSame(0, $records[0]['context']['memory_counter_unreadable']);
            $this->assertArrayNotHasKey('memory_counter_exited', $records[0]['context']);
            $this->assertMatchesRegularExpression('/hatfield.session.cpu_percent:[0-9.]+\|g/', $payload);
            $this->assertMatchesRegularExpression('/hatfield.session.rss_bytes:[1-9][0-9]*\|g/', $payload);
            $this->assertMatchesRegularExpression('/hatfield.session.process_count:(?:[3-9]|[1-9][0-9]+)\|g/', $payload);
            $this->assertMatchesRegularExpression('/hatfield.session.pss_bytes:[1-9][0-9]*\|g/', $payload);
            $this->assertMatchesRegularExpression('/hatfield.session.uss_bytes:[1-9][0-9]*\|g/', $payload);
            $this->assertStringContainsString('session_id:native-tree', $payload);
        } finally {
            fclose($socket);
        }
    }

    public function testCompiledContainerDispatchesCallableSamplerAndKeepsDefaultJobs(): void
    {
        // Prove the compiled Messenger handler resolves the sampler itself, not the
        // Schedule decorator that AddScheduleMessengerPass wraps around providers.
        $cwd = TestDirectoryIsolation::createProjectTempDir('session-resource-sampler-wiring');
        TestDirectoryIsolation::createHatfieldTree($cwd);
        $originalCwd = getcwd();
        $this->assertNotFalse($originalCwd);
        $envKeys = [
            'APP_ENV',
            'APP_DEBUG',
            'APP_SECRET',
            'HATFIELD_CWD',
            'HATFIELD_SESSION_RESOURCE_SAMPLER',
            'HATFIELD_SESSION_ID',
            'HATFIELD_TELEMETRY_ENV',
            'HATFIELD_DOGSTATSD_HOST',
            'HATFIELD_DOGSTATSD_PORT',
        ];
        $previous = [];
        foreach ($envKeys as $key) {
            $previous[$key] = [
                'process' => getenv($key),
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, \STREAM_SERVER_BIND);
        $this->assertIsResource($socket);
        try {
            $address = stream_socket_get_name($socket, false);
            $this->assertIsString($address);
            $port = (int) substr($address, strrpos($address, ':') + 1);

            chdir($cwd);
            $values = [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '0',
                'APP_SECRET' => 'test-secret',
                'HATFIELD_CWD' => $cwd,
                'HATFIELD_SESSION_RESOURCE_SAMPLER' => '1',
                'HATFIELD_SESSION_ID' => 'wiring-owned',
                'HATFIELD_TELEMETRY_ENV' => 'dev',
                'HATFIELD_DOGSTATSD_HOST' => '127.0.0.1',
                'HATFIELD_DOGSTATSD_PORT' => (string) $port,
            ];
            foreach ($values as $key => $value) {
                putenv($key.'='.$value);
                $_ENV[$key] = $_SERVER[$key] = $value;
            }

            $self = (int) getmypid();
            $this->command('ps', 'echo '.var_export("2 1\n2147483620 2\n2147483621 2147483620\n$self 2147483621\n2147483622 2147483621\n", true).';');
            $header = "top - 00:00:00 up 1 day\n\nPID USER PR NI VIRT RES SHR S %CPU %MEM TIME+ COMMAND\n";
            $first = $second = $header;
            foreach ([2147483620, 2147483621, $self, 2147483622, 2] as $index => $pid) {
                $first .= "$pid user 20 0 0 900000 0 S 99 0 0:00 fixture\n";
                $second .= "$pid user 20 0 0 ".(($index + 1) * 100).' 0 S '.($index + 1)." 0 0:00 fixture\n";
            }
            $this->command('top', 'echo '.var_export($first."\n".$second, true).';');

            $kernel = new Kernel('test', false);
            $kernel->boot();
            try {
                $container = $kernel->getContainer()->get('test.service_container');
                $provider = $container->get('scheduler.provider.default');
                $jobs = $provider->getSchedule()->getRecurringMessages();
                $this->assertCount(3, $jobs);

                $payloadTypes = [];
                foreach ($jobs as $job) {
                    $messages = iterator_to_array($job->getMessages(new MessageContext(
                        'default',
                        $job->getId(),
                        $job->getTrigger(),
                        new \DateTimeImmutable(),
                        null,
                    )));
                    $payloadTypes[] = $messages[0]::class;
                    if ($messages[0] instanceof SessionResourceSampleMessage) {
                        $this->assertSame('every 60 seconds', (string) $job->getTrigger());
                    }
                }
                sort($payloadTypes);
                $this->assertSame([
                    SessionResourceSampleMessage::class,
                    RunCommandMessage::class,
                    ServiceCallMessage::class,
                ], $payloadTypes);

                $handlers = iterator_to_array(
                    $container->get('agent.command.bus.messenger.handlers_locator')
                        ->getHandlers(new Envelope(new SessionResourceSampleMessage())),
                );
                $this->assertCount(1, $handlers);
                $this->assertSame(
                    SessionResourceSamplerTask::class.'::__invoke',
                    $handlers[0]->getName(),
                );

                $container->get('agent.command.bus')->dispatch(new SessionResourceSampleMessage());
                $read = [$socket];
                $write = $except = [];
                $this->assertSame(1, stream_select($read, $write, $except, 2));
                $key = hash('sha256', (string) realpath($cwd)).':wiring-owned';
                $payload = stream_socket_recvfrom($socket, 4096);
                $this->assertSame(
                    "hatfield.session.cpu_percent:10|g|#service:hatfield,env:dev,session_id:wiring-owned,session_key:$key\n"
                    ."hatfield.session.rss_bytes:1024000|g|#service:hatfield,env:dev,session_id:wiring-owned,session_key:$key\n"
                    ."hatfield.session.process_count:4|g|#service:hatfield,env:dev,session_id:wiring-owned,session_key:$key\n",
                    $payload,
                );
                $this->assertStringNotContainsString('hatfield.session.pss_bytes', $payload);
                $this->assertStringNotContainsString('hatfield.session.uss_bytes', $payload);
                $record = $container->get(LogContextProcessor::class)(new LogRecord(
                    new \DateTimeImmutable(), 'test', Level::Info, 'test', context: ['session_id' => 'wiring-owned'],
                ));
                $this->assertSame($key, $record->extra['session_key']);

                putenv('HATFIELD_SESSION_RESOURCE_SAMPLER=0');
                $_ENV['HATFIELD_SESSION_RESOURCE_SAMPLER'] = $_SERVER['HATFIELD_SESSION_RESOURCE_SAMPLER'] = '0';
                $kernel->shutdown();
                $kernel = new Kernel('test', false);
                $kernel->boot();
                $disabledJobs = $kernel->getContainer()
                    ->get('test.service_container')
                    ->get('scheduler.provider.default')
                    ->getSchedule()
                    ->getRecurringMessages();
                $this->assertCount(2, $disabledJobs);
                foreach ($disabledJobs as $job) {
                    $messages = iterator_to_array($job->getMessages(new MessageContext(
                        'default',
                        $job->getId(),
                        $job->getTrigger(),
                        new \DateTimeImmutable(),
                        null,
                    )));
                    $this->assertNotInstanceOf(SessionResourceSampleMessage::class, $messages[0]);
                }
            } finally {
                $kernel->shutdown();
            }
        } finally {
            fclose($socket);
            chdir($originalCwd);
            foreach ($previous as $key => $state) {
                if (false === $state['process']) {
                    putenv($key);
                } else {
                    putenv($key.'='.$state['process']);
                }
                if (null === $state['env']) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $state['env'];
                }
                if (null === $state['server']) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $state['server'];
                }
            }
            TestDirectoryIsolation::removeDirectory($cwd);
        }
    }

    public function testDashboardScopesQueriesToQualifiedSessionsAndKeepsWildcardSelection(): void
    {
        $dashboard = Yaml::parseFile(\dirname(__DIR__, 3).'/ops/datadog/dashboard.yaml');
        $this->assertSame('session_key', $dashboard['template_variables'][1]['prefix']);
        $this->assertSame(['*'], $dashboard['template_variables'][1]['defaults']);
        // Preserve mapping/list types: the native list-stream schema requires
        // indexes to be a list, but default YAML parsing turns both {} and [] into [].
        $typedDashboard = Yaml::parseFile(\dirname(__DIR__, 3).'/ops/datadog/dashboard.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
        foreach ($typedDashboard->widgets as $widget) {
            if ('list_stream' === $widget->definition->type) {
                $this->assertSame([], $widget->definition->requests[0]->query->indexes);
            }
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveArrayIterator($dashboard['widgets'])) as $field => $value) {
            if (!\is_string($value) || !\in_array($field, ['query', 'query_string', 'facet'], true)) {
                continue;
            }
            $this->assertStringNotContainsString('session_id', $value);
            if (str_contains($value, 'hatfield.session.')) {
                $this->assertStringContainsString('session_key:*', $value);
            } elseif (str_contains($value, 'service:hatfield')) {
                $this->assertStringContainsString('@extra.session_key:$session.value', $value);
            }
        }
    }

    private function task(TestLogger $logger, bool $enabled = true, string $sessionId = 'session-owned', int $port = 8125): SessionResourceSamplerTask
    {
        return new SessionResourceSamplerTask($enabled, $sessionId, 'dev', '127.0.0.1', $port, $logger, new TelemetrySessionKeyProvider($this->directory));
    }

    private function schedule(bool $enabled = true, string $sessionId = 'session-owned'): SessionResourceSamplerSchedule
    {
        return new SessionResourceSamplerSchedule($enabled, $sessionId);
    }

    private function command(string $name, string $body): void
    {
        $path = $this->directory.'/'.$name;
        $this->assertNotFalse(file_put_contents($path, '#!'.\PHP_BINARY."\n<?php\n".$body."\n"));
        $this->assertTrue(chmod($path, 0755));
    }
}
