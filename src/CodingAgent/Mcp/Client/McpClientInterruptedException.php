<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

/**
 * A caller cancellation or deadline for an MCP tool call.
 *
 * The connection may still be usable after interruption. When the SDK reports
 * the client is no longer connected, the connection manager evicts it before
 * rethrowing this exception.
 */
final class McpClientInterruptedException extends McpClientInvocationException
{
}
