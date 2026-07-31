<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Public, immutable ambient context for one permanent tool invocation.
 *
 * Carries only the current session/run identity so extension tools can stay
 * session-scoped without accepting run_id as a model argument.
 */
final readonly class ToolInvocationContextDTO
{
    public function __construct(
        public string $runId,
    ) {
        if ('' === trim($this->runId)) {
            throw new \InvalidArgumentException('ToolInvocationContextDTO runId must be a non-empty string.');
        }
    }
}
