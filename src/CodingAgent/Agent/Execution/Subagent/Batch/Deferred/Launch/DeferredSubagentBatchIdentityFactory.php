<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Symfony\Component\Uid\Uuid;

/**
 * Deterministic batch lifecycle and per-child identities for a parent tool call.
 */
final class DeferredSubagentBatchIdentityFactory
{
    private const string BATCH_NAMESPACE = 'b7e2a9c4-1f3d-4e5a-9b8c-0d1e2f3a4b5c';
    private const string CHILD_NAMESPACE = 'c8f3b0d5-2a4e-5f6b-0c9d-1e2f3a4b5c6d';

    public function batchLifecycleId(string $parentRunId, string $parentToolCallId): string
    {
        $name = $parentRunId.'|'.$parentToolCallId.'|deferred_subagent_batch';

        return Uuid::v5(Uuid::fromString(self::BATCH_NAMESPACE), $name)->toRfc4122();
    }

    /**
     * @return array{childRunId: string, artifactId: string}
     */
    public function childIdentity(string $parentRunId, string $parentToolCallId, int $batchIndex): array
    {
        $name = $parentRunId.'|'.$parentToolCallId.'|'.$batchIndex;
        $childRunId = Uuid::v5(Uuid::fromString(self::CHILD_NAMESPACE), $name)->toRfc4122();
        $artifactId = 'agent_'.substr(hash('sha256', $name.'|artifact'), 0, 16);

        return [
            'childRunId' => $childRunId,
            'artifactId' => $artifactId,
        ];
    }
}
