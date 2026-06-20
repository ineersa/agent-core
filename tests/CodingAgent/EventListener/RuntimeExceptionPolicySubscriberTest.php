<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\EventListener;

use Ineersa\CodingAgent\EventListener\RuntimeExceptionPolicySubscriber;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeErrorCaptureConfig;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RuntimeExceptionPolicySubscriberTest extends TestCase
{
    #[Test]
    public function onRuntimeExceptionRethrowsOriginalExceptionWhenCaptureDisabled(): void
    {
        $exception = new \RuntimeException('test error message');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $subscriber = new RuntimeExceptionPolicySubscriber(
            new RuntimeErrorCaptureConfig(captureErrors: false),
            $logger,
        );

        $this->expectExceptionObject($exception);

        $subscriber->onRuntimeException(new RuntimeExceptionEvent(
            exception: $exception,
            operation: 'test.operation',
        ));
    }

    #[Test]
    public function onRuntimeExceptionLogsStructuredErrorWhenCaptureEnabled(): void
    {
        $exception = new \RuntimeException('test error message');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::equalTo('Runtime exception at boundary'),
                self::callback(static fn (array $ctx): bool => 'test.operation' === ($ctx['operation'] ?? null)
                    && 'run-abc' === ($ctx['run_id'] ?? null)
                    && $ctx['exception'] instanceof \RuntimeException
                    && 'test error message' === $ctx['exception']->getMessage()
                    && ['extra' => 'data'] === ($ctx['context'] ?? null)),
            );

        $subscriber = new RuntimeExceptionPolicySubscriber(
            new RuntimeErrorCaptureConfig(captureErrors: true),
            $logger,
        );
        $event = new RuntimeExceptionEvent(
            exception: $exception,
            operation: 'test.operation',
            runId: 'run-abc',
            context: ['extra' => 'data'],
        );

        $subscriber->onRuntimeException($event);
    }

    #[Test]
    public function getSubscribedEventsReturnsEventClassWithHighPriority(): void
    {
        $events = RuntimeExceptionPolicySubscriber::getSubscribedEvents();

        self::assertArrayHasKey(RuntimeExceptionEvent::class, $events);
        self::assertSame(['onRuntimeException', 1024], $events[RuntimeExceptionEvent::class]);
    }
}
