<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Schema;

use Ineersa\CodingAgent\Config\AgentsConfig;
use Symfony\AI\Platform\Contract\JsonSchema\Provider\SchemaProviderInterface;

/**
 * Runtime fragment for the agent_resume `tasks` property schema.
 */
final readonly class AgentResumeTasksSchemaProvider implements SchemaProviderInterface
{
    public function __construct(
        private AgentsConfig $config,
    ) {
    }

    public function getSchemaFragment(array $context = []): array
    {
        return [
            'description' => \sprintf(
                'Parallel resume tasks (max %d per call). Use instead of artifact_id/task for parallel mode.',
                $this->config->maxAgents,
            ),
            'minItems' => 1,
            'maxItems' => $this->config->maxAgents,
        ];
    }
}
