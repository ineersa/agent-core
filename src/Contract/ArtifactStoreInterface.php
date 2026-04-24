<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Artifact\ArtifactMetadata;

interface ArtifactStoreInterface
{
    /**
     * Persists an artifact with given run ID, name, content, and optional metadata.
     */
    public function put(string $runId, string $artifactName, string $content, ?ArtifactMetadata $metadata = null): string;
}
