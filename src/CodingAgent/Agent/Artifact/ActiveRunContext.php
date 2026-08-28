<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\RunOperationalProjectionWriterInterface;
use Ineersa\AgentCore\Domain\Run\RunState;

/** Process-local run_control context; canonical replay is only performed on a cache miss. */
final class ActiveRunContext implements ActiveRunContextInterface
{
    /** @var array<string, RunState> */
    private array $states = [];

    public function __construct(
        private readonly RunStateRebuilderInterface $runStateRebuilder,
        private readonly RunOperationalProjectionWriterInterface $projectionWriter,
        private readonly RunOwnerSessionResolver $ownerSessionResolver,
    ) {
    }

    public function stateFor(string $runId): RunState
    {
        if (isset($this->states[$runId])) {
            return $this->states[$runId];
        }

        $replay = $this->runStateRebuilder->rebuildIfStale(RunState::queued($runId), $runId);
        $state = $replay->rebuiltState ?? RunState::queued($runId);
        $this->persist($state);
        $this->states[$runId] = $state;

        return $state;
    }

    public function remember(RunState $state): void
    {
        try {
            $this->persist($state);
        } catch (\Throwable $e) {
            unset($this->states[$state->runId]);

            throw $e;
        }
        $this->states[$state->runId] = $state;
    }

    public function invalidate(string $runId): void
    {
        unset($this->states[$runId]);
    }

    public function clear(): void
    {
        $this->states = [];
    }

    private function persist(RunState $state): void
    {
        $this->projectionWriter->replace($this->ownerSessionResolver->ownerSessionIdFor($state->runId), $state);
    }
}
