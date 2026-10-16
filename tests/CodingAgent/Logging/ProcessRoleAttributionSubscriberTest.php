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
    protected function tearDown(): void
    {
        while ([] !== RunLogContext::current()) {
            RunLogContext::leave();
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
        $this->assertSame(ProcessRoleEnum::Controller->processTitle(), $this->readProcessTitle($command));

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

    public function testMessengerWorkerStartedReplacesTemporaryConsoleRole(): void
    {
        $subscriber = new ProcessRoleAttributionSubscriber();
        $command = new Command('messenger:consume');
        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            new ArrayInput([]),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRoleEnum::Console->value, RunLogContext::current()['process_role'] ?? null);
        $this->assertSame(ProcessRoleEnum::Console->processTitle(), $this->readProcessTitle($command));

        $worker = $this->createStub(Worker::class);
        $worker->method('getMetadata')->willReturn(new WorkerMetadata([
            'transportNames' => ['run_control'],
        ]));
        $subscriber->onWorkerStarted(new WorkerStartedEvent($worker));

        $this->assertSame(ProcessRoleEnum::RunControl->value, RunLogContext::current()['process_role'] ?? null);
        if (\function_exists('cli_get_process_title')) {
            $this->assertSame(ProcessRoleEnum::RunControl->processTitle(), cli_get_process_title());
        }
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
    }

    public function testProcessTitleIsVisibleToDatadogStyleJoinedCmdlineMatching(): void
    {
        $title = ProcessRoleEnum::Controller->processTitle();
        $readyFile = sys_get_temp_dir().'/hatfield-process-title-'.bin2hex(random_bytes(8)).'.ready';
        $scriptFile = sys_get_temp_dir().'/hatfield-process-title-'.bin2hex(random_bytes(8)).'.php';
        $script = <<<'PHP'
<?php
declare(strict_types=1);
cli_set_process_title($argv[1]);
file_put_contents($argv[2], (string) getmypid());
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
while (true) {
    usleep(100000);
}
PHP;
        file_put_contents($scriptFile, $script);

        $process = new Process([PHP_BINARY, $scriptFile, $title, $readyFile]);
        $process->start();

        try {
            $pid = null;
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                if (is_file($readyFile)) {
                    $contents = trim((string) file_get_contents($readyFile));
                    if ('' !== $contents && ctype_digit($contents)) {
                        $pid = (int) $contents;
                        break;
                    }
                }
                usleep(20000);
            }

            $this->assertNotNull($pid, 'Owned probe process did not publish readiness.');
            $cmdline = (string) file_get_contents('/proc/'.$pid.'/cmdline');
            $joined = str_replace("\0", ' ', $cmdline);
            $pattern = '(?:^|[\\x00\\s])hatfield\\.role\\.controller(?:[\\x00\\s]|$)';
            $this->assertSame(1, preg_match('#'.$pattern.'#', $joined), $joined);
            $this->assertSame(0, preg_match('#(?:^|[\\x00\\s])hatfield\\.role\\.screen(?:[\\x00\\s]|$)#', $joined));
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
