<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ShellCommandHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(ShellCommandHandler::class)]
final class ShellCommandHandlerTest extends TestCase
{
    private ShellCommandSpyClient $spyClient;
    private ShellCommandSpyBus $spyBus;
    /** @var list<RunEvent> */
    private array $appendedEvents = [];
    private ShellCommandSpyRunStore $runStore;

    protected function setUp(): void
    {
        $this->spyClient = new ShellCommandSpyClient();
        $this->spyBus = new ShellCommandSpyBus();
        $this->appendedEvents = [];
        $this->runStore = new ShellCommandSpyRunStore();
    }

    public function testDispatchesShellCommandViaExecutionBus(): void
    {
        $this->runStore->state = new RunState(runId: 'run-123', status: RunStatus::Completed, turnNo: 2, lastSeq: 5);
        $handler = $this->createHandler();

        $command = new RuntimeCommand(
            id: 'cmd_1',
            type: 'shell_command',
            runId: 'run-123',
            payload: [
                'text' => 'echo hello',
                'original_text' => '!echo hello',
                'standalone' => false,
            ],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        // Shell commands are now dispatched via the async Messenger bus
        // instead of the synchronous in-process client (issue #183).
        $this->assertNull($this->spyClient->lastCommand, 'Client send() should NOT be called for shell commands');
        $this->assertNotNull($this->spyBus->lastMessage);
        $this->assertInstanceOf(ExecuteShellToolCall::class, $this->spyBus->lastMessage);
        $this->assertSame('run-123', $this->spyBus->lastMessage->runId());
        $this->assertSame('echo hello', $this->spyBus->lastMessage->commandText);
        $this->assertFalse($this->spyBus->lastMessage->standalone, 'Payload without standalone flag defaults to false');
        $this->assertSame(2, $this->spyBus->lastMessage->turnNo(), 'Worker must receive current leaf turn');

        $this->assertCount(1, $this->appendedEvents);
        $this->assertSame(RunEventTypeEnum::AgentCommandApplied->value, $this->appendedEvents[0]->type);
        $this->assertSame(2, $this->appendedEvents[0]->turnNo);
        $this->assertSame('shell_command', $this->appendedEvents[0]->payload['kind'] ?? null);
        $this->assertSame('!echo hello', $this->appendedEvents[0]->payload['text'] ?? null);
        $this->assertSame($this->spyBus->lastMessage->toolCallId, $this->appendedEvents[0]->payload['tool_call_id'] ?? null);
    }

    public function testEmitsProtocolErrorWhenRunIdMissing(): void
    {
        $handler = $this->createHandler();

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_2',
            type: 'shell_command',
            runId: '',
            payload: ['text' => 'ls'],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        $this->assertNull($this->spyClient->lastCommand);
        $this->assertNull($this->spyBus->lastMessage);
        $this->assertCount(1, $emittedEvents);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
    }

    public function testIgnoresNonShellCommands(): void
    {
        $handler = $this->createHandler();

        // complete_run is NOT handled here — the controller must never
        // synchronously write AgentEnd for async shell work (issue #183).
        $command = new RuntimeCommand(
            id: 'cmd_complete',
            type: 'complete_run',
            runId: 'run-complete-1',
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        $this->assertSame(0, $this->spyClient->completeRunCalls, 'complete_run must NOT be handled (worker owns AgentEnd)');
        $this->assertNull($this->spyBus->lastMessage);

        $command2 = new RuntimeCommand(
            id: 'cmd_3',
            type: 'user_message',
            runId: 'run-789',
            payload: ['text' => 'hello'],
        );

        $event2 = new ControllerCommandEvent($command2, static function (): void {});
        $handler($event2);

        $this->assertNull($this->spyClient->lastCommand);
        $this->assertNull($this->spyBus->lastMessage);
    }

    public function testMalformedShellPayloadEmitsProtocolError(): void
    {
        $handler = $this->createHandler();
        $emittedEvents = [];

        $command = new RuntimeCommand(
            id: 'cmd_4',
            type: 'shell_command',
            runId: 'run-456',
            payload: [],
        );

        $handler(new ControllerCommandEvent($command, static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        }));

        $this->assertNull($this->spyBus->lastMessage);
        $this->assertCount(1, $emittedEvents);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
    }

    public function testStandaloneShellCommandPassesFlagToWorker(): void
    {
        $handler = $this->createHandler();

        $command = new RuntimeCommand(
            id: 'cmd_standalone',
            type: 'shell_command',
            runId: 'run-standalone-1',
            payload: [
                'text' => 'echo hello',
                'original_text' => '!echo hello',
                'standalone' => true,
            ],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        // Standalone shell commands: the standalone flag is passed through
        // to the worker so it writes the terminal AgentEnd event in guaranteed
        // order after tool_exec events (issue #183).  The handler no longer
        // calls completeRun() itself — the worker owns the terminal event.
        $this->assertNotNull($this->spyBus->lastMessage);
        $this->assertInstanceOf(ExecuteShellToolCall::class, $this->spyBus->lastMessage);
        $this->assertTrue($this->spyBus->lastMessage->standalone, 'Standalone flag must be passed to the worker');
        $this->assertSame('run-standalone-1', $this->spyBus->lastMessage->runId());
        $this->assertSame(0, $this->spyClient->completeRunCalls, 'Handler must NOT call completeRun for standalone — worker owns AgentEnd');
    }

    public function testInlineShellCommandDoesNotCompleteRun(): void
    {
        $handler = $this->createHandler();
        $this->runStore->state = new RunState(runId: 'run-inline-1', status: RunStatus::Running, turnNo: 2, lastSeq: 5);

        $command = new RuntimeCommand(
            id: 'cmd_inline',
            type: 'shell_command',
            runId: 'run-inline-1',
            payload: [
                'text' => 'echo done',
                'original_text' => '!echo done',
                'standalone' => false,
            ],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        // Non-standalone shell commands are completed by the worker without a
        // terminal event owned by the controller.
        $this->assertNotNull($this->spyBus->lastMessage);
        $this->assertInstanceOf(ExecuteShellToolCall::class, $this->spyBus->lastMessage);
        $this->assertFalse($this->spyBus->lastMessage->standalone);
        $this->assertSame(2, $this->spyBus->lastMessage->turnNo());
        $this->assertSame(0, $this->spyClient->completeRunCalls);
    }

    private function createHandler(): ShellCommandHandler
    {
        return new ShellCommandHandler($this->spyBus, $this->createEventStore(), $this->runStore);
    }

    private function createEventStore(): EventStoreInterface
    {
        return new class($this->appendedEvents) implements EventStoreInterface {
            /** @var list<RunEvent> */
            private array $collector;

            /** @param list<RunEvent> &$collector */
            public function __construct(array &$collector)
            {
                $this->collector = &$collector;
            }

            public function append(RunEvent $event): RunEvent
            {
                $seq = \count(array_filter($this->collector, static fn (RunEvent $e): bool => $e->runId === $event->runId)) + 1;
                $persisted = new RunEvent($event->runId, $seq, $event->turnNo, $event->type, $event->payload, $event->createdAt);
                $this->collector[] = $persisted;

                return $persisted;
            }

            public function appendMany(array $events): array
            {
                $out = [];
                foreach ($events as $event) {
                    $out[] = $this->append($event);
                }

                return $out;
            }

            public function allFor(string $runId): array
            {
                return array_values(array_filter(
                    $this->collector,
                    static fn (RunEvent $e): bool => $e->runId === $runId,
                ));
            }
        };
    }
}

/**
 * Test spy for MessageBusInterface in shell command context.
 *
 * @internal test helper
 */
final class ShellCommandSpyBus implements MessageBusInterface
{
    /** @var object|null */
    public $lastMessage;

    /** @var list<Envelope> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->lastMessage = $message;
        $envelope = new Envelope($message, $stamps);
        $this->dispatched[] = $envelope;

        return $envelope;
    }
}

/**
 * Test run store for current-leaf turn assertions.
 *
 * @internal test helper
 */
final class ShellCommandSpyRunStore implements RunStoreInterface
{
    public ?RunState $state = null;

    public function get(string $runId): ?RunState
    {
        return $this->state?->runId === $runId ? $this->state : null;
    }

    public function compareAndSwap(RunState $state, int $expectedVersion): bool
    {
        $this->state = $state;

        return true;
    }

    public function findRunningStaleBefore(\DateTimeImmutable $updatedBefore): array
    {
        return [];
    }
}

/**
 * Test spy for AgentSessionClient in shell command context.
 *
 * @internal test helper
 */
final class ShellCommandSpyClient implements AgentSessionClient
{
    public ?UserCommand $lastCommand = null;
    public int $completeRunCalls = 0;
    public ?string $lastCompletedRunId = null;

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \RuntimeException('Unexpected start()');
    }

    public function attach(string $runId): RunHandle
    {
        throw new \RuntimeException('Unexpected attach()');
    }

    public function send(string $runId, UserCommand $command): void
    {
        $this->lastCommand = $command;
    }

    public function beginObservingChildRun(string $childRunId): void
    {
    }

    public function endObservingChildRun(string $childRunId): void
    {
    }

    public function events(string $runId): iterable
    {
        return [];
    }

    public function cancel(string $runId): void
    {
        throw new \RuntimeException('Unexpected cancel()');
    }

    public function shellExecute(\Ineersa\CodingAgent\Runtime\Contract\ShellExecutionRequestDTO $request): RunHandle
    {
        throw new \RuntimeException('Unexpected shellExecute()');
    }

    public function completeRun(string $runId): void
    {
        ++$this->completeRunCalls;
        $this->lastCompletedRunId = $runId;
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
        throw new \RuntimeException('Unexpected compact()');
    }
}
