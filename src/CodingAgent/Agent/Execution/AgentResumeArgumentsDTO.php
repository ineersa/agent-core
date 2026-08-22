<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Tool\Schema\AgentResumeTasksSchemaProvider;
use Ineersa\CodingAgent\Tool\Validation\SubagentTasks\SubagentTasksLimit;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated `agent_resume` tool arguments (single or parallel mode).
 */
final class AgentResumeArgumentsDTO
{
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
            new Assert\NotBlank(message: 'Single agent_resume mode requires artifact_id or agent_run_id plus a non-empty task.'),
        ],
    )]
    public readonly ?string $artifact_id;

    #[Assert\When(
        expression: 'this.tasks !== null',
        constraints: [
            new Assert\IsNull(message: 'Use either single mode {"artifact_id","task"} or parallel mode {"tasks":[...]}, not both.'),
        ],
    )]
    #[Assert\When(
        expression: 'this.tasks === null',
        constraints: [
            new Assert\NotBlank(message: 'Single agent_resume mode requires a non-empty task.'),
        ],
    )]
    public readonly ?string $task;

    #[Assert\Length(min: 1)]
    #[Assert\When(
        expression: 'this.tasks !== null',
        constraints: [
            new Assert\IsNull(message: 'Use either single mode {"artifact_id","task"} or parallel mode {"tasks":[...]}, not both.'),
        ],
    )]
    public readonly ?string $agent_run_id;

    /**
     * @var list<AgentResumeTaskDTO>|null
     */
    #[Assert\When(
        expression: 'this.tasks !== null',
        constraints: [
            new Assert\Count(min: 1, minMessage: 'Parallel agent_resume mode requires a non-empty "tasks" array.'),
        ],
    )]
    #[Assert\Valid]
    #[SubagentTasksLimit]
    public readonly ?array $tasks;

    /**
     * @param list<AgentResumeTaskDTO>|null $tasks
     */
    public function __construct(
        #[Schema(description: 'Child artifact id for single mode (e.g. agent_abc123).')]
        ?string $artifact_id = null,
        #[Schema(description: 'Continuation task for single mode.')]
        ?string $task = null,
        #[Schema(description: 'Child AgentCore run id for single mode.')]
        ?string $agent_run_id = null,
        #[Schema(provider: AgentResumeTasksSchemaProvider::class)]
        ?array $tasks = null,
    ) {
        $artifactId = null === $artifact_id ? null : trim($artifact_id);
        $taskValue = null === $task ? null : trim($task);
        $agentRunId = null === $agent_run_id ? null : trim($agent_run_id);

        $this->artifact_id = (null === $artifactId || '' === $artifactId) ? null : $artifactId;
        $this->task = (null === $taskValue || '' === $taskValue) ? null : $taskValue;
        $this->agent_run_id = (null === $agentRunId || '' === $agentRunId) ? null : $agentRunId;
        $this->tasks = $tasks;
    }

    public function isParallelMode(): bool
    {
        return null !== $this->tasks && [] !== $this->tasks;
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
