<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\CodingAgent\Logging\ProcessRoleAttributionSubscriber;
use Ineersa\CodingAgent\Logging\ProcessRoleEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\WorkerMetadata;
use Symfony\Component\Process\Process;

#[CoversClass(ProcessRoleAttributionSubscriber::class)]
#[CoversClass(ProcessRoleEnum::class)]
final class ProcessRoleAttributionSubscriberTest extends TestCase
{
    private ?string $originalProcessTitle = null;

    protected function setUp(): void
    {
        if (\function_exists('cli_get_process_title')) {
            $current = cli_get_process_title();
            $this->originalProcessTitle = \is_string($current) ? $current : '';
        }
    }

    protected function tearDown(): void
    {
        while ([] !== RunLogContext::current()) {
            RunLogContext::leave();
        }

        if (null !== $this->originalProcessTitle && \function_exists('cli_set_process_title')) {
            // Restoring an empty title clears /proc/self/cmdline on Linux and
            // breaks later baseline capture. Only restore a real prior title.
            if ('' !== $this->originalProcessTitle) {
                @cli_set_process_title($this->originalProcessTitle);
            }
        }
    }

    public function testAgentCommandOptionsSetStableRolesAndPreferController(): void
    {
        $subscriber = new ProcessRoleAttributionSubscriber();
        $command = new Command('agent');

        $definition = new InputDefinition([
            new InputOption('controller', mode: InputOption::VALUE_NONE),
            new InputOption('headless', mode: InputOption::VALUE_NONE),
            new InputOption('prompt', mode: InputOption::VALUE_REQUIRED),
        ]);

        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            new ArrayInput(['--controller' => true, '--headless' => true], $definition),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRoleEnum::Controller->value, RunLogContext::current()['process_role'] ?? null);
        $title = (string) $this->readProcessTitle($command);
        $marker = ProcessRoleEnum::Controller->processTitleMarker();
        $this->assertTrue(
            $title === $marker || str_ends_with($title, ' '.$marker),
            $title,
        );

        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(
            $command,
            new ArrayInput([], $definition),
            new NullOutput(),
            0,
        ));
        $this->assertSame([], RunLogContext::current());

        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            new ArrayInput(['--headless' => true, '--prompt' => '--controller'], $definition),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRoleEnum::Headless->value, RunLogContext::current()['process_role'] ?? null);
        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(
            $command,
            new ArrayInput([], $definition),
            new NullOutput(),
            0,
        ));

        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            new ArrayInput(['--prompt' => 'use --headless later'], $definition),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRoleEnum::Screen->value, RunLogContext::current()['process_role'] ?? null);
    }

    public function testMessengerWorkerStartedReplacesTemporaryConsoleRoleWithoutEarlyOsRename(): void
    {
        $this->assertTrue(\function_exists('cli_get_process_title'));
        $this->assertTrue(\function_exists('cli_set_process_title'));

        $baseline = 'php /tmp/bin/console messenger:consume run_control --no-interaction';
        cli_set_process_title($baseline);

        $subscriber = new ProcessRoleAttributionSubscriber();
        $command = new Command('messenger:consume');
        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            new ArrayInput([]),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRoleEnum::Console->value, RunLogContext::current()['process_role'] ?? null);
        $this->assertNull($this->readProcessTitle($command));
        $this->assertSame($baseline, cli_get_process_title());

        $worker = $this->createStub(Worker::class);
        $worker->method('getMetadata')->willReturn(new WorkerMetadata([
            'transportNames' => ['run_control'],
        ]));
        $subscriber->onWorkerStarted(new WorkerStartedEvent($worker));

        $this->assertSame(ProcessRoleEnum::RunControl->value, RunLogContext::current()['process_role'] ?? null);
        $this->assertSame(
            $baseline.' '.ProcessRoleEnum::RunControl->processTitleMarker(),
            cli_get_process_title(),
        );
    }

    public function testSchedulerTransportMapsToSchedulerRole(): void
    {
        $subscriber = new ProcessRoleAttributionSubscriber();
        $worker = $this->createStub(Worker::class);
        $worker->method('getMetadata')->willReturn(new WorkerMetadata([
            'transportNames' => ['scheduler_default'],
        ]));
        $subscriber->onWorkerStarted(new WorkerStartedEvent($worker));

        $this->assertSame(ProcessRoleEnum::Scheduler->value, RunLogContext::current()['process_role'] ?? null);
    }

    public function testUnrelatedConsoleCommandsRemainUnattributed(): void
    {
        $subscriber = new ProcessRoleAttributionSubscriber();
        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            new Command('cache:clear'),
            new ArrayInput([]),
            new NullOutput(),
        ));

        $this->assertSame([], RunLogContext::current());
    }

    public function testMultiReceiverWorkersStayUnattributedBeyondTemporaryConsole(): void
    {
        $this->assertTrue(\function_exists('cli_get_process_title'));
        $this->assertTrue(\function_exists('cli_set_process_title'));

        $baseline = 'php /tmp/bin/console messenger:consume llm tool';
        cli_set_process_title($baseline);

        $subscriber = new ProcessRoleAttributionSubscriber();
        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            new Command('messenger:consume'),
            new ArrayInput([]),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRoleEnum::Console->value, RunLogContext::current()['process_role'] ?? null);

        $worker = $this->createStub(Worker::class);
        $worker->method('getMetadata')->willReturn(new WorkerMetadata([
            'transportNames' => ['llm', 'tool'],
        ]));
        $subscriber->onWorkerStarted(new WorkerStartedEvent($worker));

        $this->assertSame(ProcessRoleEnum::Console->value, RunLogContext::current()['process_role'] ?? null);
        $this->assertSame($baseline, cli_get_process_title());
    }

    public function testNestedConsoleTerminateDoesNotClearOuterRole(): void
    {
        $subscriber = new ProcessRoleAttributionSubscriber();
        $outer = new Command('agent');
        $inner = new Command('cache:clear');
        $definition = new InputDefinition([
            new InputOption('controller', mode: InputOption::VALUE_NONE),
            new InputOption('headless', mode: InputOption::VALUE_NONE),
        ]);

        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            $outer,
            new ArrayInput(['--controller' => true], $definition),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRoleEnum::Controller->value, RunLogContext::current()['process_role'] ?? null);

        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            $inner,
            new ArrayInput([]),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRoleEnum::Controller->value, RunLogContext::current()['process_role'] ?? null);

        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(
            $inner,
            new ArrayInput([]),
            new NullOutput(),
            0,
        ));
        $this->assertSame(ProcessRoleEnum::Controller->value, RunLogContext::current()['process_role'] ?? null);

        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(
            $outer,
            new ArrayInput([], $definition),
            new NullOutput(),
            0,
        ));
        $this->assertSame([], RunLogContext::current());
    }

    public function testProcessTitleKeepsOriginalIdentityAndAnchoredRoleSuffix(): void
    {
        $marker = ProcessRoleEnum::Controller->processTitleMarker();
        $readyFile = sys_get_temp_dir().'/hatfield-process-title-'.bin2hex(random_bytes(8)).'.ready';
        $scriptFile = sys_get_temp_dir().'/hatfield-process-title-'.bin2hex(random_bytes(8)).'.php';
        $script = <<<'PHP'
<?php
declare(strict_types=1);
$marker = $argv[1];
$ready = $argv[2];
$beforeTitle = cli_get_process_title();
$cmdline = (string) file_get_contents('/proc/self/cmdline');
$baseline = is_string($beforeTitle) && '' !== $beforeTitle
    ? $beforeTitle
    : rtrim(str_replace("\0", ' ', $cmdline));
cli_set_process_title($baseline.' '.$marker);
$after = (string) file_get_contents('/proc/self/cmdline');
file_put_contents($ready, json_encode([
    'pid' => getmypid(),
    'before_title' => $beforeTitle,
    'baseline' => $baseline,
    'after' => str_replace("\0", ' ', $after),
], JSON_THROW_ON_ERROR));
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
while (true) {
    usleep(100000);
}
PHP;
        file_put_contents($scriptFile, $script);

        $process = new Process([
            PHP_BINARY,
            $scriptFile,
            $marker,
            $readyFile,
            'messenger:consume',
            'llm',
            '--no-interaction',
        ]);
        $process->start();

        try {
            $pid = null;
            $payload = null;
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                if (is_file($readyFile)) {
                    $contents = trim((string) file_get_contents($readyFile));
                    if ('' !== $contents) {
                        $decoded = json_decode($contents, true);
                        if (\is_array($decoded) && isset($decoded['pid']) && \is_int($decoded['pid'])) {
                            $pid = $decoded['pid'];
                            $payload = $decoded;
                            break;
                        }
                    }
                }
                usleep(20000);
            }

            $this->assertNotNull($pid, 'Owned probe process did not publish readiness.');
            $this->assertIsArray($payload);
            $joined = (string) $payload['after'];
            $this->assertSame('', $payload['before_title']);
            $this->assertStringContainsString('messenger:consume', (string) $payload['baseline']);
            $this->assertStringContainsString('messenger:consume llm', $joined);
            $pattern = '(?:^|[\\x00\\s])hatfield\\.role\\.controller(?:[\\x00\\s]*)$';
            $this->assertSame(1, preg_match('#'.$pattern.'#', $joined), $joined);
            $this->assertSame(0, preg_match('#(?:^|[\\x00\\s])hatfield\\.role\\.screen(?:[\\x00\\s]*)$#', $joined));
            $this->assertSame(0, preg_match('#(?:^|[\\x00\\s])hatfield\\.role\\.controller(?:[\\x00\\s]*)$#', 'php app --prompt=hatfield.role.controller more'));
        } finally {
            if ($process->isRunning()) {
                $process->stop(0, SIGTERM);
            }
            @unlink($readyFile);
            @unlink($scriptFile);
        }
    }

    private function readProcessTitle(Command $command): ?string
    {
        $reader = \Closure::bind(
            static fn (Command $command): ?string => $command->processTitle,
            null,
            Command::class,
        );

        return $reader($command);
    }
}
