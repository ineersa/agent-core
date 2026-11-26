<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Container\ContainerInterface;

/** Seeds a child run as a launch does: batch reservation first, then its artifact. */
final class ChildRunFixture
{
    public static function create(
        ContainerInterface $container,
        string $parentRunId,
        string $artifactId,
        string $childRunId,
        string $agentName = 'scout',
        AgentArtifactKindEnum $kind = AgentArtifactKindEnum::Subagent,
    ): AgentArtifactEntryDTO {
        self::reserve($container, $parentRunId, $artifactId, $childRunId, $agentName);

        return $container->get(AgentArtifactRegistry::class)->create($parentRunId, $artifactId, $childRunId, $agentName, $kind);
    }

    /** The batch reservation alone, for tests that create the artifact through a lifecycle service. */
    public static function reserve(ContainerInterface $container, string $parentRunId, string $artifactId, string $childRunId, string $agentName = 'scout'): void
    {
        $container->get(DeferredSubagentBatchRepository::class)->reserveBatch(
            $artifactId.'-batch', $parentRunId, 1, $artifactId.'-call', 0, new \DateTimeImmutable('+1 hour'),
            new DeferredSubagentBatchChildIntentDTO(1, $childRunId, $artifactId, $agentName, 'task', 'model', 'medium'),
        );
    }
}
