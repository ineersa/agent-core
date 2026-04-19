<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/**
 * Defines the contract for persisting agent execution artifacts, such as logs or intermediate outputs, to a storage backend. It ensures consistent storage semantics across different implementations by standardizing the input parameters and return value for artifact registration.
 */
interface ArtifactStoreInterface
{
    /**
     * persists an artifact with given run ID, name, content, and optional metadata.
     *
     * @param array<string, mixed> $metadata
     */
    public function put(string $runId, string $artifactName, string $content, array $metadata = []): string;
}
