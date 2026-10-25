<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Retry;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\AmpRetryClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AmpRetryClockTest extends TestCase
{
    public function testTimeAndTimezoneFollowTheInjectedFrameworkClock(): void
    {
        $time = new MockClock('2026-10-24T12:00:00+00:00');
        $clock = new AmpRetryClock($time);
        $this->assertEquals($time->now(), $clock->now());
        $zoned = $clock->withTimeZone('Europe/Paris');
        $this->assertSame('Europe/Paris', $zoned->now()->getTimezone()->getName());
        $this->assertSame($clock->now()->getTimestamp(), $zoned->now()->getTimestamp());
        $this->assertSame('+00:00', $clock->now()->format('P'));
    }
}
