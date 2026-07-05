<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEndReasonEnum;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventDTO;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TuiSessionLifecycleDispatcher::class)]
final class TuiSessionLifecycleDispatcherTest extends TestCase
{
    #[Test]
    public function testDispatchCallsSingleSubscriber(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $called = false;
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventDTO $event) use (&$called): void {
            $called = true;
        });

        $dispatcher->dispatch($this->sessionStartedEvent());

        $this->assertTrue($called, 'Single subscriber must be called on dispatch.');
    }

    #[Test]
    public function testDispatchCallsMultipleSubscribersInOrder(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $order = [];
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventDTO $event) use (&$order): void {
            $order[] = 'first';
        });
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventDTO $event) use (&$order): void {
            $order[] = 'second';
        });
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventDTO $event) use (&$order): void {
            $order[] = 'third';
        });

        $dispatcher->dispatch($this->sessionStartedEvent());

        $this->assertSame(['first', 'second', 'third'], $order, 'Subscribers must be called in registration order.');
    }

    #[Test]
    public function testDispatchReceivesCorrectEventType(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $receivedType = null;
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventDTO $event) use (&$receivedType): void {
            $receivedType = $event->type;
        });

        $event = new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionResumed,
            sessionId: 'resumed-42',
            isDraft: false,
            resuming: true,
        );

        $dispatcher->dispatch($event);

        $this->assertSame(TuiSessionLifecycleEventTypeEnum::SessionResumed, $receivedType);
    }

    #[Test]
    public function testDispatchReceivesFullEventPayload(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $captured = null;
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventDTO $event) use (&$captured): void {
            $captured = $event;
        });

        $event = new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionEnded,
            sessionId: 'ending-session',
            isDraft: false,
            resuming: false,
            previousSessionId: 'previous-session',
            endReason: TuiSessionLifecycleEndReasonEnum::Switch,
        );

        $dispatcher->dispatch($event);

        $this->assertNotNull($captured);
        $this->assertSame(TuiSessionLifecycleEventTypeEnum::SessionEnded, $captured->type);
        $this->assertSame('ending-session', $captured->sessionId);
        $this->assertFalse($captured->isDraft);
        $this->assertFalse($captured->resuming);
        $this->assertSame('previous-session', $captured->previousSessionId);
        $this->assertSame(TuiSessionLifecycleEndReasonEnum::Switch, $captured->endReason);
    }

    #[Test]
    public function testDispatchWithNoSubscribersDoesNotThrow(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $dispatcher->dispatch($this->sessionStartedEvent());

        // No assertion needed — just must not throw.
        $this->assertTrue(true);
    }

    #[Test]
    public function testDispatchStopsAtFirstSubscriberException(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $dispatcher->subscribe(static function (TuiSessionLifecycleEventDTO $event): void {
            throw new \RuntimeException('First subscriber error');
        });
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventDTO $event): void {
            self::fail('Second subscriber must NOT be reached after first throw.');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('First subscriber error');

        $dispatcher->dispatch($this->sessionStartedEvent());
    }

    #[Test]
    public function testFreshDispatcherHasNoSubscribersFromPriorInstance(): void
    {
        $first = new TuiSessionLifecycleDispatcher();
        $first->subscribe(static function (TuiSessionLifecycleEventDTO $event): void {});

        $second = new TuiSessionLifecycleDispatcher();

        $secondCalled = false;
        $second->subscribe(static function (TuiSessionLifecycleEventDTO $event) use (&$secondCalled): void {
            $secondCalled = true;
        });

        $second->dispatch($this->sessionStartedEvent());

        $this->assertTrue($secondCalled, 'Fresh dispatcher must not inherit subscriptions from prior instance.');
    }

    #[Test]
    public function testDraftStartedEventCarriesCorrectFields(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $captured = null;
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventDTO $event) use (&$captured): void {
            $captured = $event;
        });

        $event = new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionDraftStarted,
            sessionId: '',
            isDraft: true,
            resuming: false,
        );

        $dispatcher->dispatch($event);

        $this->assertNotNull($captured);
        $this->assertSame(TuiSessionLifecycleEventTypeEnum::SessionDraftStarted, $captured->type);
        $this->assertSame('', $captured->sessionId);
        $this->assertTrue($captured->isDraft);
        $this->assertFalse($captured->resuming);
    }

    private function sessionStartedEvent(string $sessionId = 'test-session'): TuiSessionLifecycleEventDTO
    {
        return new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionStarted,
            sessionId: $sessionId,
            isDraft: false,
            resuming: false,
        );
    }
}
