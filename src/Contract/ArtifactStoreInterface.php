<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

interface ArtifactStoreInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function put(string $runId, string $artifactName, string $content, array $metadata = []): string;
}
