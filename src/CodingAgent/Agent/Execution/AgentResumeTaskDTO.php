<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One resume target inside parallel {@see AgentResumeArgumentsDTO} tasks.
 */
final class AgentResumeTaskDTO
{
    #[Assert\Length(min: 1)]
    #[Assert\When(
        expression: 'this.agent_run_id === null',
        constraints: [
            new Assert\NotBlank(message: 'Provide at least one identifier: artifact_id or agent_run_id.'),
        ],
    )]
    public readonly ?string $artifact_id;

    #[Assert\NotBlank(message: 'Resume task must be a non-empty string.')]
    public readonly ?string $task;

    #[Assert\Length(min: 1)]
    #[Assert\When(
        expression: 'this.artifact_id === null',
        constraints: [
            new Assert\NotBlank(message: 'Provide at least one identifier: artifact_id or agent_run_id.'),
        ],
    )]
    public readonly ?string $agent_run_id;

    public function __construct(
        #[Schema(description: 'Child artifact id (e.g. agent_abc123) within the current parent session.')]
        ?string $artifact_id = null,
        #[Schema(description: 'Continuation task for the existing child run.')]
        ?string $task = null,
        #[Schema(description: 'Child AgentCore run id (UUID) for the subagent run.')]
        ?string $agent_run_id = null,
    ) {
        $artifactId = null === $artifact_id ? null : trim($artifact_id);
        $taskValue = null === $task ? null : trim($task);
        $agentRunId = null === $agent_run_id ? null : trim($agent_run_id);

        $this->artifact_id = (null === $artifactId || '' === $artifactId) ? null : $artifactId;
        $this->task = (null === $taskValue || '' === $taskValue) ? null : $taskValue;
        $this->agent_run_id = (null === $agentRunId || '' === $agentRunId) ? null : $agentRunId;
    }
}
