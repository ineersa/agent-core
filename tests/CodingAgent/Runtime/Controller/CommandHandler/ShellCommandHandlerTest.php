<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
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

    protected function setUp(): void
    {
        $this->spyClient = new ShellCommandSpyClient();
        $this->spyBus = new ShellCommandSpyBus();
    }

    public function testDispatchesShellCommandViaExecutionBus(): void
    {
        $handler = new ShellCommandHandler($this->spyBus);

        $command = new RuntimeCommand(
            id: 'cmd_1',
            type: 'shell_command',
            runId: 'run-123',
            payload: [
                'text' => 'echo hello',
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
    }

    public function testEmitsProtocolErrorWhenRunIdMissing(): void
    {
        $handler = new ShellCommandHandler($this->spyBus);

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
        $handler = new ShellCommandHandler($this->spyBus);

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

    public function testEmptyCommandTextDoesNotDispatchToBus(): void
    {
        $handler = new ShellCommandHandler($this->spyBus);

        $command = new RuntimeCommand(
            id: 'cmd_4',
            type: 'shell_command',
            runId: 'run-456',
            payload: [],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        // Empty command text: the worker returns early (no-op),
        // but the dispatch still happens — ExecuteShellToolCallWorker
        // handles the empty-command case.
        $this->assertNotNull($this->spyBus->lastMessage);
        $this->assertInstanceOf(ExecuteShellToolCall::class, $this->spyBus->lastMessage);
        $this->assertSame('', $this->spyBus->lastMessage->commandText);
    }

    public function testStandaloneShellCommandPassesFlagToWorker(): void
    {
        $handler = new ShellCommandHandler($this->spyBus);

        $command = new RuntimeCommand(
            id: 'cmd_standalone',
            type: 'shell_command',
            runId: 'run-standalone-1',
            payload: [
                'text' => 'echo hello',
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
        $handler = new ShellCommandHandler($this->spyBus);

        // Non-standalone: dispatched via bus for subsequent shell commands
        // during an agent run. No completeRun() call.
        $command = new RuntimeCommand(
            id: 'cmd_inline',
            type: 'shell_command',
            runId: 'run-inline-1',
            payload: ['text' => 'echo done'],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        $this->assertNotNull($this->spyBus->lastMessage);
        $this->assertInstanceOf(ExecuteShellToolCall::class, $this->spyBus->lastMessage);
        $this->assertFalse($this->spyBus->lastMessage->standalone);
        $this->assertSame(0, $this->spyClient->completeRunCalls);
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

    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle
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
