<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationKindEnum;
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

    public function finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO $request): void
    {
        if (ChildRunTerminalFinalizationKindEnum::PersistOnly !== $request->kind) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported child-run terminal finalization kind "%s".',
                $request->kind->value,
            ));
        }

        $this->persistArtifactOutcome($request->artifactOutcome);
    }

    private function persistArtifactOutcome(ChildRunTerminalOutcomeDTO $outcome): void
    {
        if (AgentArtifactStatusEnum::Cancelled === $outcome->status) {
            // Keep logging on the finalizer path used by persist-only cancelled outcomes.
            $this->artifactFinalizer->logChildCancelled($outcome->identity);
        }

        $this->artifactFinalizer->apply($outcome);
    }
}
