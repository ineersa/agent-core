<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Dto;

final readonly class ReplayIntegrity
{
    /**
     * @param list<int> $missingSequences
     */
    public function __construct(
        public string $runId,
        public string $source,
        public int $eventCount,
        public int $lastSeq,
        public array $missingSequences,
        public bool $isContiguous,
    ) {
    }
}
