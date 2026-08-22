<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Tool\Schema\AgentResumeTasksSchemaProvider;
use Ineersa\CodingAgent\Tool\Validation\SubagentTasks\SubagentTasksLimit;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validated `agent_resume` tool arguments (single or parallel mode).
 */
final class AgentResumeArgumentsDTO
{
    /**
     * @param list<AgentResumeTaskDTO>|null $tasks
     */
    public function __construct(
        #[Schema(description: 'Child artifact id for single mode (e.g. agent_abc123).')]
        #[Assert\Length(min: 1)]
        #[Assert\When(
            expression: 'this.tasks !== null',
            constraints: [
                new Assert\IsNull(message: 'Use either single mode {"artifact_id","task"} or parallel mode {"tasks":[...]}, not both.'),
            ],
        )]
        #[Assert\When(
            expression: 'this.tasks === null && this.agent_run_id === null',
            constraints: [
                new Assert\NotBlank(normalizer: 'trim', message: 'Single agent_resume mode requires artifact_id or agent_run_id plus a non-empty task.'),
            ],
        )]
        public readonly ?string $artifact_id = null,
        #[Schema(description: 'Continuation task for single mode.')]
        #[Assert\When(
            expression: 'this.tasks !== null',
            constraints: [
                new Assert\IsNull(message: 'Use either single mode {"artifact_id","task"} or parallel mode {"tasks":[...]}, not both.'),
            ],
        )]
        #[Assert\When(
            expression: 'this.tasks === null',
            constraints: [
                new Assert\NotBlank(normalizer: 'trim', message: 'Single agent_resume mode requires a non-empty task.'),
            ],
        )]
        public readonly ?string $task = null,
        #[Schema(description: 'Child AgentCore run id for single mode.')]
        #[Assert\Length(min: 1)]
        #[Assert\When(
            expression: 'this.tasks !== null',
            constraints: [
                new Assert\IsNull(message: 'Use either single mode {"artifact_id","task"} or parallel mode {"tasks":[...]}, not both.'),
            ],
        )]
        public readonly ?string $agent_run_id = null,
        #[Schema(provider: AgentResumeTasksSchemaProvider::class)]
        #[Assert\When(
            expression: 'this.tasks !== null',
            constraints: [
                new Assert\Count(min: 1, minMessage: 'Parallel agent_resume mode requires a non-empty "tasks" array.'),
            ],
        )]
        #[Assert\Valid]
        #[SubagentTasksLimit]
        public readonly ?array $tasks = null,
    ) {
    }

    #[Assert\Callback]
    public function validateUniqueArtifactIds(ExecutionContextInterface $context): void
    {
        if (null === $this->tasks || [] === $this->tasks) {
            return;
        }

        $seen = [];
        foreach ($this->tasks as $index => $task) {
            if (!$task instanceof AgentResumeTaskDTO) {
                continue;
            }
            $artifactId = $task->trimmedArtifactId();
            if (null === $artifactId) {
                continue;
            }
            if (isset($seen[$artifactId])) {
                $context->buildViolation(\sprintf('Duplicate artifact_id "%s" in one agent_resume call.', $artifactId))
                    ->atPath('tasks['.$index.'].artifact_id')
                    ->addViolation();

                continue;
            }
            $seen[$artifactId] = true;
        }
    }

    public function isParallelMode(): bool
    {
        return null !== $this->tasks && [] !== $this->tasks;
    }

    public function trimmedArtifactId(): ?string
    {
        if (null === $this->artifact_id) {
            return null;
        }

        $trimmed = trim($this->artifact_id);

        return '' === $trimmed ? null : $trimmed;
    }

    public function trimmedAgentRunId(): ?string
    {
        if (null === $this->agent_run_id) {
            return null;
        }

        $trimmed = trim($this->agent_run_id);

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
     * @return list<AgentResumeTaskDTO>
     */
    public function parallelTasks(): array
    {
        if (!$this->isParallelMode()) {
            return [];
        }

        /** @var list<AgentResumeTaskDTO> $tasks */
        $tasks = $this->tasks;

        return $tasks;
    }
}
