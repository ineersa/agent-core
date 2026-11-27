<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ConversationHistoryConversion;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ConversionCacheIdleSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

final class ConversionCacheIdleSubscriberTest extends TestCase
{
    public function testIdleEventEvictsExpiredCacheButBusyEventDoesNot(): void
    {
        $clock = new MockClock();
        $converter = new AgentMessageConverter(historyConversion: new ConversationHistoryConversion(), clock: $clock);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConversionCacheIdleSubscriber($converter));
        $worker = new Worker([], $this->createStub(MessageBusInterface::class));
        $messages = [new AgentMessage('user', [['type' => 'text', 'text' => 'context']])];
        $bag = $converter->toMessageBagForTarget($messages, 'target');
        $clock->sleep(60);
        $dispatcher->dispatch(new WorkerRunningEvent($worker, false));
        $this->assertSame($bag, $converter->toMessageBagForTarget($messages, 'target'));
        $clock->sleep(59);
        $dispatcher->dispatch(new WorkerRunningEvent($worker, true));
        $this->assertSame($bag, $converter->toMessageBagForTarget($messages, 'target'));
        $clock->sleep(60);
        $dispatcher->dispatch(new WorkerRunningEvent($worker, true));
        $this->assertNotSame($bag, $converter->toMessageBagForTarget($messages, 'target'));
    }
}
