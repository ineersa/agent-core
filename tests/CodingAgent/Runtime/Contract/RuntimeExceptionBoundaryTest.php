<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Contract;

use Ineersa\CodingAgent\EventListener\RuntimeExceptionPolicySubscriber;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeErrorCaptureConfig;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

class RuntimeExceptionBoundaryTest extends TestCase
{
    #[Test]
    public function captureDisabledRethrowsOriginalExceptionThroughPolicySubscriber(): void
    {
        $boundary = $this->createBoundary(captureErrors: false);

        $original = new \RuntimeException('test error');

        try {
            $boundary->catch($original, 'test.operation');
            self::fail('Expected exception was not rethrown');
        } catch (\Throwable $caught) {
            self::assertSame($original, $caught);
        }
    }

    #[Test]
    public function captureEnabledReturnsNormally(): void
    {
        $boundary = $this->createBoundary(captureErrors: true);

        // Should not throw.
        $boundary->catch(new \RuntimeException('test error'), 'test.operation');
        self::assertTrue(true);
    }

    #[Test]
    public function captureEnabledDispatchesEvent(): void
    {
        $dispatched = [];
        $dispatcher = $this->createDispatcher(captureErrors: true);
        $dispatcher->addListener(RuntimeExceptionEvent::class, static function (RuntimeExceptionEvent $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $boundary = new RuntimeExceptionBoundary($dispatcher);

        $exception = new \RuntimeException('dispatch test');
        $boundary->catch($exception, 'test.operation', ['key' => 'value']);

        self::assertCount(1, $dispatched);
        self::assertSame($exception, $dispatched[0]->exception);
        self::assertSame('test.operation', $dispatched[0]->operation);
        self::assertNull($dispatched[0]->runId);
        self::assertSame(['key' => 'value'], $dispatched[0]->context);
    }

    #[Test]
    public function captureEnabledExtractsRunIdFromContext(): void
    {
        $dispatched = [];
        $dispatcher = $this->createDispatcher(captureErrors: true);
        $dispatcher->addListener(RuntimeExceptionEvent::class, static function (RuntimeExceptionEvent $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $boundary = new RuntimeExceptionBoundary($dispatcher);

        $boundary->catch(new \RuntimeException('run error'), 'test.run_failed', ['run_id' => 'run-123']);

        self::assertCount(1, $dispatched);
        self::assertSame('run-123', $dispatched[0]->runId);
    }

    private function createBoundary(bool $captureErrors): RuntimeExceptionBoundary
    {
        return new RuntimeExceptionBoundary($this->createDispatcher($captureErrors));
    }

    private function createDispatcher(bool $captureErrors): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new RuntimeExceptionPolicySubscriber(
            new RuntimeErrorCaptureConfig(captureErrors: $captureErrors),
            new NullLogger(),
        );
        $dispatcher->addSubscriber($subscriber);

        return $dispatcher;
    }
}
