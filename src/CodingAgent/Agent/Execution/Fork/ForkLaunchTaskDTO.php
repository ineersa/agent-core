<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\AgentChildLaunchTaskInterface;

/**
 * Single fork deferred launch intent (one child per parent fork tool call).
 */
final readonly class ForkLaunchTaskDTO implements AgentChildLaunchTaskInterface
{
    public function __construct(
        public string $task,
        public ?string $modelOverride = null,
        public ?string $reasoningOverride = null,
    ) {
    }

    public function displayName(): string
    {
        return 'fork';
    }

    public function taskSummary(): string
    {
        return trim($this->task);
    }

    public function definitionModel(): ?string
    {
        return $this->modelOverride;
    }

    public function reasoningOverride(): ?string
    {
        return $this->reasoningOverride;
    }
}
