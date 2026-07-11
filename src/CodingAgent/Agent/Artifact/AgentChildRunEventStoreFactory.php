<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\RunSequenceAllocatorInterface;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Factory for per-instance AgentChildRunEventStore instances.
 *
 * Each child store is bound to a specific (parentRunId, agentRunId,
 * artifactId) triple.  This factory centralizes the construction of
 * child stores and injects the shared dependencies (path resolver,
 * event normalizer, lock factory, logger, sequence allocator).
 *
 * Consumer code (e.g. ChildAwareEventStore) creates child stores on demand.
 */
final readonly class AgentChildRunEventStoreFactory
{
    public function __construct(
        private AgentArtifactPathResolver $pathResolver,
        private EventPayloadNormalizer $eventPayloadNormalizer,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        private RunSequenceAllocatorInterface $sequenceAllocator,
        private EventLogMaxSeqBootstrapReader $bootstrapReader = new EventLogMaxSeqBootstrapReader(),
    ) {
    }

    public function create(string $parentRunId, string $agentRunId, string $artifactId): AgentChildRunEventStore
    {
        return new AgentChildRunEventStore(
            pathResolver: $this->pathResolver,
            eventPayloadNormalizer: $this->eventPayloadNormalizer,
            lockFactory: $this->lockFactory,
            logger: $this->logger,
            sequenceAllocator: $this->sequenceAllocator,
            parentRunId: $parentRunId,
            agentRunId: $agentRunId,
            artifactId: $artifactId,
            bootstrapReader: $this->bootstrapReader,
        );
    }
}
