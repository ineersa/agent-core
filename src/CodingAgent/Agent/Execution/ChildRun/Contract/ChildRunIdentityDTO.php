<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;

final readonly class ChildRunIdentityDTO
{
    public readonly string $launchModel;
    public readonly string $launchReasoning;

    public function __construct(
        public string $parentRunId,
        public string $childRunId,
        public string $artifactId,
        public string $displayName,
        public string $taskSummary,
        string $launchModel,
        string $launchReasoning,
        public AgentArtifactKindEnum $artifactKind,
        public int $batchIndex = 1,
    ) {
        $model = trim($launchModel);
        $reasoning = trim($launchReasoning);
        if ('' === $model || '' === $reasoning) {
            throw new \InvalidArgumentException('Child run identity requires non-empty launch model and reasoning.');
        }
        $this->launchModel = $model;
        $this->launchReasoning = $reasoning;
    }
}
