<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

/**
 * Sizes the session broker connection budget from the live consumer topology.
 *
 * Idle sqlite-queue consumers open one operations socket and one notification
 * socket each. Send-only publishers open the operations socket only. The
 * controller publishes commands onto run_control. run_control publishes
 * Execute* work to the five execution transports. Each execution worker
 * publishes results back to run_control. scheduler_default is not a
 * sqlite-queue receiver and does not consume broker connections.
 */
final class SessionSqliteQueueBrokerTopology
{
    /** run_control, agent, mcp, and extension_agent — one consumer each. */
    public const int FIXED_BROKER_CONSUMERS = 4;

    /** Operations and notification sockets owned by one idle consumer transport. */
    public const int CONNECTIONS_PER_IDLE_CONSUMER = 2;

    /** llm, tool, agent, mcp, extension_agent — outbound from run_control. */
    public const int RUN_CONTROL_OUTBOUND_TRANSPORTS = 5;

    /** Controller process publishes onto run_control. */
    public const int CONTROLLER_PUBLISHER_TRANSPORTS = 1;

    /** agent, mcp, and extension_agent result publishers besides llm/tool pools. */
    public const int FIXED_EXECUTION_RESULT_PUBLISHERS = 3;

    private function __construct()
    {
    }

    /**
     * Minimum broker connections required before runtime.ready for the resolved pools.
     *
     * Example: 32 tool + 4 llm + 4 other consumers need 80 idle connections, then
     * publishers add controller, run_control outbound, and execution-result sockets.
     */
    public static function requiredConnections(int $llmWorkers, int $toolWorkers): int
    {
        if ($llmWorkers < 1) {
            throw new \InvalidArgumentException('LLM worker count must be a positive integer.');
        }
        if ($toolWorkers < 1) {
            throw new \InvalidArgumentException('Tool worker count must be a positive integer.');
        }

        $brokerConsumers = $llmWorkers + $toolWorkers + self::FIXED_BROKER_CONSUMERS;
        $idleConsumerConnections = self::CONNECTIONS_PER_IDLE_CONSUMER * $brokerConsumers;

        $executionResultPublishers = $llmWorkers + $toolWorkers + self::FIXED_EXECUTION_RESULT_PUBLISHERS;
        $publisherConnections = $executionResultPublishers
            + self::RUN_CONTROL_OUTBOUND_TRANSPORTS
            + self::CONTROLLER_PUBLISHER_TRANSPORTS;

        return $idleConsumerConnections + $publisherConnections;
    }
}
