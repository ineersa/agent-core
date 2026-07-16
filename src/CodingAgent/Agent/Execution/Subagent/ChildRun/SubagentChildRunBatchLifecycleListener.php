<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationResultDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLifecycleListenerInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunArtifactFinalizer;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer;

final class SubagentChildRunBatchLifecycleListener implements ChildRunBatchLifecycleListenerInterface
{
    public function __construct(
        private readonly SubagentChildRunArtifactFinalizer $artifactFinalizer,
        private readonly SubagentChildRunHandoffRenderer $handoffRenderer,
    ) {
    }

    public function finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        return match ($request->kind) {
            ChildRunTerminalFinalizationKindEnum::PersistOnly => $this->finalizePersistOnly($request->artifactOutcome),
            ChildRunTerminalFinalizationKindEnum::SingleCompleted => $this->finalizeSingleCompleted($request),
            ChildRunTerminalFinalizationKindEnum::SingleFailed => $this->finalizeSingleFailed($request),
            ChildRunTerminalFinalizationKindEnum::SingleChildCancelled => $this->finalizeSingleChildCancelled($request),
            ChildRunTerminalFinalizationKindEnum::SingleTimeout => $this->finalizeSingleTimeout($request),
            ChildRunTerminalFinalizationKindEnum::ParallelRunTerminal => $this->finalizeParallelRunTerminal($request),
        };
    }

    private function finalizeParallelRunTerminal(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        $identity = $request->artifactOutcome->identity;
        $state = $request->childRunState ?? throw new \InvalidArgumentException('Parallel run terminal finalization requires child run state.');
        $summary = $this->handoffRenderer->extractLastMessage($state);
        $outcome = new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Completed,
            summary: $summary,
        );
        $this->persistArtifactOutcome($outcome);

        return ChildRunTerminalFinalizationResultDTO::persistOnly($summary);
    }

    private function finalizePersistOnly(ChildRunTerminalOutcomeDTO $outcome): ChildRunTerminalFinalizationResultDTO
    {
        $this->persistArtifactOutcome($outcome);

        return ChildRunTerminalFinalizationResultDTO::persistOnly();
    }

    private function finalizeSingleCompleted(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        $identity = $request->artifactOutcome->identity;
        $state = $request->childRunState ?? throw new \InvalidArgumentException('Single completed finalization requires child run state.');
        $finalMessages = $this->handoffRenderer->extractLastMessage($state);
        $outcome = new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Completed,
            summary: $finalMessages,
        );
        $this->persistArtifactOutcome($outcome);

        return ChildRunTerminalFinalizationResultDTO::withPresentation(
            $this->handoffRenderer->formatCompletedResult($identity->displayName, $identity->artifactId, $finalMessages),
        );
    }

    private function finalizeSingleFailed(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        $outcome = $request->artifactOutcome;
        $this->persistArtifactOutcome($outcome);
        $errorMsg = $outcome->failureReason ?? $outcome->summary ?? 'Run failed without error message.';

        return ChildRunTerminalFinalizationResultDTO::withPresentation(
            $this->handoffRenderer->formatFailedResult($outcome->identity->displayName, $outcome->identity->artifactId, $errorMsg),
        );
    }

    private function finalizeSingleChildCancelled(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        $outcome = $request->artifactOutcome;
        $this->persistArtifactOutcome($outcome);

        return ChildRunTerminalFinalizationResultDTO::withPresentation(
            $this->handoffRenderer->formatChildCancelledMessage($outcome->identity->displayName, $outcome->identity->artifactId),
        );
    }

    private function finalizeSingleTimeout(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO
    {
        $identity = $request->artifactOutcome->identity;
        $timeoutSeconds = $request->timeoutSeconds ?? throw new \InvalidArgumentException('Single timeout finalization requires timeoutSeconds.');
        $this->persistArtifactOutcome($request->artifactOutcome);

        return ChildRunTerminalFinalizationResultDTO::withPresentation(
            $this->handoffRenderer->formatTimeoutResult(
                $identity->displayName,
                $timeoutSeconds,
                $identity->taskSummary,
                $identity->artifactId,
            ),
        );
    }

    private function persistArtifactOutcome(ChildRunTerminalOutcomeDTO $outcome): void
    {
        if (AgentArtifactStatusEnum::Cancelled === $outcome->status) {
            $this->artifactFinalizer->logChildCancelled($outcome->identity);
        }

        $this->artifactFinalizer->apply($outcome);
    }
}
