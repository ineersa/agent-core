<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Domain\Run\RunState;

/** @internal */
final class TestActiveRunContext implements ActiveRunContextInterface
{
    /** @var list<string> */
    public array $invalidatedRunIds = [];
    /** @var list<RunState> */
    public array $rememberedStates = [];
    /** @var array<string, RunState> */
    private array $states = [];

    public function stateFor(string $runId): RunState
    {
        return $this->states[$runId] ??= RunState::queued($runId);
    }

    public function remember(RunState $state): void
    {
        $this->rememberedStates[] = $state;
        $this->states[$state->runId] = $state;
    }

    public function invalidate(string $runId): void
    {
        $this->invalidatedRunIds[] = $runId;
        unset($this->states[$runId]);
    }

    public function clear(): void
    {
        $this->states = [];
    }
}
