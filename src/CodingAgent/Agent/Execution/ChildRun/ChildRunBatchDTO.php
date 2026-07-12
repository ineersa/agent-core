<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

final readonly class ChildRunBatchDTO
{
    /**
     * @param list<PreparedAgentChildRunDTO> $children
     */
    public function __construct(
        public string $parentRunId,
        public array $children,
        public int $timeoutSeconds,
    ) {
    }

    public function isSingle(): bool
    {
        return 1 === \count($this->children);
    }
}
