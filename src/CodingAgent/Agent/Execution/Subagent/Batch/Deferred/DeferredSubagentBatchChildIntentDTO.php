<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

/**
 * Ordered durable child reservation intent for deferred batch launch (Piece 4A).
 */
final readonly class DeferredSubagentBatchChildIntentDTO
{
    public function __construct(
        public int $batchIndex,
        public string $childRunId,
        public string $artifactId,
        public string $agentName,
        public string $task,
        public ?string $definitionModel,
    ) {
    }

    /**
     * @return array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string}
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
        ];
    }
}
