<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Message;

use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Ineersa\CodingAgent\Mcp\Handler\McpInitializeSessionHandler;
use Ineersa\CodingAgent\Mcp\Messenger\McpWorkerShutdownSubscriber;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Lifecycle command: disconnect all MCP servers for a session.
 *
 * Handled by {@see McpInitializeSessionHandler::onDisconnectSession()}
 * which delegates to {@see McpConnectionManagerInterface::disconnectAll()}.
 *
 * Graceful shutdown is best-effort via {@see McpWorkerShutdownSubscriber}
 * which listens to {@see WorkerStoppedEvent} and calls
 * disconnectAll directly in-process — no extra Messenger dispatch is needed.
 *
 * Routed to the `mcp` Messenger transport.
 */
final readonly class McpDisconnectSessionCommand
{
    public function __construct(
        public string $runId,
        public string $correlationId = '',
    ) {
    }
}
