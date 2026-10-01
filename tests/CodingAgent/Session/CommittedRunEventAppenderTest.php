<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\InvalidateRunContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBusInterface;

final class CommittedRunEventAppenderTest extends TestCase
{
    public function testAppendPersistsAdvancesSharedStateThenInvalidatesHotCache(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $persisted = $this->event('parent-1', 4);
        $order = [];
        $eventStore->expects($this->once())->method('append')->willReturnCallback(static function () use (&$order, $persisted): RunEvent {
            $order[] = 'append';

            return $persisted;
        });
        $commandBus = new TestMessageBus();
        $active = $this->createMock(ActiveRunContextInterface::class);
        $active->expects($this->once())->method('withdrawForCommit')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'withdraw';
        });
        $active->expects($this->once())
            ->method('applyCommittedSuffix')
            ->with('parent-1', [$persisted], $this->callback(static fn (mixed $value): bool => \is_callable($value)))
            ->willReturn(new RunState('parent-1', RunStatus::Running, lastSeq: 4, model: 'm'));
        $history = $this->createMock(HistoryProjectionStoreInterface::class);
        $history->expects($this->once())->method('applyCommitted')->with('parent-1', [$persisted]);

        $result = $this->appender($eventStore, $commandBus, $active, $history)->append($this->event('parent-1', 0));

        $this->assertSame($persisted, $result);
        $this->assertSame(['withdraw', 'append'], $order);
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(InvalidateRunContext::class, $commandBus->messages[0]);
        $this->assertSame('parent-1', $commandBus->messages[0]->runId());
    }

    public function testAppendManyInvalidatesLastPersistedRunOnce(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $persisted = [$this->event('parent-1', 4), $this->event('parent-1', 5)];
        $eventStore->expects($this->once())->method('appendMany')->willReturn($persisted);
        $commandBus = new TestMessageBus();
        $active = $this->createMock(ActiveRunContextInterface::class);
        $active->method('withdrawForCommit');
        $active->expects($this->once())
            ->method('applyCommittedSuffix')
            ->with('parent-1', $persisted, $this->callback(static fn (mixed $value): bool => \is_callable($value)))
            ->willReturn(new RunState('parent-1', RunStatus::Running, lastSeq: 5, model: 'm'));

        $result = $this->appender($eventStore, $commandBus, $active)->appendMany([
            $this->event('parent-1', 0),
            $this->event('parent-1', 0),
        ]);

        $this->assertSame($persisted, $result);
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(InvalidateRunContext::class, $commandBus->messages[0]);
        $this->assertSame('parent-1', $commandBus->messages[0]->runId());
    }

    public function testAppendManyWithNoEventsDoesNotPersistOrInvalidate(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('appendMany');
        $commandBus = new TestMessageBus();
        $active = $this->createMock(ActiveRunContextInterface::class);
        $active->method('withdrawForCommit');
        $active->expects($this->never())->method('applyCommittedSuffix');

        $this->assertSame([], $this->appender($eventStore, $commandBus, $active)->appendMany([]));
        $this->assertSame([], $commandBus->messages);
    }

    public function testTransitionLockCoversCanonicalAppendAndProjectionPublication(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $competing = $factory->createLock('agent_loop.run.parent-1');
        $locks = new RunLockManager($factory);
        $eventStore = $this->createMock(EventStoreInterface::class);
        $persisted = $this->event('parent-1', 4);
        $eventStore->expects($this->once())->method('append')->willReturnCallback(function () use ($competing, $persisted): RunEvent {
            $this->assertFalse($competing->acquire(), 'A transition cannot interleave with the canonical append.');

            return $persisted;
        });
        $active = $this->createMock(ActiveRunContextInterface::class);
        $active->method('withdrawForCommit');
        $active->expects($this->once())->method('applyCommittedSuffix')->willReturnCallback(function () use ($competing): RunState {
            $this->assertFalse($competing->acquire(), 'The append lock must still cover projection publication.');

            return new RunState('parent-1', RunStatus::Running, lastSeq: 4, model: 'm');
        });

        $this->appender($eventStore, new TestMessageBus(), $active, locks: $locks)->append($this->event('parent-1', 0));
        try {
            $this->assertTrue($competing->acquire(), 'The transition lock must be released after publication.');
        } finally {
            $competing->release();
        }
    }

    public function testAppendFailureDoesNotInvalidate(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())->method('append')->willThrowException(new \RuntimeException('append failed'));
        $commandBus = new TestMessageBus();
        $active = $this->createMock(ActiveRunContextInterface::class);
        $active->method('withdrawForCommit');
        $active->expects($this->never())->method('applyCommittedSuffix');

        try {
            $this->appender($eventStore, $commandBus, $active)->append($this->event('parent-1', 0));
            $this->fail('Expected canonical append failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('append failed', $exception->getMessage());
        }

        $this->assertSame([], $commandBus->messages);
    }

    public function testInvalidationDispatchFailurePropagatesAfterCanonicalAppend(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())->method('append')->willReturn($this->event('parent-1', 4));
        $commandBus = $this->createMock(MessageBusInterface::class);
        $commandBus->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (object $message): bool => $message instanceof InvalidateRunContext && 'parent-1' === $message->runId()))
            ->willThrowException(new \RuntimeException('dispatch failed'));
        $active = $this->createMock(ActiveRunContextInterface::class);
        $active->method('withdrawForCommit');
        $active->expects($this->once())
            ->method('applyCommittedSuffix')
            ->willReturn(new RunState('parent-1', RunStatus::Running, lastSeq: 4, model: 'm'));

        $this->expectExceptionMessage('dispatch failed');
        $this->appender($eventStore, $commandBus, $active)->append($this->event('parent-1', 0));
    }

    private function appender(
        EventStoreInterface $eventStore,
        MessageBusInterface $commandBus,
        ActiveRunContextInterface $active,
        ?HistoryProjectionStoreInterface $history = null,
        ?RunLockManager $locks = null,
    ): CommittedRunEventAppender {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();

        return new CommittedRunEventAppender(
            $eventStore,
            $commandBus,
            $active,
            new RunStateReducer(
                AttributeSerializerValidatorTestFactory::denormalizer(),
                new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec($serializer),
            ),
            $locks ?? new RunLockManager(new LockFactory(new InMemoryStore())),
            $history,
        );
    }

    private function event(string $runId, int $seq): RunEvent
    {
        return new RunEvent($runId, $seq, 1, 'tool_execution_update', []);
    }
}
