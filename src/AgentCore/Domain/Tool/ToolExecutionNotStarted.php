<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Transient marker: the tool handler was not invoked.
 *
 * Produced at the ToolCallRequested short-circuit boundary (deny/replace/
 * allowlist) and preserved through result normalization so owner settlement
 * can retire unused preparations without a persistent started flag.
 */
final readonly class ToolExecutionNotStarted
{
    public function __construct(
        public string $reason,
        public mixed $result = null,
    ) {
    }
}
