<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Contract\TurnTree\BranchReplayFilterInterface;
use Ineersa\AgentCore\Contract\TurnTree\BranchReplayResultDTO;

/**
 * Bridges session branch replay filter to Core contract (SESSION-07A).
 */
final readonly class BranchReplayFilterContractAdapter implements BranchReplayFilterInterface
{
    public function __construct(
        private TurnTreeReplayFilter $filter,
    ) {
    }

    public function filter(string $runId, array $events): BranchReplayResultDTO
    {
        return $this->toContractResult($this->filter->filter($runId, $events));
    }

    public function filterForLeaf(string $runId, array $events, ?int $targetLeafTurnNo = null): BranchReplayResultDTO
    {
        return $this->toContractResult($this->filter->filterForLeaf($runId, $events, $targetLeafTurnNo));
    }

    private function toContractResult(TurnBranchReplayDTO $dto): BranchReplayResultDTO
    {
        return new BranchReplayResultDTO(
            events: $dto->events,
            canonicalEventCount: $dto->canonicalEventCount,
            canonicalLastSeq: $dto->canonicalLastSeq,
            activePathTurnNos: $dto->activePathTurnNos,
            currentLeafTurnNo: $dto->currentLeafTurnNo,
        );
    }
}
