<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\GrokAuthRecord;
use PHPUnit\Framework\TestCase;

final class GrokAuthRecordTest extends TestCase
{
    public function testFutureTimestampNotExpired(): void
    {
        $record = new GrokAuthRecord(
            access: 'tok',
            refresh: 'ref',
            expires: time() + 3600,
        );

        $this->assertFalse($record->isExpired());
    }

    public function testPastTimestampIsExpired(): void
    {
        $record = new GrokAuthRecord(
            access: 'tok',
            refresh: 'ref',
            expires: time() - 3600,
        );

        $this->assertTrue($record->isExpired());
    }

    public function testBufferMakesFutureRecordAppearExpired(): void
    {
        $record = new GrokAuthRecord(
            access: 'tok',
            refresh: 'ref',
            expires: time() + 30,
        );

        $this->assertTrue($record->isExpired(60));
        $this->assertFalse($record->isExpired(0));
    }

    public function testRoundTripSerialization(): void
    {
        $record = new GrokAuthRecord(
            access: 'access-token-123',
            refresh: 'refresh-token-456',
            expires: time() + 3600,
            type: 'oauth',
        );

        $data = $record->toArray();
        $restored = GrokAuthRecord::fromArray($data);

        $this->assertSame($record->access, $restored->access);
        $this->assertSame($record->refresh, $restored->refresh);
        $this->assertSame($record->expires, $restored->expires);
        $this->assertSame('oauth', $restored->type);
        $this->assertArrayNotHasKey('accountId', $data);
    }

    public function testFromArrayThrowsOnMissingFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required fields');

        GrokAuthRecord::fromArray(['type' => 'oauth', 'expires' => 1234567890]);
    }
}
