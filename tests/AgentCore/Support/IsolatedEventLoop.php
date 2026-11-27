<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\DriverFactory;

/**
 * Gives each test a fresh Revolt driver so callbacks queued by other tests on
 * the global loop cannot run inside it, and restores the previous driver after.
 *
 * Revolt refuses to swap a driver whose loop Fiber is suspended, which is the
 * state an await from {main} leaves behind. Such a loop is resumed until it
 * has no referenced callbacks left, exactly as the next EventLoop::run() would.
 */
trait IsolatedEventLoop
{
    private ?Driver $previousEventLoopDriver = null;

    #[Before]
    public function installIsolatedEventLoop(): void
    {
        self::finishSuspendedEventLoop();
        $this->previousEventLoopDriver = EventLoop::getDriver();
        EventLoop::setDriver((new DriverFactory())->create());
    }

    #[After]
    public function restorePreviousEventLoop(): void
    {
        if (null !== $this->previousEventLoopDriver) {
            self::finishSuspendedEventLoop();
            EventLoop::setDriver($this->previousEventLoopDriver);
            $this->previousEventLoopDriver = null;
        }
    }

    private static function finishSuspendedEventLoop(): void
    {
        if (EventLoop::getDriver()->isRunning()) {
            EventLoop::run();
        }
    }
}
