<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\CodingAgent\Logging\ProcessRole;
use Ineersa\CodingAgent\Logging\ProcessRoleAttributionSubscriber;
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

#[CoversClass(ProcessRoleAttributionSubscriber::class)]
#[CoversClass(ProcessRole::class)]
final class ProcessRoleAttributionSubscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        while ([] !== RunLogContext::current()) {
            RunLogContext::leave();
        }
    }

    public function testAgentCommandOptionsSetStableRoles(): void
    {
        $subscriber = new ProcessRoleAttributionSubscriber();
        $command = new Command('agent');

        $definition = new InputDefinition([
            new InputOption('controller', mode: InputOption::VALUE_NONE),
            new InputOption('headless', mode: InputOption::VALUE_NONE),
        ]);

        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            new ArrayInput(['--controller' => true], $definition),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRole::CONTROLLER, RunLogContext::current()['process_role'] ?? null);

        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(
            $command,
            new ArrayInput([], $definition),
            new NullOutput(),
            0,
        ));
        $this->assertSame([], RunLogContext::current());

        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            new ArrayInput(['--headless' => true], $definition),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRole::HEADLESS, RunLogContext::current()['process_role'] ?? null);
        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(
            $command,
            new ArrayInput([], $definition),
            new NullOutput(),
            0,
        ));

        $subscriber->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            new ArrayInput([], $definition),
            new NullOutput(),
        ));
        $this->assertSame(ProcessRole::SCREEN, RunLogContext::current()['process_role'] ?? null);
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
        $this->assertSame(ProcessRole::CONSOLE, RunLogContext::current()['process_role'] ?? null);

        $worker = $this->createStub(Worker::class);
        $worker->method('getMetadata')->willReturn(new WorkerMetadata([
            'transportNames' => ['run_control'],
        ]));
        $subscriber->onWorkerStarted(new WorkerStartedEvent($worker));

        $this->assertSame(ProcessRole::RUN_CONTROL, RunLogContext::current()['process_role'] ?? null);
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
        $this->assertSame(ProcessRole::CONSOLE, RunLogContext::current()['process_role'] ?? null);

        $worker = $this->createStub(Worker::class);
        $worker->method('getMetadata')->willReturn(new WorkerMetadata([
            'transportNames' => ['llm', 'tool'],
        ]));
        $subscriber->onWorkerStarted(new WorkerStartedEvent($worker));

        $this->assertSame(ProcessRole::CONSOLE, RunLogContext::current()['process_role'] ?? null);
    }
}
