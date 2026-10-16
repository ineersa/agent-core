<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

/**
 * Sizes the session broker connection budget from the live process topology.
 *
 * Every controller-owned process that inherits the six sqlite-queue Messenger
 * DSNs can open one operations socket per transport. Broker consumers also keep
 * one notification socket for the transport they consume. scheduler_default is
 * not a sqlite-queue receiver, but it inherits the same DSNs and may open
 * operations sockets for hooks or extensions. The budget therefore covers every
 * configured broker transport in each resolved process, not only the default
 * send routes observed in the happy path.
 */
final class SessionSqliteQueueBrokerTopology
{
    /**
     * Configured sqlite-queue Messenger transports:
     * run_control, llm, tool, agent, mcp, extension_agent.
     */
    public const int BROKER_TRANSPORTS = 6;

    /** run_control, agent, mcp, and extension_agent — one consumer each. */
    public const int FIXED_BROKER_CONSUMERS = 4;

    /** Notification socket owned by one idle broker consumer transport. */
    public const int CONSUMER_NOTIFICATION_CONNECTIONS = 1;

    /**
     * Upper bound for one broker consumer process: operations for every
     * configured broker transport plus the consumer notification socket.
     */
    public const int CONNECTIONS_PER_BROKER_CONSUMER_PROCESS = self::BROKER_TRANSPORTS + self::CONSUMER_NOTIFICATION_CONNECTIONS;

    /**
     * Controller and scheduler_default inherit broker DSNs. They do not consume
     * sqlite-queue receivers, so they need only operations sockets.
     */
    public const int NON_CONSUMER_BROKER_PROCESSES = 2;

    public const int CONNECTIONS_PER_NON_CONSUMER_PROCESS = self::BROKER_TRANSPORTS;

    private function __construct()
    {
    }

    /**
     * Minimum broker connections required before runtime.ready for the resolved pools.
     *
     * Example: 32 tool + 4 llm + 4 fixed broker consumers = 40 consumer processes
     * × 7 sockets, plus controller and scheduler × 6 operations sockets each.
     */
    public static function requiredConnections(int $llmWorkers, int $toolWorkers): int
    {
        if ($llmWorkers < 1) {
            throw new \InvalidArgumentException('LLM worker count must be a positive integer.');
        }
        if ($toolWorkers < 1) {
            throw new \InvalidArgumentException('Tool worker count must be a positive integer.');
        }

        $consumerProcesses = $llmWorkers + $toolWorkers + self::FIXED_BROKER_CONSUMERS;
        $nonConsumerConnections = self::NON_CONSUMER_BROKER_PROCESSES * self::CONNECTIONS_PER_NON_CONSUMER_PROCESS;
        $maxConsumerProcesses = intdiv(\PHP_INT_MAX - $nonConsumerConnections, self::CONNECTIONS_PER_BROKER_CONSUMER_PROCESS);
        if ($consumerProcesses > $maxConsumerProcesses) {
            throw new \InvalidArgumentException(\sprintf(
                'Broker connection budget overflows for llmWorkers=%d toolWorkers=%d.',
                $llmWorkers,
                $toolWorkers,
            ));
        }

        return ($consumerProcesses * self::CONNECTIONS_PER_BROKER_CONSUMER_PROCESS) + $nonConsumerConnections;
    }
}
