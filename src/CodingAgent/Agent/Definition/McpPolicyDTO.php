<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * Per-agent MCP access policy.
 *
 * Determines which MCP tools are available to an agent run.
 * Immutable value object assembled during definition parsing.
 */
final readonly class McpPolicyDTO
{
    /**
     * @param McpAgentModeEnum $mode  MCP access mode
     * @param list<string>     $tools Allowed MCP tool names (only meaningful when mode is Specific)
     */
    public function __construct(
        public McpAgentModeEnum $mode = McpAgentModeEnum::None,
        public array $tools = [],
    ) {
    }
}
