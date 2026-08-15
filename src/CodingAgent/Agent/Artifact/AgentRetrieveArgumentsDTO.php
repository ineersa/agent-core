<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validated tool arguments for {@see AgentArtifactRetrievalService}.
 *
 * @internal
 */
final class AgentRetrieveArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'Child artifact id (e.g. agent_abc123) within the current parent session.')]
        #[Assert\Length(min: 1)]
        public readonly ?string $artifactId = null,
        #[Schema(description: 'Child AgentCore run id (UUID) for the subagent run.')]
        #[Assert\Length(min: 1)]
        public readonly ?string $agentRunId = null,
        #[Schema(description: 'Output mode. Default handoff.')]
        #[Assert\Choice(choices: ['handoff', 'metadata', 'events', 'history', 'debug'], message: 'Invalid mode "{{ value }}". Supported modes: handoff, metadata, events, history, debug.')]
        public readonly ?string $mode = null,
        #[Schema(description: 'Max rows for events/history modes (default '.AgentArtifactRetrievalService::DEFAULT_LIMIT.').')]
        #[Assert\Range(min: 1, max: AgentArtifactRetrievalService::MAX_LIMIT)]
        public readonly ?int $limit = null,
    ) {
    }

    public function trimmedArtifactId(): ?string
    {
        if (null === $this->artifactId) {
            return null;
        }

        $trimmed = trim($this->artifactId);

        return '' === $trimmed ? null : $trimmed;
    }

    public function trimmedAgentRunId(): ?string
    {
        if (null === $this->agentRunId) {
            return null;
        }

        $trimmed = trim($this->agentRunId);

        return '' === $trimmed ? null : $trimmed;
    }

    public function resolvedMode(): AgentRetrieveModeEnum
    {
        $raw = $this->mode;
        if (null === $raw || '' === trim($raw)) {
            return AgentRetrieveModeEnum::Handoff;
        }

        $mode = AgentRetrieveModeEnum::tryFrom(trim($raw));
        if (null === $mode) {
            throw new \InvalidArgumentException(\sprintf('Invalid mode "%s". Supported modes: handoff, metadata, events, history, debug.', $raw));
        }

        return $mode;
    }

    public function resolvedLimit(int $defaultLimit, int $maxLimit): int
    {
        if (null === $this->limit) {
            return $defaultLimit;
        }

        if ($this->limit < 1 || $this->limit > $maxLimit) {
            throw new \InvalidArgumentException(\sprintf('limit must be between 1 and %d.', $maxLimit));
        }

        return $this->limit;
    }

    #[Assert\Callback]
    public function validateIdentifiers(ExecutionContextInterface $context): void
    {
        if (null === $this->trimmedArtifactId() && null === $this->trimmedAgentRunId()) {
            $context->buildViolation('Provide at least one identifier: artifact_id or agent_run_id.')
                ->addViolation();
        }
    }
}
