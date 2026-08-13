<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

/**
 * Ordered durable child reservation intent for deferred batch launch (Piece 4A).
 *
 * Launch model/reasoning are concrete values from prepare-time resolvers
 * (subagent/fork resolveLaunchIdentity); not an external trust boundary.
 */
final readonly class DeferredSubagentBatchChildIntentDTO
{
    public function __construct(
        public int $batchIndex,
        public string $childRunId,
        public string $artifactId,
        public string $agentName,
        public string $task,
        public string $launchModel,
        public string $launchReasoning,
    ) {
    }

    /**
     * @return array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, launchModel: string, launchReasoning: string}
     */
    public function toReserveArray(): array
    {
        return [
            'batchIndex' => $this->batchIndex,
            'childRunId' => $this->childRunId,
            'artifactId' => $this->artifactId,
            'agentName' => $this->agentName,
            'task' => $this->task,
            'launchModel' => $this->launchModel,
            'launchReasoning' => $this->launchReasoning,
        ];
    }
}
