<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\History;

/**
 * Ordinary shell-command idempotency lookup without archive scans.
 *
 * Implemented by CodingAgent shared history projections.
 */
interface AppliedShellCommandLookupInterface
{
    /**
     * True when a shell_command AgentCommandApplied with this idempotency key
     * is already reflected in the ready shared history projection.
     *
     * Missing/not-ready projections fail closed as a recovery requirement.
     */
    public function hasAppliedShellCommand(string $runId, string $idempotencyKey): bool;
}
