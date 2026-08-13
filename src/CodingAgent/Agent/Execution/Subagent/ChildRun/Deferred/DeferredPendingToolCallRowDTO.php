<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fixed pending-tool-call row stored under child_lifecycle_projection.pending_tool_calls.
 *
 * Wire keys remain camelCase ({@see displayLine}) to match historical JSON rows.
 */
final readonly class DeferredPendingToolCallRowDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name,
        #[Assert\NotBlank]
        public string $displayLine,
    ) {
    }
}
