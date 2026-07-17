<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

final readonly class ForkLaunchTaskDTO
{
    public function __construct(
        public string $task,
        public ?string $modelOverride = null,
        public ?string $reasoningOverride = null,
        public ?string $forkLocalRunId = null,
    ) {
    }

    public function withForkLocalRunId(string $forkLocalRunId): self
    {
        return new self(
            task: $this->task,
            modelOverride: $this->modelOverride,
            reasoningOverride: $this->reasoningOverride,
            forkLocalRunId: $forkLocalRunId,
        );
    }
}
