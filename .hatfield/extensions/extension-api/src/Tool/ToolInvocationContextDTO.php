<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Public, immutable ambient context for one permanent tool invocation.
 *
 * Carries session/run identity plus optional cooperative cancellation and
 * timeout budget so extension tools can stay session-scoped without accepting
 * run_id/timeout as model arguments.
 *
 * New optional fields remain backward-compatible: existing callers that pass
 * only runId continue to work. timeoutSeconds is a cooperative budget, not a
 * generic kill guarantee.
 */
final readonly class ToolInvocationContextDTO
{
    public function __construct(
        public string $runId,
        public ?ToolCancellationTokenInterface $cancellationToken = null,
        public ?int $timeoutSeconds = null,
    ) {
        if ('' === trim($this->runId)) {
            throw new \InvalidArgumentException('ToolInvocationContextDTO runId must be a non-empty string.');
        }

        if (null !== $this->timeoutSeconds && $this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('ToolInvocationContextDTO timeoutSeconds must be null or a positive integer.');
        }
    }
}
