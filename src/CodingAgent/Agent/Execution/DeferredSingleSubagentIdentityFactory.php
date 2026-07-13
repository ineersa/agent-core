<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Symfony\Component\Uid\Uuid;

/**
 * Deterministic child/artifact identities for a parent tool call (idempotent retries).
 */
final class DeferredSingleSubagentIdentityFactory
{
    /** DNS namespace UUID for v5 derivation of child run ids. */
    private const string CHILD_RUN_NAMESPACE = 'a3f2c8e1-4b5d-6e7f-8a9b-0c1d2e3f4a5b';

    /**
     * @return array{childRunId: string, artifactId: string}
     */
    public function forParentToolCall(string $parentRunId, string $toolCallId): array
    {
        $name = $parentRunId.'|'.$toolCallId;
        $childRunId = Uuid::v5(Uuid::fromString(self::CHILD_RUN_NAMESPACE), $name)->toRfc4122();
        $artifactId = 'agent_'.substr(hash('sha256', $name.'|artifact'), 0, 16);

        return [
            'childRunId' => $childRunId,
            'artifactId' => $artifactId,
        ];
    }
}
