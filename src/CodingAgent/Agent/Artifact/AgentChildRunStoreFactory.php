<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Factory for per-instance AgentChildRunStore instances.
 *
 * Each child store is bound to a specific (parentRunId, agentRunId,
 * artifactId) triple.  This factory centralizes the construction of
 * child stores and injects the shared dependencies (path resolver,
 * serializer, lock factory).
 *
 * Consumer code (e.g. ChildAwareRunStore) creates child stores on demand.
 */
final readonly class AgentChildRunStoreFactory
{
    public function __construct(
        private AgentArtifactPathResolver $pathResolver,
        private NormalizerInterface&DenormalizerInterface $serializer,
        private LockFactory $lockFactory,
    ) {
    }

    public function create(string $parentRunId, string $agentRunId, string $artifactId): AgentChildRunStore
    {
        return new AgentChildRunStore(
            pathResolver: $this->pathResolver,
            serializer: $this->serializer,
            lockFactory: $this->lockFactory,
            parentRunId: $parentRunId,
            agentRunId: $agentRunId,
            artifactId: $artifactId,
        );
    }
}
