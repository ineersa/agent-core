<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolDefinition;

/**
 * Resolves the available tool catalog from execution context.
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
