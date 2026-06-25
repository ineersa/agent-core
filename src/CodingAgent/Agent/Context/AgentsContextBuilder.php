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
     * Render enabled, foreground-launchable agent definitions for the parent model.
     *
     * Returns empty string when agent discovery is disabled or no agents qualify.
     */
    public function build(): string
    {
        if (!$this->agentsConfig->enabled) {
            return '';
        }

        $launchable = array_values(array_filter(
            $this->catalog->enabled(),
            static fn ($definition): bool => $definition->foregroundAllowed,
        ));

        return $this->renderer->renderAvailableAgents($launchable);
    }
}
