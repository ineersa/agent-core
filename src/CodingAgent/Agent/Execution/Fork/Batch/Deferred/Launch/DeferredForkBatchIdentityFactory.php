<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Launch;

use Symfony\Component\Uid\Uuid;

/**
 * Deterministic fork batch lifecycle and per-child identities (separate UUID namespaces from subagent).
 */
final class DeferredForkBatchIdentityFactory
{
    private const string BATCH_NAMESPACE = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5d';
    private const string CHILD_NAMESPACE = 'e2b3c4d5-f6a7-4e8b-9c0d-1e2f3a4b5c6e';

    public function batchLifecycleId(string $parentRunId, string $parentToolCallId): string
    {
        $name = $parentRunId.'|'.$parentToolCallId.'|deferred_fork_batch';

        return Uuid::v5(Uuid::fromString(self::BATCH_NAMESPACE), $name)->toRfc4122();
    }

    /**
     * @return array{childRunId: string, artifactId: string}
     */
    public function childIdentity(string $parentRunId, string $parentToolCallId, int $batchIndex): array
    {
        $name = $parentRunId.'|'.$parentToolCallId.'|fork|'.$batchIndex;
        $childRunId = Uuid::v5(Uuid::fromString(self::CHILD_NAMESPACE), $name)->toRfc4122();
        $artifactId = 'agent_'.substr(hash('sha256', $name.'|artifact'), 0, 16);

        return [
            'childRunId' => $childRunId,
            'artifactId' => $artifactId,
        ];
    }
}
