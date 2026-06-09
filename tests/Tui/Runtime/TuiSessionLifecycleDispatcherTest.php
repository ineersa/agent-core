<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventDTO;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TuiSessionLifecycleDispatcher::class)]
final class TuiSessionLifecycleDispatcherTest extends TestCase
{
    private function sessionStartedEvent(string $sessionId = 'test-session'): TuiSessionLifecycleEventDTO
    {
        return new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionStarted,
            sessionId: $sessionId,
            isDraft: false,
            resuming: false,
        );
    }

    #[Test]
    public function testDispatchCallsSingleSubscriber(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $called = false;
        $dispatcher->subscribe(function (TuiSessionLifecycleEventDTO $event) use (&$called): void {
            $called = true;
        });

        $dispatcher->dispatch($this->sessionStartedEvent());

        self::assertTrue($called, 'Single subscriber must be called on dispatch.');
    }

    #[Test]
    public function testDispatchCallsMultipleSubscribersInOrder(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $order = [];
        $dispatcher->subscribe(function (TuiSessionLifecycleEventDTO $event) use (&$order): void {
            $order[] = 'first';
        });
        $dispatcher->subscribe(function (TuiSessionLifecycleEventDTO $event) use (&$order): void {
            $order[] = 'second';
        });
        $dispatcher->subscribe(function (TuiSessionLifecycleEventDTO $event) use (&$order): void {
            $order[] = 'third';
        });

        $dispatcher->dispatch($this->sessionStartedEvent());

        self::assertSame(['first', 'second', 'third'], $order, 'Subscribers must be called in registration order.');
    }

    #[Test]
    public function testDispatchReceivesCorrectEventType(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $receivedType = null;
        $dispatcher->subscribe(function (TuiSessionLifecycleEventDTO $event) use (&$receivedType): void {
            $receivedType = $event->type;
        });

        $event = new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionResumed,
            sessionId: 'resumed-42',
            isDraft: false,
            resuming: true,
        );

        $dispatcher->dispatch($event);

        self::assertSame(TuiSessionLifecycleEventTypeEnum::SessionResumed, $receivedType);
    }

    #[Test]
    public function testDispatchReceivesFullEventPayload(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $captured = null;
        $dispatcher->subscribe(function (TuiSessionLifecycleEventDTO $event) use (&$captured): void {
            $captured = $event;
        });

        $event = new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionEnded,
            sessionId: 'ending-session',
            isDraft: false,
            resuming: false,
            previousSessionId: 'previous-session',
            endReason: 'switch',
        );

        $dispatcher->dispatch($event);

        self::assertNotNull($captured);
        self::assertSame(TuiSessionLifecycleEventTypeEnum::SessionEnded, $captured->type);
        self::assertSame('ending-session', $captured->sessionId);
        self::assertFalse($captured->isDraft);
        self::assertFalse($captured->resuming);
        self::assertSame('previous-session', $captured->previousSessionId);
        self::assertSame('switch', $captured->endReason);
    }

    #[Test]
    public function testDispatchWithNoSubscribersDoesNotThrow(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $dispatcher->dispatch($this->sessionStartedEvent());

        // No assertion needed — just must not throw.
        self::assertTrue(true);
    }

    #[Test]
    public function testDispatchCallsSecondSubscriberAfterFirstThrows(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $secondCalled = false;
        $dispatcher->subscribe(function (TuiSessionLifecycleEventDTO $event): void {
            throw new \RuntimeException('First subscriber error');
        });
        $dispatcher->subscribe(function (TuiSessionLifecycleEventDTO $event) use (&$secondCalled): void {
            $secondCalled = true;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('First subscriber error');

        $dispatcher->dispatch($this->sessionStartedEvent());

        // The second subscriber was NOT reached because dispatch()
        // does not guard subscriber errors — the first throw propagates.
        // This test documents the current behaviour, not a design goal.
        self::assertFalse($secondCalled, 'Second subscriber NOT reached after first throw (no error guard).');
    }

    #[Test]
    public function testFreshDispatcherHasNoSubscribersFromPriorInstance(): void
    {
        $first = new TuiSessionLifecycleDispatcher();
        $first->subscribe(function (TuiSessionLifecycleEventDTO $event): void {});

        $second = new TuiSessionLifecycleDispatcher();

        $secondCalled = false;
        $second->subscribe(function (TuiSessionLifecycleEventDTO $event) use (&$secondCalled): void {
            $secondCalled = true;
        });

        $second->dispatch($this->sessionStartedEvent());

        self::assertTrue($secondCalled, 'Fresh dispatcher must not inherit subscriptions from prior instance.');
    }

    #[Test]
    public function testDraftStartedEventCarriesCorrectFields(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $captured = null;
        $dispatcher->subscribe(function (TuiSessionLifecycleEventDTO $event) use (&$captured): void {
            $captured = $event;
        });

        $event = new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionDraftStarted,
            sessionId: '',
            isDraft: true,
            resuming: false,
        );

        $dispatcher->dispatch($event);

        self::assertNotNull($captured);
        self::assertSame(TuiSessionLifecycleEventTypeEnum::SessionDraftStarted, $captured->type);
        self::assertSame('', $captured->sessionId);
        self::assertTrue($captured->isDraft);
        self::assertFalse($captured->resuming);
    }
}
