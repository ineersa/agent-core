<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerTopology;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerTopology
 */
final class SessionSqliteQueueBrokerTopologyTest extends TestCase
{
    public function testRequiredConnectionsCountsEveryBrokerTransportOwner(): void
    {
        // 32 tool + 4 llm + 3 fixed broker consumers = 39 processes × (6 ops + 1 notify).
        // Controller + scheduler_default = 2 processes × 6 ops for inherited DSNs/hooks.
        // Tool/controller role replies add two publishers and two operations/WAIT pairs.
        $this->assertSame(291, SessionSqliteQueueBrokerTopology::requiredConnections(4, 32));
        $this->assertSame(
            (39 * SessionSqliteQueueBrokerTopology::CONNECTIONS_PER_BROKER_CONSUMER_PROCESS)
            + (SessionSqliteQueueBrokerTopology::NON_CONSUMER_BROKER_PROCESSES * SessionSqliteQueueBrokerTopology::CONNECTIONS_PER_NON_CONSUMER_PROCESS)
            + SessionSqliteQueueBrokerTopology::BASH_REGISTRATION_REPLY_CONNECTIONS,
            SessionSqliteQueueBrokerTopology::requiredConnections(4, 32),
        );
        $this->assertSame(95, SessionSqliteQueueBrokerTopology::requiredConnections(4, 4));
        $this->assertSame(53, SessionSqliteQueueBrokerTopology::requiredConnections(1, 1));
    }

    public function testRequiredConnectionsRejectsNonPositivePools(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SessionSqliteQueueBrokerTopology::requiredConnections(0, 1);
    }

    public function testRequiredConnectionsRejectsIntegerOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('overflows');
        SessionSqliteQueueBrokerTopology::requiredConnections(1, \PHP_INT_MAX);
    }
}
