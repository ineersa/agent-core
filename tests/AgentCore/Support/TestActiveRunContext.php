<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Domain\Run\RunState;

/** @internal */
final class TestActiveRunContext implements ActiveRunContextInterface
{
    /** @var list<string> */
    /** @var list<RunState> */
    /** @var array<string, RunState> */
    private array $states = [];

    public function stateFor(string $runId): RunState
    {
        return $this->states[$runId] ??= RunState::queued($runId);
    }

    public function remember(RunState $state): void
    {
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
}
