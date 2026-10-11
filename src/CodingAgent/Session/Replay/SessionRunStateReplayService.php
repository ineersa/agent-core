<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;

/** Framework facade; history selection and body replay belong to the owner coordinator. */
final readonly class SessionRunStateReplayService implements RunStateRebuilderInterface
{
    public function __construct(private EventStoreInterface $eventStore, private SessionReplayCoordinator $coordinator)
    {
    }

    public function rebuildIfStale(RunState $state, string $runId): RunStateReplayResult
    {
        $sequence = $this->eventStore->latestSequenceFor($runId);
        if (null === $sequence) {
            return RunStateReplayResult::noEvents();
        }
        if ($state->lastSeq >= $sequence) {
            return RunStateReplayResult::current();
        }

        return $this->rebuild($state, $runId);
    }

    public function rebuildAtPosition(RunState $state, string $runId, int $positionTurnNo): RunStateReplayResult
    {
        return $this->rebuild($state, $runId, $positionTurnNo);
    }

    private function rebuild(RunState $state, string $runId, ?int $positionTurnNo = null): RunStateReplayResult
    {
        if ($state->runId !== $runId) {
            throw new \InvalidArgumentException('Replay state does not match the requested run.');
        }
        $result = $this->coordinator->reconstruct($state, $positionTurnNo);

        return null === $result ? RunStateReplayResult::noEvents() : RunStateReplayResult::rebuilt($result->state);
    }
}
