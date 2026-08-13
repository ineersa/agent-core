<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

/**
 * Ordered durable child reservation intent for deferred batch launch (Piece 4A).
 */
final readonly class DeferredSubagentBatchChildIntentDTO
{
    public readonly string $launchModel;
    public readonly string $launchReasoning;

    public function __construct(
        public int $batchIndex,
        public string $childRunId,
        public string $artifactId,
        public string $agentName,
        public string $task,
        string $launchModel,
        string $launchReasoning,
    ) {
        $model = trim($launchModel);
        $reasoning = trim($launchReasoning);
        if ('' === $model || '' === $reasoning) {
            throw new \InvalidArgumentException('Deferred child intent requires non-empty launch model and reasoning.');
        }
        $this->launchModel = $model;
        $this->launchReasoning = $reasoning;
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
