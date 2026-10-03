<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Domain\Message\RepairSession;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\RepairHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RepairHandlerTest extends TestCase
{
    public function testDispatchesRepairWithoutWaitingForCompletion(): void
    {
        $bus = new TestMessageBus();
        $events = [];
        (new RepairHandler($bus))(new ControllerCommandEvent(new RuntimeCommand('request', 'repair', 'run', ['apply' => false]), static function (RuntimeEvent $event) use (&$events): void { $events[] = $event; }));
        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(RepairSession::class, $bus->messages[0]);
        $this->assertSame('run', $bus->messages[0]->runId);
        $this->assertSame('request', $bus->messages[0]->commandId);
        $this->assertFalse($bus->messages[0]->apply);
        $this->assertSame([], $events);
    }

    public function testSubmissionFailureEmitsSanitizedCorrelatedFailure(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('private detail'));
        $events = [];
        (new RepairHandler($bus))(new ControllerCommandEvent(new RuntimeCommand('request', 'repair', 'run'), static function (RuntimeEvent $event) use (&$events): void { $events[] = $event; }));
        $this->assertSame('request', $events[0]->payload['commandId']);
        $this->assertSame('failed', $events[0]->payload['status']);
        $this->assertSame(\RuntimeException::class, $events[0]->payload['exception_class']);
        $this->assertArrayNotHasKey('exception_message', $events[0]->payload);
    }

    public function testMissingRunDoesNotDispatch(): void
    {
        $bus = new TestMessageBus();
        $events = [];
        (new RepairHandler($bus))(new ControllerCommandEvent(new RuntimeCommand('request', 'repair', ''), static function (RuntimeEvent $event) use (&$events): void { $events[] = $event; }));
        $this->assertSame([], $bus->messages);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $events[0]->type);
    }

    public function testIgnoresOtherCommands(): void
    {
        $bus = new TestMessageBus();
        (new RepairHandler($bus))(new ControllerCommandEvent(new RuntimeCommand('request', 'compact', 'run'), static function (): void {}));
        $this->assertSame([], $bus->messages);
    }
}
