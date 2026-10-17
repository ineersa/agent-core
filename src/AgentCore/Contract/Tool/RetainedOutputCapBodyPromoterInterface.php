<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;

/**
 * Promotes host-owned output-cap full-body files into sealed retained evidence.
 *
 * Implementations must verify exact owned scope paths; arbitrary result paths
 * are never trusted. Returns null when no owned capped body is present.
 */
interface RetainedOutputCapBodyPromoterInterface
{
    /**
     * @param array<string, mixed> $normalizedToolResult
     */
    public function promoteIfOwned(
        string $owningRootSessionId,
        string $messageId,
        array $normalizedToolResult,
        string $sourceRunId,
    ): ?RetainedToolCompletionBodyReference;
}
