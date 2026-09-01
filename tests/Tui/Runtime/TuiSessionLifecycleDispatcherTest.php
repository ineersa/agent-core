<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TuiSessionLifecycleDispatcher::class)]
final class TuiSessionLifecycleDispatcherTest extends TestCase
{
    #[Test]
    public function testDispatchPassesEventTypeToSubscribersInRegistrationOrder(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $received = [];
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventTypeEnum $eventType) use (&$received): void {
            $received[] = ['first', $eventType];
        });
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventTypeEnum $eventType) use (&$received): void {
            $received[] = ['second', $eventType];
        });

        $dispatcher->dispatch(TuiSessionLifecycleEventTypeEnum::SessionResumed);

        $this->assertSame([
            ['first', TuiSessionLifecycleEventTypeEnum::SessionResumed],
            ['second', TuiSessionLifecycleEventTypeEnum::SessionResumed],
        ], $received);
    }

    #[Test]
    public function testDispatchStopsAtFirstSubscriberException(): void
    {
        $dispatcher = new TuiSessionLifecycleDispatcher();

        $dispatcher->subscribe(static function (TuiSessionLifecycleEventTypeEnum $eventType): void {
            throw new \RuntimeException('First subscriber error');
        });
        $dispatcher->subscribe(static function (TuiSessionLifecycleEventTypeEnum $eventType): void {
            self::fail('Second subscriber must NOT be reached after first throw.');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('First subscriber error');

        $dispatcher->dispatch(TuiSessionLifecycleEventTypeEnum::SessionStarted);
    }
}
