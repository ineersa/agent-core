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
    public function __construct(
        #[Schema(description: 'Child artifact id (e.g. agent_abc123) within the current parent session.')]
        #[Assert\Length(min: 1)]
        #[Assert\When(
            expression: 'this.agent_run_id === null',
            constraints: [
                new Assert\NotBlank(normalizer: 'trim', message: 'Provide at least one identifier: artifact_id or agent_run_id.'),
            ],
        )]
        public readonly ?string $artifact_id = null,
        #[Schema(description: 'Continuation task for the existing child run.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'Resume task must be a non-empty string.')]
        public readonly ?string $task = null,
        #[Schema(description: 'Child AgentCore run id (UUID) for the subagent run.')]
        #[Assert\Length(min: 1)]
        #[Assert\When(
            expression: 'this.artifact_id === null',
            constraints: [
                new Assert\NotBlank(normalizer: 'trim', message: 'Provide at least one identifier: artifact_id or agent_run_id.'),
            ],
        )]
        public readonly ?string $agent_run_id = null,
    ) {
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

    public function trimmedTask(): string
    {
        return trim((string) $this->task);
    }
}
