<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeErrorCaptureConfig;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class RuntimeExceptionBoundaryTest extends TestCase
{
    #[Test]
    public function captureDisabledRethrowsOriginalException(): void
    {
        $boundary = $this->createBoundary(captureErrors: false);

        $original = new \RuntimeException('test error');

        try {
            $boundary->catch($original, 'test.operation');
            $this->fail('Expected exception was not rethrown');
        } catch (\Throwable $caught) {
            $this->assertSame($original, $caught);
        }
    }

    #[Test]
    public function captureEnabledReturnsNormally(): void
    {
        $boundary = $this->createBoundary(captureErrors: true);

        // Should not throw.
        $boundary->catch(new \RuntimeException('test error'), 'test.operation');
        $this->assertTrue(true);
    }

    #[Test]
    public function captureEnabledDispatchesEvent(): void
    {
        $dispatched = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(RuntimeExceptionEvent::class, static function (RuntimeExceptionEvent $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $boundary = new RuntimeExceptionBoundary(
            $dispatcher,
            new RuntimeErrorCaptureConfig(captureErrors: true),
        );

        $exception = new \RuntimeException('dispatch test');
        $boundary->catch($exception, 'test.operation', ['key' => 'value']);

        $this->assertCount(1, $dispatched);
        $this->assertSame($exception, $dispatched[0]->exception);
        $this->assertSame('test.operation', $dispatched[0]->operation);
        $this->assertNull($dispatched[0]->runId);
        $this->assertSame(['key' => 'value'], $dispatched[0]->context);
    }

    #[Test]
    public function captureEnabledExtractsRunIdFromContext(): void
    {
        $dispatched = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(RuntimeExceptionEvent::class, static function (RuntimeExceptionEvent $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $boundary = new RuntimeExceptionBoundary(
            $dispatcher,
            new RuntimeErrorCaptureConfig(captureErrors: true),
        );

        $boundary->catch(new \RuntimeException('run error'), 'test.run_failed', ['run_id' => 'run-123']);

        $this->assertCount(1, $dispatched);
        $this->assertSame('run-123', $dispatched[0]->runId);
    }

    private function createBoundary(bool $captureErrors): RuntimeExceptionBoundary
    {
        return new RuntimeExceptionBoundary(
            $this->createMock(EventDispatcherInterface::class),
            new RuntimeErrorCaptureConfig(captureErrors: $captureErrors),
        );
    }
}
