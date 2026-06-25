<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Config;

/**
 * Controls whether an MCP server's tools are exposed by default (parent / omitted-tools child).
 *
 * - all: globally available — included when agent `tools` is omitted (with global MCP inheritance).
 * - specific: opt-in only via `mcp:` selectors in agent frontmatter `tools`.
 */
enum McpServerAvailabilityEnum: string
{
    case All = 'all';
    case Specific = 'specific';
}
