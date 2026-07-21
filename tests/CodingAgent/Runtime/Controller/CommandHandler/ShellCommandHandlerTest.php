<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Domain\Message\ApplyShellCommand;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ShellCommandHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Thesis: controller shell_command protocol validates raw bang input and
 * dispatches ApplyShellCommand into the locked pipeline. It must not choose
 * turns, append events, or dispatch ExecuteShellToolCall directly.
 */
#[CoversClass(ShellCommandHandler::class)]
final class ShellCommandHandlerTest extends TestCase
{
    private ShellCommandSpyBus $spyBus;

    protected function setUp(): void
    {
        $this->spyBus = new ShellCommandSpyBus();
    }

    public function testDispatchesApplyShellCommandOnCommandBus(): void
    {
        $handler = new ShellCommandHandler($this->spyBus);
        $emitted = [];

        $command = new RuntimeCommand(
            id: 'cmd_1',
            type: 'shell_command',
            runId: 'run-123',
            payload: ['text' => '!echo hello'],
        );

        $handler(new ControllerCommandEvent($command, static function (RuntimeEvent $event) use (&$emitted): void {
            $emitted[] = $event;
        }));

        $this->assertNotNull($this->spyBus->lastMessage);
        $this->assertInstanceOf(ApplyShellCommand::class, $this->spyBus->lastMessage);
        $this->assertSame('run-123', $this->spyBus->lastMessage->runId());
        $this->assertSame('!echo hello', $this->spyBus->lastMessage->rawInput);
        $this->assertSame('cmd_1', $this->spyBus->lastMessage->stepId());
        $this->assertSame(hash('sha256', 'run-123|cmd_1'), $this->spyBus->lastMessage->idempotencyKey());

        $this->assertCount(1, $emitted);
        $this->assertSame(RuntimeEventTypeEnum::RunStarted->value, $emitted[0]->type);
        $this->assertSame('shell', $emitted[0]->payload['kind'] ?? null);
    }

    public function testEmitsProtocolErrorWhenRunIdMissing(): void
    {
        $handler = new ShellCommandHandler($this->spyBus);
        $emitted = [];

        $handler(new ControllerCommandEvent(new RuntimeCommand(
            id: 'cmd_2',
            type: 'shell_command',
            runId: '',
            payload: ['text' => '!ls'],
        ), static function (RuntimeEvent $event) use (&$emitted): void {
            $emitted[] = $event;
        }));

        $this->assertNull($this->spyBus->lastMessage);
        $this->assertCount(1, $emitted);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emitted[0]->type);
    }

    public function testRejectsMissingBangPrefixAndEmptyCommand(): void
    {
        $handler = new ShellCommandHandler($this->spyBus);

        $missingBang = [];
        $handler(new ControllerCommandEvent(new RuntimeCommand(
            id: 'cmd_3',
            type: 'shell_command',
            runId: 'run-1',
            payload: ['text' => 'echo hi'],
        ), static function (RuntimeEvent $event) use (&$missingBang): void {
            $missingBang[] = $event;
        }));
        $this->assertNull($this->spyBus->lastMessage);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $missingBang[0]->type);

        $empty = [];
        $handler(new ControllerCommandEvent(new RuntimeCommand(
            id: 'cmd_4',
            type: 'shell_command',
            runId: 'run-1',
            payload: ['text' => '!'],
        ), static function (RuntimeEvent $event) use (&$empty): void {
            $empty[] = $event;
        }));
        $this->assertNull($this->spyBus->lastMessage);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $empty[0]->type);
    }

    public function testIgnoresNonShellCommands(): void
    {
        $handler = new ShellCommandHandler($this->spyBus);

        $handler(new ControllerCommandEvent(new RuntimeCommand(
            id: 'cmd_complete',
            type: 'complete_run',
            runId: 'run-complete-1',
        ), static function (): void {}));

        $this->assertNull($this->spyBus->lastMessage);
    }
}

/**
 * @internal test helper
 */
final class ShellCommandSpyBus implements MessageBusInterface
{
    public ?object $lastMessage = null;

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
