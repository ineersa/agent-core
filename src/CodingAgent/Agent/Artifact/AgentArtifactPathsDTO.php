<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Relative paths for a parent-scoped agent artifact.
 *
 * All paths are relative to the parent session directory
 * (<sessionsBase>/<parentRunId>/).  Callers that need absolute
 * paths should use {@see AgentArtifactPathResolver}.
 *
 * Immutable value object assembled during artifact creation.
 */
final readonly class AgentArtifactPathsDTO
{
    /**
     * @param string $artifactDir  Relative: artifacts/agents/<artifact_id>/
     * @param string $metadataPath Relative: artifacts/agents/<artifact_id>/metadata.json
     * @param string $handoffPath  Relative: artifacts/agents/<artifact_id>/handoff.md
     * @param string $eventsPath   Relative: artifacts/agents/<artifact_id>/events.jsonl
     * @param string $statePath    Relative: artifacts/agents/<artifact_id>/state.json
     */
    public function __construct(
        #[SerializedName('artifact_dir')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'artifact_dir must not be blank')]
        public string $artifactDir,
        #[SerializedName('metadata_path')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'metadata_path must not be blank')]
        public string $metadataPath,
        #[SerializedName('handoff_path')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'handoff_path must not be blank')]
        public string $handoffPath,
        #[SerializedName('events_path')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'events_path must not be blank')]
        public string $eventsPath,
        #[SerializedName('state_path')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'state_path must not be blank')]
        public string $statePath,
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
            handoffPath: "{$dir}/handoff.md",
            eventsPath: "{$dir}/events.jsonl",
            statePath: "{$dir}/state.json",
        );
    }
}
