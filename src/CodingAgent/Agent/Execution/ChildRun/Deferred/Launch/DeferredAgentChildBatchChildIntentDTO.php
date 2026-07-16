<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;

/**
 * Ordered durable child reservation intent for deferred batch launch.
 */
final readonly class DeferredAgentChildBatchChildIntentDTO
{
    public function __construct(
        public int $batchIndex,
        public string $childRunId,
        public string $artifactId,
        public string $agentName,
        public string $task,
        public ?string $definitionModel,
        public AgentArtifactKindEnum $artifactKind,
        public ?string $definitionReasoning = null,
    ) {
    }

    /**
     * @return array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string, artifactKind: string}
     */
    public function toReserveArray(): array
    {
        return [
            'batchIndex' => $this->batchIndex,
            'childRunId' => $this->childRunId,
            'artifactId' => $this->artifactId,
            'agentName' => $this->agentName,
            'task' => $this->task,
            'definitionModel' => $this->definitionModel,
            'artifactKind' => $this->artifactKind->value,
        ];
    }
}
