<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * Per-agent MCP tool access mode.
 *
 * Controls which MCP tools are visible to an agent run:
 *  - none:     No MCP tools and no MCP discovery tools.
 *  - specific: Only explicitly listed MCP tools are available.
 *  - all:      All MCP tools visible to the current run's MCP catalog.
 */
enum McpAgentModeEnum: string
{
    case None = 'none';
    case Specific = 'specific';
    case All = 'all';
}
