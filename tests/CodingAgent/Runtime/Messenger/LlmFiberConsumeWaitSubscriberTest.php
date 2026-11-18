<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeWaitSubscriber;
use Ineersa\SqliteQueue\Exception\TransportException as ClientTransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Worker;

final class LlmFiberConsumeWaitSubscriberTest extends TestCase
{
    #[DataProvider('waitFailures')]
    public function testIdleWaitFailure(\Throwable $failure, bool $requestStop, bool $handled): void
    {
        $logger = new TestLogger();
        $subscriber = new LlmFiberConsumeWaitSubscriber(new ServiceLocator([]), $logger, 'test-session');
        $worker = new Worker([], new TestMessageBus());
        $stop = new DeferredCancellation();

        // Inject at the wait boundary so even a direct Amp exception can reach the subscriber.
        (new \ReflectionProperty($subscriber, 'stopsByWorker'))->getValue($subscriber)[$worker] = $stop;
        (new \ReflectionProperty($subscriber, 'waitsByWorker'))->getValue($subscriber)[$worker] = static function (int $budget, Cancellation $cancellation) use ($failure, $requestStop, $stop): never {
            self::assertSame($stop->getCancellation(), $cancellation);
            if ($requestStop) {
                $stop->cancel();
            }

            throw $failure;
        };

        if (!$handled && $failure instanceof ClientTransportException) {
            $this->expectExceptionObject(new TransportException('Queue wait failed.', 0, $failure));
        } elseif (!$handled) {
            $this->expectExceptionObject($failure);
        }

        try {
            $subscriber->onWorkerRunning(new WorkerRunningEvent($worker, true));
        } finally {
            if ($handled) {
                $this->assertCount(1, $logger->records);
                $this->assertSame('debug', $logger->records[0]['level']);
                $this->assertSame('llm_fiber_consume.queue_wait_cancelled', $logger->records[0]['context']['event_type']);
                $this->assertSame('test-session', $logger->records[0]['context']['session_id']);
            } else {
                $this->assertSame([], $logger->records);
            }
        }
    }

    /** @return iterable<string, array{\Throwable, bool, bool}> */
    public static function waitFailures(): iterable
    {
        $direct = new CancelledException();
        $single = new TransportException('Wait cancelled', 0, $direct);
        $double = new TransportException('Could not wait', 0, new ClientTransportException('Broker exchange cancelled', 0, $direct));
        $failure = new TransportException('Broker lost');

        yield 'direct stop cancellation' => [$direct, true, true];
        yield 'single wrapped stop cancellation' => [$single, true, true];
        yield 'double wrapped stop cancellation' => [$double, true, true];
        yield 'double wrapped unrelated cancellation' => [$double, false, false];
        yield 'direct unrelated cancellation' => [$direct, false, false];
        yield 'transport failure' => [$failure, false, false];
        yield 'transport failure after stop' => [$failure, true, false];
        yield 'client failure after stop' => [new ClientTransportException('Broker lost'), true, false];
    }
}
