<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Config;

/**
 * MCP server transport type.
 *
 * Describes how the Hatfield MCP client communicates with an external MCP server.
 * Used by typed config models for validation and SDK client factory routing.
 */
enum McpTransportTypeEnum: string
{
    case STDIO = 'stdio';
    case HTTP = 'http';
}
