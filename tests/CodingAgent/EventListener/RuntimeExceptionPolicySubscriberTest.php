<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\EventListener;

use Ineersa\CodingAgent\EventListener\RuntimeExceptionPolicySubscriber;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RuntimeExceptionPolicySubscriberTest extends TestCase
{
    #[Test]
    public function onRuntimeExceptionLogsStructuredError(): void
    {
        $exception = new \RuntimeException('test error message');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo('Runtime exception at boundary'),
                $this->callback(static fn (array $ctx): bool => 'test.operation' === ($ctx['operation'] ?? null)
                    && 'run-abc' === ($ctx['run_id'] ?? null)
                    && $ctx['exception'] instanceof \RuntimeException
                    && 'test error message' === $ctx['exception']->getMessage()
                    && ['extra' => 'data'] === ($ctx['context'] ?? null)),
            );

        $subscriber = new RuntimeExceptionPolicySubscriber($logger);
        $event = new RuntimeExceptionEvent(
            exception: $exception,
            operation: 'test.operation',
            runId: 'run-abc',
            context: ['extra' => 'data'],
        );

        $subscriber->onRuntimeException($event);
    }

    #[Test]
    public function getSubscribedEventsReturnsEventClass(): void
    {
        $events = RuntimeExceptionPolicySubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(RuntimeExceptionEvent::class, $events);
        $this->assertSame('onRuntimeException', $events[RuntimeExceptionEvent::class]);
    }
}
