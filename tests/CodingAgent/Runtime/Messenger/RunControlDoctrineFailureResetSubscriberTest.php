<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Messenger\RunControlDoctrineFailureResetSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class RunControlDoctrineFailureResetSubscriberTest extends TestCase
{
    #[Test]
    public function resetsConnectionAndManagerForRetryableRunControlFailure(): void
    {
        $calls = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('close')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'connection';
            });
        $manager = $this->createStub(ObjectManager::class);
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects($this->once())
            ->method('resetManager')
            ->willReturnCallback(static function () use (&$calls, $manager): ObjectManager {
                $calls[] = 'manager';

                return $manager;
            });

        $subscriber = new RunControlDoctrineFailureResetSubscriber($managerRegistry, $connection, new NullLogger());
        $event = $this->failedEvent('run_control');
        $event->setForRetry();
        $subscriber->onWorkerMessageFailed($event);

        $this->assertSame(['connection', 'manager'], $calls);
    }

    #[Test]
    public function ignoresFailuresFromOtherReceivers(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('close');
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects($this->never())->method('resetManager');

        $subscriber = new RunControlDoctrineFailureResetSubscriber($managerRegistry, $connection, new NullLogger());
        $subscriber->onWorkerMessageFailed($this->failedEvent('llm'));
    }

    #[Test]
    public function logsResetFailureWithoutInterruptingMessengerFailureHandling(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('close')->willThrowException(new \RuntimeException('close failed'));
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects($this->never())->method('resetManager');
        $logger = new TestLogger();

        $subscriber = new RunControlDoctrineFailureResetSubscriber($managerRegistry, $connection, $logger);
        $subscriber->onWorkerMessageFailed($this->failedEvent('run_control'));

        $errors = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'agent_loop.worker_failed_doctrine_reset_error' === $record['message'],
        ));
        $this->assertCount(1, $errors);
        $this->assertSame('close failed', $errors[0]['context']['exception']->getMessage());
    }

    #[Test]
    public function runsAfterMessengerRetryDecisionAndBeforeTerminalSubscriber(): void
    {
        $events = RunControlDoctrineFailureResetSubscriber::getSubscribedEvents();

        $this->assertSame(['onWorkerMessageFailed', 50], $events[WorkerMessageFailedEvent::class]);
    }

    private function failedEvent(string $receiver): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            $receiver,
            new \RuntimeException('handler failed'),
        );
    }
}
