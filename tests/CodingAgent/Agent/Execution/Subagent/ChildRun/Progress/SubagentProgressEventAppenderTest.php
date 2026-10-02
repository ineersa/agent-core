<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Progress;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Message\CommitSubagentProgress;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubagentProgressEventAppenderTest extends TestCase
{
    public function testProcessModeEmitsNonTerminalProgressAtSequenceZeroWithoutCommand(): void
    {
        $bus = new TestMessageBus();
        $sink = $this->createMock(RuntimeEventSinkInterface::class);
        $sink->expects($this->once())->method('emit')->with($this->callback(function (RuntimeEvent $event): bool {
            $this->assertSame(0, $event->seq);
            $this->assertSame('running', $event->payload['subagent_progress']['status']);

            return true;
        }));
        $this->assertTrue($this->appender($bus, $sink, true)->append('parent', 2, 'call', 0, 'subagent', $this->progress('running'), 'batch', 7));
        $this->assertSame([], $bus->messages);
    }

    #[DataProvider('canonicalModes')]
    public function testCanonicalProgressQueuesOwnerCommandWithoutClaimingDelivery(bool $stream, string $status): void
    {
        $bus = new TestMessageBus();
        $sink = $this->createMock(RuntimeEventSinkInterface::class);
        $sink->expects($this->never())->method('emit');
        $this->assertFalse($this->appender($bus, $sink, $stream)->append('parent', 2, 'call', 0, 'subagent', $this->progress($status), 'batch', 7));
        $this->assertCount(1, $bus->messages);
        $command = $bus->messages[0];
        $this->assertInstanceOf(CommitSubagentProgress::class, $command);
        $this->assertSame('batch', $command->lifecycleId);
        $this->assertSame(7, $command->revision);
        $this->assertSame($status, $command->progress['status']);
    }

    public static function canonicalModes(): iterable
    {
        yield 'controller terminal' => [true, 'completed'];
        yield 'in-process nonterminal' => [false, 'waiting_human'];
    }

    public function testCommandDispatchFailurePropagatesWithoutClaimingDelivery(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('dispatch failed'));
        $this->expectExceptionMessage('dispatch failed');
        $this->appender($bus, $this->createStub(RuntimeEventSinkInterface::class), true)->append('parent', 2, 'call', 0, 'subagent', $this->progress('completed'), 'batch', 7);
    }

    private function appender(MessageBusInterface $bus, RuntimeEventSinkInterface $sink, bool $stream): SubagentProgressEventAppender
    {
        return new SubagentProgressEventAppender($bus, SubagentProgressSerializerTestSupport::normalizer(), SubagentProgressSerializerTestSupport::validator(), $sink,
            new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()))), $stream);
    }

    private function progress(string $status): SubagentProgressSnapshotInterface
    {
        return SubagentProgressSerializerTestSupport::denormalizer()->denormalize(SubagentProgressSerializerTestSupport::canonicalSingleWire(status: $status), SubagentProgressSnapshotInterface::class);
    }
}
