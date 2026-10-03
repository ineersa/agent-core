<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Run\RunState;

/** @internal Fixture loading is explicit; this double has no durable reservation store. */
final class TestActiveRunContext implements ActiveRunContextInterface
{
    /** @var array<string, RunState> */
    private array $states = [];

    public function createNew(string $runId): RunState
    {
        $state = RunState::queued($runId);
        $this->loadRecovered($state);

        return $state;
    }

    public function loadRecovered(RunState $state): void
    {
        $this->states[$state->runId] = $state;
    }

    public function requireLoaded(string $runId): RunState
    {
        return $this->states[$runId] ?? throw new RunContextNotLoadedException('Run is not loaded: '.$runId);
    }

    public function replaceCurrent(RunState $state): void
    {
        $this->requireLoaded($state->runId);
        $this->states[$state->runId] = $state;
    }

    public function release(string $runId): void
    {
        unset($this->states[$runId]);
    }
}
