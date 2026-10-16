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
    public function testRequiredConnectionsSizesIdleConsumersAndPublishers(): void
    {
        // 32 tool + 4 llm + 4 fixed broker consumers = 80 idle connections.
        // Publishers: 32+4+3 execution results + 5 run_control outbound + 1 controller.
        $this->assertSame(125, SessionSqliteQueueBrokerTopology::requiredConnections(4, 32));
        $this->assertSame(41, SessionSqliteQueueBrokerTopology::requiredConnections(4, 4));
        $this->assertSame(23, SessionSqliteQueueBrokerTopology::requiredConnections(1, 1));
    }

    public function testRequiredConnectionsRejectsNonPositivePools(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SessionSqliteQueueBrokerTopology::requiredConnections(0, 1);
    }
}
