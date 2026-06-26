<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;

/**
 * Lightweight permanent-tool provider for `subagent`.
 *
 * Does not depend on SubagentExecutionService or the prompt/policy stack,
 * so ToolRegistry can seed definitions without a compile-time DI cycle.
 */
final class SubagentToolDefinitionProvider implements HatfieldToolProviderInterface
{
    public function __construct(
        private readonly AgentsConfig $agentsConfig,
        private readonly SubagentToolHandler $handler,
    ) {
    }

    public function definition(): ToolDefinitionDTO
    {
        return SubagentToolDefinitionBuilder::build($this->agentsConfig, $this->handler);
    }
}
