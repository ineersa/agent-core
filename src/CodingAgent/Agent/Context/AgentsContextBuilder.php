<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Context;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Config\AgentsConfig;

/**
 * Builds the available-agents user-context block for parent runs.
 */
final readonly class AgentsContextBuilder
{
    public function __construct(
        private AgentDefinitionCatalog $catalog,
        private AgentsConfig $agentsConfig,
        private AgentContextRenderer $renderer,
    ) {
    }

    /**
     * Render enabled agent definitions for the parent model.
     *
     * Returns empty string when agent discovery is disabled or no agents qualify.
     * Enabled definitions are foreground-launchable; disabled definitions are excluded.
     */
    public function build(): string
    {
        if (!$this->agentsConfig->enabled) {
            return '';
        }

        return $this->renderer->renderAvailableAgents($this->catalog->enabled());
    }
}
