<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

/**
 * Relative paths for a parent-scoped agent artifact.
 *
 * All paths are relative to the parent session directory
 * (<sessionsBase>/<parentRunId>/).  Callers that need absolute
 * paths should join against the resolved sessions base.
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
        public string $artifactDir,
        public string $metadataPath,
        public string $handoffPath,
        public string $eventsPath,
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
