<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

/**
 * Durable reservation intent for the deferred child.
 *
 * Launch model/reasoning are concrete values from prepare-time resolvers
 * (subagent/fork resolveLaunchIdentity); not an external trust boundary.
 */
final readonly class DeferredSubagentBatchChildIntentDTO
{
    public function __construct(
        public string $childRunId,
        public string $artifactId,
        public string $agentName,
        public string $task,
        public string $launchModel,
        public string $launchReasoning,
    ) {
    }

    /**
     * @return array{childRunId: string, artifactId: string, agentName: string, task: string, launchModel: string, launchReasoning: string}
     */
    public function toReserveArray(): array
    {
        return [
            'childRunId' => $this->childRunId,
            'artifactId' => $this->artifactId,
            'agentName' => $this->agentName,
            'task' => $this->task,
            'launchModel' => $this->launchModel,
            'launchReasoning' => $this->launchReasoning,
        ];
    }
}
