<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

/**
 * Context for {@see AfterSessionStartHookInterface}.
 *
 * `runId` is the Hatfield session id (`session_id === run_id`).
 */
final readonly class AfterSessionStartHookContextDTO
{
    public function __construct(
        public string $runId,
    ) {
        if ('' === trim($this->runId)) {
            throw new \InvalidArgumentException('AfterSessionStartHookContextDTO runId must be non-empty.');
        }
    }
}
