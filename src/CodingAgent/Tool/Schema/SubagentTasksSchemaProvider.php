<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Schema;

use Ineersa\CodingAgent\Config\AgentsConfig;
use Symfony\AI\Platform\Contract\JsonSchema\Provider\SchemaProviderInterface;

/**
 * Runtime fragment for the subagent `tasks` property schema.
 *
 * Feeds the provider-visible description and `maxItems` bound from the same
 * AgentsConfig instance the subagent definition builder and its
 * SubagentTasksLimit constraint consume, so schema and runtime validation
 * cannot drift.
 */
final readonly class SubagentTasksSchemaProvider implements SchemaProviderInterface
{
    public function __construct(
        private AgentsConfig $config,
    ) {
    }

    public function getSchemaFragment(array $context = []): array
    {
        return [
            'description' => \sprintf(
                'Parallel tasks (max %d per call). Use instead of agent/task for parallel mode.',
                $this->config->maxAgents,
            ),
            'minItems' => 1,
            'maxItems' => $this->config->maxAgents,
        ];
    }
}
