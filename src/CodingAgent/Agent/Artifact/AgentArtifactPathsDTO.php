<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Relative paths for a parent-scoped agent artifact.
 *
 * All paths are relative to the parent session directory
 * (<sessionsBase>/<parentRunId>/).  Callers that need absolute
 * paths should use {@see \Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver}.
 *
 * Immutable value object assembled during artifact creation.
 *
 * Handoffs live under {@see $artifactDir}/handoffs/<uuid>.md with index.json;
 * there is no mutable latest handoff.md path.
 */
final readonly class AgentArtifactPathsDTO
{
    /**
     * @param string $artifactDir  Relative: artifacts/agents/<artifact_id>/
     * @param string $metadataPath Relative: artifacts/agents/<artifact_id>/metadata.json
     * @param string $eventsPath   Relative: artifacts/agents/<artifact_id>/events.jsonl
     */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', message: 'artifact_dir must not be blank')]
        public string $artifactDir,
        #[Assert\NotBlank(normalizer: 'trim', message: 'metadata_path must not be blank')]
        public string $metadataPath,
        #[Assert\NotBlank(normalizer: 'trim', message: 'events_path must not be blank')]
        public string $eventsPath,
    ) {
    }

    /**
     * Build paths for a given artifact ID.
     *
     * The artifact directory is the leaf component under
     * artifacts/agents/ — all files live inside it.
     */
    public static function forArtifactId(string $artifactId): self
    {
        $dir = "artifacts/agents/{$artifactId}";

        return new self(
            artifactDir: $dir,
            metadataPath: "{$dir}/metadata.json",
            eventsPath: "{$dir}/events.jsonl",
        );
    }
}
