<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

/** A caller cancellation or deadline that leaves the MCP connection usable. */
final class McpClientInterruptedException extends McpClientInvocationException
{
}
