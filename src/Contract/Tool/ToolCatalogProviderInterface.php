<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolDefinition;

interface ToolCatalogProviderInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return list<ToolDefinition>
     */
    public function resolveToolCatalog(array $context = []): array;
}
