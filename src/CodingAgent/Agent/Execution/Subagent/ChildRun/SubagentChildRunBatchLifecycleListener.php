<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedNativeHandoffGeneration;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLifecycleListenerInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunArtifactFinalizer;

final class SubagentChildRunBatchLifecycleListener implements ChildRunBatchLifecycleListenerInterface
{
    public function __construct(
        private readonly SubagentChildRunArtifactFinalizer $artifactFinalizer,
    ) {
    }

    public function finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO $request): RetainedNativeHandoffGeneration
    {
        return $this->persistArtifactOutcome($request->artifactOutcome);
    }

    private function persistArtifactOutcome(ChildRunTerminalOutcomeDTO $outcome): RetainedNativeHandoffGeneration
    {
        if (AgentArtifactStatusEnum::Cancelled === $outcome->status) {
            // Keep logging on the finalizer path used by persist-only cancelled outcomes.
            $this->artifactFinalizer->logChildCancelled($outcome->identity);
        }

        return $this->artifactFinalizer->apply($outcome);
    }
}
