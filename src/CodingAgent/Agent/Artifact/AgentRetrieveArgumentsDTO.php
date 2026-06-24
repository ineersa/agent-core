<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Symfony\Component\Serializer\Attribute\SerializedName;
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
        #[SerializedName('artifact_id')]
        public readonly ?string $artifactId = null,
        #[SerializedName('agent_run_id')]
        public readonly ?string $agentRunId = null,
        public readonly ?string $mode = null,
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
