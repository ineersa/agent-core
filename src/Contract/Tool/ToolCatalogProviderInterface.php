<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolCatalogContext;
use Ineersa\AgentCore\Domain\Tool\ToolDefinition;

interface ToolCatalogProviderInterface
{
    /**
     * Resolves and returns the tool catalog based on the provided context.
     *
     * @return list<ToolDefinition>
     */
    public function resolveToolCatalog(ToolCatalogContext $context): array;
}
