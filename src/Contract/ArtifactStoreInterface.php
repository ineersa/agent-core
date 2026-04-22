<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

interface ArtifactStoreInterface
{
    /**
     * persists an artifact with given run ID, name, content, and optional metadata.
     *
     * @param array<string, mixed> $metadata
     */
    public function put(string $runId, string $artifactName, string $content, array $metadata = []): string;
}
