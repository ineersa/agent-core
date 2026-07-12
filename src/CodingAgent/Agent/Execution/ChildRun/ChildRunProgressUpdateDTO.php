<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Domain\Run\RunState;

final readonly class ChildRunProgressUpdateDTO
{
    /**
     * @param list<ChildRunBatchItemSnapshotDTO> $items
     * @param array<string, int>                 $activeTurns childRunId => turnNo
     */
    public function __construct(
        public string $parentRunId,
        public array $items,
        public array $activeTurns,
        public int $seq,
        public int $progressStartedMicros,
        public string $aggregateStatus,
        public bool $isSingleChild,
        public ?ChildRunIdentityDTO $singleIdentity = null,
        public ?RunState $singleState = null,
        public string $singleProgressStatus = 'running',
    ) {
    }
}
