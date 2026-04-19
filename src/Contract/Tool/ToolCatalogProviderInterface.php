<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolDefinition;

/**
 * Defines the contract for resolving tool catalogs within the AgentCore framework. Implementations provide a mechanism to retrieve available tools based on a provided context array. This interface abstracts the source of tool definitions to allow for flexible catalog resolution strategies.
 */
interface ToolCatalogProviderInterface
{
    /**
     * Resolves and returns the tool catalog based on the provided context.
     *
     * @param array<string, mixed> $context
     *
     * @return list<ToolDefinition>
     */
    public function resolveToolCatalog(array $context = []): array;
}
