<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Message;

/**
 * Lifecycle command: refresh the MCP tool catalog for active servers.
 *
 * Skeleton for future Phase 3+ use. In Phase 1 the handler is a no-op
 * that only logs the event — real discovery/catalog persistence is
 * deferred to MCP-03 (connection discovery) and MCP-04 (dynamic tools).
 *
 * Routed to the `mcp` Messenger transport.
 */
final readonly class McpRefreshCatalogCommand
{
    public function __construct(
        public string $runId,
        public string $correlationId = '',
    ) {
    }
}
