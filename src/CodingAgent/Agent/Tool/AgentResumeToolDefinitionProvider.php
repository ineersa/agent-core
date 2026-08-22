<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;

/**
 * Lightweight permanent-tool provider for `agent_resume`.
 */
final class AgentResumeToolDefinitionProvider implements HatfieldToolProviderInterface
{
    public function __construct(
        private readonly AgentsConfig $agentsConfig,
        private readonly AgentResumeToolHandler $handler,
    ) {
    }

    public function definition(): ToolDefinitionDTO
    {
        return AgentResumeToolDefinitionBuilder::build($this->agentsConfig, $this->handler);
    }
}
