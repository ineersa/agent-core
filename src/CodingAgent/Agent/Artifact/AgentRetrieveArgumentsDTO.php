<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

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
        #[Assert\When(
            expression: 'this.agent_run_id === null',
            constraints: [
                new Assert\NotBlank(normalizer: 'trim', message: 'Provide at least one identifier: artifact_id or agent_run_id.'),
            ],
        )]
        public readonly ?string $artifact_id = null,
        #[Schema(description: 'Child AgentCore run id (UUID) for the subagent run.')]
        #[Assert\Length(min: 1)]
        #[Assert\When(
            expression: 'this.artifact_id === null',
            constraints: [
                new Assert\NotBlank(normalizer: 'trim', message: 'Provide at least one identifier: artifact_id or agent_run_id.'),
            ],
        )]
        public readonly ?string $agent_run_id = null,
        #[Schema(description: 'Output mode. Default handoff.')]
        #[Assert\Choice(choices: ['handoff', 'metadata', 'events', 'history', 'handoff_history', 'debug'], message: 'Invalid mode "{{ value }}". Supported modes: handoff, metadata, events, history, handoff_history, debug.')]
        public readonly ?string $mode = null,
        #[Schema(description: 'Max rows for events/history modes (default '.AgentArtifactRetrievalService::DEFAULT_LIMIT.').')]
        #[Assert\Range(min: 1, max: AgentArtifactRetrievalService::MAX_LIMIT)]
        public readonly ?int $limit = null,
        #[Schema(description: 'Optional archived handoff index (1-based) for mode=handoff_history. Omit to list archives.')]
        #[Assert\Range(min: 1)]
        public readonly ?int $index = null,
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

    public function resolvedMode(): AgentRetrieveModeEnum
    {
        $raw = $this->mode;
        if (null === $raw || '' === trim($raw)) {
            return AgentRetrieveModeEnum::Handoff;
        }

        $mode = AgentRetrieveModeEnum::tryFrom(trim($raw));
        if (null === $mode) {
            throw new \InvalidArgumentException(\sprintf('Invalid mode "%s". Supported modes: handoff, metadata, events, history, handoff_history, debug.', $raw));
        }

        return $mode;
    }

    public function resolvedIndex(): ?int
    {
        return $this->index;
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
}
