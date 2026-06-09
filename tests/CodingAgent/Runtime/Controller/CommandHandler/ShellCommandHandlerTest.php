<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

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

#[CoversClass(ShellCommandHandler::class)]
final class ShellCommandHandlerTest extends TestCase
{
    private ShellCommandSpyClient $spyClient;

    protected function setUp(): void
    {
        $this->spyClient = new ShellCommandSpyClient();
    }

    public function testDispatchesShellCommandToClient(): void
    {
        $handler = new ShellCommandHandler($this->spyClient);

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

        self::assertNotNull($this->spyClient->lastCommand);
        self::assertSame('shell_command', $this->spyClient->lastCommand->type);
        self::assertSame('echo hello', $this->spyClient->lastCommand->text);
    }

    public function testEmitsProtocolErrorWhenRunIdMissing(): void
    {
        $handler = new ShellCommandHandler($this->spyClient);

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

        self::assertNull($this->spyClient->lastCommand);
        self::assertCount(1, $emittedEvents);
        self::assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
    }

    public function testIgnoresNonShellCommands(): void
    {
        $handler = new ShellCommandHandler($this->spyClient);

        $command = new RuntimeCommand(
            id: 'cmd_3',
            type: 'user_message',
            runId: 'run-789',
            payload: ['text' => 'hello'],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        self::assertNull($this->spyClient->lastCommand);
        $this->addToAssertionCount(1);
    }

    public function testSendEmptyTextStillDispatches(): void
    {
        $handler = new ShellCommandHandler($this->spyClient);

        $command = new RuntimeCommand(
            id: 'cmd_4',
            type: 'shell_command',
            runId: 'run-456',
            payload: [],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        self::assertNotNull($this->spyClient->lastCommand);
        self::assertSame('shell_command', $this->spyClient->lastCommand->type);
        self::assertSame('', $this->spyClient->lastCommand->text);
    }
}

/**
 * Test spy for AgentSessionClient::send() in shell command context.
 *
 * @internal test helper
 */
final class ShellCommandSpyClient implements AgentSessionClient
{
    public ?UserCommand $lastCommand = null;

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \RuntimeException('Unexpected start()');
    }

    public function resume(string $runId): RunHandle
    {
        throw new \RuntimeException('Unexpected resume()');
    }

    public function send(string $runId, UserCommand $command): void
    {
        $this->lastCommand = $command;
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
}
