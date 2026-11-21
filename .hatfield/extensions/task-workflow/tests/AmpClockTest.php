<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Clock\AmpClock;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Clock\MockClock;

final class AmpClockTest extends TestCase
{
    public function testSleepYieldsToTheEventLoop(): void
    {
        $ran = false;
        EventLoop::queue(static function () use (&$ran): void {
            $ran = true;
        });

        (new AmpClock())->sleep(0);

        $this->assertTrue($ran);
    }

    public function testDelegatesTimeAndTimezoneWithoutMutatingTheOriginalClock(): void
    {
        $source = new MockClock('2026-01-01T00:00:00Z');
        $clock = new AmpClock($source);

        $this->assertEquals($source->now(), $clock->now());
        $this->assertSame('America/New_York', $clock->withTimeZone('America/New_York')->now()->getTimezone()->getName());
        $this->assertSame('Z', $clock->now()->getTimezone()->getName());
    }
}
