<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Tool\Schema\SubagentTasksSchemaProvider;
use Ineersa\CodingAgent\Tool\Validation\SubagentTasks\SubagentTasksLimit;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated `subagent` tool arguments (single or parallel mode).
 *
 * Mode selection is declarative: providing `tasks` selects parallel mode and
 * excludes agent/task; omitting it selects single mode which requires both.
 * The parallel task-count bound (agents.max_agents) is settings-derived:
 * SubagentTasksLimit validates the runtime limit and
 * SubagentTasksSchemaProvider feeds the schema fragment from the same config.
 */
final class SubagentArgumentsDTO
{
    /**
     * @param list<SubagentTaskDTO>|null $tasks
     */
    public function __construct(
        #[Schema(description: 'Agent definition name for single mode.')]
        #[Assert\When(
            expression: 'this.tasks !== null',
            constraints: [
                new Assert\IsNull(message: 'Use either single mode {"agent","task"} or parallel mode {"tasks":[...]}, not both.'),
            ],
        )]
        #[Assert\When(
            expression: 'this.tasks === null',
            constraints: [
                new Assert\NotBlank(normalizer: 'trim', message: 'Single subagent mode requires non-empty "agent" and "task" strings.'),
            ],
        )]
        public readonly ?string $agent = null,
        #[Schema(description: 'Task text for single mode.')]
        #[Assert\When(
            expression: 'this.tasks !== null',
            constraints: [
                new Assert\IsNull(message: 'Use either single mode {"agent","task"} or parallel mode {"tasks":[...]}, not both.'),
            ],
        )]
        #[Assert\When(
            expression: 'this.tasks === null',
            constraints: [
                new Assert\NotBlank(normalizer: 'trim', message: 'Single subagent mode requires non-empty "agent" and "task" strings.'),
            ],
        )]
        public readonly ?string $task = null,
        #[Schema(provider: SubagentTasksSchemaProvider::class)]
        #[Assert\When(
            expression: 'this.tasks !== null',
            constraints: [
                new Assert\Count(min: 1, minMessage: 'Parallel subagent mode requires a non-empty "tasks" array.'),
            ],
        )]
        #[Assert\Valid]
        #[SubagentTasksLimit]
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
}
