<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildArtifactFinalizer;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunTerminalizerPort;

final class SubagentChildRunTerminalizerAdapter implements ChildRunTerminalizerPort
{
    public function __construct(
        private readonly AgentChildArtifactFinalizer $artifactFinalizer,
        private readonly AgentChildHandoffRenderer $handoffRenderer,
    ) {
    }

    public function summarizeCompletedSummary(RunState $state): string
    {
        return $this->handoffRenderer->extractLastMessage($state);
    }

    public function applyTerminalOutcome(ChildRunTerminalOutcomeDTO $outcome): void
    {
        if (AgentArtifactStatusEnum::Cancelled === $outcome->status) {
            $this->artifactFinalizer->logChildCancelled($outcome->identity);
        }

        $this->artifactFinalizer->apply($outcome);
    }

    public function completeToolResult(ChildRunIdentityDTO $identity, RunState $state): string
    {
        $finalMessages = $this->handoffRenderer->extractLastMessage($state);
        $this->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Completed,
            summary: $finalMessages,
        ));

        return $this->handoffRenderer->formatCompletedResult($identity->displayName, $identity->artifactId, $finalMessages);
    }

    public function failToolResult(ChildRunIdentityDTO $identity, RunState $state): string
    {
        $errorMsg = $state->errorMessage ?? 'Run failed without error message.';
        $this->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Failed,
            failureReason: $errorMsg,
            summary: $errorMsg,
        ));

        return $this->handoffRenderer->formatFailedResult($identity->displayName, $identity->artifactId, $errorMsg);
    }

    public function cancelChildToolResult(ChildRunIdentityDTO $identity, RunState $state): string
    {
        $this->applyTerminalOutcome(new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Cancelled,
            summary: 'Child run was cancelled.',
            childState: $state,
        ));

        return $this->handoffRenderer->formatChildCancelledMessage($identity->displayName, $identity->artifactId);
    }

    public function parentCancelledSingleMessage(ChildRunIdentityDTO $identity): string
    {
        return $this->handoffRenderer->formatParentCancelledSingleMessage($identity->displayName, $identity->artifactId);
    }

    public function timeoutToolResult(ChildRunIdentityDTO $identity, int $timeoutSeconds): string
    {
        return $this->handoffRenderer->formatTimeoutResult(
            $identity->displayName,
            $timeoutSeconds,
            $identity->taskSummary,
            $identity->artifactId,
        );
    }
}
