<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\InvalidateRunContext;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class CommittedRunEventAppenderTest extends TestCase
{
    public function testAppendPersistsThenInvalidatesItsRunContext(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $persisted = $this->event('parent-1', 4);
        $eventStore->expects($this->once())->method('append')->willReturn($persisted);
        $commandBus = new TestMessageBus();

        $result = (new CommittedRunEventAppender($eventStore, $commandBus))->append($this->event('parent-1', 0));

        $this->assertSame($persisted, $result);
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

        $result = (new CommittedRunEventAppender($eventStore, $commandBus))->appendMany([
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

        $this->assertSame([], (new CommittedRunEventAppender($eventStore, $commandBus))->appendMany([]));
        $this->assertSame([], $commandBus->messages);
    }

    public function testAppendFailureDoesNotInvalidate(): void
    {
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())->method('append')->willThrowException(new \RuntimeException('append failed'));
        $commandBus = new TestMessageBus();

        try {
            (new CommittedRunEventAppender($eventStore, $commandBus))->append($this->event('parent-1', 0));
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

        $this->expectExceptionMessage('dispatch failed');
        (new CommittedRunEventAppender($eventStore, $commandBus))->append($this->event('parent-1', 0));
    }

    private function event(string $runId, int $seq): RunEvent
    {
        return new RunEvent($runId, $seq, 1, 'tool_execution_update', []);
    }
}
