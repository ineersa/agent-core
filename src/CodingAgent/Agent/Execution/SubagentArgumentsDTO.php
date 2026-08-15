<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validated `subagent` tool arguments (single or parallel mode).
 */
final class SubagentArgumentsDTO
{
    /**
     * @param list<SubagentTaskDTO>|null $tasks
     */
    public function __construct(
        #[Schema(description: 'Agent definition name for single mode.')]
        public readonly ?string $agent = null,
        #[Schema(description: 'Task text for single mode.')]
        public readonly ?string $task = null,
        // TODO(commit 2, settings-derived): the provider-visible tasks
        // description "Parallel tasks (max %d per call). Use instead of
        // agent/task for parallel mode." and its maxItems bound depend on
        // agents.max_agents, so they need a Symfony SchemaProviderInterface
        // fragment (ai.platform.json_schema.provider) wired into the native
        // Factory/Describer used by RegistryBackedToolbox. Do not hard-code
        // the max value into this static attribute.
        public readonly ?array $tasks = null,
    ) {
    }

    public function isParallelMode(): bool
    {
        return null !== $this->tasks && [] !== $this->tasks;
    }

    public function trimmedAgent(): ?string
    {
        if (null === $this->agent) {
            return null;
        }

        $trimmed = trim($this->agent);

        return '' === $trimmed ? null : $trimmed;
    }

    public function trimmedTask(): ?string
    {
        if (null === $this->task) {
            return null;
        }

        $trimmed = trim($this->task);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * @return list<SubagentTaskDTO>
     */
    public function parallelTasks(): array
    {
        if (!$this->isParallelMode()) {
            return [];
        }

        /** @var list<SubagentTaskDTO> $tasks */
        $tasks = $this->tasks;

        return $tasks;
    }

    #[Assert\Callback]
    public function validateMode(ExecutionContextInterface $context): void
    {
        $hasSingleAgent = null !== $this->trimmedAgent();
        $hasSingleTask = null !== $this->trimmedTask();
        $hasTasksArray = null !== $this->tasks;

        if ($hasTasksArray) {
            if ($hasSingleAgent || $hasSingleTask) {
                $context->buildViolation('Use either single mode {"agent","task"} or parallel mode {"tasks":[...]}, not both.')
                    ->addViolation();

                return;
            }

            if (!\is_array($this->tasks) || [] === $this->tasks) {
                $context->buildViolation('Parallel subagent mode requires a non-empty "tasks" array.')
                    ->addViolation();

                return;
            }

            foreach ($this->tasks as $index => $task) {
                if (!$task instanceof SubagentTaskDTO) {
                    $context->buildViolation(\sprintf('tasks[%d] must be an object with "agent" and "task" strings.', $index))
                        ->atPath('tasks')
                        ->addViolation();

                    return;
                }
            }

            return;
        }

        if (!$hasSingleAgent || !$hasSingleTask) {
            $context->buildViolation('Single subagent mode requires non-empty "agent" and "task" strings.')
                ->addViolation();
        }
    }
}
