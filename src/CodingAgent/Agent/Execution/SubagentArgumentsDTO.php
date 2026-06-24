<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

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
        public readonly ?string $agent = null,
        public readonly ?string $task = null,
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
